<?php

declare(strict_types=1);

namespace App\Service;

use stdClass;

final class ClassFeatureCatalogueValidator
{
    private const SLUG_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/D';
    private const REVIEW_STATUSES = ['approved', 'pending'];
    private const SOURCE_COVERAGE = ['detailed', 'index-only'];
    private const SPELLCASTING = ['none', 'full', 'half', 'artificer', 'third', 'pact'];
    private const ACTIVATIONS = ['passive', 'action', 'bonus_action', 'reaction', 'free_action', 'special'];
    private const RECHARGES = ['none', 'short-rest', 'long-rest'];
    private const MAXIMUM_TYPES = ['fixed', 'proficiency-bonus', 'ability-modifier'];
    private const ABILITIES = ['strength', 'dexterity', 'constitution', 'intelligence', 'wisdom', 'charisma'];
    private const HANDLERS = ['aid', 'heroes-feast', 'flexible-casting', 'arcane-recovery'];

    /** @return list<string> */
    public function validate(mixed $catalogue): array
    {
        if (!$catalogue instanceof stdClass) {
            return ['Catalogue must be a JSON object.'];
        }

        $errors = [];
        $this->exactKeys($catalogue, [
            'schemaVersion', 'catalogue', 'edition', 'sourceSnapshot', 'classes', 'subclasses',
            'features', 'featureRules', 'resources', 'resourceRules', 'controlledActions',
            'actionClassRules', 'sourceGaps',
        ], 'catalogue', $errors);

        if (($catalogue->schemaVersion ?? null) !== 1
            || ($catalogue->catalogue ?? null) !== 'dnd-2014-class-features'
            || ($catalogue->edition ?? null) !== '2014') {
            $errors[] = 'Expected schemaVersion=1, catalogue=dnd-2014-class-features and edition=2014.';
        }

        foreach (['classes', 'subclasses', 'features', 'featureRules', 'resources', 'resourceRules', 'controlledActions', 'actionClassRules', 'sourceGaps'] as $field) {
            if (!isset($catalogue->$field) || !is_array($catalogue->$field)) {
                $errors[] = "$field must be an array.";
            }
        }
        if ($errors !== []) {
            return $errors;
        }

        $classSlugs = $this->validateClasses($catalogue->classes, $errors);
        $subclassKeys = $this->validateSubclasses($catalogue->subclasses, $classSlugs, $errors);
        $featureSlugs = $this->validateFeatures($catalogue->features, $errors);
        $resourceSlugs = $this->validateResources($catalogue->resources, $errors);
        $actionSlugs = $this->validateActions($catalogue->controlledActions, $errors);

        $this->validateFeatureRules($catalogue->featureRules, $featureSlugs, $classSlugs, $subclassKeys, $errors);
        $this->validateResourceRules($catalogue->resourceRules, $resourceSlugs, $classSlugs, $subclassKeys, $errors);
        $this->validateActionRules($catalogue->actionClassRules, $actionSlugs, $classSlugs, $errors);

        foreach ($catalogue->features as $index => $feature) {
            if ($feature instanceof stdClass && $feature->resourceSlug !== null && !isset($resourceSlugs[$feature->resourceSlug])) {
                $errors[] = "features[$index].resourceSlug references an unknown resource.";
            }
        }

        return $errors;
    }

    private function validateClasses(array $entries, array &$errors): array
    {
        $seen = [];
        foreach ($entries as $i => $entry) {
            $label = "classes[$i]";
            if (!$this->entry($entry, ['slug', 'name', 'description', 'hitDie', 'subclassSelectionLevel', 'spellcastingProgression', 'custom', 'englishName', 'sourceBook', 'sourceUrl', 'sourceCoverage', 'reviewStatus', 'reviewNotes'], $label, $errors)) continue;
            $this->slug($entry->slug, $label, $seen, $errors);
            if (!in_array($entry->hitDie, [6, 8, 10, 12], true)) $errors[] = "$label.hitDie is invalid.";
            $this->level($entry->subclassSelectionLevel, "$label.subclassSelectionLevel", $errors);
            if (!in_array($entry->spellcastingProgression, self::SPELLCASTING, true)) $errors[] = "$label.spellcastingProgression is invalid.";
            $this->metadata($entry, $label, $errors);
        }
        return $seen;
    }

