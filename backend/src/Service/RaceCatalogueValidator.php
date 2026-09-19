<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\Ability;
use App\Enum\ConditionType;
use App\Enum\CreatureSize;
use App\Enum\DamageType;
use stdClass;

final class RaceCatalogueValidator
{
    private const SLUG_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/D';
    private const TYPES = ['race', 'subrace', 'variant', 'lineage'];
    private const STATUSES = ['include', 'alternative-version', 'superseded'];
    private const INHERITANCE_MODES = ['independent', 'additive', 'replacement'];
    private const REVIEW_STATUSES = ['approved', 'pending-editorial', 'pending-mechanical', 'runtime-unsupported'];
    private const REPRESENTATIONS = ['full', 'partial', 'descriptive'];
    private const TRAIT_CATEGORIES = ['descriptive-feature', 'ability-modifier', 'metadata', 'resource', 'choice', 'spell-grant', 'proficiency', 'other'];
    private const CHOICE_KINDS = ['ability', 'language', 'proficiency', 'spell', 'trait', 'feat', 'other'];
    private const MOVEMENT_KEYS = ['swim', 'fly', 'climb'];
    private const SENSE_KEYS = ['darkvision', 'blindsight', 'tremorsense', 'truesight'];

    /** @return list<string> */
    public function validate(mixed $catalogue, mixed $manifest): array
    {
        $errors = [];
        if (!$catalogue instanceof stdClass || ($catalogue->schemaVersion ?? null) !== 1 || ($catalogue->catalogue ?? null) !== 'dnd-2014-races' || ($catalogue->rulesFamily ?? null) !== 'dnd-5e-2014' || !is_array($catalogue->entries ?? null) || !is_array($catalogue->localReconciliation ?? null)) {
            return ['Invalid racial catalogue envelope.'];
        }
        if (!$manifest instanceof stdClass || ($manifest->schemaVersion ?? null) !== 1 || ($manifest->rulesFamily ?? null) !== 'dnd-5e-2014' || !is_array($manifest->publications ?? null) || !is_array($manifest->expectedEntrySlugs ?? null) || !is_array($manifest->outOfScopeSlugs ?? null) || !$manifest->expectedCounts instanceof stdClass) {
            return ['Invalid racial coverage manifest envelope.'];
        }

        $publications = $this->validateManifest($manifest, $errors);
        $entries = [];
        $legacySlugs = [];
        foreach ($catalogue->entries as $index => $entry) {
            $label = "entries[$index]";
            if (!$entry instanceof stdClass) {
                $errors[] = "$label must be an object.";
                continue;
            }
            $this->validateEntry($entry, $label, $publications, $entries, $legacySlugs, $errors);
        }

        $this->validateRelations($catalogue->entries, $entries, $errors);
        $this->validateLocalReconciliation($catalogue->localReconciliation, $entries, $legacySlugs, $errors);
        $this->validateCoverage($catalogue->entries, $manifest, $errors);
        $this->validateVariantHuman($entries, $errors);
        $this->validateReproducibleAbilityChoices($entries, $errors);

        return $errors;
    }

