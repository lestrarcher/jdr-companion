<?php

declare(strict_types=1);
namespace App\Service;

use App\Entity\{Character, CharacterFeatureDefinition, CharacterFeatureRule};
use Doctrine\ORM\EntityManagerInterface;

/** Pure read model. Callers supply base rules, never recursively resolve selected features. */
final readonly class FeatureChoiceReader
{
    public function __construct(private EntityManagerInterface $em) {}

    /** Automatic option rules become choice-only; preserve explicitly snapshotted legacy rights until GM repair. */
    public function filterBaseRules(Character $character, array $rules): array
    {
        $db = $this->em->getConnection();
        $gates = $db->fetchAllKeyValue('SELECT rule_id,group_key FROM feature_choice_rule_gate');
        $legacy = $character->getId() === null ? [] : $db->fetchFirstColumn('SELECT rule_id FROM feature_choice_legacy_rule WHERE character_id=?', [$character->getId()]);
        $repaired = $character->getId() === null ? [] : $db->fetchFirstColumn('SELECT DISTINCT g.group_key FROM character_feature_choice c JOIN feature_choice_grant g ON g.key=c.grant_key WHERE c.character_id=? AND c.active', [$character->getId()]);
        return array_values(array_filter($rules, static fn ($rule) => !isset($gates[$rule->getId()])
            || (in_array($rule->getId(), $legacy) && !in_array($gates[$rule->getId()], $repaired, true))));
    }

    public function replacementAllowed(Character $character, array $grant, string $classKey, array $newKeys, array $grants): bool
    {
        if ($grant['classKey'] !== $classKey) return false;
        if ($grant['replacement'] === 'class-level') return true;
        if ($grant['replacement'] === 'grant-level' && array_filter($grants, static fn ($g) => $g['group_key'] === $grant['group_key'] && in_array($g['key'], $newKeys, true))) return true;
        foreach (json_decode($grant['replacement_windows'], true, 512, JSON_THROW_ON_ERROR) as $window) {
            if (in_array($grant['classLevel'], $window['levels'], true)
                && $this->reasons($character, $window['prerequisites'], $grant['knownFeatures']) === []) {
                foreach ($window['excludesSpellReplacementBuckets'] ?? [] as $bucket) {
                    if ($character->getId() !== null && $this->em->getConnection()->fetchOne('SELECT 1 FROM character_spell_acquisition a JOIN character_class_level l ON l.id=a.class_level_id WHERE a.character_id=? AND l.position=? AND a.replaces_id IS NOT NULL AND a.quota_bucket=? AND a.retirement_id IS NULL', [$character->getId(),$character->getTotalLevel(),$bucket])) return false;
                }
                return true;
            }
        }
        return false;
    }

    public function history(Character $character): array
    {
        if ($character->getId() === null) return [];
        return $this->em->getConnection()->fetchAllAssociative(
            'SELECT * FROM character_feature_choice WHERE character_id=? ORDER BY created_at,key', [$character->getId()]);
    }

    public function legacyAttributions(Character $character): array
    {
        if ($character->getId() === null) return [];
        return $this->em->getConnection()->fetchAllAssociative('SELECT l.rule_id,g.group_key,f.slug AS feature_key,f.name FROM feature_choice_legacy_rule l JOIN feature_choice_rule_gate g ON g.rule_id=l.rule_id JOIN character_feature_rule r ON r.id=l.rule_id JOIN character_feature_definition f ON f.id=r.feature_definition_id WHERE l.character_id=? AND NOT EXISTS (SELECT 1 FROM character_feature_choice c JOIN feature_choice_grant a ON a.key=c.grant_key WHERE c.character_id=l.character_id AND c.active AND a.group_key=g.group_key) ORDER BY g.group_key,f.slug', [$character->getId()]);
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
        foreach ($db->fetchAllAssociative('SELECT a.*, g.name, g.uniqueness, g.replacement, g.replacement_windows, g.replacement_event_key FROM feature_choice_grant a JOIN feature_choice_group g ON g.key=a.group_key WHERE a.enabled AND g.enabled AND (g.owner_id IS NULL OR g.owner_id=?) ORDER BY a.key', [$owner->getId()]) as $grant) {
            if (!isset($rules[$grant['rule_id']])) continue;
            $rule = $rules[$grant['rule_id']];
            $class = $rule->getCharacterClass() ?? $rule->getCharacterSubclass()?->getCharacterClass();
            $grant['classKey'] = $class?->getSlug();
            $grant['classLevel'] = $class === null ? null : $character->getLevelInClass($class);
            $grant['unlockLevel'] = $rule->getUnlockLevel();
            $grant['sourceFeature'] = $rule->getFeatureDefinition()->getSlug();
            $grant['knownFeatures'] = $known;
            $grant['options'] = [];
            foreach ($db->fetchAllAssociative('SELECT * FROM feature_choice_option WHERE group_key=? AND enabled ORDER BY key', [$grant['group_key']]) as $option) {
                $feature = $this->em->find(CharacterFeatureDefinition::class, $option['feature_id']);
                if ($feature === null || !ReferenceVisibility::allows($feature, $owner)) continue;
                $option['prerequisites'] = json_decode($option['prerequisites'], true, 512, JSON_THROW_ON_ERROR);
                $option['featureKey'] = $feature->getSlug();
                $option['name'] = $feature->getName();
                $option['description'] = $feature->getDescription();
                $option['alreadyAcquired'] = isset($known[$feature->getSlug()]);
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
        if (array_diff(array_keys($requirements), ['classLevels','subclasses','features','feats','anyOf','spellsKnown'])) return ['unsupported_prerequisite'];
        $reasons = [];
        foreach ($requirements as $kind => $values) {
            if (!is_array($values)) return ['invalid_prerequisite'];
            if ($kind === 'anyOf') {
                if (!array_is_list($values) || $values === []) return ['invalid_prerequisite'];
                $ok = false;
                foreach ($values as $alternative) {
                    if (!is_array($alternative) || $alternative === []) return ['invalid_prerequisite'];
                    if ($this->reasons($character, $alternative, $known) === []) $ok = true;
                }
                if (!$ok) $reasons[] = 'anyOf';
                continue;
            }
            if ($kind === 'spellsKnown') {
                if (!array_is_list($values)) return ['invalid_prerequisite'];
                foreach ($values as $slug) {
                    if (!is_string($slug)) return ['invalid_prerequisite'];
                    $has = $character->getId() !== null && $this->em->getConnection()->fetchOne('SELECT 1 FROM character_spell_acquisition a JOIN spell_definition s ON s.id=a.spell_id WHERE a.character_id=? AND s.slug=? AND a.known AND a.retirement_id IS NULL AND NOT EXISTS (SELECT 1 FROM character_spell_acquisition newer WHERE newer.replaces_id=a.id)', [$character->getId(), $slug]);
                    if (!$has && $character->getId() !== null) {
                        $sources = $this->em->getConnection()->fetchFirstColumn('SELECT f.slug FROM character_feature_spell a JOIN feature_spell_grant g ON g.id=a.grant_id JOIN character_feature_definition f ON f.id=g.feature_id JOIN spell_definition s ON s.id=a.spell_id WHERE a.character_id=? AND s.slug=? AND a.active AND g.enabled', [$character->getId(), $slug]);
                        $has = count(array_intersect($sources, array_keys($known))) > 0;
                    }
                    if (!$has) $reasons[] = 'spellsKnown:'.$slug;
                }
                continue;
            }
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
