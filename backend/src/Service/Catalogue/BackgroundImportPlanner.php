<?php

declare(strict_types=1);

namespace App\Service\Catalogue;

use App\Entity\{CharacterBackground, ProficiencyDefinition, ProficiencyGrantRule};
use App\Enum\{ProficiencyAcquisitionMode, ReferenceOrigin};

/** Validates the entire catalogue before any managed entity is changed. */
final class BackgroundImportPlanner
{
    /**
     * @param array<string, CharacterBackground> $backgrounds
     * @param array<string, ProficiencyDefinition> $definitions
     * @param list<ProficiencyGrantRule> $existingRules
     * @param list<int> $usedRuleIds
     * @return array{backgrounds: list<array{action: string, candidate: CharacterBackground, existing: ?CharacterBackground}>, rules: list<array{action: string, candidate: ProficiencyGrantRule, existing: ?ProficiencyGrantRule}>}
     */
    public function plan(array $catalogue, array $backgrounds, array $definitions, array $existingRules, array $usedRuleIds = [], bool $update = false): array
    {
        if (($catalogue['edition'] ?? null) !== '2014' || !is_array($catalogue['backgrounds'] ?? null)
            || !array_is_list($catalogue['backgrounds']) || $catalogue['backgrounds'] === []) {
            throw new \DomainException('Catalogue d’historiques D&D 2014 invalide.');
        }
        $byKey = [];
        foreach ($existingRules as $rule) {
            if ($rule->getImportKey() === null) continue;
            if (isset($byKey[$rule->getImportKey()])) throw new \DomainException('Clé existante ambiguë.');
            $byKey[$rule->getImportKey()] = $rule;
        }
        $plan = ['backgrounds' => [], 'rules' => []];
        $seen = $keys = [];
        foreach ($catalogue['backgrounds'] as $entry) {
            if (!is_array($entry) || !is_string($entry['slug'] ?? null) || !is_string($entry['name'] ?? null)
                || !array_key_exists('description', $entry) || ($entry['description'] !== null && !is_string($entry['description']))
                || !is_array($entry['rules'] ?? null) || !array_is_list($entry['rules']) || $entry['rules'] === []
                || !is_array($entry['deferred'] ?? null) || !array_is_list($entry['deferred'])) {
                throw new \DomainException('Historique invalide.');
            }
            foreach ($entry['deferred'] as $reason) {
                if (!is_string($reason) || trim($reason) === '') throw new \DomainException('Limite invalide.');
            }
            $candidate = (new CharacterBackground($entry['slug'], $entry['name']))->setDescription($entry['description']);
            $slug = $candidate->getSlug();
            if ($slug !== $entry['slug'] || isset($seen[$slug])) throw new \DomainException('Slug non canonique ou dupliqué.');
            $seen[$slug] = true;
            $existing = $backgrounds[$slug] ?? null;
            if ($existing !== null && ($existing->getOrigin() !== ReferenceOrigin::Official || $existing->getOwner() !== null)) {
                throw new \DomainException('Historique personnalisé protégé : '.$slug);
            }
            $different = $existing !== null && ($existing->getName() !== $candidate->getName() || $existing->getDescription() !== $candidate->getDescription());
            $plan['backgrounds'][] = ['action' => $this->action($existing, $different, $update), 'candidate' => $candidate, 'existing' => $existing];
            $source = $existing ?? $candidate;
            foreach ($entry['rules'] as $data) {
                if (!is_array($data) || !is_string($data['key'] ?? null) || !is_string($data['category'] ?? null)
                    || !in_array($data['category'], ['skills', 'tools-automatic', 'tools-choice'], true)
                    || !is_string($data['label'] ?? null) || trim($data['label']) === '' || mb_strlen($data['label']) > 120
                    || ($data['acquisitionMode'] ?? null) !== 'ANY' || ($data['unlockLevel'] ?? null) !== 1
                    || !is_int($data['choiceCount'] ?? null) || $data['choiceCount'] < 0
                    || !is_array($data['options'] ?? null) || !array_is_list($data['options']) || $data['options'] === []) {
                    throw new \DomainException('Règle d’historique invalide : '.$slug);
                }
                $key = 'phb-2014-background-'.$slug.'-'.$data['category'];
                if ($data['key'] !== $key || isset($keys[$key])
                    || ($data['category'] === 'tools-choice' ? $data['choiceCount'] < 1 : $data['choiceCount'] !== 0)
                    || ($data['category'] === 'skills' && count($data['options']) !== 2)) {
                    throw new \DomainException('Clé ou choix incohérent : '.$key);
                }
                $keys[$key] = true;
                $rule = (new ProficiencyGrantRule($data['label']))->setImportKey($key)->setCharacterBackground($source)
                    ->setChoiceCount($data['choiceCount']);
                $options = [];
                foreach ($data['options'] as $option) {
                    if (!is_string($option) || isset($options[$option])) throw new \DomainException('Option invalide ou dupliquée.');
                    $definition = $definitions[$option] ?? null;
                    if (!$definition instanceof ProficiencyDefinition || $definition->getOrigin() !== ReferenceOrigin::Official
                        || $definition->getOwner() !== null || $definition->getType()->value !== ($data['category'] === 'skills' ? 'SKILL' : 'TOOL')) {
                        throw new \DomainException('Maîtrise officielle inconnue, protégée ou de type incorrect : '.$option);
                    }
                    $options[$option] = true;
                    $rule->addOption($definition);
                }
                $rule->validateRule();
                $old = $byKey[$key] ?? null;
                if ($old !== null) {
                    $old->validateRule();
                    if ($old->getOrigin() !== ReferenceOrigin::Official || $old->getOwner() !== null
                        || $old->getCharacterBackground() !== $source || $old->getAcquisitionMode() !== ProficiencyAcquisitionMode::Any) {
                        throw new \DomainException('Règle protégée ou identité incompatible : '.$key);
                    }
                }
                foreach ($existingRules as $other) {
                    if ($other === $old || $other->getCharacterBackground() !== $source) continue;
                    if ($other->getLabel() === $rule->getLabel() || $this->mechanics($other) === $this->mechanics($rule)) {
                        throw new \DomainException('Règle préexistante ambiguë : '.$key);
                    }
                }
                $changed = $old !== null && $this->mechanics($old) !== $this->mechanics($rule);
                if ($update && $changed && in_array($old->getId(), $usedRuleIds, true)) {
                    throw new \DomainException('Modification mécanique refusée : des choix utilisent '.$key);
                }
                $different = $old !== null && ($changed || $old->getLabel() !== $rule->getLabel());
                $plan['rules'][] = ['action' => $this->action($old, $different, $update), 'candidate' => $rule, 'existing' => $old];
            }
            if (!isset($keys['phb-2014-background-'.$slug.'-skills'])) throw new \DomainException('Deux compétences sont requises : '.$slug);
        }

        return $plan;
    }

    private function action(?object $existing, bool $different, bool $update): string
    {
        return $existing === null ? 'create' : (!$different ? 'unchanged' : ($update ? 'update' : 'keep'));
    }

    private function mechanics(ProficiencyGrantRule $rule): array
    {
        $options = array_map(static fn (ProficiencyDefinition $definition): string => $definition->getSlug(), $rule->getOptions()->toArray());
        sort($options);

        return [$rule->getUnlockLevel(), $rule->getChoiceCount(), $options];
    }

    public function applyBackgroundUpdate(CharacterBackground $existing, CharacterBackground $candidate): void
    {
        $existing->setName($candidate->getName())->setDescription($candidate->getDescription());
    }

    public function applyRuleUpdate(ProficiencyGrantRule $existing, ProficiencyGrantRule $candidate): void
    {
        $existing->setLabel($candidate->getLabel())->setUnlockLevel($candidate->getUnlockLevel())->setChoiceCount($candidate->getChoiceCount());
        foreach ($existing->getOptions()->toArray() as $option) {
            if (!$candidate->getOptions()->contains($option)) $existing->removeOption($option);
        }
        foreach ($candidate->getOptions() as $option) $existing->addOption($option);
        $existing->validateRule();
    }
}