    /** @return array<string, stdClass> */
    private function validateManifest(stdClass $manifest, array &$errors): array
    {
        $publications = [];
        foreach ($manifest->publications as $index => $publication) {
            $label = "manifest.publications[$index]";
            if (!$publication instanceof stdClass || !$this->nonEmpty($publication->code ?? null) || !$this->nonEmpty($publication->name ?? null) || !is_int($publication->year ?? null) || $publication->year < 2014 || $publication->year > 2023 || ($publication->status ?? null) !== 'included' || !is_int($publication->expectedDatabaseEntryCount ?? null) || $publication->expectedDatabaseEntryCount < 1 || !is_int($publication->expectedPlayableOptionCount ?? null) || $publication->expectedPlayableOptionCount < 0 || $publication->expectedPlayableOptionCount > $publication->expectedDatabaseEntryCount) {
                $errors[] = "$label is invalid.";
                continue;
            }
            if (isset($publications[$publication->code])) {
                $errors[] = "$label duplicates publication {$publication->code}.";
            }
            $publications[$publication->code] = $publication;
        }

        $seen = [];
        foreach ($manifest->expectedEntrySlugs as $index => $slug) {
            if (!$this->validSlug($slug)) {
                $errors[] = "manifest.expectedEntrySlugs[$index] is invalid.";
            } elseif (isset($seen[$slug])) {
                $errors[] = "manifest.expectedEntrySlugs duplicates $slug.";
            }
            $seen[$slug] = true;
        }
        $excluded = [];
        foreach ($manifest->outOfScopeSlugs as $index => $slug) {
            if (!$this->validSlug($slug)) $errors[] = "manifest.outOfScopeSlugs[$index] is invalid.";
            elseif (isset($excluded[$slug])) $errors[] = "manifest.outOfScopeSlugs duplicates $slug.";
            elseif (isset($seen[$slug])) $errors[] = "manifest.outOfScopeSlugs collides with active slug $slug.";
            $excluded[$slug] = true;
        }
        if (count($manifest->expectedEntrySlugs) !== 63 || count($manifest->outOfScopeSlugs) !== 87) $errors[] = 'Manifest must declare exactly 63 active and 87 out-of-scope races.';

        return $publications;
    }

    private function validateEntry(stdClass $entry, string $label, array $publications, array &$entries, array &$legacySlugs, array &$errors): void
    {
        foreach (['slug', 'englishName', 'name', 'type', 'selectable', 'featChoiceCount', 'sourceBook', 'sourceCode', 'versionFamily', 'version', 'status', 'inheritanceMode', 'abilityModifiers', 'metadata', 'traits', 'choices', 'replacedTraitSlugs', 'metadataByLevel', 'functionalLimitations', 'legacySlugs', 'reviewStatus', 'reviewNotes'] as $field) {
            if (!property_exists($entry, $field)) {
                $errors[] = "$label.$field is required.";
            }
        }
        if (!$this->validSlug($entry->slug ?? null)) {
            $errors[] = "$label.slug is invalid.";
        } elseif (isset($entries[$entry->slug])) {
            $errors[] = "$label duplicates slug {$entry->slug}.";
        } elseif (isset($legacySlugs[$entry->slug])) {
            $errors[] = "$label.slug is already used as an ambiguous slug by {$legacySlugs[$entry->slug]}.";
        } else {
            $entries[$entry->slug] = $entry;
        }
        foreach (['englishName', 'name', 'sourceBook', 'sourceCode', 'versionFamily', 'version'] as $field) {
            if (!$this->nonEmpty($entry->$field ?? null)) $errors[] = "$label.$field is invalid.";
        }
        if (!isset($publications[$entry->sourceCode ?? '']) || (isset($publications[$entry->sourceCode]) && $publications[$entry->sourceCode]->name !== $entry->sourceBook)) $errors[] = "$label references an unknown or inconsistent publication.";
        if (!in_array($entry->type ?? null, self::TYPES, true)) $errors[] = "$label.type is invalid.";
        if (!is_bool($entry->selectable ?? null)) $errors[] = "$label.selectable must be boolean.";
        if (!is_int($entry->featChoiceCount ?? null) || $entry->featChoiceCount < 0) $errors[] = "$label.featChoiceCount is invalid.";
        if (($entry->selectable ?? true) === false && !$this->nonEmpty($entry->structuralReason ?? null)) $errors[] = "$label selectable=false requires structuralReason.";
        if (!in_array($entry->status ?? null, self::STATUSES, true)) $errors[] = "$label.status is invalid.";
        if (!in_array($entry->inheritanceMode ?? null, self::INHERITANCE_MODES, true)) $errors[] = "$label.inheritanceMode is invalid.";
        $this->review($entry, $label, $errors);
        $this->validateMetadata($entry->metadata ?? null, "$label.metadata", $errors);
        $this->validateAbilityModifiers($entry->abilityModifiers ?? null, "$label.abilityModifiers", $errors);
        if (($entry->slug ?? null) === 'human-phb') {
            $fixed = [];
            foreach ($entry->abilityModifiers ?? [] as $modifier) if (($modifier->kind ?? null) === 'fixed' && ($modifier->amount ?? null) === 1) $fixed[] = $modifier->ability ?? null;
            sort($fixed);
            $expected = array_column(Ability::cases(), 'value'); sort($expected);
            if ($fixed !== $expected || count($entry->abilityModifiers ?? []) !== 6) $errors[] = "$label human-phb must define exactly six fixed +1 ability modifiers.";
        }
        $this->validateTraits($entry->traits ?? null, "$label.traits", $errors);
        $this->validateChoices($entry->choices ?? null, "$label.choices", $errors);
        foreach (['replacedTraitSlugs', 'functionalLimitations', 'reviewNotes'] as $field) $this->stringList($entry->$field ?? null, "$label.$field", $errors, $field === 'replacedTraitSlugs');
        if (($entry->reviewStatus ?? null) === 'approved' && is_array($entry->functionalLimitations ?? null) && $this->containsMechanicalUncertainty($entry->functionalLimitations)) $errors[] = "$label cannot be mechanically approved while a limitation reports mechanical uncertainty.";
        $this->validateMetadataByLevel($entry->metadataByLevel ?? null, "$label.metadataByLevel", $errors);
        $this->stringList($entry->legacySlugs ?? null, "$label.legacySlugs", $errors, true);
        foreach ($entry->legacySlugs ?? [] as $legacySlug) {
            if (isset($legacySlugs[$legacySlug]) || isset($entries[$legacySlug])) $errors[] = "$label.legacySlugs contains ambiguous slug $legacySlug.";
            $legacySlugs[$legacySlug] = $entry->slug ?? $label;
        }
    }

