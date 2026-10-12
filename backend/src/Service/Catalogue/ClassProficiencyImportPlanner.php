<?php

declare(strict_types=1);

namespace App\Service\Catalogue;

use App\Entity\CharacterClass;
use App\Entity\ProficiencyDefinition;
use App\Entity\ProficiencyGrantRule;
use App\Enum\ProficiencyAcquisitionMode;
use App\Enum\ReferenceOrigin;

/** Builds the entire plan without mutating managed entities. */
final class ClassProficiencyImportPlanner
{
    private const CLASSES = ['barbarian', 'bard', 'cleric', 'druid', 'sorcerer', 'fighter', 'wizard', 'monk', 'warlock', 'paladin', 'ranger', 'rogue'];
    private const TYPES = ['saves' => ['SAVING_THROW'], 'skills' => ['SKILL'], 'weapons' => ['WEAPON', 'WEAPON_GROUP'], 'armor' => ['ARMOR'], 'tools' => ['TOOL']];

    /**
     * @param array<string, CharacterClass> $classes
     * @param array<string, ProficiencyDefinition> $definitions
     * @param list<ProficiencyGrantRule> $existingRules
     * @param list<int> $usedRuleIds
     * @return list<array{action: string, candidate: ProficiencyGrantRule, existing: ?ProficiencyGrantRule}>
     */
    public function plan(array $catalogue, array $classes, array $definitions, array $existingRules, array $usedRuleIds = [], bool $update = false): array
    {
        if (($catalogue['edition'] ?? null) !== '2014' || !is_array($catalogue['rules'] ?? null)
            || !array_is_list($catalogue['rules']) || $catalogue['rules'] === []) {
            throw new \DomainException('Catalogue de règles D&D 2014 invalide.');
        }
        $byKey = [];
        foreach ($existingRules as $rule) {
            if ($rule->getImportKey() !== null) {
                if (isset($byKey[$rule->getImportKey()])) {
                    throw new \DomainException('Clé existante ambiguë : '.$rule->getImportKey());
                }
                $byKey[$rule->getImportKey()] = $rule;
            }
        }

        $plan = $seen = $covered = [];
        foreach ($catalogue['rules'] as $entry) {
            if (!is_array($entry)) throw new \DomainException('Entrée de règle invalide.');
            foreach (['key', 'classSlug', 'acquisitionMode', 'category', 'label'] as $field) {
                if (!is_string($entry[$field] ?? null) || trim($entry[$field]) === '') {
                    throw new \DomainException('Champ invalide : '.$field);
                }
            }
            $slug = $entry['classSlug'];
            $mode = ProficiencyAcquisitionMode::tryFrom($entry['acquisitionMode']);
            $category = $entry['category'];
            if (!in_array($slug, self::CLASSES, true) || !isset(self::TYPES[$category])
                || !in_array($mode, [ProficiencyAcquisitionMode::StartingClass, ProficiencyAcquisitionMode::Multiclass], true)
                || ($entry['unlockLevel'] ?? null) !== 1
                || !is_int($entry['choiceCount'] ?? null) || $entry['choiceCount'] < 0
                || !is_array($entry['options'] ?? null) || !array_is_list($entry['options']) || $entry['options'] === []
                || mb_strlen($entry['label']) > 120) {
                throw new \DomainException('Structure de règle invalide : '.$entry['key']);
            }
            $key = 'phb-2014-'.$slug.'-'.($mode === ProficiencyAcquisitionMode::StartingClass ? 'starting' : 'multiclass').'-'.$category;
            if ($entry['key'] !== $key || isset($seen[$key])) {
                throw new \DomainException('Clé invalide ou dupliquée : '.$entry['key']);
            }
            if (($category === 'saves' && ($mode === ProficiencyAcquisitionMode::Multiclass || $entry['choiceCount'] !== 0))
                || (in_array($category, ['weapons', 'armor'], true) && $entry['choiceCount'] !== 0)
                || ($category === 'skills' && $entry['choiceCount'] === 0)) {
                throw new \DomainException('Mode de choix incompatible : '.$key);
            }
            $seen[$key] = true;
            $class = $classes[$slug] ?? null;
            if (!$class instanceof CharacterClass || $class->getOrigin() !== ReferenceOrigin::Official || $class->getOwner() !== null || $class->isCustom()) {
                throw new \DomainException('Classe officielle absente ou protégée : '.$slug);
            }
            $candidate = (new ProficiencyGrantRule($entry['label']))->setImportKey($key)
                ->setCharacterClass($class)->setAcquisitionMode($mode)->setUnlockLevel(1)->setChoiceCount($entry['choiceCount']);
            $options = [];
            foreach ($entry['options'] as $option) {
                if (!is_string($option) || isset($options[$option])) {
                    throw new \DomainException('Option invalide ou dupliquée : '.$key);
                }
                $definition = $definitions[$option] ?? null;
                if (!$definition instanceof ProficiencyDefinition || $definition->getOrigin() !== ReferenceOrigin::Official || $definition->getOwner() !== null
                    || !in_array($definition->getType()->value, self::TYPES[$category], true)) {
                    throw new \DomainException('Maîtrise officielle absente, protégée ou de type incorrect : '.$option);
                }
                $options[$option] = true;
                $candidate->addOption($definition);
            }
            $candidate->validateRule();
            if ($mode === ProficiencyAcquisitionMode::StartingClass) $covered[$slug] = true;
            $existing = $byKey[$key] ?? null;
            if ($existing !== null && ($existing->getOrigin() !== ReferenceOrigin::Official || $existing->getOwner() !== null)) {
                throw new \DomainException('Règle personnalisée protégée : '.$key);
            }
            if ($existing !== null && ($existing->getCharacterClass() !== $class || $existing->getAcquisitionMode() !== $mode
                || $existing->getCharacterRace() !== null || $existing->getCharacterSubclass() !== null || $existing->getFeat() !== null || $existing->getCharacterBackground() !== null)) {
                throw new \DomainException('Identité de règle incompatible : '.$key);
            }
            foreach ($existingRules as $other) {
                if ($other === $existing || $other->getCharacterClass() !== $class || $other->getAcquisitionMode() !== $mode) continue;
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
        if (count($covered) !== count(self::CLASSES)) {
            throw new \DomainException('Le catalogue doit couvrir les douze classes initiales du PHB 2014.');
        }

        return $plan;
    }

    private function mechanics(ProficiencyGrantRule $rule): array
    {
        $options = array_map(static fn (ProficiencyDefinition $definition): string => $definition->getSlug(), $rule->getOptions()->toArray());
        sort($options);

        return [$rule->getUnlockLevel(), $rule->getChoiceCount(), $options];
    }

    /** Apply an already validated plan item, retaining rule identity and choice relations. */
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
