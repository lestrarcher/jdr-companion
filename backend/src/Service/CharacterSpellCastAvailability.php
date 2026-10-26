<?php

declare(strict_types=1);

namespace App\Service;

/** Pure evaluation of one source against a CharacterSpellAvailabilityContext::build() snapshot. */
final readonly class CharacterSpellCastAvailability
{
    /** Availability of explicitly resolved feature methods, also used by the public directory.
     * Execution must still revalidate the directory under the session lock.
     * Inputs must describe the same character and current session; no implicit session lookup.
     * @return array{grantId:?int,status:string,reason:?string,methods:list<array>}
     */
    public function evaluateResolvedFeature(array $resolution, array $context): array
    {
        $result=['grantId'=>$resolution['grantId']??null,'status'=>'unknown','reason'=>null,'methods'=>[]];
        if (($resolution['status']??null)!=='resolved') return array_replace($result,['reason'=>$resolution['reason']??'grant_resolution_unknown']);
        if (!is_array($resolution['provenance']??null)
            || !in_array($resolution['provenance']['type']??null,['feat','class','subclass','race','progression'],true)
            || !is_int($resolution['provenance']['id']??null) || $resolution['provenance']['id']<1
            || ($resolution['abilityReason']??null)!==null
            || !in_array($resolution['castingAbility']??null,array_column(\App\Enum\Ability::cases(),'value'),true)
            || ($resolution['configurationVersion']??null)!==1) return array_replace($result,['reason'=>'grant_resolution_unknown']);
        if (($resolution['requiresRestrictionReview']??null)!==false || ($resolution['restrictions']??null)!==null) return array_replace($result,['reason'=>'restrictions_pending_review']);
        $configured=$resolution['methods']??null;
        if (!is_array($configured) || !array_is_list($configured) || $configured===[]) return array_replace($result,['reason'=>'invalid_resolved_methods']);
        $methods=[]; $seen=[];
        foreach ($configured as $method) {
            $kind=is_array($method)?($method['kind']??null):null;
            if (!is_string($kind) || isset($seen[$kind]) || !in_array($kind,['resource','standard-slot'],true)) return array_replace($result,['reason'=>'invalid_resolved_methods']);
            $seen[$kind]=true;
            if ($kind==='standard-slot') {
                $minimum=$method['minimumCastingLevel']??null;
                if (!is_int($minimum) || $minimum<1 || $minimum>9) return array_replace($result,['reason'=>'invalid_resolved_methods']);
                foreach (range($minimum,9) as $level) {
                    $slug='spell-slot-'.$level;
                    $pools=array_values(array_filter($context['standardSlots']??[],static fn(array $pool): bool => ($pool['slug']??null)===$slug));
                    $pool=count($pools)===1 ? $pools[0] : [];
                    if (($pool['applicable']??null)===false) continue;
                    if (($pool['level']??null)!==$level) $pool=[];
                    $methods[]=$this->slotMethod('standard-slot',$slug,$level,$minimum,$pool);
                }
            } else {
                $id=$method['resourceDefinitionId']??null; $cost=$method['cost']??null; $level=$method['castingLevel']??null;
                if (!is_int($id) || $id<1 || !is_int($cost) || $cost<1 || !is_int($level) || $level<0 || $level>9) return array_replace($result,['reason'=>'invalid_resolved_methods']);
                $pools=array_values(array_filter($context['resources']??[],static fn(array $pool): bool => ($pool['definitionId']??null)===$id));
                $pool=count($pools)===1 ? $pools[0] : null;
                $slug=$pool['slug']??null; $status=$pool['status']??'unknown'; $reason=$pool['reason']??null;
                if ($pool===null) { $status='unknown'; $reason=$pools===[]?'resource_not_resolved':'ambiguous_resource'; }
                elseif (($pool['applicable']??null)===false) { $status='unavailable'; $reason='inactive_pool'; }
                elseif ($status==='unknown') { $reason ??= 'resource_state_unknown'; }
                elseif (($pool['applicable']??null)!==true || !is_string($slug) || $slug===''
                    || !is_int($pool['remaining']??null) || !is_int($pool['maximum']??null)
                    || $pool['remaining']<0 || $pool['remaining']>$pool['maximum'] || ($pool['issues']??[])!==[]
                    || !in_array($status,['available','unavailable'],true)) { $status='unknown'; $reason='invalid_pool_context'; }
                elseif ($pool['remaining']===0) { $status='unavailable'; $reason='exhausted'; }
                elseif ($pool['remaining']<$cost) { $status='unavailable'; $reason='insufficient_resource'; }
                elseif ($status==='available') { $reason=null; }
                $methods[]=$this->method('resource',is_string($slug)?$slug:null,$level,$cost,$status,$reason)+['resourceDefinitionId'=>$id];
            }
        }
        $statuses=array_column($methods,'status');
        $status=in_array('available',$statuses,true)?'available':(in_array('unknown',$statuses,true)?'unknown':'unavailable');
        return array_replace($result,['status'=>$status,'reason'=>match($status){'available'=>null,'unknown'=>'casting_means_unknown',default=>'no_available_casting_method'},'methods'=>$methods]);
    }

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
        if ($eligibility['requiresGrantReview'] || ($entry['sourceType'] ?? null) === 'subclass') {
            return array_replace($result, ['reason'=>'grant_rules_pending']);
        }
        if (($entry['sourceType'] ?? null) === 'feature') {
            if (!$eligibility['canCastNormally']) return array_replace($result, ['reason'=>'grant_rules_pending']);
            $feature = $this->evaluateResolvedFeature($entry['castingRulesResolution'] ?? [], $context);
            return array_replace($result, array_intersect_key($feature, array_flip(['status','reason','methods'])));
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
