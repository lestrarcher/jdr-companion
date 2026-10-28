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
        // Server directory resolves this only from an active warlock Arcanum acquisition.
        if (($entry['arcanum'] ?? false) === true && $sourceType === 'class'
            && $classSlug === 'warlock' && is_int($level) && $level >= 6 && $level <= 9) {
            return $this->result(true, false, null, 'ritual_feature_or_preparation_missing', false);
        }

        // Resolution describes permission, independently of the current counters.
        if ($sourceType === 'feature') {
            $resolution = $entry['castingRulesResolution'] ?? [];
            if (($resolution['status'] ?? null) === 'resolved'
                && ($resolution['configurationVersion'] ?? null) === 1
                && is_array($resolution['provenance'] ?? null)
                && in_array($resolution['castingAbility'] ?? null, array_column(\App\Enum\Ability::cases(), 'value'), true)
                && ($resolution['abilityReason'] ?? null) === null
                && ($resolution['requiresRestrictionReview'] ?? null) === false
                && ($resolution['restrictions'] ?? null) === null
                && is_array($resolution['methods'] ?? null) && $resolution['methods'] !== []) {
                return $this->result(true, false, null, 'ritual_feature_or_preparation_missing', false);
            }
        }
        $ordinaryGrant = $sourceType === 'subclass' && $learningStyle !== null
            && in_array($entry['mode'] ?? null, ['grant-known', 'grant-always-prepared'], true)
            && in_array($entry['castingAbility'] ?? null, array_column(\App\Enum\Ability::cases(), 'value'), true)
            && ($entry['restrictions'] ?? null) === null && ($entry['resource'] ?? null) === null
            && ($entry['slotUsage'] ?? null) === null;
        // Special or unresolved grants still require explicit casting configuration.
        if (($sourceType === 'subclass' && !$ordinaryGrant) || $sourceType === 'feature') {
            return $this->result(false, false, 'grant_rules_pending', 'grant_rules_pending', true);
        }
        if (!is_int($level) || $level < 0 || $level > 9) {
            return $this->result(false, false, 'spell_level_unknown', 'spell_level_unknown', false);
        }
        if (($sourceType !== 'class' && !$ordinaryGrant) || $learningStyle === null) {
            return $this->result(false, false, 'source_policy_unknown', 'source_policy_unknown', false);
        }

        if ($level === 0) {
            return $this->result($known, false,
                $known ? null : 'cantrip_not_known', 'not_a_ritual', false);
        }

        $normal = $ordinaryGrant ? ($known || $alwaysPrepared) : match ($learningStyle) {
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
            // Bard may ritual-cast known spells; cleric/druid and Tasha's artificer require preparation.
            // Other classes/subclasses require explicit feature authorization (D2 follow-up).
            $canRitual = match ($slug) {
                'wizard' => $learningStyle === 'spellbook' && $inSpellbook,
                'bard' => $learningStyle === 'known' && $known,
                'cleric', 'druid', 'artificer' => $learningStyle === 'prepared' && ($prepared || $alwaysPrepared),
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