    private function validateMetadata(mixed $metadata, string $label, array &$errors): void
    {
        if (!$metadata instanceof stdClass) {
            $errors[] = "$label must be an object.";
            return;
        }
        $allowed = ['sizeOptions', 'walkingSpeed', 'movementSpeeds', 'languages', 'languageChoiceCount', 'senses', 'damageResistances', 'damageImmunities', 'conditionImmunities'];
        foreach (array_keys(get_object_vars($metadata)) as $key) if (!in_array($key, $allowed, true)) $errors[] = "$label.$key is unsupported.";
        if (isset($metadata->sizeOptions)) $this->enumList($metadata->sizeOptions, CreatureSize::class, "$label.sizeOptions", $errors);
        if (isset($metadata->walkingSpeed) && !$this->positiveNumber($metadata->walkingSpeed)) $errors[] = "$label.walkingSpeed must be positive.";
        if (isset($metadata->movementSpeeds)) $this->nullableNumberMap($metadata->movementSpeeds, self::MOVEMENT_KEYS, "$label.movementSpeeds", $errors);
        if (isset($metadata->languages)) $this->slugList($metadata->languages, "$label.languages", $errors);
        if (isset($metadata->languageChoiceCount) && (!is_int($metadata->languageChoiceCount) || $metadata->languageChoiceCount < 0)) $errors[] = "$label.languageChoiceCount is invalid.";
        if (isset($metadata->senses)) $this->nullableNumberMap($metadata->senses, self::SENSE_KEYS, "$label.senses", $errors);
        if (isset($metadata->damageResistances)) $this->enumList($metadata->damageResistances, DamageType::class, "$label.damageResistances", $errors);
        if (isset($metadata->damageImmunities)) $this->enumList($metadata->damageImmunities, DamageType::class, "$label.damageImmunities", $errors);
        if (isset($metadata->conditionImmunities)) $this->enumList($metadata->conditionImmunities, ConditionType::class, "$label.conditionImmunities", $errors);
    }

