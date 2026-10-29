<?php
declare(strict_types=1);
namespace App\Service;

use App\Entity\{Character,User,SpellGrantSubstitutionRule,CharacterClassLevel};
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\LockMode;
use Symfony\Component\HttpKernel\Exception\{BadRequestHttpException,ConflictHttpException,NotFoundHttpException};

final readonly class CharacterSpellSubstitutions
{
    public function __construct(private EntityManagerInterface $em,private SpellCatalogue $catalogue,private CharacterSpellAccessResolver $access) {}

    private function owner(Character $character,User $actor): void
    {
        if ($character->getCampaign()->getOwner()->getId()!==$actor->getId()) throw new NotFoundHttpException('Personnage indisponible.');
    }

    public function history(Character $character,User $actor): array
    {
        $this->owner($character,$actor);
        if (!$this->em->getConnection()->fetchOne("SELECT to_regclass('character_spell_substitution')")) {
            throw new \Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException(null,'Migration des substitutions requise.');
        }
        return $this->em->getConnection()->fetchAllAssociative('SELECT s.*,r.grant_id,r.version,sp.name AS spell_name FROM character_spell_substitution s JOIN spell_grant_substitution_rule r ON r.id=s.rule_id JOIN spell_definition sp ON sp.id=s.spell_id WHERE s.character_id=? ORDER BY s.id',[$character->getId()]);
    }

    /** Read-only, server-filtered choices. No implicit session or client quota calculation. */
    public function options(Character $character,User $actor): array
    {
        $history=$this->history($character,$actor); $heads=[];
        foreach ($history as $h) $heads[(int)$h['grant_id']]=$h;
        $paths=[]; foreach ($this->access->resolve($character) as $path) $paths[$path->key]=$path;
        $rules=$this->em->getRepository(SpellGrantSubstitutionRule::class)->findBy([],['version'=>'DESC','id'=>'DESC']);
        $byId=[]; foreach ($rules as $r) $byId[$r->getId()]=$r;
        $groups=[]; $catalogues=[];
        foreach ($rules as $rule) {
            $grant=$rule->getGrant(); $id=$grant->getId(); $path=$paths['subclass-grant:'.$id]??null;
            if (!$path || !ReferenceVisibility::allows($grant->getSubclass(),$actor)) continue;
            $rule->validate();
            $original=$this->catalogue->get($grant->getSpell()->getId(),$actor);
            $level=$original['level'];
            if (!isset($catalogues[$level])) {
                $catalogues[$level]=[]; $page=1;
                do {
                    $batch=$this->catalogue->search(['level'=>$level,'page'=>$page++,'pageSize'=>100],$actor);
                    array_push($catalogues[$level],...$batch['spells']);
                } while (($page-1)*100<$batch['total']);
            }
            $lists=array_map(static fn($c)=>$c->getId(),$rule->getLists());
            $available=array_values(array_filter($catalogues[$level],static fn($s)=>
                (!$rule->getSchools() || in_array($s['school'],$rule->getSchools(),true)) &&
                array_intersect($lists,array_column($s['classes'],'id'))));
            $levels=[]; $rank=0; $chosen=false;
            foreach ($character->getClassLevels() as $l) {
                if ($l->getCharacterClass()!==$grant->getSubclass()->getCharacterClass()) continue;
                ++$rank; if ($l->getSubclass()===$grant->getSubclass()) $chosen=true;
                $spent=0; $later=false;
                foreach ($history as $h) {
                    $r=$byId[(int)$h['rule_id']];
                    if ($r->getGrant()->getSubclass()!==$grant->getSubclass()) continue;
                    $hLevel=$this->em->find(CharacterClassLevel::class,(int)$h['class_level_id']);
                    if (($h['effective_position']??$hLevel->getPosition())>$l->getPosition()) $later=true;
                    if ($h['decision_kind']==='substitution' && (int)$h['class_level_id']===$l->getId() && $r->getBudgetKey()===$rule->getBudgetKey()) ++$spent;
                }
                if ($chosen && $rank >= $grant->getUnlockLevel()) $levels[]=['id'=>$l->getId(),'level'=>$rank,'remaining'=>$later?0:max(0,$rule->getOpportunities()[$rank-1]-$spent)];
            }
            if (!$levels) continue;
            $head=$heads[$id]??null;
            $groups[$id]??=['grantId'=>$id,'sourceName'=>$grant->getSubclass()->getName(),'className'=>$grant->getSubclass()->getCharacterClass()->getName(),'originalSpell'=>['id'=>$original['id'],'name'=>$original['name']],'currentSpell'=>['id'=>$path->spell['id'],'name'=>$path->spell['name']],'expectedPrevious'=>$head===null?null:(int)$head['id'],'activeRuleId'=>$head===null?$rule->getId():(int)$head['rule_id'],'currentClassLevelId'=>$levels[array_key_last($levels)]['id'],'versions'=>[]];
            $groups[$id]['versions'][]=['id'=>$rule->getId(),'version'=>$rule->getVersion(),'provenance'=>$rule->getProvenance(),'levels'=>$levels,'available'=>array_map(static fn($s)=>['id'=>$s['id'],'name'=>$s['name']],$available)];
        }
        return ['characterName'=>$character->getName(),'history'=>$history,'grants'=>array_values($groups)];
    }

    public function substitute(Character $character,int $ruleId,int $levelId,int $spellId,?int $expectedPrevious,string $reason,bool $confirmed,User $actor,string $kind='substitution'): int
    {
        $this->owner($character,$actor);
        $this->history($character,$actor); // Check schema availability before locking or writing.
        if (!$confirmed || trim($reason)==='' || mb_strlen($reason)>10000) throw new BadRequestHttpException('Substitution explicitement confirmée et motivée requise.');
        if (!in_array($kind,['substitution','correction','revocation','transition'],true)) throw new BadRequestHttpException('Type de décision invalide.');
        $db=$this->em->getConnection();
        return $db->transactional(function () use ($db,$character,$ruleId,$levelId,$spellId,$expectedPrevious,$reason,$actor,$kind) {
            $this->em->lock($character,LockMode::PESSIMISTIC_WRITE); $this->em->refresh($character); $this->owner($character,$actor);
            $db->executeStatement('LOCK TABLE spell_grant_substitution_rule,spell_grant_substitution_list,spell_subclass_grant,spell_definition,spell_class_list_entry,character_class,character_subclass,character_class_level IN SHARE MODE');
            $rule=$this->em->find(SpellGrantSubstitutionRule::class,$ruleId);
            if (!$rule || !ReferenceVisibility::allows($rule->getGrant()->getSubclass(),$actor)) throw new NotFoundHttpException('Règle indisponible.');
            $rule->validate(); $grant=$rule->getGrant();
            $level=$this->em->find(CharacterClassLevel::class,$levelId);
            if (!$level || $level->getCharacter()!==$character || $level->getCharacterClass()!==$grant->getSubclass()->getCharacterClass()) throw new BadRequestHttpException('Niveau étranger à la classe source.');
            $rank=0; $chosen=false;
            foreach ($character->getClassLevels() as $l) if ($l->getPosition()<=$level->getPosition() && $l->getCharacterClass()===$level->getCharacterClass()) {
                ++$rank; if ($l->getSubclass()===$grant->getSubclass()) $chosen=true;
            }
            if (!$chosen || $rank<$grant->getUnlockLevel() || $character->getSubclassFor($level->getCharacterClass())!==$grant->getSubclass()) throw new BadRequestHttpException('Attribution inactive à ce niveau.');
            $history=$this->history($character,$actor); $previous=null;
            foreach ($history as $h) if ((int)$h['grant_id']===$grant->getId()) $previous=$h;
            if (($previous===null?null:(int)$previous['id'])!==$expectedPrevious) throw new ConflictHttpException('La substitution a changé ; relire son historique.');
            $effectivePosition=$level->getPosition();
            if ($kind!=='substitution') {
                if ($previous===null) throw new ConflictHttpException('Une décision existante est requise.');
                $currentLevels=$character->getClassLevels()->toArray();
                $currentPosition=max(array_map(static fn($l)=>$l->getPosition(),$currentLevels));
                // Administrative decisions take effect now, never at a reconstructed past level.
                $sourceLevels=array_filter($currentLevels,static fn($l)=>$l->getCharacterClass()===$level->getCharacterClass());
                if ($level->getPosition()!==max(array_map(static fn($l)=>$l->getPosition(),$sourceLevels))) throw new BadRequestHttpException('Le dernier niveau réel de la classe source est requis.');
                $effectivePosition=$currentPosition;
            }
            if ($previous!==null && (int)$previous['rule_id']!==$ruleId && $kind!=='transition') throw new ConflictHttpException('La version de substitution est figée après le premier choix.');
            if ($kind==='transition' && ((int)$previous['rule_id']===$ruleId || (int)$previous['version'] >= $rule->getVersion())) throw new ConflictHttpException('Une version plus récente est requise.');
            if ($previous===null && $db->fetchOne('SELECT 1 FROM spell_grant_substitution_rule WHERE grant_id=? AND version>?',[$grant->getId(),$rule->getVersion()])) throw new ConflictHttpException('Utiliser la dernière version pour le premier choix.');
            $spent=0;
            foreach ($history as $h) {
                $oldLevel=$this->em->find(CharacterClassLevel::class,(int)$h['class_level_id']);
                $oldRule=$this->em->find(SpellGrantSubstitutionRule::class,(int)$h['rule_id']);
                if ($oldRule->getGrant()->getSubclass()!==$grant->getSubclass()) continue;
                if (($h['effective_position']??$oldLevel->getPosition())>$effectivePosition) throw new ConflictHttpException('Une substitution ultérieure existe déjà.');
                if ($h['decision_kind']==='substitution' && $oldLevel===$level && $oldRule->getBudgetKey()===$rule->getBudgetKey()) ++$spent;
            }
            if ($kind==='substitution' && $spent >= $rule->getOpportunities()[$rank-1]) throw new ConflictHttpException('Budget de substitution épuisé.');
            if ($kind==='revocation' && $spellId!==$grant->getSpell()->getId()) throw new BadRequestHttpException('La révocation rétablit le sort initial.');
            if ($kind==='transition' && $spellId!==(int)$previous['spell_id']) throw new BadRequestHttpException('Une transition conserve le sort courant.');
            $spell=$this->catalogue->get($spellId,$actor);
            if ($kind!=='transition' && $spellId===($previous===null?$grant->getSpell()->getId():(int)$previous['spell_id'])) throw new ConflictHttpException('Ce sort est déjà accordé par cette voie.');
            $restoring=$kind==='revocation' || ($kind==='transition' && $spellId===$grant->getSpell()->getId());
            if (!$restoring && ($spell['level']!==$grant->getSpell()->getLevel() || ($rule->getSchools() && !in_array($spell['school'],$rule->getSchools(),true)))) throw new BadRequestHttpException('Niveau ou école incompatible.');
            $lists=array_map(static fn($c)=>$c->getId(),$rule->getLists());
            if (!$restoring && !array_intersect($lists,array_column($spell['classes'],'id'))) throw new BadRequestHttpException('Sort absent des listes autorisées.');
            // Resolve first: rejects malformed dependencies and never changes other provenance paths.
            $paths=$this->access->resolve($character);
            if (!array_filter($paths,static fn($p)=>$p->key==='subclass-grant:'.$grant->getId())) throw new ConflictHttpException('Attribution indisponible.');
            return (int)$db->fetchOne('INSERT INTO character_spell_substitution (character_id,rule_id,spell_id,class_level_id,previous_id,decided_by_id,reason,decision_kind,effective_position,decided_at) VALUES (?,?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP) RETURNING id',[$character->getId(),$ruleId,$spellId,$levelId,$expectedPrevious,$actor->getId(),trim($reason),$kind,$effectivePosition]);
        });
    }
}
