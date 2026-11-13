<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Character;

/** 2014 casting costs, not a combat simulator. Unknown combat facts remain player checks. */
final readonly class CharacterSpellMetamagic
{
    private const COSTS = [
        'metamagic-careful-spell'=>1, 'metamagic-distant-spell'=>1,
        'metamagic-empowered-spell'=>1, 'metamagic-extended-spell'=>1,
        'metamagic-heightened-spell'=>3, 'metamagic-quickened-spell'=>2,
        'metamagic-subtle-spell'=>1, 'metamagic-twinned-spell'=>1,
        'metamagic-seeking-spell'=>2, 'metamagic-transmuted-spell'=>1,
    ];

    public function __construct(private CharacterFeatureResolver $features) {}

    public function acquired(Character $character, array $state): array
    {
        $result = [];
        foreach ($this->features->resolve($character, CharacterSessionStateSynchronizer::extractProgressionValues($state)) as $key=>$rule) {
            if (isset(self::COSTS[$key])) $result[$key] = $rule->getFeatureDefinition()->getName();
        }
        return $result;
    }

    /** Uses the same trusted resource snapshot and casting method as ordinary casting. */
    public function options(array $acquired, array $spell, array $method, array $context, int $characterLevel): array
    {
        $pools = array_values(array_filter($context['resources'], static fn(array $pool): bool => $pool['slug'] === 'sorcery-points'));
        $pool = count($pools) === 1 ? $pools[0] : null;
        $result = [];
        foreach ($acquired as $key=>$name) {
            if (!isset(self::COSTS[$key])) continue;
            $cost = $key === 'metamagic-twinned-spell' ? max(1, $method['castingLevel']) : self::COSTS[$key];
            [$reason, $checks] = self::compatibility($key, $spell, $method);
            // Canonical 2014 identities only; never infer targeting from a translated name.
            if ($key === 'metamagic-twinned-spell' && match ($spell['importKey'] ?? null) {
                'aidedd-2014:decharge-occulte' => $characterLevel >= 5,
                'aidedd-2014:immobilisation-de-monstre' => $method['castingLevel'] >= 6,
                default => false,
            }) $reason = 'metamagic_multiple_possible_targets';
            $reason ??= $method['status'] === 'available' ? null : ($method['reason'] ?? 'method_not_available');
            $reason ??= $pool === null ? 'sorcery_points_unavailable'
                : ($pool['status'] !== 'available' ? 'sorcery_points_'.($pool['reason'] ?? 'unavailable') : null);
            // A resource-granted spell may itself consume this same pool.
            $total = $cost + ($method['poolSlug'] === 'sorcery-points' ? $method['cost'] : 0);
            if ($reason === null && $pool['remaining'] < $total) $reason = 'insufficient_sorcery_points';
            $result[] = ['key'=>$key, 'name'=>$name, 'poolSlug'=>'sorcery-points', 'cost'=>$cost,
                'status'=>$reason === null ? 'available' : 'unavailable', 'reason'=>$reason, 'playerChecks'=>$checks];
        }
        return $result;
    }

    /** No inference from spell names/descriptions: only explicit, recognizable catalogue fields. */
    private static function compatibility(string $key, array $spell, array $method): array
    {
        $range = self::text($spell['range'] ?? '');
        $duration = self::text($spell['duration'] ?? '');
        $time = self::text($spell['castingTime'] ?? '');
        $self = (bool) preg_match('/^(self|personnelle|personnel|soi|vous-même)(\b|\s*\()/u', $range);
        $checks = match ($key) {
            'metamagic-careful-spell'=>'saving_throw_and_protected_creatures',
            'metamagic-empowered-spell'=>'damage_roll_and_charisma_reroll_limit',
            'metamagic-heightened-spell'=>'first_saving_throw_of_one_target',
            'metamagic-quickened-spell'=>'bonus_action_spell_restrictions_2014',
            'metamagic-twinned-spell'=>'only_one_possible_creature_at_casting_level',
            'metamagic-seeking-spell'=>'missed_spell_attack',
            'metamagic-transmuted-spell'=>'acid_cold_fire_lightning_poison_thunder_damage',
            'metamagic-extended-spell'=>'duration_doubled_maximum_24_hours',
            'metamagic-subtle-spell'=>'material_components_still_required',
            default=>'range_doubled_or_touch_30_feet',
        };
        $reason = null;
        if ($key === 'metamagic-twinned-spell' && $self) $reason = 'metamagic_self_range';
        if ($key === 'metamagic-distant-spell') {
            if ($self) $reason = 'metamagic_range_too_short';
            if (preg_match('/^(\d+(?:[.,]\d+)?)\s*(feet|foot|ft|pieds?|m|mètres?|meters?)\.?$/u', $range, $m)) {
                $minimum = preg_match('/^(m|mètre|meter)/u', $m[2]) ? 1.5 : 5;
                if ((float)str_replace(',', '.', $m[1]) < $minimum) $reason = 'metamagic_range_too_short';
            }
        }
        if ($key === 'metamagic-extended-spell' && (preg_match('/^(instantanée?|instantaneous)$/u', $duration)
            || preg_match('/^(?:jusqu’à |jusqu\'à |up to )?(\d+)\s*(rounds?|tours?|seconds?|secondes?)$/u', $duration, $m)
                && (int)$m[1] * (preg_match('/^(round|tour)/', $m[2]) ? 6 : 1) < 60)) {
            $reason = 'metamagic_duration_too_short';
        }
        if ($key === 'metamagic-quickened-spell' && ($method['kind'] === 'ritual'
            || preg_match('/^(?:1|une|one) (?:bonus action|action bonus|réaction|reaction)\b/u', $time)
            || preg_match('/^\d+ (?:minutes?|heures?|hours?|rounds?|tours?|jours?|days?)\b/u', $time)
            || preg_match('/^(\d+) actions?$/u', $time, $m) && (int)$m[1] !== 1)) {
            $reason = 'metamagic_requires_one_action';
        }
        if ($key === 'metamagic-subtle-spell' && ($spell['verbal'] ?? null) === false && ($spell['somatic'] ?? null) === false) {
            $reason = 'metamagic_no_verbal_or_somatic_components';
        }
        $checks = [$checks];
        if ($key === 'metamagic-quickened-spell' && !in_array($time, ['1 action', 'one action', 'une action'], true)) $checks[] = 'casting_time_is_one_action';
        if ($key === 'metamagic-distant-spell') $checks[] = 'range_touch_or_at_least_5_feet';
        if ($key === 'metamagic-extended-spell') $checks[] = 'duration_at_least_one_minute';
        return [$reason, $checks];
    }

    private static function text(string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value)));
    }
}
