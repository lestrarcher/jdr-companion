<?php

declare(strict_types=1);

namespace App\Service;

use stdClass;

final class FeatCatalogueValidator
{
    const ABILITIES = ['strength', 'dexterity', 'constitution', 'intelligence', 'wisdom', 'charisma'];
    const APPROVED_ABILITIES = [
        'resilient' => self::ABILITIES,
        'war-caster' => [],
        'observant' => ['intelligence', 'wisdom'],
        'fey-touched' => ['intelligence', 'wisdom', 'charisma'],
        'slasher' => ['strength', 'dexterity'],
        'great-weapon-master' => [],
        'dragon-hide' => ['strength', 'constitution', 'charisma'],
        'dragon-fear' => ['strength', 'constitution', 'charisma'],
        'gift-of-the-metallic-dragon' => [],
    ];
    const OLD_SLUGS = ['cuir-du-dragon', 'peur-du-dragon', 'don-des-dragons-metalliques'];

    private static function exactKeys(stdClass $object, array $expected): bool
    {
        $actual = array_keys(get_object_vars($object));
        sort($actual);
        sort($expected);

        return $actual === $expected;
    }

    private static function nonEmptyString(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    /** @return list<string> */
    public function validate(mixed $catalogue, bool $checkBaseline = false): array
    {
        if (!$catalogue instanceof stdClass || !self::exactKeys($catalogue, ['schemaVersion', 'catalogue', 'feats'])) {
            return ['Invalid catalogue envelope.'];
        }

        $errors = [];
        if ($catalogue->schemaVersion !== 1 || $catalogue->catalogue !== 'dnd-2014-feats') {
            $errors[] = 'Expected schemaVersion 1 and catalogue dnd-2014-feats.';
        }
        if (!is_array($catalogue->feats) || count($catalogue->feats) !== 83) {
            return [...$errors, 'Expected exactly 83 feats.'];
        }

        $slugs = [];
        $approved = [];
        $keys = ['slug', 'name', 'description', 'repeatable', 'requiresAbilityChoice',
            'chosenAbilityIncrease', 'allowedAbilities', 'custom', 'prerequisiteText',
            'englishName', 'sourceBook', 'edition', 'sourceUrl', 'reviewStatus', 'reviewNotes'];

        foreach ($catalogue->feats as $index => $feat) {
            $label = "feats[$index]";
            if (!$feat instanceof stdClass || !self::exactKeys($feat, $keys)) {
                $errors[] = "$label: missing or unexpected properties.";
                continue;
            }
            if (!is_string($feat->slug) || strlen($feat->slug) > 80
                || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $feat->slug) !== 1) {
                $errors[] = "$label: invalid slug.";
            } else {
                if (isset($slugs[$feat->slug]) || in_array($feat->slug, self::OLD_SLUGS, true)) {
                    $errors[] = "$label: duplicate or obsolete slug.";
                }
                $slugs[$feat->slug] = true;
                if (is_string($feat->englishName)) {
                    $englishSlug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($feat->englishName)), '-');
                    if ($englishSlug !== $feat->slug) {
                        $errors[] = "$label: slug must correspond to englishName.";
                    }
                }
            }
            if (!self::nonEmptyString($feat->name) || mb_strlen($feat->name) > 120) {
                $errors[] = "$label: invalid name length.";
            }
            foreach (['description', 'englishName', 'sourceBook'] as $field) {
                if (!self::nonEmptyString($feat->$field)) {
                    $errors[] = "$label: $field must be a non-empty string.";
                }
            }
            if (is_string($feat->description) && mb_strlen($feat->description) > 300) {
                $errors[] = "$label: description must remain a short summary (300 characters maximum).";
            }
            if ($feat->custom !== false || $feat->edition !== '2014') {
                $errors[] = "$label: expected custom=false and edition=2014.";
            }
            if ($feat->prerequisiteText !== null && !self::nonEmptyString($feat->prerequisiteText)) {
                $errors[] = "$label: prerequisiteText must be null or non-empty text.";
            }
            if (!is_string($feat->sourceUrl) || !filter_var($feat->sourceUrl, FILTER_VALIDATE_URL)
                || parse_url($feat->sourceUrl, PHP_URL_SCHEME) !== 'https') {
                $errors[] = "$label: invalid HTTPS sourceUrl.";
            }
            if (!in_array($feat->reviewStatus, ['approved', 'pending'], true)) {
                $errors[] = "$label: invalid reviewStatus.";
            }
            if (!is_array($feat->reviewNotes) || count(array_filter($feat->reviewNotes, [self::class, 'nonEmptyString'])) !== count($feat->reviewNotes)
                || ($feat->reviewStatus === 'pending' && $feat->reviewNotes === [])) {
                $errors[] = "$label: invalid or missing review notes.";
            }
            foreach (['repeatable', 'requiresAbilityChoice'] as $field) {
                if ($feat->$field !== null && !is_bool($feat->$field)) {
                    $errors[] = "$label: $field must be boolean or null.";
                }
            }
            if ($feat->chosenAbilityIncrease !== null && (!is_int($feat->chosenAbilityIncrease)
                || $feat->chosenAbilityIncrease < 0 || $feat->chosenAbilityIncrease > 2)) {
                $errors[] = "$label: invalid ability increase.";
            }
            if ($feat->allowedAbilities !== null) {
                if (!is_array($feat->allowedAbilities)) {
                    $errors[] = "$label: allowedAbilities must be an array or null.";
                } else {
                    $seen = [];
                    foreach ($feat->allowedAbilities as $ability) {
                        if (!is_string($ability) || !in_array($ability, self::ABILITIES, true) || isset($seen[$ability])) {
                            $errors[] = "$label: invalid or duplicate ability.";
                            break;
                        }
                        $seen[$ability] = true;
                    }
                }
            }
            if ($feat->reviewStatus === 'approved') {
                foreach (['repeatable', 'requiresAbilityChoice', 'chosenAbilityIncrease', 'allowedAbilities'] as $field) {
                    if ($feat->$field === null) {
                        $errors[] = "$label: approved mechanics cannot be null.";
                    }
                }
                if ($feat->requiresAbilityChoice === false && ($feat->chosenAbilityIncrease !== 0 || $feat->allowedAbilities !== [])) {
                    $errors[] = "$label: no ability choice requires zero increase and an empty list.";
                }
                if ($feat->requiresAbilityChoice === true && (!is_array($feat->allowedAbilities) || $feat->allowedAbilities === [])) {
                    $errors[] = "$label: ability choice requires an explicit non-empty list.";
                }
                if (is_string($feat->slug)) {
                    $approved[] = $feat->slug;
                    if ($checkBaseline && array_key_exists($feat->slug, self::APPROVED_ABILITIES)) {
                        $expected = self::APPROVED_ABILITIES[$feat->slug];
                        if ($feat->repeatable !== false || $feat->requiresAbilityChoice !== ($expected !== [])
                            || $feat->chosenAbilityIncrease !== ($expected !== [] ? 1 : 0) || $feat->allowedAbilities !== $expected) {
                            $errors[] = "$label: mechanics differ from the existing approved baseline.";
                        }
                    }
                }
            }
        }
        $expected = array_keys(self::APPROVED_ABILITIES);
        sort($expected);
        sort($approved);
        if ($checkBaseline && $approved !== $expected) {
            $errors[] = 'Expected exactly the nine approved English slugs; all other feats must remain pending.';
        }

        return $errors;
    }
}
