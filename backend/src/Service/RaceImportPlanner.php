<?php

declare(strict_types=1);

namespace App\Service;

use stdClass;

final class RaceImportPlanner
{
    /** @return list<stdClass> */
    public function parentsFirst(array $entries): array
    {
        $bySlug = [];
        foreach ($entries as $entry) $bySlug[$entry->slug] = $entry;
        $ordered = [];
        $visiting = [];
        $visit = function (stdClass $entry) use (&$visit, &$ordered, &$visiting, $bySlug): void {
            if (isset($ordered[$entry->slug])) return;
            if (isset($visiting[$entry->slug])) throw new \RuntimeException("Cycle racial autour de $entry->slug.");
            $visiting[$entry->slug] = true;
            if (isset($entry->parentSlug)) $visit($bySlug[$entry->parentSlug]);
            unset($visiting[$entry->slug]);
            $ordered[$entry->slug] = $entry;
        };
        foreach ($entries as $entry) $visit($entry);

        return array_values($ordered);
    }

    /** @param array<string, array<string, mixed>> $databaseBySlug */
    public function match(stdClass $entry, array $databaseBySlug): array
    {
        $exact = $databaseBySlug[$entry->slug] ?? null;
        $legacy = [];
        foreach ($entry->legacySlugs as $slug) if (isset($databaseBySlug[$slug])) $legacy[] = $databaseBySlug[$slug];
        if ($exact !== null && $legacy !== []) return ['status' => 'conflict', 'row' => null, 'legacySlug' => null];
        if (count($legacy) > 1) return ['status' => 'conflict', 'row' => null, 'legacySlug' => null];
        if ($exact !== null) return ['status' => 'exact', 'row' => $exact, 'legacySlug' => null];
        if ($legacy !== []) return ['status' => 'legacy', 'row' => $legacy[0], 'legacySlug' => $legacy[0]['slug']];

        return ['status' => 'new', 'row' => null, 'legacySlug' => null];
    }

    /** @return array<string, string> */
    public function preservedLocalStrategies(array $reconciliations): array
    {
        $result = [];
        foreach ($reconciliations as $item) {
            $result[$item->localSlug] = match ($item->status) {
                'structural-parent' => 'KEEP_STRUCTURAL',
                'compatibility-required' => 'KEEP_COMPATIBILITY',
                'version-ambiguous' => 'UNRESOLVED',
                default => 'KEEP_UPDATE',
            };
        }

        return $result;
    }

    public function outOfScopeAction(?array $row, bool $protected, bool $update): string
    {
        if ($row === null) return 'absent';
        if ($protected || filter_var($row['custom'], FILTER_VALIDATE_BOOL)) return 'protected';
        if (!filter_var($row['selectable'], FILTER_VALIDATE_BOOL)) return 'already-disabled';
        return $update ? 'disable' : 'kept';
    }

    public function abilityModifierRepresentable(stdClass $modifier): bool
    {
        if (($modifier->kind ?? null) === 'fixed') return isset($modifier->ability) && is_int($modifier->amount ?? null);
        return ($modifier->kind ?? null) === 'choice' && isset($modifier->choiceKey) && ($modifier->choiceCount ?? null) === 1
            && ($modifier->distinct ?? null) === true && count($modifier->abilities ?? []) === 6 && is_int($modifier->amount ?? null);
    }

    public function traitIsDescriptiveOnly(stdClass $trait): bool
    {
        return ($trait->representation ?? null) === 'descriptive' || ($trait->reviewStatus ?? null) === 'runtime-unsupported';
    }

    public function featureSlug(string $raceSlug, string $traitSlug): string
    {
        return 'racial-'.$raceSlug.'-'.$traitSlug;
    }

    public function staleTraitRuleAction(bool $managedCanonical, bool $custom, bool $update): string { if (!$managedCanonical || $custom) return 'preserve'; return $update ? 'remove' : 'kept'; }

    public function modifierAction(int $existingValue, int $catalogueValue, bool $referenced, bool $update): string
    {
        if ($existingValue === $catalogueValue) return 'unchanged';
        if ($referenced) return 'conflict';
        return $update ? 'update' : 'kept';
    }

    public function preservedModifierAction(bool $referenced): string
    {
        return $referenced ? 'PRESERVE_REFERENCED' : 'PRESERVE_LOCAL';
    }

    public function featChoiceAction(int $existing, int $catalogue, int $dependentFeats, bool $update): string
    {
        if ($existing === $catalogue) return 'unchanged';
        if ($existing > $catalogue && $dependentFeats > $catalogue) return 'conflict';
        return $update ? 'update' : 'kept';
    }
}
