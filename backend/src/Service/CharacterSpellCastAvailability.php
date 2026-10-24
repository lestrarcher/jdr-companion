<?php

declare(strict_types=1);

namespace App\Service;

/** Pure evaluation of one source against a CharacterSpellAvailabilityContext::build() snapshot. */
final readonly class CharacterSpellCastAvailability
{
    /**
     * @return array{sourceKey:?string,policyId:?int,status:string,reason:?string,methods:list<array>}
     * Each method contains kind, poolSlug (?string), castingLevel (?int), cost (int),
     * status (available|unavailable|unknown), reason (?string).
     * Context is built once by the caller, never recalculated or mutated here.
     */
    public function evaluate(array $entry, array $context): array
    {
        $result = ['sourceKey'=>$entry['key'] ?? null, 'policyId'=>$entry['policyId'] ?? null,
            'status'=>'unknown', 'reason'=>null, 'methods'=>[]];
        $eligibility = $entry['castingEligibility'] ?? null;
        if (!is_array($eligibility)
            || !is_bool($eligibility['canCastNormally'] ?? null)
            || !is_bool($eligibility['canCastAsRitual'] ?? null)
            || !is_bool($eligibility['requiresGrantReview'] ?? null)) {
            return array_replace($result, ['reason'=>'eligibility_unknown']);
        }
        if ($eligibility['requiresGrantReview'] || in_array($entry['sourceType'] ?? null, ['feature','subclass'], true)) {
            return array_replace($result, ['reason'=>'grant_rules_pending']);
        }
        $level = $entry['level'] ?? null;
        if (!is_int($level) || $level < 0 || $level > 9) {
            return array_replace($result, ['reason'=>'spell_level_unknown']);
        }
        $methods = [];
        if (!$eligibility['canCastNormally']) {
            $methods[] = $this->method('normal', null, $level, 0, 'unavailable', $eligibility['reason'] ?? 'normal_cast_not_allowed');
        } elseif ($level === 0) {
            $methods[] = $this->method('cantrip', null, 0, 0, 'available', null);
        } else {
            foreach (range(1, 9) as $slotLevel) {
                $slug = 'spell-slot-'.$slotLevel;
                $pools = array_values(array_filter($context['standardSlots'] ?? [], static fn(array $pool): bool => ($pool['slug'] ?? null) === $slug));
                $methods[] = $this->slotMethod('standard-slot', $slug, $slotLevel, $level, count($pools) === 1 ? $pools[0] : []);
            }
            $pact = $context['pactSlots'] ?? [];
            $methods[] = $this->slotMethod('pact-slot', 'warlock-pact-slots', $pact['level'] ?? null, $level, $pact);
        }
        $ritual = $level > 0 && $eligibility['canCastAsRitual'];
        $methods[] = $this->method('ritual', null, $level, 0, $ritual ? 'available' : 'unavailable',
            $ritual ? null : ($level === 0 ? 'not_a_ritual' : ($eligibility['ritualReason'] ?? 'ritual_cast_not_allowed')));
        $statuses = array_column($methods, 'status');
        $status = in_array('available', $statuses, true) ? 'available'
            : (in_array('unknown', $statuses, true) ? 'unknown' : 'unavailable');
        return array_replace($result, ['status'=>$status, 'reason'=>match ($status) {
            'available'=>null, 'unknown'=>'casting_means_unknown', default=>'no_available_casting_method',
        }, 'methods'=>$methods]);
    }

    private function slotMethod(string $kind, string $slug, ?int $level, int $spellLevel, array $pool): array
    {
        if (($pool['applicable'] ?? null) === false) {
            return $this->method($kind, $slug, $level, 1, 'unavailable', 'inactive_pool');
        }
        if ($level !== null && $level < $spellLevel) {
            return $this->method($kind, $slug, $level, 1, 'unavailable', 'slot_level_too_low');
        }
        $status = $pool['status'] ?? 'unknown';
        $reason = $pool['reason'] ?? null;
        // Never promote an unknown context pool, or accept a malformed available counter.
        if ($level === null || $level < 1 || $level > 9
            || !in_array($status, ['available','unavailable','unknown'], true)
            || ($status === 'available' && (($pool['applicable'] ?? null) !== true
                || !is_int($pool['remaining'] ?? null) || $pool['remaining'] < 1
                || !is_int($pool['maximum'] ?? null) || $pool['remaining'] > $pool['maximum']
                || ($pool['issues'] ?? []) !== []))) {
            $status = 'unknown'; $reason = 'invalid_pool_context';
        }
        return $this->method($kind, $slug, $level, 1, $status, $reason ?? ($status === 'unknown' ? 'pool_context_missing' : null));
    }

    private function method(string $kind, ?string $poolSlug, ?int $castingLevel, int $cost, string $status, ?string $reason): array
    {
        return compact('kind', 'poolSlug', 'castingLevel', 'cost', 'status', 'reason');
    }
}
