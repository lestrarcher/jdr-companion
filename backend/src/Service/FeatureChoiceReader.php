<?php

declare(strict_types=1);
namespace App\Service;

use App\Entity\{Character, CharacterFeatureDefinition, CharacterFeatureRule};
use Doctrine\ORM\EntityManagerInterface;

/** Pure read model. Callers supply base rules, never recursively resolve selected features. */
final readonly class FeatureChoiceReader
{
    public function __construct(private EntityManagerInterface $em) {}

    public function history(Character $character): array
    {
        if ($character->getId() === null) return [];
        return $this->em->getConnection()->fetchAllAssociative(
            'SELECT * FROM character_feature_choice WHERE character_id=? ORDER BY created_at,key', [$character->getId()]);
    }

    public function evaluate(Character $character, array $baseRules, ?array $selections = null): array
    {
        $owner = $character->getCampaign()->getOwner();
        $rules = [];
        $known = [];
        foreach ($baseRules as $rule) {
            if ($rule->getId() !== null) $rules[$rule->getId()] = $rule;
            $known[$rule->getFeatureDefinition()->getSlug()] = true;
        }
        $db = $this->em->getConnection();
        $grants = [];
        foreach ($db->fetchAllAssociative('SELECT a.*, g.name, g.uniqueness, g.replacement FROM feature_choice_grant a JOIN feature_choice_group g ON g.key=a.group_key WHERE a.enabled AND g.enabled AND (g.owner_id IS NULL OR g.owner_id=?) ORDER BY a.key', [$owner->getId()]) as $grant) {
            if (!isset($rules[$grant['rule_id']])) continue;
            $rule = $rules[$grant['rule_id']];
            $class = $rule->getCharacterClass() ?? $rule->getCharacterSubclass()?->getCharacterClass();
            $grant['classKey'] = $class?->getSlug();
            $grant['classLevel'] = $class === null ? null : $character->getLevelInClass($class);
            $grant['unlockLevel'] = $rule->getUnlockLevel();
            $grant['sourceFeature'] = $rule->getFeatureDefinition()->getSlug();
            $grant['options'] = [];
            foreach ($db->fetchAllAssociative('SELECT * FROM feature_choice_option WHERE group_key=? AND enabled ORDER BY key', [$grant['group_key']]) as $option) {
                $feature = $this->em->find(CharacterFeatureDefinition::class, $option['feature_id']);
                if ($feature === null || !ReferenceVisibility::allows($feature, $owner)) continue;
                $option['prerequisites'] = json_decode($option['prerequisites'], true, 512, JSON_THROW_ON_ERROR);
                $option['featureKey'] = $feature->getSlug();
                $option['name'] = $feature->getName();
                $grant['options'][$option['key']] = $option;
            }
            $grants[$grant['key']] = $grant;
        }
        $selections ??= array_values(array_filter($this->history($character), static fn ($row) => $row['active']));
        $valid = [];
        // Fixed point permits prerequisite choices in one submission, but never self-supporting cycles.
        do {
            $changed = false;
            foreach ($selections as $index => $choice) {
                if (isset($valid[$index])) continue;
                $grant = $grants[$choice['grant_key']] ?? null;
                $option = $grant['options'][$choice['option_key']] ?? null;
                if ($grant === null || $option === null || $choice['slot'] < 1 || $choice['slot'] > $grant['choice_count']) continue;
                if ($this->reasons($character, $option['prerequisites'], $known) !== []) continue;
                $valid[$index] = $option;
                $known[$option['featureKey']] = true;
                $changed = true;
            }
        } while ($changed);
        foreach ($grants as &$grant) {
            $grant['selected'] = [];
            foreach ($selections as $index => $choice) {
                if ($choice['grant_key'] === $grant['key']) $grant['selected'][] = [
                    'key'=>$choice['key'] ?? null, 'slot'=>(int)$choice['slot'], 'optionKey'=>$choice['option_key'],
                    'valid'=>isset($valid[$index]),
                ];
            }
            $grant['remaining'] = max(0, (int)$grant['choice_count'] - count($grant['selected']));
            foreach ($grant['options'] as &$option) {
                $option['reasons'] = $this->reasons($character, $option['prerequisites'], $known);
                foreach ($valid as $index => $selected) {
                    $otherGrant = $grants[$selections[$index]['grant_key']];
                    if ($selected['featureKey'] === $option['featureKey'] && ($grant['uniqueness'] === 'character' || $otherGrant['uniqueness'] === 'character'
                        || ($grant['uniqueness'] === 'group' && $otherGrant['group_key'] === $grant['group_key']))) {
                        $option['reasons'][] = 'already_selected'; break;
                    }
                }
            }
            unset($option);
        }
        unset($grant);
        return ['grants'=>$grants, 'valid'=>$valid, 'selections'=>$selections];
    }

    public function selectedRules(Character $character, array $baseRules): array
    {
        $result = $this->evaluate($character, $baseRules);
        foreach ($result['valid'] as $index => $option) {
            $feature = $this->em->find(CharacterFeatureDefinition::class, $option['feature_id']);
            $rule = CharacterFeatureRule::forCharacter($feature, $character);
            $rule->setChoiceProvenance($result['selections'][$index]['key']);
            $baseRules[] = $rule;
        }
        return $baseRules;
    }

    /** Small explicit prerequisite vocabulary; unsupported data fails closed. */
    public function reasons(Character $character, array $requirements, array $known): array
    {
        if (array_diff(array_keys($requirements), ['classLevels','subclasses','features','feats'])) return ['unsupported_prerequisite'];
        $reasons = [];
        foreach ($requirements as $kind => $values) {
            if (!is_array($values)) return ['invalid_prerequisite'];
            if (in_array($kind, ['features','feats'], true)) {
                if (!array_is_list($values)) return ['invalid_prerequisite'];
                foreach ($values as $slug) {
                    if (!is_string($slug)) return ['invalid_prerequisite'];
                    $has = $kind === 'features' ? isset($known[$slug]) : count(array_filter($character->getFeats()->toArray(), static fn ($f) => $f->getFeat()->getSlug() === $slug)) > 0;
                    if (!$has) $reasons[] = $kind.':'.$slug;
                }
            } else {
                foreach ($values as $slug => $expected) {
                    if (!is_string($slug) || ($kind === 'classLevels' ? (!is_int($expected) || $expected < 1 || $expected > 20) : !is_string($expected))) return ['invalid_prerequisite'];
                    $class = null;
                    foreach ($character->getClassLevels() as $level) if ($level->getCharacterClass()->getSlug() === $slug) $class = $level->getCharacterClass();
                    if ($class === null || ($kind === 'classLevels' ? $character->getLevelInClass($class) < $expected : $character->getSubclassFor($class)?->getSlug() !== $expected)) $reasons[] = $kind.':'.$slug;
                }
            }
        }
        return $reasons;
    }
}
