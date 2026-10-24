<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Character;

/** Read-only validation. The caller must lock, check revision and handle idempotency before mutation. */
final readonly class CharacterSpellCastCommandValidator
{
    public function __construct(private PlayerSpellDirectory $directory) {}

    /**
     * @return array{valid:bool,status:string,reason:?string,source:?array,method:?string,castingLevel:?int,poolSlug:?string,cost:?int}
     * Revision and requestId are checked for syntax only: no session lookup, reservation or receipt.
     * Pool and cost are returned only for a validated method, exclusively from server resolution.
     */
    public function validate(Character $character, array $state, array $command): array
    {
        $result = ['valid'=>false, 'status'=>'unavailable', 'reason'=>null, 'source'=>null,
            'method'=>null, 'castingLevel'=>null, 'poolSlug'=>null, 'cost'=>null];
        $keys = ['revision','requestId','sourceKey','method','castingLevel'];
        if (array_diff($keys, array_keys($command)) || array_diff(array_keys($command), $keys)
            || !is_int($command['revision']) || $command['revision'] < 1
            || !is_string($command['requestId'])
            || !preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i', $command['requestId'])
            || !is_string($command['sourceKey']) || $command['sourceKey'] === '' || strlen($command['sourceKey']) > 255
            || !in_array($command['method'], ['standard-slot','pact-slot','cantrip','ritual'], true)
            || !is_int($command['castingLevel']) || $command['castingLevel'] < 0 || $command['castingLevel'] > 9) {
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
        return array_replace($result, ['valid'=>true, 'status'=>'available',
            'poolSlug'=>$method['poolSlug'], 'cost'=>$method['cost']]);
    }
}