    private function validateSubclasses(array $entries, array $classes, array &$errors): array
    {
        $seen = [];
        foreach ($entries as $i => $entry) {
            $label = "subclasses[$i]";
            if (!$this->entry($entry, ['classSlug', 'slug', 'name', 'description', 'spellcastingProgression', 'custom', 'englishName', 'sourceBook', 'sourceUrl', 'sourceCoverage', 'reviewStatus', 'reviewNotes'], $label, $errors)) continue;
            $local = [];
            $this->slug($entry->slug, "$label.slug", $local, $errors);
            if (!isset($classes[$entry->classSlug])) $errors[] = "$label.classSlug references an unknown class.";
            $key = $entry->classSlug . ':' . $entry->slug;
            if (isset($seen[$key])) $errors[] = "$label duplicates $key.";
            $seen[$key] = true;
            if ($entry->spellcastingProgression !== null && !in_array($entry->spellcastingProgression, self::SPELLCASTING, true)) $errors[] = "$label.spellcastingProgression is invalid.";
            $this->metadata($entry, $label, $errors);
        }
        return $seen;
    }

    private function validateFeatures(array $entries, array &$errors): array
    {
        $seen = [];
        foreach ($entries as $i => $entry) {
            $label = "features[$i]";
            if (!$this->entry($entry, ['slug', 'legacySlugs', 'name', 'description', 'activationType', 'resourceSlug', 'visible', 'custom', 'sourceUrl', 'reviewStatus', 'reviewNotes'], $label, $errors)) continue;
            $this->slug($entry->slug, $label, $seen, $errors);
            if (!in_array($entry->activationType, self::ACTIVATIONS, true)) $errors[] = "$label.activationType is invalid.";
            if (!is_array($entry->legacySlugs)) $errors[] = "$label.legacySlugs must be an array.";
            $this->review($entry, $label, $errors);
        }
        return $seen;
    }

    private function validateResources(array $entries, array &$errors): array
    {
        $seen = [];
        foreach ($entries as $i => $entry) {
            $label = "resources[$i]";
            if (!$this->entry($entry, ['slug', 'legacySlugs', 'name', 'description', 'rechargeType', 'maximumType', 'baseMaximum', 'multiplier', 'minimumMaximum', 'scalingAbility', 'custom', 'reviewStatus', 'reviewNotes'], $label, $errors)) continue;
            $this->slug($entry->slug, $label, $seen, $errors);
            if (!in_array($entry->rechargeType, self::RECHARGES, true)) $errors[] = "$label.rechargeType is invalid.";
            if (!in_array($entry->maximumType, self::MAXIMUM_TYPES, true)) $errors[] = "$label.maximumType is invalid.";
            if (!is_int($entry->baseMaximum) || $entry->baseMaximum < 0 || !is_int($entry->multiplier) || $entry->multiplier < 1 || !is_int($entry->minimumMaximum) || $entry->minimumMaximum < 0) $errors[] = "$label has invalid maximum values.";
            if ($entry->maximumType === 'ability-modifier' && !in_array($entry->scalingAbility, self::ABILITIES, true)) $errors[] = "$label requires scalingAbility.";
            if ($entry->maximumType !== 'ability-modifier' && $entry->scalingAbility !== null) $errors[] = "$label cannot have scalingAbility.";
            $this->review($entry, $label, $errors);
        }
        return $seen;
    }

    private function validateActions(array $entries, array &$errors): array
    {
        $seen = [];
        foreach ($entries as $i => $entry) {
            $label = "controlledActions[$i]";
            if (!$this->entry($entry, ['slug', 'name', 'description', 'handlerType', 'requiresPreparation', 'active', 'custom', 'reviewStatus', 'reviewNotes'], $label, $errors)) continue;
            $this->slug($entry->slug, $label, $seen, $errors);
            if (!in_array($entry->handlerType, self::HANDLERS, true)) $errors[] = "$label.handlerType has no existing handler.";
            $this->review($entry, $label, $errors);
        }
        return $seen;
    }

    private function validateFeatureRules(array $entries, array $features, array $classes, array $subclasses, array &$errors): void
    {
        $seen = [];
        foreach ($entries as $i => $entry) {
            $label = "featureRules[$i]";
            if (!$this->entry($entry, ['featureSlug', 'sourceType', 'classSlug', 'subclassSlug', 'unlockLevel', 'displayOrder', 'reviewStatus', 'reviewNotes'], $label, $errors)) continue;
            $this->sourceRule($entry, $label, $classes, $subclasses, $errors);
            if (!isset($features[$entry->featureSlug])) $errors[] = "$label.featureSlug references an unknown feature.";
            $this->level($entry->unlockLevel, "$label.unlockLevel", $errors);
            if (!is_int($entry->displayOrder) || $entry->displayOrder < 0) $errors[] = "$label.displayOrder is invalid.";
            $this->uniqueRule($entry->sourceType . ':' . $entry->classSlug . ':' . ($entry->subclassSlug ?? '') . ':' . $entry->featureSlug . ':' . $entry->unlockLevel, $label, $seen, $errors);
            $this->review($entry, $label, $errors);
        }
    }

