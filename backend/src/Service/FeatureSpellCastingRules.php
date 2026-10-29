<?php
declare(strict_types=1);
namespace App\Service;

use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/** Catalogue configuration only; never grants runtime casting permission. */
final class FeatureSpellCastingRules
{
    public static function validate(mixed $rules, mixed $resourceId, int $baseLevel): ?array
    {
        $fail = static function (): never { throw new BadRequestHttpException('Configuration de lancement invalide.'); };
        if ($resourceId !== null && (!is_int($resourceId) || $resourceId < 1)) $fail();
        if ($rules === null) {
            if ($resourceId !== null) $fail();
            return null;
        }
        if (!is_array($rules) || array_is_list($rules)
            || array_diff(array_keys($rules), ['version','methods','restrictions'])
            || ($rules['version'] ?? null) !== 1
            || !is_array($rules['methods'] ?? null) || !array_is_list($rules['methods'])
            || count($rules['methods']) < 1 || count($rules['methods']) > 2) $fail();
        if (array_key_exists('restrictions', $rules)
            && (!is_string($rules['restrictions']) || trim($rules['restrictions']) === '' || strlen($rules['restrictions']) > 4000)) $fail();
        $seen = [];
        foreach ($rules['methods'] as $method) {
            if (!is_array($method) || array_is_list($method)) $fail();
            $kind = $method['kind'] ?? null;
            if (!is_string($kind) || !in_array($kind, ['resource','standard-slot'], true) || isset($seen[$kind])) $fail();
            $seen[$kind] = true;
            if ($kind === 'standard-slot') {
                if (array_keys($method) !== ['kind'] || $baseLevel === 0) $fail();
            } else {
                if (array_diff(array_keys($method), ['kind','cost','castingLevel'])
                    || !is_int($method['cost'] ?? null) || $method['cost'] < 1 || $method['cost'] > 2147483647
                    || !is_int($method['castingLevel'] ?? null) || $method['castingLevel'] < $baseLevel || $method['castingLevel'] > 9
                    || ($baseLevel === 0 && $method['castingLevel'] !== 0)) $fail();
            }
        }
        if (isset($seen['resource']) !== ($resourceId !== null)) $fail();
        return $rules;
    }
}
