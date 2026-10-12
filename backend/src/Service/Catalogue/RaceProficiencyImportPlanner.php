<?php

declare(strict_types=1);

namespace App\Service\Catalogue;

use App\Entity\CharacterRace;
use App\Entity\ProficiencyDefinition;
use App\Entity\ProficiencyGrantRule;
use App\Enum\ProficiencyAcquisitionMode;
use App\Enum\ReferenceOrigin;

/** Pure planning: no managed entity is changed until the entire catalogue passes. */
final class RaceProficiencyImportPlanner
{
    private const TYPES = ['skills' => ['SKILL'], 'weapons' => ['WEAPON', 'WEAPON_GROUP'], 'armor' => ['ARMOR'], 'tools' => ['TOOL']];

    /**
     * @param array<string, CharacterRace> $races
     * @param array<string, ProficiencyDefinition> $definitions
     * @param list<ProficiencyGrantRule> $existingRules
     * @param list<int> $usedRuleIds
     * @return list<array{action: string, candidate: ProficiencyGrantRule, existing: ?ProficiencyGrantRule}>
     */
    public function plan(array $catalogue, array $races, array $definitions, array $existingRules, array $usedRuleIds = [], bool $update = false): array
    {
        if (($catalogue['edition'] ?? null) !== '2014' || !is_array($catalogue['races'] ?? null)
            || !array_is_list($catalogue['races']) || $catalogue['races'] === []
            || !is_array($catalogue['rules'] ?? null) || !array_is_list($catalogue['rules'])) {
            throw new \DomainException('Catalogue racial D&D 2014 invalide.');
        }
        $scope = [];
        foreach ($catalogue['races'] as $entry) {
            if (!is_array($entry) || !is_string($entry['slug'] ?? null) || !array_key_exists('parentSlug', $entry)
                || ($entry['parentSlug'] !== null && !is_string($entry['parentSlug'])) || isset($scope[$entry['slug']])
                || !is_int($entry['ruleCount'] ?? null) || $entry['ruleCount'] < 0
                || !is_array($entry['deferred'] ?? null) || !array_is_list($entry['deferred'])) {
                throw new \DomainException('Périmètre racial invalide ou dupliqué.');
            }
            $race = $races[$entry['slug']] ?? null;
            if (!$race instanceof CharacterRace || $race->getOrigin() !== ReferenceOrigin::Official || $race->getOwner() !== null || $race->isCustom()) {
                throw new \DomainException('Race officielle absente ou protégée : '.$entry['slug']);
            }
            if ($race->getParentRace()?->getSlug() !== $entry['parentSlug']) {
                throw new \DomainException('Parenté raciale différente du catalogue : '.$entry['slug']);
            }
            $scope[$entry['slug']] = $entry;
            foreach ($entry['deferred'] as $reason) {
                if (!is_string($reason) || trim($reason) === '') throw new \DomainException('Limite raciale invalide.');
            }
        }
        foreach ($scope as $entry) {
            $visited = [];
            for ($slug = $entry['slug']; $slug !== null; $slug = $scope[$slug]['parentSlug']) {
                if (!isset($scope[$slug]) || isset($visited[$slug])) {
                    throw new \DomainException('Ancêtre hors périmètre ou cycle racial : '.$slug);
                }
                $visited[$slug] = true;
            }
        }
        $byKey = [];
        foreach ($existingRules as $rule) {
            if ($rule->getImportKey() !== null) {
                if (isset($byKey[$rule->getImportKey()])) throw new \DomainException('Clé existante ambiguë.');
                $byKey[$rule->getImportKey()] = $rule;
            }
        }
        $plan = $seen = [];
        foreach ($catalogue['rules'] as $entry) {
            if (!is_array($entry)) throw new \DomainException('Règle invalide.');
            foreach (['key', 'raceSlug', 'category', 'label'] as $field) {
                if (!is_string($entry[$field] ?? null) || trim($entry[$field]) === '') throw new \DomainException('Champ invalide : '.$field);
            }
            $slug = $entry['raceSlug'];
            $category = $entry['category'];
            $key = 'dnd-2014-race-'.$slug.'-'.$category;
            if (!isset($scope[$slug], self::TYPES[$category]) || $entry['key'] !== $key || isset($seen[$key])
                || ($entry['acquisitionMode'] ?? null) !== 'ANY' || ($entry['unlockLevel'] ?? null) !== 1
                || !is_int($entry['choiceCount'] ?? null) || $entry['choiceCount'] < 0
                || !is_array($entry['options'] ?? null) || !array_is_list($entry['options']) || $entry['options'] === []
                || mb_strlen($entry['label']) > 120) {
                throw new \DomainException('Structure de règle raciale invalide : '.$entry['key']);
            }
            $seen[$key] = true;
            $race = $races[$slug];
            $candidate = (new ProficiencyGrantRule($entry['label']))->setImportKey($key)->setCharacterRace($race)
                ->setAcquisitionMode(ProficiencyAcquisitionMode::Any)->setUnlockLevel(1)->setChoiceCount($entry['choiceCount']);
            $options = [];
            foreach ($entry['options'] as $option) {
                if (!is_string($option) || isset($options[$option])) throw new \DomainException('Option invalide ou dupliquée : '.$key);
                $definition = $definitions[$option] ?? null;
                if (!$definition instanceof ProficiencyDefinition || $definition->getOrigin() !== ReferenceOrigin::Official
                    || $definition->getOwner() !== null || !in_array($definition->getType()->value, self::TYPES[$category], true)) {
                    throw new \DomainException('Maîtrise officielle absente, protégée ou de type incorrect : '.$option);
                }
                $options[$option] = true;
                $candidate->addOption($definition);
            }
            $candidate->validateRule();
            $existing = $byKey[$key] ?? null;
            if ($existing !== null && ($existing->getOrigin() !== ReferenceOrigin::Official || $existing->getOwner() !== null)) {
                throw new \DomainException('Règle personnalisée protégée : '.$key);
            }
            if ($existing !== null && ($existing->getCharacterRace() !== $race || $existing->getAcquisitionMode() !== ProficiencyAcquisitionMode::Any
                || $existing->getCharacterClass() !== null || $existing->getCharacterSubclass() !== null || $existing->getFeat() !== null || $existing->getCharacterBackground() !== null)) {
                throw new \DomainException('Identité de règle incompatible : '.$key);
            }
            foreach ($existingRules as $other) {
                if ($other === $existing || $other->getCharacterRace() !== $race) continue;
                if ($other->getLabel() === $candidate->getLabel() || $this->mechanics($other) === $this->mechanics($candidate)) {
                    throw new \DomainException('Règle préexistante ambiguë, rapprochement manuel requis : '.$key);
                }
            }
            $mechanicsChanged = $existing !== null && $this->mechanics($existing) !== $this->mechanics($candidate);
            $different = $existing !== null && ($mechanicsChanged || $existing->getLabel() !== $candidate->getLabel());
            if ($update && $mechanicsChanged && in_array($existing->getId(), $usedRuleIds, true)) {
                throw new \DomainException('Modification mécanique refusée : des choix utilisent '.$key);
            }
            $plan[] = ['action' => $existing === null ? 'create' : (!$different ? 'unchanged' : ($update ? 'update' : 'keep')),
                'candidate' => $candidate, 'existing' => $existing];
        }

        foreach ($scope as $slug => $entry) {
            $count = count(array_filter($plan, static fn (array $item): bool => $item['candidate']->getCharacterRace() === $races[$slug]));
            if ($count !== $entry['ruleCount']) throw new \DomainException('Nombre de règles incohérent : '.$slug);
        }

        return $plan;
    }

    private function mechanics(ProficiencyGrantRule $rule): array
    {
        $options = array_map(static fn (ProficiencyDefinition $definition): string => $definition->getSlug(), $rule->getOptions()->toArray());
        sort($options);

        return [$rule->getUnlockLevel(), $rule->getChoiceCount(), $options];
    }

    public function applyUpdate(ProficiencyGrantRule $existing, ProficiencyGrantRule $candidate): void
    {
        $existing->setLabel($candidate->getLabel())->setUnlockLevel($candidate->getUnlockLevel())->setChoiceCount($candidate->getChoiceCount());
        foreach ($existing->getOptions()->toArray() as $option) {
            if (!$candidate->getOptions()->contains($option)) $existing->removeOption($option);
        }
        foreach ($candidate->getOptions() as $option) $existing->addOption($option);
        $existing->validateRule();
    }
}
