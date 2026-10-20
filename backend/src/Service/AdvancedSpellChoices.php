<?php
declare(strict_types=1);
namespace App\Service;

use App\Entity\{Character, SpellcastingPolicyDefinition, SpellPolicyChoiceRule, User};
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\{BadRequestHttpException,ConflictHttpException,NotFoundHttpException};

/** Declarative format 2 evaluator. Called inside the existing acquisition boundary. */
final readonly class AdvancedSpellChoices
{
    public function __construct(private EntityManagerInterface $em, private CharacterSpellAccessResolver $access) {}

    public function rules(SpellcastingPolicyDefinition $policy): array
    {
        $rules=[];
        foreach (array_filter([$policy->getBasePolicy(),$policy]) as $part) {
            foreach ($this->em->getRepository(SpellPolicyChoiceRule::class)->findBy(['policy'=>$part],['id'=>'ASC']) as $rule) {
                $rule->validate();
                if (isset($rules[$rule->getKey()])) throw new ConflictHttpException('Clé de choix ambiguë dans la composition.');
                $rules[$rule->getKey()]=$rule;
            }
        }
        $groups=[];
        foreach ($rules as $r) {
            $p=$r->parameters()->values; $group=$p['replacementBudget'];
            if (isset($groups[$group]) && $groups[$group]!==$p['replacements']) throw new ConflictHttpException('Budget partagé contradictoire.');
            $groups[$group]=$p['replacements'];
        }
        return array_column(array_map(static fn($r)=>['id'=>$r->getId(),'rule'=>$r],array_values($rules)),'rule','id');
    }

    private function rows(Character $character, SpellcastingPolicyDefinition $policy): array
    {
        return $this->em->getConnection()->fetchAllAssociative('SELECT a.*, c.rule_id, c.opportunity_level_id, c.origin_known, s.level AS spell_level FROM character_spell_acquisition a LEFT JOIN character_spell_choice c ON c.acquisition_id=a.id JOIN spell_definition s ON s.id=a.spell_id WHERE a.character_id=? AND a.policy_definition_id=? AND a.retirement_id IS NULL ORDER BY a.id',[$character->getId(),$policy->getId()]);
    }

    private function eligible(SpellPolicyChoiceRule $rule,array $spell,int $level,bool $replacement,User $owner,array $extensions=[]): bool
    {
        $p=$rule->parameters()->values;
        if ($spell['level']<$p[$replacement?'replacementMinimumSpellLevel':'minimumSpellLevel'] || $spell['level']>$p['maxSpellLevels'][$level-1]) return false;
        $schools=$p[$replacement?'replacementSchools':'schools'];
        if ($schools && !in_array($spell['school'],$schools,true)) return false;
        if (!$replacement && $rule->getRequiredSpell()!==null && $rule->getRequiredSpell()->getId()!==$spell['id']) return false;
        $list=$replacement ? ($rule->getReplacementListClass() ?? $rule->getListClass()) : $rule->getListClass();
        if ($list!==null && !ReferenceVisibility::allows($list,$owner)) return false;
        if ($rule->getCastingClass()!==null && !ReferenceVisibility::allows($rule->getCastingClass(),$owner)) return false;
        if ($list===null && !$p['allClassLists']) return false;
        // The catalogue already checked every dependency and visibility before exposing this spell.
        foreach ($spell['classes'] as $class) if ($list===null || $class['id']===$list->getId()) return true;
        if ($list!==null && isset($extensions[$list->getId()][$spell['id']])) return true;
        return false;
    }

    public function options(Character $character,SpellcastingPolicyDefinition $policy,array $levels,int $index): array
    {
        $owner=$character->getCampaign()->getOwner(); $rules=$this->rules($policy); $position=$levels[$index]->getPosition();
        $positions=[]; foreach ($levels as $l) $positions[$l->getId()]=$l->getPosition();
        $rows=array_values(array_filter($this->rows($character,$policy),static fn($r)=>isset($positions[$r['class_level_id']]) && $positions[$r['class_level_id']]<=$position));
        $replaced=array_column($rows,'replaces_id');
        $active=array_values(array_filter($rows,static fn($r)=>!in_array($r['id'],$replaced,false)));
        $catalogue=new SpellCatalogue($this->em->getConnection());
        $used=['known'=>0,'cantrips'=>0,'spellbook'=>0,'preparation'=>0]; $occupied=[];
        foreach ($active as $row) {
            $catalogue->get((int)$row['spell_id'],$owner);
            $rule=$rules[$row['rule_id']??0]??null;
            $bucket=$rule?->parameters()->values['quota'] ?? $row['quota_bucket'];
            if (isset($used[$bucket])) { ++$used[$bucket]; $occupied[$bucket][$row['spell_id']]=true; }
        }
        $blocked=[]; $automatic=[]; $extensions=[];
        foreach ($this->access->resolve($character,[],$position) as $path) {
            if ($path->mode==='add-to-list') {
                $sourceLevel=0; $chosen=false;
                foreach ($character->getClassLevels() as $level) {
                    if ($level->getPosition()>$position || $level->getCharacterClass()->getId()!==$path->source['classId']) continue;
                    ++$sourceLevel;
                    if ($level->getSubclass()?->getId()===$path->source['subclassId']) $chosen=true;
                }
                if ($chosen && $sourceLevel>=$path->source['unlockLevel']) $extensions[$path->castingClass['id']][$path->spell['id']]=true;
                continue;
            }
            if ($path->source['classId']!==$policy->getCharacterClass()->getId() || !in_array($path->mode,['grant-known','grant-always-prepared'],true) || $path->source['unlockLevel']>$index+1) continue;
            $chosen=false;
            foreach (array_slice($levels,0,$index+1) as $level) if ($level->getSubclass()?->getId()===($path->source['subclassId']??null)) $chosen=true;
            if (!$chosen) continue;
            $bucket=$policy->policy()->grantQuota($path->mode,$path->spell['level']);
            $automatic[]=['key'=>$path->key,'spellId'=>$path->spell['id'],'bucket'=>$bucket,'impact'=>$path->quotaImpact,'acquisitionClassId'=>$policy->getCharacterClass()->getId(),'quotaClassId'=>$policy->getCharacterClass()->getId(),'castingClassId'=>$path->castingClass['id']??null];
            if ($path->quotaImpact==='unspecified' && $bucket!==null) $blocked[]=$bucket;
            if ($path->quotaImpact==='counts-toward-quota') {
                if ($bucket===null) $blocked[]='source-policy';
                elseif (!isset($occupied[$bucket][$path->spell['id']])) { ++$used[$bucket]; $occupied[$bucket][$path->spell['id']]=true; }
            }
        }
        $all=[]; $page=1;
        do {
            $batch=$catalogue->search(['page'=>$page++,'pageSize'=>100],$owner);
            foreach ($batch['spells'] as $spell) $all[$spell['id']]=$spell;
        } while (($page-1)*100<$batch['total']);
        $choices=[]; $available=[];
        foreach ($rules as $id=>$rule) {
            $p=$rule->parameters();
            $spent=count(array_filter($rows,static fn($r)=>$r['provenance']!=='spellbook-copy' && (int)$r['rule_id']===$id && (int)$r['class_level_id']===$levels[$index]->getId() && $r['replaces_id']===null));
            $eligible=array_values(array_filter($all,fn($s)=>$this->eligible($rule,$s,$index+1,false,$owner,$extensions)));
            foreach ($eligible as $spell) $available[$spell['id']]=$spell;
            $choices[]=['ruleId'=>$id,'key'=>$rule->getKey(),'quota'=>$p->values['quota'],'result'=>$p->values['result'],'remaining'=>max(0,$p->at('opportunities',$index+1)-$spent),
                'castingClassId'=>$rule->getCastingClass()?->getId() ?? $policy->getCharacterClass()->getId(),
                'available'=>$eligible,
                'replacementAvailable'=>array_values(array_filter($all,fn($s)=>$this->eligible($rule,$s,$index+1,true,$owner,$extensions)))];
        }
        $limits=$policy->policy()->atLevel($index+1);
        $remaining=['known'=>max(0,$limits['known']-$used['known']),'cantrips'=>max(0,$limits['cantrips']-$used['cantrips']),'spellbook'=>0];
        foreach ($choices as $choice) if ($choice['quota']==='spellbook') $remaining['spellbook']+=$choice['remaining'];
        $overQuota=$used['known']>$limits['known'] || $used['cantrips']>$limits['cantrips'];
        return ['policyId'=>$policy->getId(),'formatVersion'=>2,'classLevelId'=>$levels[$index]->getId(),'classLevel'=>$index+1,
            'acquisitionClassId'=>$policy->getCharacterClass()->getId(),'quotaClassId'=>$policy->getCharacterClass()->getId(),
            'learningStyle'=>$policy->policy()->learningStyle,'ability'=>$policy->policy()->ability->value,'limits'=>$policy->policy()->atLevel($index+1),
            'choices'=>$choices,'available'=>array_values($available),'acquisitions'=>$active,'used'=>$used,'blocked'=>array_values(array_unique($blocked)),
            'automatic'=>$automatic,'remaining'=>$remaining,'limitations'=>$policy->policy()->limitations,
            'preparation'=>['base'=>$limits['preparationBase'],'automaticCounted'=>$used['preparation'],'dailyChoicesManaged'=>false],
            'canCast'=>null,'status'=>$blocked?'quota-configuration-required':($overQuota?'quota-exceeded':'choices-to-review')];
    }

    /** Existing caller owns authorization, character lock, reference locks and transaction. */
    public function recordLocked(Character $character,SpellcastingPolicyDefinition $policy,array $levels,array $choices,string $provenance,string $note,User $actor,?int $revisionId=null,bool $override=false,?array $copyDetails=null): array
    {
        $db=$this->em->getConnection(); $rules=$this->rules($policy); $ids=[];
        $db->executeStatement('LOCK TABLE spell_policy_choice_rule IN SHARE MODE');
        foreach ($choices as $choice) {
            if ($provenance==='spellbook-copy') {
                $spell=(new SpellCatalogue($db))->get($choice['spellId'],$actor);
                $copyOptions=$this->options($character,$policy,$levels,count($levels)-1);
                $candidates=[];
                foreach ($copyOptions['choices'] as $route) if ($route['result']==='spellbook' && in_array($spell['id'],array_column($route['available'],'id'),true)) $candidates[$route['ruleId']]=$rules[$route['ruleId']];
                if (count($candidates)!==1) throw new ConflictHttpException('Voie de copie absente ou ambiguë.');
                $choice['ruleId']=array_key_first($candidates);
                \App\Dto\SpellbookCopyDecision::validate($copyDetails,$spell['level']);
            }
            if (!is_array($choice) || array_diff(array_keys($choice),['spellId','classLevelId','ruleId','replacesId','optionConfirmed']) || !is_int($choice['spellId']??null) || !is_int($choice['classLevelId']??null) || !is_int($choice['ruleId']??null) || (isset($choice['replacesId'])&&!is_int($choice['replacesId'])) || (isset($choice['optionConfirmed'])&&!is_bool($choice['optionConfirmed']))) throw new BadRequestHttpException('Choix avancé invalide.');
            $index=array_search($choice['classLevelId'],array_map(static fn($l)=>$l->getId(),$levels),true);
            if ($index===false || !isset($rules[$choice['ruleId']])) throw new BadRequestHttpException('Niveau ou règle étrangère.');
            $rule=$rules[$choice['ruleId']]; $p=$rule->parameters(); $replaces=$choice['replacesId']??null;
            $options=$this->options($character,$policy,$levels,$index); $rows=$this->rows($character,$policy);
            $active=array_column($options['acquisitions'],null,'id'); $originLevel=$provenance==='level-history'?$choice['classLevelId']:null;
            if ($provenance==='manual-reconciliation' && $index!==count($levels)-1) throw new BadRequestHttpException('Reprise au niveau actuel uniquement.');
            foreach ($rows as $row) {
                $oldIndex=array_search((int)$row['class_level_id'],array_map(static fn($l)=>$l->getId(),$levels),true);
                if ($row['provenance']!=='spellbook-copy' && $provenance==='level-history' && ($oldIndex>$index || ($row['provenance']==='manual-reconciliation' && $oldIndex>=$index))) throw new ConflictHttpException('Chronologie historique incompatible.');
                if ($provenance==='manual-reconciliation' && $revisionId===null && $row['provenance']!=='manual-reconciliation') throw new ConflictHttpException('Reconstruction déjà commencée.');
            }
            $spell=(new SpellCatalogue($db))->get($choice['spellId'],$actor);
            $route=array_column($options['choices'],null,'ruleId')[$rule->getId()];
            if (!$override && !in_array($spell['id'],array_column($route[$replaces!==null?'replacementAvailable':'available'],'id'),true)) throw new BadRequestHttpException('Sort hors des critères de choix.');
            foreach ($active as $row) if ((int)$row['spell_id']===$choice['spellId']) throw new ConflictHttpException('Sort déjà acquis.');
            foreach ($options['automatic'] as $grant) if ($grant['spellId']===$choice['spellId'] && $grant['bucket']!==null) throw new ConflictHttpException('Sort automatiquement attribué.');
            $bucket=$p->values['quota'];
            if (in_array($bucket,$options['blocked'],true) || in_array('source-policy',$options['blocked'],true)) throw new ConflictHttpException('Quota non renseigné.');
            if ($replaces!==null) {
                if ($p->values['optionalReplacement'] && ($choice['optionConfirmed']??false)!==true) throw new BadRequestHttpException('Activer explicitement cette option facultative pour ce choix.');
                $old=$active[$replaces]??null;
                if (!$old || $provenance!=='level-history' || (int)$old['class_level_id']===$choice['classLevelId']) throw new BadRequestHttpException('Remplacement invalide.');
                if ((int)$old['spell_level']<$p->values['replacementMinimumSpellLevel']) throw new BadRequestHttpException('Ce sort ne peut pas être remplacé par cette règle.');
                if ($old['rule_id']===null) throw new ConflictHttpException('Provenance à qualifier par le MJ.');
                if (!filter_var($old['origin_known'],FILTER_VALIDATE_BOOL)) {
                    $semantics=[];
                    foreach ($rules as $candidate) {
                        $cp=$candidate->parameters()->values;
                        if ($cp['result']===$p->values['result'] && array_sum($cp['replacements'])>0) $semantics[]=json_encode([$cp['quota'],$cp['replacementSchools'],$candidate->getReplacementListClass()?->getId() ?? $candidate->getListClass()?->getId(),$cp['replacementBudget'],$cp['replacements']]);
                    }
                    if (count(array_unique($semantics))>1) throw new ConflictHttpException('Ce remplacement nécessite une provenance qualifiée par le MJ.');
                }
                if ((int)$old['rule_id']!==$rule->getId()) throw new BadRequestHttpException('Le remplacement conserve sa règle d’origine.');
                $originLevel=$old['opportunity_level_id'];
                $group=$p->values['replacementBudget'];
                $spent=count(array_filter($rows,static fn($r)=>$r['replaces_id']!==null && (int)$r['class_level_id']===$choice['classLevelId'] && isset($rules[$r['rule_id']]) && $rules[$r['rule_id']]->parameters()->values['replacementBudget']===$group));
                if ($spent>=$p->at('replacements',$index+1)) throw new ConflictHttpException('Budget de remplacement épuisé.');
            } elseif ($provenance!=='spellbook-copy') {
                $spent=count(array_filter($rows,static fn($r)=>$r['provenance']!=='spellbook-copy' && $r['replaces_id']===null && (int)$r['rule_id']===$rule->getId() && ($provenance==='manual-reconciliation' || (int)$r['class_level_id']===$choice['classLevelId'])));
                $limit=$provenance==='manual-reconciliation'?array_sum(array_slice($p->values['opportunities'],0,$index+1)):$p->at('opportunities',$index+1);
                if (!$override && !($provenance==='manual-reconciliation' && $p->values['result']==='spellbook') && $spent>=$limit) throw new ConflictHttpException('Opportunité épuisée.');
                if (!$override && isset($options['limits'][$bucket]) && $options['used'][$bucket]>=$options['limits'][$bucket]) throw new ConflictHttpException('Quota atteint.');
            }
            $storedBucket=$p->values['result']==='cast'?'cast':($bucket==='free'?'known':$bucket);
            $decisionNote=$replaces!==null && $p->values['optionalReplacement']?'Option facultative confirmée : '.$note:$note;
            if (mb_strlen($decisionNote)>10000) throw new BadRequestHttpException('Motif trop long.');
            $id=(int)$db->fetchOne('INSERT INTO character_spell_acquisition (character_id,spell_id,policy_definition_id,class_level_id,replaces_id,decided_by_id,provenance,quota_bucket,known,in_spellbook,historical_validation,decision_note,decided_at,revision_id,copy_details) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP,?,?) RETURNING id',[$character->getId(),$spell['id'],$policy->getId(),$choice['classLevelId'],$replaces,$actor->getId(),$provenance,$storedBucket,$p->values['result']==='known'?1:0,$p->values['result']==='spellbook'?1:0,$provenance==='level-history'?1:0,$decisionNote,$revisionId,$copyDetails===null?null:json_encode($copyDetails,JSON_THROW_ON_ERROR)]);
            $db->insert('character_spell_choice',['acquisition_id'=>$id,'rule_id'=>$rule->getId(),'opportunity_level_id'=>$originLevel,'decided_by_id'=>$actor->getId(),'reason'=>$note,'origin_known'=>$replaces!==null?(filter_var($old['origin_known'],FILTER_VALIDATE_BOOL)?1:0):($provenance==='level-history'?1:0),'decided_at'=>date('Y-m-d H:i:s')]);
            $ids[]=$id;
        }
        return $ids;
    }
}