    private function validateAbilityModifiers(mixed $modifiers, string $label, array &$errors): void
    {
        if (!is_array($modifiers)) { $errors[] = "$label must be an array."; return; }
        $choiceKeys = [];
        foreach ($modifiers as $index => $modifier) {
            $item = "{$label}[$index]";
            if (!$modifier instanceof stdClass || !in_array($modifier->kind ?? null, ['fixed', 'choice'], true) || !is_int($modifier->amount ?? null) || $modifier->amount < 1) { $errors[] = "$item is malformed."; continue; }
            if ($modifier->kind === 'fixed' && (!in_array($modifier->ability ?? null, array_column(Ability::cases(), 'value'), true) || isset($modifier->choiceKey))) $errors[] = "$item.ability is invalid.";
            if ($modifier->kind === 'choice') {
                $this->enumList($modifier->abilities ?? null, Ability::class, "$item.abilities", $errors);
                if (!is_int($modifier->choiceCount ?? null) || $modifier->choiceCount < 1 || !is_bool($modifier->distinct ?? null)) $errors[] = "$item choice constraints are invalid.";
                if (property_exists($modifier, 'choiceKey')) {
                    if (!$this->validSlug($modifier->choiceKey)) $errors[] = "$item.choiceKey is invalid or duplicated.";
                    elseif (isset($choiceKeys[$modifier->choiceKey])) $errors[] = "$item.choiceKey is invalid or duplicated.";
                    else $choiceKeys[$modifier->choiceKey] = true;
                    if (($modifier->choiceCount ?? null) !== 1 || ($modifier->distinct ?? null) !== true || !is_array($modifier->abilities ?? null) || count($modifier->abilities) !== 6) $errors[] = "$item keyed choice cannot be represented by the current builder.";
                }
            }
        }
    }

    private function validateTraits(mixed $traits, string $label, array &$errors): void
    {
        if (!is_array($traits)) { $errors[] = "$label must be an array."; return; }
        $seen = [];
        foreach ($traits as $index => $trait) {
            $item = "{$label}[$index]";
            if (!$trait instanceof stdClass || !$this->validSlug($trait->slug ?? null) || $trait->slug === 'racial-properties' || isset($seen[$trait->slug ?? '']) || !$this->nonEmpty($trait->englishName ?? null) || !$this->nonEmpty($trait->name ?? null) || !$this->nonEmpty($trait->summary ?? null) || !is_int($trait->unlockLevel ?? null) || $trait->unlockLevel < 1 || $trait->unlockLevel > 20 || !is_array($trait->categories ?? null) || !in_array($trait->representation ?? null, self::REPRESENTATIONS, true) || !is_bool($trait->descriptiveFallbackAllowed ?? null) || !in_array($trait->reviewStatus ?? null, self::REVIEW_STATUSES, true) || !is_array($trait->reviewNotes ?? null) || ($trait->reviewStatus !== 'approved' && $trait->reviewNotes === [])) {
                $errors[] = "$item is malformed.";
                continue;
            }
            $seen[$trait->slug] = true;
            foreach ($trait->categories as $category) if (!in_array($category, self::TRAIT_CATEGORIES, true)) $errors[] = "$item has invalid category.";
        }
    }

    private function validateMetadataByLevel(mixed $levels, string $label, array &$errors): void
    {
        if (!is_array($levels)) { $errors[] = "$label must be an array."; return; }
        $seen = [];
        foreach ($levels as $index => $level) {
            $item = "{$label}[$index]";
            if (!$level instanceof stdClass || !is_int($level->level ?? null) || $level->level < 1 || $level->level > 20 || isset($seen[$level->level ?? 0]) || !in_array($level->reviewStatus ?? null, self::REVIEW_STATUSES, true)) {
                $errors[] = "$item is malformed.";
                continue;
            }
            $seen[$level->level] = true;
            $this->validateMetadata($level->metadata ?? null, "$item.metadata", $errors);
        }
    }

