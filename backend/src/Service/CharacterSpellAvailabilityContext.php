<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Character;
use App\Repository\TrackableResourceDefinitionRepository;

/** Read-only pool snapshot. Neither casting permission nor a reservation. */
final readonly class CharacterSpellAvailabilityContext
{
    public function __construct(
        private CharacterSpellSlotCalculator $slots,
        private CharacterSpellSlotStateService $slotState,
        private CharacterResourceResolver $resources,
        private TrackableResourceDefinitionRepository $definitions,
    ) {}

    /**
     * @return array{
     *   standardSlots: list<array>, pactSlots: array, resources: list<array>, issues: list<string>
     * }
     * Pools: slug, definitionId (?int), level (?int), applicable (?bool), maximum (?int),
     * remaining (?int), status (available|unavailable|unknown), reason (?string), issues (list<string>).
     * Only trusted counters are returned as remaining. No implicit session or synchronization.
     */
    public function build(Character $character, array $state): array
    {
        $rows = array_key_exists('resources', $state) ? $state['resources'] : [];
        $issues = [];
        $index = [];
        if (!is_array($rows) || !array_is_list($rows)) {
            $issues[] = 'invalid_resources';
            $rows = [];
        }
        foreach ($rows as $row) {
            if (!is_array($row) || !is_string($row['id'] ?? null) || $row['id'] === '') {
                $issues[] = 'invalid_resource_entry';
                continue;
            }
            $index[$row['id']][] = $row;
        }
        // Unidentifiable entries could conceal a duplicate: do not trust any counter.
        $globalIssues = array_values(array_unique($issues));
        $natural = $this->slots->calculate($character);
        $safeRows = [];
        $bonusIssues = [];
        foreach ($index as $slug => $matches) {
            if (!preg_match('/\Aspell-slot-([1-9])\z/', (string) $slug, $levelMatch)) continue;
            foreach ($matches as $row) {
                if (!array_key_exists('flexibleCastingBonus', $row)) continue;
                $bonus = $row['flexibleCastingBonus'];
                if (!is_int($bonus) || $bonus < 0 || $bonus > PHP_INT_MAX - ($natural[(int) $levelMatch[1]] ?? 0)) {
                    $bonusIssues[$slug] = ['invalid_temporary_bonus'];
                }
            }
            if (count($matches) === 1 && !isset($bonusIssues[$slug])) $safeRows[] = $matches[0];
        }
        $maximums = $this->slotState->effectiveMaximums($character, ['resources' => $safeRows]);
        $standard = [];
        for ($level = 1; $level <= 9; ++$level) {
            $slug = 'spell-slot-'.$level;
            $poolIssues = [...$globalIssues, ...($bonusIssues[$slug] ?? [])];
            $ambiguous = count($index[$slug] ?? []) > 1 || $poolIssues !== [];
            $maximum = $ambiguous ? null : ($maximums[$level] ?? 0);
            $standard[] = $this->pool($slug, null, $level, $maximum === null ? null : $maximum > 0,
                $maximum, $index[$slug] ?? [], $poolIssues);
        }

        $resolved = $this->resources->resolve($character, CharacterSessionStateSynchronizer::extractProgressionValues($state));
        $historical = [];
        foreach ($this->definitions->findVisibleBySlugs(array_keys($index), $character->getCampaign()->getOwner()) as $definition) {
            $historical[$definition->getSlug()] = $definition;
        }
        $pactSlug = 'warlock-pact-slots';
        $pactLevel = $this->slots->pactSlotLevel($character);
        $pactResource = $resolved[$pactSlug] ?? null;
        $pactIssues = $globalIssues;
        if ($pactLevel !== null && $pactResource === null) $pactIssues[] = 'pact_resource_unresolved';
        $pact = $this->pool($pactSlug, $pactResource?->getDefinition()->getId(), $pactLevel,
            $pactLevel === null ? false : ($pactResource === null ? null : true),
            $pactLevel === null ? 0 : $pactResource?->getMaximum(), $index[$pactSlug] ?? [], $pactIssues);

        $resourcePools = [];
        $slugs = array_unique([...array_keys($resolved), ...array_keys($index)]);
        sort($slugs, SORT_STRING);
        foreach ($slugs as $slug) {
            $slug = (string) $slug;
            if ($slug === $pactSlug || preg_match('/\Aspell-slot-[1-9]\z/', $slug)) continue;
            $resource = $resolved[$slug] ?? null;
            $definition = $resource?->getDefinition() ?? $historical[$slug] ?? null;
            $resourcePools[] = $this->pool($slug, $definition?->getId(), null,
                $resource !== null ? true : ($definition !== null ? false : null),
                $resource?->getMaximum(), $index[$slug] ?? [], $globalIssues);
        }
        return ['standardSlots' => $standard, 'pactSlots' => $pact, 'resources' => $resourcePools, 'issues' => $globalIssues];
    }

    private function pool(string $slug, ?int $definitionId, ?int $level, ?bool $applicable, ?int $maximum, array $rows, array $issues): array
    {
        $remaining = null;
        if (count($rows) > 1) $issues[] = 'duplicate_counter';
        elseif ($rows !== []) {
            $value = $rows[0]['currentValue'] ?? null;
            if (!is_int($value) || $value < 0) $issues[] = 'invalid_counter';
            elseif ($maximum !== null && $value > $maximum) $issues[] = 'counter_above_maximum';
            else $remaining = $value;
        }
        $issues = array_values(array_unique($issues));
        if ($issues !== []) $remaining = null;
        $reason = match (true) {
            $issues !== [] => $issues[0],
            $applicable === false => 'inactive_pool',
            $applicable === null => 'unresolved_resource',
            $rows === [] => 'missing_counter',
            $remaining === 0 => 'exhausted',
            default => null,
        };
        $status = $issues !== [] || $applicable === null || ($applicable && $rows === [])
            ? 'unknown' : ($applicable && $remaining > 0 ? 'available' : 'unavailable');
        return compact('slug', 'definitionId', 'level', 'applicable', 'maximum', 'remaining', 'status', 'reason', 'issues');
    }
}
