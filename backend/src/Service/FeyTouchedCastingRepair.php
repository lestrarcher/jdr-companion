<?php
declare(strict_types=1);
namespace App\Service;

use App\Entity\{Character, CharacterSessionState, Feat, TrackableResourceDefinition, TrackableResourceRule};
use App\Enum\{ResourceMaximumType, ResourceRechargeType};
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/** Explicit, narrow configuration repair. Never changes a spell acquisition. */
final readonly class FeyTouchedCastingRepair
{
    public function __construct(private EntityManagerInterface $em, private CharacterSessionStateSynchronizer $synchronizer,
        private CharacterFeatureSpellCastingResolver $resolver, private PlayerSpellDirectory $directory) {}

    public function run(int $characterId, int $stateId, bool $apply = false): array
    {
        $db = $this->em->getConnection();
        if ($db->isTransactionActive()) throw new \LogicException('Repair must own its transaction.');
        $db->beginTransaction();
        try {
            $character = $this->em->find(Character::class, $characterId);
            $session = $this->em->find(CharacterSessionState::class, $stateId);
            if (!$character || !$session || $session->getCharacter()->getId() !== $characterId) throw new \RuntimeException('Character/state mismatch.');
            $this->em->lock($character, LockMode::PESSIMISTIC_WRITE);
            // Prevent a new holder/attribution/acquisition from widening the scope during repair.
            $db->executeStatement('LOCK TABLE character_feat, character_feature_rule, character_feature_spell, feature_spell_grant IN SHARE ROW EXCLUSIVE MODE');
            $this->em->lock($session, LockMode::PESSIMISTIC_WRITE); $this->em->refresh($session);
            $feat = $db->fetchAssociative("SELECT f.id,cf.chosen_ability FROM feat f JOIN character_feat cf ON cf.feat_id=f.id WHERE cf.character_id=? AND f.slug='fey-touched'", [$characterId]);
            if (!$feat || !in_array($feat['chosen_ability'], ['intelligence','wisdom','charisma'], true)) throw new \RuntimeException('Fey Touched with an explicit mental ability is required.');
            if ((int)$db->fetchOne('SELECT COUNT(*) FROM character_feat WHERE feat_id=?', [$feat['id']]) !== 1) throw new \RuntimeException('Shared feat: repair would affect another character.');
            $rows = $db->fetchAllAssociative('SELECT g.*,a.spell_id acquired_spell_id,s.name,s.level FROM character_feature_spell a JOIN feature_spell_grant g ON g.id=a.grant_id JOIN spell_definition s ON s.id=a.spell_id WHERE a.character_id=? AND a.active=true AND g.feature_id IN (SELECT feature_definition_id FROM character_feature_rule WHERE feat_id=?) ORDER BY g.id', [$characterId,$feat['id']]);
            if (count($rows) !== 2 || array_sum(array_column($rows,'level')) !== 3) throw new \RuntimeException('Expected the two existing level 1/2 Fey Touched grants.');
            $before = $this->projection($character, $session->getState(), array_column($rows,'id'));
            $plan = [];
            foreach ($rows as $row) {
                $grantId = (int)$row['id']; $level = (int)$row['level'];
                if (!in_array($level,[1,2],true) || ($level===2 && ($row['mode']!=='fixed' || (int)$row['spell_id']!==(int)$row['acquired_spell_id']))) throw new \RuntimeException('Unexpected fixed grant.');
                $resolution = $this->resolver->resolve($character,$grantId,CharacterSessionStateSynchronizer::extractProgressionValues($session->getState()));
                if (($resolution['provenance']['type']??null)!=='feat' || ($resolution['provenance']['id']??null)!==(int)$feat['id']) throw new \RuntimeException('Ambiguous grant provenance.');
                if ((int)$db->fetchOne('SELECT COUNT(*) FROM character_feature_rule WHERE feature_definition_id=? AND (feat_id IS NULL OR feat_id<>?)',[$row['feature_id'],$feat['id']]) !== 0
                    || (int)$db->fetchOne('SELECT COUNT(*) FROM character_feature_spell WHERE grant_id=? AND character_id<>?',[$grantId,$characterId]) !== 0) throw new \RuntimeException('Shared grant: refusing broader configuration changes.');
                $rules = ['version'=>1,'methods'=>[['kind'=>'resource','cost'=>1,'castingLevel'=>$level],['kind'=>'standard-slot']]];
                $slug = 'fey-touched-grant-'.$grantId;
                $resource = $this->em->getRepository(TrackableResourceDefinition::class)->findOneBy(['slug'=>$slug]);
                if ($resource && ($resource->getOwner()!==null || $resource->getMaximumType()!==ResourceMaximumType::Fixed || $resource->getBaseMaximum()!==1
                    || $resource->getMultiplier()!==1 || $resource->getMinimumMaximum()>1
                    || $resource->getRechargeType()!==ResourceRechargeType::LongRest)) throw new \RuntimeException('Existing resource differs from the reviewed configuration.');
                if (($row['casting_rules']!==null && json_decode($row['casting_rules'],true,512,JSON_THROW_ON_ERROR)!=$rules)
                    || ($row['resource_definition_id']!==null && (!$resource || (int)$row['resource_definition_id']!==$resource->getId()))
                    || !in_array($row['casting_ability'],[null,'CHOICE'],true)) throw new \RuntimeException('Existing casting configuration differs: manual review required.');
                $plan[] = ['grantId'=>$grantId,'spellId'=>(int)$row['acquired_spell_id'],'name'=>$row['name'],'resource'=>$slug,'castingAbility'=>'CHOICE','castingRules'=>$rules];
                if (!$apply) continue;
                if (!$resource) {
                    $resource = new TrackableResourceDefinition($slug,'Faveur des Fées — '.$row['name'],ResourceRechargeType::LongRest);
                    $resource->setBaseMaximum(1); $this->em->persist($resource); $this->em->flush();
                }
                $assignments = $this->em->getRepository(TrackableResourceRule::class)->findBy(['resourceDefinition'=>$resource]);
                if ($assignments===[]) {
                    $this->em->persist(TrackableResourceRule::forFeat($resource,$this->em->find(Feat::class,(int)$feat['id'])));
                    $this->em->flush();
                } elseif (count($assignments)!==1 || $assignments[0]->getFeat()?->getId()!==(int)$feat['id']
                    || $assignments[0]->getUnlockLevel()!==1 || $assignments[0]->getMaximumOverride()!==null || $assignments[0]->getMaximumBonus()!==0) throw new \RuntimeException('Resource attribution differs.');
                FeatureSpellCastingRules::validate($rules,$resource->getId(),$level);
                if ($row['casting_ability']!=='CHOICE' || $row['casting_rules']===null || $row['resource_definition_id']===null) {
                    $db->update('feature_spell_grant',['casting_ability'=>'CHOICE','casting_rules'=>json_encode($rules,JSON_THROW_ON_ERROR),'resource_definition_id'=>$resource->getId()],['id'=>$grantId]);
                }
            }
            if ($apply) {
                $state = $session->getState(); $next = $state;
                $slugs = array_column($plan,'resource');
                // Reuse normal initialization but copy only the two missing counters.
                foreach ($this->synchronizer->synchronizeResources($character,$state)['resources'] as $counter) {
                    if (in_array($counter['id'],$slugs,true) && !in_array($counter['id'],array_column($state['resources']??[],'id'),true)) $next['resources'][]=$counter;
                }
                if ($next!==$state) { $session->setState($next); $this->em->flush(); }
            }
            $after = $apply ? $this->projection($character,$session->getState(),array_column($rows,'id')) : null;
            $result = ['applied'=>$apply,'characterId'=>$characterId,'stateId'=>$stateId,'revision'=>$session->getRevision(),'before'=>$before,'plan'=>$plan,'after'=>$after];
            $db->commit(); return $result;
        } catch (\Throwable $error) { $db->rollBack(); $this->em->clear(); throw $error; }
    }

    private function projection(Character $character,array $state,array $grantIds): array
    {
        $keys = array_map(static fn($id)=>'feature:'.$id,$grantIds);
        return array_values(array_map(static fn($entry)=>array_intersect_key($entry,array_flip(['key','name','castingAbility','castingRulesResolution','castingEligibility','castingAvailability'])),
            array_filter($this->directory->forCharacter($character,$state),static fn($entry)=>in_array($entry['key'],$keys,true))));
    }
}
