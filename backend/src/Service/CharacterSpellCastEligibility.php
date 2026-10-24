<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Pure, read-only eligibility decision for a single spell source.
 * Does not inspect or spend slots, enforce action economy, or resolve grant charges.
 */
final readonly class CharacterSpellCastEligibility
{
    /**
     * @param array<string,mixed> $entry A single PlayerSpellDirectory entry.
     * @param 'known'|'spellbook'|'prepared'|null $learningStyle
     * @param string|null $classSlug Canonical class slug for ritual rules.
     * @return array{canCastNormally:bool,canCastAsRitual:bool,reason:?string,ritualReason:?string,requiresGrantReview:bool}
     */
    public function evaluate(array $entry, ?string $learningStyle, ?string $classSlug): array
    {
        $level = $entry['level'] ?? null;
        $ritual = ($entry['details']['ritual'] ?? false) === true;
        $sourceType = $entry['sourceType'] ?? null;
        $known = ($entry['known'] ?? false) === true;
        $prepared = ($entry['prepared'] ?? false) === true;
        $inSpellbook = ($entry['inSpellbook'] ?? false) === true;
        $alwaysPrepared = ($entry['alwaysPrepared'] ?? false) === true;

        // Subclass/feature grants may be once-per-rest, slot-only, free-only, etc.
        // Their metadata must be resolved before authorizing a casting mode.
        if ($sourceType === 'subclass' || $sourceType === 'feature') {
            return $this->result(false, false, 'grant_rules_pending', 'grant_rules_pending', true);
        }
        if (!is_int($level) || $level < 0 || $level > 9) {
            return $this->result(false, false, 'spell_level_unknown', 'spell_level_unknown', false);
        }
        if ($sourceType !== 'class' || $learningStyle === null) {
            return $this->result(false, false, 'source_policy_unknown', 'source_policy_unknown', false);
        }

        if ($level === 0) {
            return $this->result($known, false,
                $known ? null : 'cantrip_not_known', 'not_a_ritual', false);
        }

        $normal = match ($learningStyle) {
            'known' => $known,
            'spellbook' => $inSpellbook && $prepared,
            'prepared' => $prepared || $alwaysPrepared,
            default => false,
        };
        $reason = $normal ? null : match ($learningStyle) {
            'known' => 'spell_not_known',
            'spellbook' => $inSpellbook ? 'spell_not_prepared' : 'spell_not_in_spellbook',
            'prepared' => 'spell_not_prepared',
            default => 'source_policy_unknown',
        };

        $canRitual = false;
        $ritualReason = 'not_a_ritual';
        if ($ritual) {
            $slug = strtolower((string) $classSlug);
            // 2014: wizard may cast ritual spells from spellbook without preparing.
            // Bard may ritual-cast known spells; cleric/druid require preparation.
            // Other classes/subclasses require explicit feature authorization (D2 follow-up).
            $canRitual = match ($slug) {
                'wizard' => $learningStyle === 'spellbook' && $inSpellbook,
                'bard' => $learningStyle === 'known' && $known,
                'cleric', 'druid' => $learningStyle === 'prepared' && ($prepared || $alwaysPrepared),
                default => false,
            };
            $ritualReason = $canRitual ? null : 'ritual_feature_or_preparation_missing';
        }
        return $this->result($normal, $canRitual, $reason, $ritualReason, false);
    }

    private function result(bool $normal, bool $ritual, ?string $reason, ?string $ritualReason, bool $review): array
    {
        return ['canCastNormally' => $normal, 'canCastAsRitual' => $ritual,
            'reason' => $reason, 'ritualReason' => $ritualReason, 'requiresGrantReview' => $review];
    }
}
