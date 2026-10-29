<?php
declare(strict_types=1);
namespace App\Service;

use App\Entity\{CharacterSessionState, TrackableResourceDefinition};
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/** Reference writes and targeted pool initialization share the grant transaction. */
final readonly class FeatureSpellGrantConfiguration
{
    public function __construct(private Connection $db, private EntityManagerInterface $em,
        private CharacterSessionStateSynchronizer $synchronizer) {}

    public function lockOwners(int $grantId, ?int $characterId = null): void
    {
        $this->db->fetchFirstColumn('SELECT c.id FROM character c WHERE EXISTS (SELECT 1 FROM character_feature_spell a WHERE a.character_id=c.id AND a.grant_id=?) AND (?::int IS NULL OR c.id=?) ORDER BY c.id FOR UPDATE', [$grantId, $characterId, $characterId]);
    }

    public function configure(array $feature, array $grant, mixed $casting, int $baseLevel): array
    {
        if (!is_array($casting) || array_is_list($casting) || array_diff(array_keys($casting), ['freeUses','rechargeType','castingLevel','standardSlot'])
            || !is_int($casting['freeUses'] ?? null) || $casting['freeUses'] < 0 || $casting['freeUses'] > 1000
            || !is_bool($casting['standardSlot'] ?? null)) throw new BadRequestHttpException('Méthodes de lancement invalides.');
        $uses = $casting['freeUses']; $managedId = $grant['managed_resource_id'] ?? null;
        $resourceId = null; $methods = [];
        $existingRules = $grant['casting_rules'] === null ? [] : json_decode($grant['casting_rules'], true, 512, JSON_THROW_ON_ERROR);
        $cost = 1;
        if ($uses === 0 && $managedId === null && $grant['resource_definition_id'] !== null) {
            throw new BadRequestHttpException('Ressource externe : conserver sa méthode gratuite ou désactiver l’octroi.');
        }
        if ($uses > 0) {
            $recharge = $casting['rechargeType'] ?? null; $level = $casting['castingLevel'] ?? null;
            if (!in_array($recharge, ['short-rest','long-rest'], true) || !is_int($level)
                || $level < $baseLevel || $level > 9 || ($baseLevel === 0 && $level !== 0)) throw new BadRequestHttpException('Utilisations gratuites invalides.');
            // Never replace or mutate an externally configured/shared legacy pool.
            if ($managedId === null && $grant['resource_definition_id'] !== null) {
                $resourceId = (int)$grant['resource_definition_id'];
                foreach ($existingRules['methods'] ?? [] as $method) if ($method['kind'] === 'resource') $cost = $method['cost'];
                $resource = $this->db->fetchAssociative('SELECT * FROM trackable_resource_definition WHERE id=?', [$resourceId]);
                if (!$resource || $resource['maximum_type'] !== 'fixed' || (int)$resource['base_maximum'] !== $uses || $resource['recharge_type'] !== $recharge) {
                    throw new BadRequestHttpException('Cette ressource existante est gérée séparément : ses utilisations et sa récupération doivent être conservées.');
                }
            } else {
                if ($managedId === null) {
                    $name = $grant['spell_id'] === null
                        ? $this->db->fetchOne('SELECT name FROM character_feature_definition WHERE id=?', [$feature['id']])
                        : $this->db->fetchOne('SELECT name FROM spell_definition WHERE id=?', [$grant['spell_id']]);
                    $this->db->insert('trackable_resource_definition', ['slug'=>'spell-grant-'.$grant['id'], 'name'=>mb_substr('Lancement gratuit — '.$name,0,150),
                        'origin'=>$feature['origin'], 'owner_id'=>$feature['owner_id'], 'custom'=>$feature['origin']==='CUSTOM' ? 1 : 0,
                        'maximum_type'=>'fixed', 'base_maximum'=>$uses, 'multiplier'=>1, 'minimum_maximum'=>0,
                        'recharge_type'=>$recharge, 'hidden_from_tracker'=>1, 'created_at'=>date('Y-m-d H:i:s'), 'updated_at'=>date('Y-m-d H:i:s')]);
                    $managedId = (int)$this->db->lastInsertId();
                } else {
                    $this->db->update('trackable_resource_definition', ['base_maximum'=>$uses, 'recharge_type'=>$recharge, 'updated_at'=>date('Y-m-d H:i:s')], ['id'=>$managedId]);
                    $definition = $this->em->find(TrackableResourceDefinition::class, (int)$managedId);
                    if ($definition) $this->em->refresh($definition);
                }
                $resourceId = (int)$managedId;
            }
            $methods[] = ['kind'=>'resource','cost'=>$cost,'castingLevel'=>$level];
        }
        if ($casting['standardSlot']) $methods[] = ['kind'=>'standard-slot'];
        $rules = ['version'=>1,'methods'=>$methods];
        if (isset($existingRules['restrictions'])) $rules['restrictions'] = $existingRules['restrictions'];
        $rules = FeatureSpellCastingRules::validate($rules, $resourceId, $baseLevel);
        return ['casting_rules'=>json_encode($rules, JSON_THROW_ON_ERROR), 'resource_definition_id'=>$resourceId, 'managed_resource_id'=>$managedId];
    }

    public function synchronize(int $grantId, ?int $characterId = null): void
    {
        $slug = $this->db->fetchOne('SELECT r.slug FROM feature_spell_grant g JOIN trackable_resource_definition r ON r.id=g.managed_resource_id WHERE g.id=? AND g.enabled=true AND g.resource_definition_id=g.managed_resource_id', [$grantId]);
        if (!$slug) return;
        $this->lockOwners($grantId, $characterId);
        $ids = $this->db->fetchFirstColumn('SELECT s.id FROM character_session_state s WHERE EXISTS (SELECT 1 FROM character_feature_spell a WHERE a.character_id=s.character_id AND a.grant_id=? AND a.active=true) AND (?::int IS NULL OR s.character_id=?) ORDER BY s.character_id,s.id', [$grantId, $characterId, $characterId]);
        foreach ($ids as $id) {
            $session = $this->em->find(CharacterSessionState::class, (int)$id);
            $this->em->lock($session, LockMode::PESSIMISTIC_WRITE); $this->em->refresh($session);
            $state = $session->getState(); $next = $state;
            foreach ($this->synchronizer->synchronizeResources($session->getCharacter(), $state)['resources'] as $counter) {
                if ($counter['id'] !== $slug) continue;
                $index = array_search($slug, array_column($state['resources'] ?? [], 'id'), true);
                if ($index === false) $next['resources'][] = $counter;
                else $next['resources'][$index] = $counter;
            }
            if ($next !== $state) $session->setState($next);
        }
        $this->em->flush();
    }
}