    private function validateChoices(mixed $choices, string $label, array &$errors): void
    {
        if (!is_array($choices)) { $errors[] = "$label must be an array."; return; }
        $seen = [];
        foreach ($choices as $index => $choice) {
            $item = "{$label}[$index]";
            if (!$choice instanceof stdClass || !$this->validSlug($choice->slug ?? null) || isset($seen[$choice->slug ?? '']) || !$this->nonEmpty($choice->name ?? null) || !in_array($choice->kind ?? null, self::CHOICE_KINDS, true) || !is_int($choice->minimumSelections ?? null) || !is_int($choice->maximumSelections ?? null) || $choice->minimumSelections < 0 || $choice->maximumSelections < $choice->minimumSelections || !is_array($choice->options ?? null) || !in_array($choice->reviewStatus ?? null, self::REVIEW_STATUSES, true)) {
                $errors[] = "$item is malformed.";
                continue;
            }
            $seen[$choice->slug] = true;
        }
    }

    private function validateRelations(array $entries, array $bySlug, array &$errors): void
    {
        foreach ($entries as $index => $entry) {
            if (!$entry instanceof stdClass) continue;
            if (isset($entry->parentSlug) && !isset($bySlug[$entry->parentSlug])) $errors[] = "entries[$index].parentSlug references an unknown parent.";
            if (($entry->inheritanceMode ?? null) === 'additive' && !isset($entry->parentSlug)) $errors[] = "entries[$index] additive inheritance requires parentSlug.";
            foreach (['sameIdentityAs', 'replacesVersion'] as $field) if (isset($entry->$field) && !isset($bySlug[$entry->$field])) $errors[] = "entries[$index].$field references an unknown entry.";
        }
        foreach ($bySlug as $slug => $entry) {
            $visited = [];
            while (isset($entry->parentSlug)) {
                if (isset($visited[$entry->parentSlug]) || $entry->parentSlug === $slug) { $errors[] = "Parent cycle detected for $slug."; break; }
                $visited[$entry->parentSlug] = true;
                $entry = $bySlug[$entry->parentSlug] ?? (object) [];
            }
        }
    }

    private function validateCoverage(array $entries, stdClass $manifest, array &$errors): void
    {
        $actual = array_values(array_map(static fn (stdClass $entry): string => $entry->slug, array_filter($entries, static fn ($entry): bool => $entry instanceof stdClass && isset($entry->slug))));
        $expected = $manifest->expectedEntrySlugs;
        foreach (array_diff($expected, $actual) as $slug) $errors[] = "Manifest entry $slug is missing from catalogue.";
        foreach (array_diff($actual, $expected) as $slug) $errors[] = "Catalogue entry $slug is not declared by manifest.";
        foreach (array_intersect($actual, $manifest->outOfScopeSlugs) as $slug) $errors[] = "Catalogue entry $slug is out of scope.";
        $counts = ['databaseEntryCount' => count($actual), 'playableOptionCount' => 0, 'structuralParentCount' => 0, 'race' => 0, 'subrace' => 0, 'variant' => 0, 'lineage' => 0];
        $publicationCounts = [];
        $publicationPlayableCounts = [];
        foreach ($entries as $entry) if ($entry instanceof stdClass) {
            if (isset($counts[$entry->type ?? ''])) ++$counts[$entry->type];
            if ($entry->selectable ?? false) { ++$counts['playableOptionCount']; $publicationPlayableCounts[$entry->sourceCode] = ($publicationPlayableCounts[$entry->sourceCode] ?? 0) + 1; } else { ++$counts['structuralParentCount']; }
            $publicationCounts[$entry->sourceCode] = ($publicationCounts[$entry->sourceCode] ?? 0) + 1;
        }
        foreach ($counts as $field => $count) if (($manifest->expectedCounts->$field ?? null) !== $count) $errors[] = "Manifest expectedCounts.$field is inconsistent.";
        if ($counts['databaseEntryCount'] !== 63 || $counts['playableOptionCount'] !== 58 || $counts['structuralParentCount'] !== 5 || ($manifest->expectedCounts->outOfScopeCount ?? null) !== 87) $errors[] = 'Reduced racial scope must contain 58 playable races, 5 structural parents and 87 exclusions.';
        foreach ($manifest->publications as $publication) {
            if (($publicationCounts[$publication->code] ?? 0) !== $publication->expectedDatabaseEntryCount) $errors[] = "Manifest database count for {$publication->code} is inconsistent.";
            if (($publicationPlayableCounts[$publication->code] ?? 0) !== $publication->expectedPlayableOptionCount) $errors[] = "Manifest playable count for {$publication->code} is inconsistent.";
        }
    }

