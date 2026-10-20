<?php
declare(strict_types=1);
namespace App\Dto;

/** A GM attests source access and actual time/cost; this is never a wallet debit. */
final class SpellbookCopyDecision
{
    public static function quote(int $spellLevel): array
    {
        if ($spellLevel < 1 || $spellLevel > 9) throw new \InvalidArgumentException('Seuls les sorts de niveau 1 à 9 peuvent être copiés.');
        return ['baseCostCp' => 5000 * $spellLevel, 'baseMinutes' => 120 * $spellLevel];
    }

    public static function validate(?array $details, int $spellLevel): void
    {
        $keys = ['baseCostCp', 'baseMinutes', 'costCp', 'minutes', 'source', 'adjustment', 'reason', 'confirmed'];
        if ($details === null || array_diff($keys, array_keys($details)) || array_diff(array_keys($details), $keys)
            || $details['confirmed'] !== true || !is_string($details['source']) || trim($details['source']) === '' || mb_strlen($details['source']) > 2000
            || !is_string($details['reason']) || mb_strlen($details['reason']) > 10000
            || !in_array($details['adjustment'], ['none', 'rule', 'gm'], true)) throw new \InvalidArgumentException('Confirmation de copie invalide.');
        foreach (self::quote($spellLevel) as $key => $value) if ($details[$key] !== $value) throw new \InvalidArgumentException('Tarif théorique incohérent.');
        foreach (['costCp', 'minutes'] as $key) if (!is_int($details[$key]) || $details[$key] < 0 || $details[$key] > 2147483647) throw new \InvalidArgumentException('Coût ou durée invalide.');
        if ($details['adjustment'] === 'none') {
            if ($details['costCp'] !== $details['baseCostCp'] || $details['minutes'] !== $details['baseMinutes']) throw new \InvalidArgumentException('Un ajustement doit être explicite.');
        } elseif (trim($details['reason']) === '') throw new \InvalidArgumentException('La règle ou la dérogation doit être motivée.');
    }
}
