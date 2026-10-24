<?php

declare(strict_types=1);

namespace App\Service;

/** Server-owned, session-scoped receipts. No eviction, mutation, transaction or replay execution.
 * Retain for the session state's lifetime. JSON grows with accepted casts; a future size
 * limit must refuse NEW commands, never evict receipts and silently permit identifier reuse.
 */
final class SpellCastReceipts
{
    public const STATE_KEY = 'spellCastReceipts';

    public static function normalizeUuid(mixed $id): string
    {
        if (!is_string($id) || !preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i', $id)) {
            throw new \InvalidArgumentException('invalid_request_id');
        }
        return strtolower($id);
    }

    /** Syntax only; deliberately independent of current spell eligibility. */
    public static function normalizeCommand(array $command): array
    {
        if (!self::keys($command, ['revision','requestId','sourceKey','method','castingLevel'])
            || !is_int($command['revision']) || $command['revision'] < 1
            || !is_string($command['sourceKey']) || $command['sourceKey'] === '' || strlen($command['sourceKey']) > 255
            || !in_array($command['method'], ['standard-slot','pact-slot','cantrip','ritual'], true)
            || !is_int($command['castingLevel']) || $command['castingLevel'] < 0 || $command['castingLevel'] > 9) {
            throw new \InvalidArgumentException('invalid_command');
        }
        return ['revision'=>$command['revision'], 'requestId'=>self::normalizeUuid($command['requestId']),
            'sourceKey'=>$command['sourceKey'], 'method'=>$command['method'], 'castingLevel'=>$command['castingLevel']];
    }

    public static function create(array $command, array $cast): array
    {
        $command = self::normalizeCommand($command);
        $id = $command['requestId']; unset($command['requestId']);
        $receipt = ['version'=>1, 'command'=>$command, 'cast'=>$cast];
        self::validateState([self::STATE_KEY=>[$id=>$receipt]]);
        return $receipt;
    }

    /** Returns the untouched map; malformed data is never silently treated as absent. */
    public static function validateState(array $state): array
    {
        if (!array_key_exists(self::STATE_KEY, $state)) return [];
        $receipts = $state[self::STATE_KEY];
        if (!is_array($receipts)) throw new \UnexpectedValueException('malformed_spell_cast_receipts');
        foreach ($receipts as $id=>$receipt) {
            try {
                if (self::normalizeUuid($id) !== $id || !is_array($receipt)
                    || !self::keys($receipt, ['version','command','cast'])) throw new \InvalidArgumentException();
                if ($receipt['version'] !== 1) throw new \UnexpectedValueException('unsupported_spell_cast_receipt_version');
                if (!is_array($receipt['command']) || !self::keys($receipt['command'], ['revision','sourceKey','method','castingLevel'])) throw new \InvalidArgumentException();
                $command = self::normalizeCommand($receipt['command'] + ['requestId'=>$id]);
                $cast = $receipt['cast'];
                if (!is_array($cast) || !self::keys($cast, ['source','method','castingLevel','poolSlug','cost'])
                    || !is_array($cast['source']) || !self::keys($cast['source'], ['key','spellId','policyId','sourceType'])
                    || $cast['source']['key'] !== $command['sourceKey']
                    || !is_int($cast['source']['spellId']) || $cast['source']['spellId'] < 1
                    || ($cast['source']['policyId'] !== null && (!is_int($cast['source']['policyId']) || $cast['source']['policyId'] < 1))
                    || !in_array($cast['source']['sourceType'], ['class','subclass','feature'], true)
                    || $cast['method'] !== $command['method'] || $cast['castingLevel'] !== $command['castingLevel']) throw new \InvalidArgumentException();
                $method=$cast['method']; $level=$cast['castingLevel'];
                $slot=in_array($method,['standard-slot','pact-slot'],true);
                $slug=match ($method) {'standard-slot'=>'spell-slot-'.$level, 'pact-slot'=>'warlock-pact-slots', default=>null};
                if ($cast['cost'] !== ($slot?1:0) || $cast['poolSlug'] !== $slug
                    || ($method==='cantrip' ? $level!==0 : $level<1)
                    || ($method==='pact-slot' && $level>5)) throw new \InvalidArgumentException();
            } catch (\InvalidArgumentException $error) {
                throw new \UnexpectedValueException('malformed_spell_cast_receipts', 0, $error);
            }
        }
        return $receipts;
    }

    /** @return 'missing'|'identical'|'conflict' */
    public static function compare(array $state, array $command): string
    {
        $command=self::normalizeCommand($command);
        $receipts=self::validateState($state);
        $id=$command['requestId']; unset($command['requestId']);
        if (!array_key_exists($id,$receipts)) return 'missing';
        foreach ($command as $field=>$value) if ($receipts[$id]['command'][$field] !== $value) return 'conflict';
        return 'identical';
    }

    private static function keys(array $value, array $keys): bool
    {
        return !array_diff($keys,array_keys($value)) && !array_diff(array_keys($value),$keys);
    }
}