    private function validateVariantHuman(array $entries, array &$errors): void
    {
        $variant = $entries['human-variant-phb'] ?? null;
        $human = $entries['human-phb'] ?? null;
        if ($variant === null || $human === null) { $errors[] = 'Both PHB Human entries are required.'; return; }
        if (isset($variant->parentSlug) || $variant->inheritanceMode !== 'independent' || $variant->featChoiceCount !== 1) $errors[] = 'Variant Human must be independent and grant one feat choice.';
        $modifiers = $variant->abilityModifiers ?? [];
        if (count($modifiers) !== 1 || ($modifiers[0]->kind ?? null) !== 'choice' || ($modifiers[0]->amount ?? null) !== 1 || ($modifiers[0]->choiceCount ?? null) !== 2 || ($modifiers[0]->distinct ?? null) !== true) $errors[] = 'Variant Human must grant exactly two distinct +1 ability choices.';
        $metadata = $variant->metadata ?? (object) [];
        if (($metadata->sizeOptions ?? null) !== ['medium'] || ($metadata->walkingSpeed ?? null) !== 9 || ($metadata->languages ?? null) !== ['common'] || ($metadata->languageChoiceCount ?? null) !== 1) $errors[] = 'Variant Human must carry its own size, speed and language metadata.';
        $traits = array_column($variant->traits ?? [], 'slug');
        foreach ($human->traits ?? [] as $trait) if (!in_array($trait->slug, $traits, true)) $errors[] = "Variant Human is missing common Human trait {$trait->slug}.";
    }

    private function validateReproducibleAbilityChoices(array $entries, array &$errors): void
    {
        foreach (['aasimar-mpmm', 'eladrin-mpmm', 'goliath-mpmm', 'reborn-vrgr', 'shadar-kai-mpmm'] as $slug) {
            $modifiers = $entries[$slug]->abilityModifiers ?? [];
            $byKey = [];
            foreach ($modifiers as $modifier) if (is_string($modifier->choiceKey ?? null)) $byKey[$modifier->choiceKey] = $modifier;
            if (count($modifiers) !== 2 || count($byKey) !== 2 || ($byKey['primary']->amount ?? null) !== 2 || ($byKey['secondary']->amount ?? null) !== 1) $errors[] = "$slug must define primary +2 and secondary +1 ability choices.";
        }
    }