    private function validateResourceRules(array $entries, array $resources, array $classes, array $subclasses, array &$errors): void
    {
        $seen = [];
        foreach ($entries as $i => $entry) {
            $label = "resourceRules[$i]";
            if (!$this->entry($entry, ['resourceSlug', 'sourceType', 'classSlug', 'subclassSlug', 'unlockLevel', 'maximumOverride', 'maximumBonus', 'reviewStatus', 'reviewNotes'], $label, $errors)) continue;
            $this->sourceRule($entry, $label, $classes, $subclasses, $errors);
            if (!isset($resources[$entry->resourceSlug])) $errors[] = "$label.resourceSlug references an unknown resource.";
            $this->level($entry->unlockLevel, "$label.unlockLevel", $errors);
            if ($entry->maximumOverride !== null && (!is_int($entry->maximumOverride) || $entry->maximumOverride < 0)) $errors[] = "$label.maximumOverride is invalid.";
            if (!is_int($entry->maximumBonus) || $entry->maximumBonus < 0) $errors[] = "$label.maximumBonus is invalid.";
            $this->uniqueRule($entry->sourceType . ':' . $entry->classSlug . ':' . ($entry->subclassSlug ?? '') . ':' . $entry->resourceSlug . ':' . $entry->unlockLevel, $label, $seen, $errors);
            $this->review($entry, $label, $errors);
        }
    }

    private function validateActionRules(array $entries, array $actions, array $classes, array &$errors): void
    {
        $seen = [];
        foreach ($entries as $i => $entry) {
            $label = "actionClassRules[$i]";
            if (!$this->entry($entry, ['actionSlug', 'classSlug', 'unlockLevel', 'reviewStatus', 'reviewNotes'], $label, $errors)) continue;
            if (!isset($actions[$entry->actionSlug]) || !isset($classes[$entry->classSlug])) $errors[] = "$label has an unknown reference.";
            $this->level($entry->unlockLevel, "$label.unlockLevel", $errors);
            $this->uniqueRule($entry->actionSlug . ':' . $entry->classSlug . ':' . $entry->unlockLevel, $label, $seen, $errors);
            $this->review($entry, $label, $errors);
        }
    }

    private function sourceRule(stdClass $entry, string $label, array $classes, array $subclasses, array &$errors): void
    {
        if (!in_array($entry->sourceType, ['class', 'subclass'], true)) $errors[] = "$label.sourceType is invalid.";
        if (!isset($classes[$entry->classSlug])) $errors[] = "$label.classSlug references an unknown class.";
        if ($entry->sourceType === 'class' && $entry->subclassSlug !== null) $errors[] = "$label class rule cannot reference a subclass.";
        if ($entry->sourceType === 'subclass' && !isset($subclasses[$entry->classSlug . ':' . $entry->subclassSlug])) $errors[] = "$label references an unknown subclass.";
    }

    private function metadata(stdClass $entry, string $label, array &$errors): void
    {
        if (!in_array($entry->sourceCoverage, self::SOURCE_COVERAGE, true)) $errors[] = "$label.sourceCoverage is invalid.";
        $this->review($entry, $label, $errors);
    }

    private function review(stdClass $entry, string $label, array &$errors): void
    {
        if (!in_array($entry->reviewStatus, self::REVIEW_STATUSES, true)) $errors[] = "$label.reviewStatus is invalid.";
        if (!is_array($entry->reviewNotes) || ($entry->reviewStatus === 'pending' && $entry->reviewNotes === [])) $errors[] = "$label.reviewNotes is invalid.";
    }

    private function entry(mixed $entry, array $keys, string $label, array &$errors): bool
    {
        if (!$entry instanceof stdClass) { $errors[] = "$label must be an object."; return false; }
        $this->exactKeys($entry, $keys, $label, $errors);
        return true;
    }

    private function exactKeys(stdClass $object, array $expected, string $label, array &$errors): void
    {
        $actual = array_keys(get_object_vars($object)); sort($actual); sort($expected);
        if ($actual !== $expected) $errors[] = "$label has missing or unexpected properties.";
    }

    private function slug(mixed $slug, string $label, array &$seen, array &$errors): void
    {
        if (!is_string($slug) || strlen($slug) > 100 || preg_match(self::SLUG_PATTERN, $slug) !== 1) { $errors[] = "$label has an invalid slug."; return; }
        if (isset($seen[$slug])) $errors[] = "$label duplicates slug $slug.";
        $seen[$slug] = true;
    }

    private function level(mixed $level, string $label, array &$errors): void
    {
        if (!is_int($level) || $level < 1 || $level > 20) $errors[] = "$label must be between 1 and 20.";
    }

    private function uniqueRule(string $key, string $label, array &$seen, array &$errors): void
    {
        if (isset($seen[$key])) $errors[] = "$label duplicates rule $key.";
        $seen[$key] = true;
    }
}
