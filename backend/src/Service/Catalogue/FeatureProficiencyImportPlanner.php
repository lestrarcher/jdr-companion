<?php

declare(strict_types=1);

namespace App\Service\Catalogue;

use App\Entity\{CharacterClass, CharacterSubclass, ProficiencyDefinition, ProficiencyGrantRule};
use App\Enum\{ProficiencyAcquisitionMode, ReferenceOrigin};

/** Permanent class/subclass benefits only. Does not import or interpret feature prose. */
final class FeatureProficiencyImportPlanner
{
    public function plan(array $catalogue, array $sources, array $definitions, array $existingRules, array $usedRuleIds = [], bool $update = false): array
    {
        if (($catalogue['edition'] ?? null) !== '2014' || !is_array($catalogue['rules'] ?? null)
            || !array_is_list($catalogue['rules']) || $catalogue['rules'] === []) throw new \DomainException('Catalogue de capacités invalide.');
        $existing = [];
        foreach ($existingRules as $rule) if ($rule->getImportKey() !== null) $existing[$rule->getImportKey()] = $rule;
        $plan = $seen = [];
        foreach ($catalogue['rules'] as $row) {
            if (!is_array($row) || !is_string($row['key'] ?? null) || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $row['key'])
                || strlen($row['key']) > 120 || isset($seen[$row['key']]) || !is_string($row['label'] ?? null)
                || !in_array($row['sourceType'] ?? null, ['class', 'subclass'], true) || !is_string($row['sourceSlug'] ?? null)
                || ($row['acquisitionMode'] ?? null) !== 'ANY' || !is_int($row['unlockLevel'] ?? null)
                || !is_int($row['choiceCount'] ?? null) || !is_array($row['options'] ?? null) || !array_is_list($row['options'])
                || !is_string($row['sourceBook'] ?? null) || trim($row['sourceBook']) === '') throw new \DomainException('Règle de capacité invalide.');
            $key = $row['key']; $seen[$key] = true;
            if ($row['sourceType'] === 'subclass' && !is_string($row['classSlug'] ?? null)) throw new \DomainException('Classe de la sous-classe requise.');
            $sourceKey = $row['sourceType'] === 'subclass' ? $row['classSlug'].':'.$row['sourceSlug'] : $row['sourceSlug'];
            $source = $sources[$row['sourceType']][$sourceKey] ?? null;
            $type = $row['sourceType'] === 'class' ? CharacterClass::class : CharacterSubclass::class;
            if (!$source instanceof $type || $source->getOrigin() !== ReferenceOrigin::Official || $source->getOwner() !== null || $source->isCustom()) {
                throw new \DomainException('Source officielle absente ou protégée : '.$row['sourceSlug']);
            }
            $candidate = (new ProficiencyGrantRule($row['label']))->setImportKey($key)
                ->setUnlockLevel($row['unlockLevel'])->setChoiceCount($row['choiceCount']);
            if ($source instanceof CharacterSubclass) $candidate->setCharacterSubclass($source); else $candidate->setCharacterClass($source);
            $options = [];
            foreach ($row['options'] as $slug) {
                if (!is_string($slug) || isset($options[$slug])) throw new \DomainException('Option invalide ou répétée.');
                $definition = $definitions[$slug] ?? null;
                if (!$definition instanceof ProficiencyDefinition || $definition->getOrigin() !== ReferenceOrigin::Official || $definition->getOwner() !== null) {
                    throw new \DomainException('Maîtrise officielle absente ou protégée : '.$slug);
                }
                $candidate->addOption($definition); $options[$slug] = true;
            }
            $candidate->validateRule();
            $old = $existing[$key] ?? null;
            foreach ([...$existingRules, ...array_column($plan, 'candidate')] as $other) {
                if ($other !== $old && $other->getCharacterClass() === $candidate->getCharacterClass()
                    && $other->getCharacterSubclass() === $candidate->getCharacterSubclass()
                    && $other->getAcquisitionMode() === ProficiencyAcquisitionMode::Any
                    && ($other->getLabel() === $candidate->getLabel() || $this->mechanics($other) === $this->mechanics($candidate))) {
                    throw new \DomainException('Règle préexistante ambiguë : '.$key);
                }
            }
            if ($old && ($old->getOrigin() !== ReferenceOrigin::Official || $old->getOwner() !== null
                || $old->getCharacterClass() !== $candidate->getCharacterClass() || $old->getCharacterSubclass() !== $candidate->getCharacterSubclass()
                || $old->getAcquisitionMode() !== ProficiencyAcquisitionMode::Any)) throw new \DomainException('Identité protégée ou incompatible : '.$key);
            $changed = $old && $this->mechanics($old) !== $this->mechanics($candidate);
            if ($update && $changed && in_array($old->getId(), $usedRuleIds, true)) throw new \DomainException('Des choix utilisent cette règle : '.$key);
            $different = $old && ($changed || $old->getLabel() !== $candidate->getLabel());
            $plan[] = ['candidate' => $candidate, 'existing' => $old,
                'action' => !$old ? 'create' : (!$different ? 'unchanged' : ($update ? 'update' : 'keep'))];
        }
        return $plan;
    }

    private function mechanics(ProficiencyGrantRule $rule): array
    {
        $options = array_map(static fn ($d) => $d->getSlug(), $rule->getOptions()->toArray()); sort($options);
        return [$rule->getUnlockLevel(), $rule->getChoiceCount(), $options];
    }

    public function applyUpdate(ProficiencyGrantRule $rule, ProficiencyGrantRule $candidate): void
    {
        $rule->setLabel($candidate->getLabel())->setUnlockLevel($candidate->getUnlockLevel())->setChoiceCount($candidate->getChoiceCount());
        foreach ($rule->getOptions()->toArray() as $option) $rule->removeOption($option);
        foreach ($candidate->getOptions() as $option) $rule->addOption($option);
    }
}