    private function validateLocalReconciliation(array $reconciliations, array $entries, array $legacySlugs, array &$errors): void
    {
        $statuses = ['canonical', 'canonical-proposed', 'structural-parent', 'version-ambiguous', 'compatibility-required'];
        $seen = [];
        foreach ($reconciliations as $index => $reconciliation) {
            $label = "localReconciliation[$index]";
            if (!$reconciliation instanceof stdClass || !$this->validSlug($reconciliation->localSlug ?? null) || isset($seen[$reconciliation->localSlug ?? '']) || !in_array($reconciliation->status ?? null, $statuses, true) || !$this->nonEmpty($reconciliation->notes ?? null)) {
                $errors[] = "$label is malformed.";
                continue;
            }
            $seen[$reconciliation->localSlug] = true;
            $ambiguous = in_array($reconciliation->status, ['version-ambiguous', 'structural-parent'], true);
            if ($reconciliation->catalogueSlug !== null && !isset($entries[$reconciliation->catalogueSlug])) $errors[] = "$label references an unknown catalogue entry.";
            if ($reconciliation->status === 'version-ambiguous' && $reconciliation->catalogueSlug !== null) $errors[] = "$label ambiguous reconciliation cannot select a catalogue entry.";
            if ($ambiguous && isset($legacySlugs[$reconciliation->localSlug])) $errors[] = "$label ambiguous local slug cannot also be a legacySlug.";
            if ($reconciliation->status === 'canonical' && ($reconciliation->catalogueSlug === null || ($legacySlugs[$reconciliation->localSlug] ?? null) !== $reconciliation->catalogueSlug)) $errors[] = "$label canonical reconciliation requires the matching legacySlug.";
        }
    }

    private function nullableNumberMap(mixed $map, array $keys, string $label, array &$errors): void
    {
        if (!$map instanceof stdClass) { $errors[] = "$label must be an object."; return; }
        foreach (get_object_vars($map) as $key => $value) if (!in_array($key, $keys, true) || ($value !== null && !$this->positiveNumber($value))) $errors[] = "$label.$key is invalid.";
    }

    private function enumList(mixed $values, string $enumClass, string $label, array &$errors): void
    {
        if (!is_array($values)) { $errors[] = "$label must be an array."; return; }
        $seen = [];
        foreach ($values as $value) {
            if (!is_string($value) || $enumClass::tryFrom($value) === null) $errors[] = "$label contains an invalid value.";
            if (isset($seen[$value])) $errors[] = "$label contains duplicate $value.";
            $seen[$value] = true;
        }
    }

    private function slugList(mixed $values, string $label, array &$errors): void
    {
        if (!is_array($values)) { $errors[] = "$label must be an array."; return; }
        $seen = [];
        foreach ($values as $value) { if (!$this->validSlug($value)) $errors[] = "$label contains an invalid slug."; if (isset($seen[$value])) $errors[] = "$label contains duplicate $value."; $seen[$value] = true; }
    }

    private function stringList(mixed $values, string $label, array &$errors, bool $slugs = false): void
    {
        if (!is_array($values)) { $errors[] = "$label must be an array."; return; }
        $seen = [];
        foreach ($values as $value) { if (!$this->nonEmpty($value) || ($slugs && !$this->validSlug($value))) $errors[] = "$label contains an invalid value."; if (isset($seen[$value])) $errors[] = "$label contains duplicate value."; $seen[$value] = true; }
    }

    private function review(stdClass $entry, string $label, array &$errors): void
    {
        if (!in_array($entry->reviewStatus ?? null, self::REVIEW_STATUSES, true) || !is_array($entry->reviewNotes ?? null) || (($entry->reviewStatus ?? null) !== 'approved' && $entry->reviewNotes === [])) $errors[] = "$label review status is invalid.";
    }

    private function containsMechanicalUncertainty(array $limitations): bool
    {
        foreach ($limitations as $limitation) if (is_string($limitation) && preg_match('/(?:mécani(?:que|quement)|source).*(?:incertain|à valider|non vérifi)|(?:incertain|à valider|non vérifi).*(?:mécani(?:que|quement)|source)/iu', $limitation) === 1) return true;

        return false;
    }

    private function positiveNumber(mixed $value): bool { return (is_int($value) || is_float($value)) && is_finite((float) $value) && $value > 0; }
    private function validSlug(mixed $value): bool { return is_string($value) && preg_match(self::SLUG_PATTERN, $value) === 1; }
    private function nonEmpty(mixed $value): bool { return is_string($value) && trim($value) !== ''; }
}
