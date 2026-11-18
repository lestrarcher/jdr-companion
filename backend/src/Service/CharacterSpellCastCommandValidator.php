<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Character;

/** Read-only validation. The caller must lock, check revision and handle idempotency before mutation. */
final readonly class CharacterSpellCastCommandValidator
{
    public function __construct(private PlayerSpellDirectory $directory) {}

    /**
     * @return array{valid:bool,status:string,reason:?string,source:?array,method:?string,castingLevel:?int,poolSlug:?string,cost:?int,metamagic?:array{key:string,poolSlug:string,cost:int},metamagics?:list<array{key:string,poolSlug:string,cost:int}>}
     * Revision and requestId are checked for syntax only: no session lookup, reservation or receipt.
     * Pool and cost are returned only for a validated method, exclusively from server resolution.
     */
    public function validate(Character $character, array $state, array $command): array
    {
        $result = ['valid'=>false, 'status'=>'unavailable', 'reason'=>null, 'source'=>null,
            'method'=>null, 'castingLevel'=>null, 'poolSlug'=>null, 'cost'=>null];
        try {
            $command = SpellCastReceipts::normalizeCommand($command);
        } catch (\InvalidArgumentException) {
            return array_replace($result, ['reason'=>'invalid_command']);
        }
        $result['method'] = $command['method'];
        $result['castingLevel'] = $command['castingLevel'];
        $sources = array_values(array_filter($this->directory->forCharacter($character, $state),
            static fn(array $entry): bool => $entry['key'] === $command['sourceKey']));
        if (count($sources) !== 1) {
            return array_replace($result, ['reason'=>$sources === [] ? 'source_not_found' : 'ambiguous_source']);
        }
        $entry = $sources[0];
        $result['source'] = array_intersect_key($entry, array_flip(['key','spellId','policyId','sourceType']));
        $availability = $entry['castingAvailability'];
        if ($entry['castingEligibility']['requiresGrantReview']) {
            return array_replace($result, ['status'=>'unknown', 'reason'=>'grant_rules_pending']);
        }
        $methods = array_values(array_filter($availability['methods'],
            static fn(array $method): bool => $method['kind'] === $command['method']));
        if ($methods === []) {
            return array_replace($result, ['reason'=>$entry['castingEligibility']['reason'] ?? 'method_not_allowed']);
        }
        $matches = array_values(array_filter($methods,
            static fn(array $method): bool => $method['castingLevel'] === $command['castingLevel']));
        if (count($matches) !== 1) {
            return array_replace($result, ['reason'=>'casting_level_mismatch']);
        }
        $method = $matches[0];
        if ($method['status'] !== 'available') {
            return array_replace($result, ['status'=>$method['status'], 'reason'=>$method['reason'] ?? 'method_not_available']);
        }
        $selected = $command['metamagics'] ?? (isset($command['metamagic']) ? [$command['metamagic']] : []);
        $metamagics = [];
        foreach ($selected as $key) {
            $options = array_values(array_filter($method['metamagics'] ?? [],
                static fn(array $option): bool => $option['key'] === $key));
            if (count($options) !== 1) return array_replace($result, ['reason'=>'metamagic_not_acquired']);
            if ($options[0]['status'] !== 'available') return array_replace($result, ['reason'=>$options[0]['reason']]);
            $metamagics[] = array_intersect_key($options[0], array_flip(['key','poolSlug','cost']));
        }
        if ($metamagics !== []) {
            $pools = array_values(array_filter($state['resources'] ?? [], static fn(array $pool): bool => ($pool['id'] ?? null) === 'sorcery-points'));
            $total = array_sum(array_column($metamagics, 'cost')) + ($method['poolSlug'] === 'sorcery-points' ? $method['cost'] : 0);
            if (count($pools) !== 1 || !is_int($pools[0]['currentValue'] ?? null) || $pools[0]['currentValue'] < $total) return array_replace($result, ['reason'=>'insufficient_sorcery_points']);
            if (isset($command['metamagic'])) $result['metamagic'] = $metamagics[0];
            else $result['metamagics'] = $metamagics;
        }
        return array_replace($result, ['valid'=>true, 'status'=>'available',
            'poolSlug'=>$method['poolSlug'], 'cost'=>$method['cost']]);
    }
}
