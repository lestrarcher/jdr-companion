<?php

declare(strict_types=1);

namespace App\Dto;

/** Structural contract only: eligibility and completeness require transactional validation. */
final class LevelUpSpellChoices
{
    public const MAX_CHOICES = 100;

    /**
     * Missing field is distinct from an explicitly empty list. Neither validates required learning.
     * ruleId is explicitly null for simple policies; advanced policies require an ID at business validation.
     *
     * @param array<string, mixed> $payload
     * @return list<array{policyId: int, spellId: int, ruleId: int|null, replacesAcquisitionId?: int, replacementConfirmed?: bool}>|null
     */
    public static function fromPayload(array $payload): ?array
    {
        if (!array_key_exists('spellChoices', $payload)) {
            return null;
        }

        $choices = $payload['spellChoices'];
        if (!is_array($choices) || !array_is_list($choices) || count($choices) > self::MAX_CHOICES) {
            throw new \InvalidArgumentException('spellChoices doit être une liste de 100 choix maximum.');
        }

        $spells = [];
        $replacements = [];
        foreach ($choices as $choice) {
            if (!is_array($choice) || array_is_list($choice)
                || array_diff(array_keys($choice), ['policyId', 'spellId', 'ruleId', 'replacesAcquisitionId', 'replacementConfirmed'])
                || !self::positiveId($choice['policyId'] ?? null)
                || !self::positiveId($choice['spellId'] ?? null)
                || !array_key_exists('ruleId', $choice)
                || ($choice['ruleId'] !== null && !self::positiveId($choice['ruleId']))) {
                throw new \InvalidArgumentException('Choix de sort invalide : policyId, spellId et ruleId sont requis, sans champ inconnu.');
            }

            $replacing = array_key_exists('replacesAcquisitionId', $choice);
            if (($replacing && !self::positiveId($choice['replacesAcquisitionId']))
                || (array_key_exists('replacementConfirmed', $choice)
                    && (!$replacing || !is_bool($choice['replacementConfirmed'])))) {
                throw new \InvalidArgumentException('Remplacement invalide : identifiant positif et confirmation booléenne uniquement avec un remplacement.');
            }

            // A spell may be selected through independent policies, but not twice within one policy.
            $key = $choice['policyId'].':'.$choice['spellId'];
            if (isset($spells[$key]) || ($replacing && isset($replacements[$choice['replacesAcquisitionId']]))) {
                throw new \InvalidArgumentException('Choix de sort ou acquisition remplacée en doublon.');
            }
            $spells[$key] = true;
            if ($replacing) {
                $replacements[$choice['replacesAcquisitionId']] = true;
            }
        }

        return $choices;
    }

    private static function positiveId(mixed $value): bool
    {
        return is_int($value) && $value > 0;
    }
}
