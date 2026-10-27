<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Character;
use App\Entity\SpellcastingPolicyDefinition;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/** Read-only directory. Never creates acquisitions, prepares spells or spends slots. */
final readonly class PlayerSpellDirectory
{
    public function __construct(
        private Connection $db,
        private EntityManagerInterface $em,
        private CharacterFeatureSpellService $featureSpells,
        private CharacterAbilityCalculator $abilities,
        private CharacterSpellAccessResolver $access,
        private CharacterSpellAcquisitions $acquisitions,
        private CharacterSpellPreparation $preparation,
        private SpellCatalogue $catalogue,
        private CharacterSpellAvailabilityContext $availabilityContext,
        private CharacterSpellCastEligibility $eligibility = new CharacterSpellCastEligibility(),
        private CharacterSpellCastAvailability $availability = new CharacterSpellCastAvailability(),
    ) {}

    public function forCharacter(Character $character, array $state = []): array
    {
        $result = [];
        $castingContext = $this->availabilityContext->build($character, $state);
        $id = $character->getId();
        $rows = $this->db->fetchAllAssociative(
            'SELECT a.id, a.spell_id, a.policy_definition_id, a.quota_bucket, a.known, a.in_spellbook,
                    s.name, s.level, s.school
             FROM character_spell_acquisition a
             JOIN spell_definition s ON s.id=a.spell_id
             WHERE a.character_id=? AND a.retirement_id IS NULL
               AND NOT EXISTS (SELECT 1 FROM character_spell_acquisition next_a WHERE next_a.replaces_id=a.id)
             ORDER BY s.level,s.name,a.id',
            [$id],
        );
        foreach ($rows as $row) {
            $definition = $this->em->find(SpellcastingPolicyDefinition::class, (int) $row['policy_definition_id']);
            if ($definition === null) continue;
            $ability = $definition->policy()->ability;
            $result[] = [
                'key'=>'acquisition:'.$row['id'], 'policyId'=>(int)$row['policy_definition_id'], 'alwaysPrepared'=>false,
                'spellId' => (int) $row['spell_id'], 'name' => $row['name'],
                'level' => (int) $row['level'], 'school' => $row['school'],
                'source' => $definition->getSource()->getName(), 'sourceType' => 'class',
                'castingAbility' => $ability->value,
                ...$this->abilities->spellStatistics($character, $ability),
                'known' => filter_var($row['known'], FILTER_VALIDATE_BOOL), 'inSpellbook' => filter_var($row['in_spellbook'], FILTER_VALIDATE_BOOL),
                'prepared' => filter_var($row['in_spellbook'], FILTER_VALIDATE_BOOL)
                    ? in_array((int)$row['spell_id'], $state['preparedSpells'][(string)$row['policy_definition_id']] ?? [], true) : null,
            ];
        }

        // Use the character's applicable (possibly pinned) policy, not an arbitrary
        // latest version or a class name inferred from the granting subclass.
        $castingPolicies = [];
        foreach ($this->acquisitions->status($character, $character->getCampaign()->getOwner()) as $status) {
            if ($status['status'] !== 'policy-configured' || count($status['policyIds']) !== 1) continue;
            $castingPolicies[$status['classId']] = $this->em->find(SpellcastingPolicyDefinition::class, $status['policyIds'][0]);
        }
        // Automatic subclass grants are distinct from class acquisition quotas.
        foreach ($this->access->resolve($character, CharacterSessionStateSynchronizer::extractProgressionValues($state)) as $path) {
            if (!in_array($path->mode, ['grant-known', 'grant-always-prepared', 'grant-cast'], true)) continue;
            $castingPolicy = in_array($path->mode, ['grant-known', 'grant-always-prepared'], true)
                ? ($castingPolicies[$path->castingClass['id'] ?? 0] ?? null) : null;
            $ability = $castingPolicy?->policy()->ability;
            $result[] = [
                'key'=>$path->key, 'policyId'=>null, 'alwaysPrepared'=>$path->mode === 'grant-always-prepared',
                'spellId' => (int) $path->spell['id'], 'name' => $path->spell['name'],
                'level' => (int) $path->spell['level'], 'school' => $path->spell['school'] ?? null,
                'source' => $path->source['subclassName'] ?? $path->source['className'] ?? 'Sous-classe',
                'sourceType' => 'subclass', 'castingAbility' => $ability?->value,
                ...($ability === null ? ['spellSaveDc'=>null, 'spellAttackBonus'=>null] : $this->abilities->spellStatistics($character, $ability)),
                'known' => $path->mode === 'grant-known',
                'inSpellbook' => false,
                'prepared' => $path->mode === 'grant-always-prepared',
                'mode' => $path->mode, 'slotUsage' => $path->slotUsage,
                'restrictions' => $path->restrictions, 'resource' => $path->resource,
                'castingClass' => $path->castingClass, 'quotaImpact' => $path->quotaImpact,
            ];
        }

        $feature = $this->featureSpells->status($character, CharacterSessionStateSynchronizer::extractProgressionValues($state));
        foreach ($feature['known'] as $grant) {
            $ability = CharacterFeatureSpellService::castingAbility($grant['castingRulesResolution']['castingAbility']);
            $statistics = $ability === null
                ? ['spellSaveDc' => null, 'spellAttackBonus' => null]
                : $this->abilities->spellStatistics($character, $ability);
            $spell = $this->db->fetchAssociative('SELECT level, school FROM spell_definition WHERE id=?', [$grant['spellId']]);
            $result[] = [
                'key'=>'feature:'.$grant['grantId'], 'policyId'=>null, 'alwaysPrepared'=>false,
                'spellId' => $grant['spellId'], 'name' => $grant['spellName'],
                'level' => $spell === false ? null : (int) $spell['level'], 'school' => $spell['school'] ?? null,
                'source' => $grant['featureName'], 'sourceType' => 'feature',
                'castingRulesResolution' => $grant['castingRulesResolution'],
                'castingAbility' => $ability?->value,
                ...$statistics,
                'known' => true, 'inSpellbook' => false, 'prepared' => null,
            ];
        }
        // Prepared-list casters do not acquire every spell on their class list.
        foreach ($state['preparedSpells'] ?? [] as $policyId => $ids) {
            $definition = $this->em->find(SpellcastingPolicyDefinition::class, (int)$policyId);
            if ($definition?->policy()->learningStyle !== 'prepared') continue;
            try {
                $options = $this->acquisitions->options($character, (int)$policyId, $character->getCampaign()->getOwner());
                $plan = $this->preparation->plan($character, $options, $state);
            } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) { continue; }
            foreach ($plan['available'] ?? [] as $spell) {
                if (!in_array($spell['id'], $plan['selected'], true)) continue;
                $result[] = ['key'=>'prepared:'.$policyId.':'.$spell['id'], 'policyId'=>(int)$policyId, 'alwaysPrepared'=>false,
                    'spellId'=>$spell['id'], 'name'=>$spell['name'], 'level'=>$spell['level'], 'school'=>$spell['school'] ?? null,
                    'source'=>$definition->getSource()->getName(), 'sourceType'=>'class', 'castingAbility'=>$options['ability'],
                    ...$this->abilities->spellStatistics($character, $definition->policy()->ability),
                    'known'=>false, 'inSpellbook'=>false, 'prepared'=>true];
            }
        }
        $details = [];
        foreach ($result as &$entry) {
            $spellId = $entry['spellId'];
            if (!array_key_exists($spellId, $details)) {
                try { $details[$spellId] = array_intersect_key($this->catalogue->get($spellId, $character->getCampaign()->getOwner()), array_flip([
                    'description', 'higherLevels', 'castingTime', 'range', 'duration', 'verbal', 'somatic', 'material', 'materialDescription', 'concentration', 'ritual',
                ])); } catch (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException) { $details[$spellId] = null; }
            }
            $entry['details'] = $details[$spellId];
            // Resolve the exact source policy, never a character-wide casting style.
            $policy = $entry['sourceType'] === 'class' && $entry['policyId'] !== null
                ? $this->em->find(SpellcastingPolicyDefinition::class, $entry['policyId']) : null;
            if ($entry['sourceType'] === 'subclass' && $entry['castingAbility'] !== null) {
                $policy = $castingPolicies[$entry['castingClass']['id'] ?? 0] ?? null;
            }
            $entry['castingEligibility'] = $this->eligibility->evaluate(
                $entry, $policy?->policy()->learningStyle, $policy?->getCharacterClass()->getSlug(),
            );
            $entry['castingAvailability'] = $this->availability->evaluate($entry, $castingContext);
        }
        unset($entry);
        return array_values(array_filter($result, static fn(array $entry): bool => $entry['details'] !== null));
    }
}
