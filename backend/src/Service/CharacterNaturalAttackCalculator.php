<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Character;
use App\Entity\CharacterFeatureRule;
use App\Enum\FeatureActivationType;

/** Read model only: no inventory entries, session lookup or combat state. */
final class CharacterNaturalAttackCalculator
{
    /** @param array<string, CharacterFeatureRule> $features Already resolved in the caller's explicit progression context. */
    public function calculate(Character $character, array $scores, array $features, array $training): array
    {
        $monkLevel = 0;
        foreach ($character->getClassLevels() as $level) {
            if ($level->getCharacterClass()->getSlug() === 'monk') ++$monkLevel;
        }
        $armor = $shield = false;
        $hands = 0;
        $nonMonkWeapon = false;
        foreach ($character->getMagicItems() as $owned) {
            if (!$owned->isEquipped()) continue;
            $item = $owned->getMagicItem();
            $armor = $armor || $item?->getCategory() === 'armor';
            $shield = $shield || $item?->getCategory() === 'shield';
            if ($item?->getEquipmentSlot() === 'hand') $hands += $owned->getHandsUsed() ?? ($item->getHandUsage() === 'two' ? 2 : 1);
            if ($item?->getCategory() !== 'weapon') continue;
            $profile = $item->getWeaponProfile();
            if (SpecialWeaponProfiles::isVariable($item) && $owned->getBaseWeaponKey() === null) {
                $profile = null;
            } elseif (SpecialWeaponProfiles::isVariable($item) || SpecialWeaponProfiles::isFixed($item)) {
                $profile = SpecialWeaponProfiles::profile($item, $owned->getBaseWeaponKey());
            }
            if ($profile === null || !($profile['proficiency'] === 'shortsword'
                || ($profile['group'] === 'simple-weapons' && $profile['mode'] === 'melee'
                    && !array_intersect($profile['properties'], ['heavy', 'two-handed'])))) $nonMonkWeapon = true;
        }
        $martial = $monkLevel > 0 && !$armor && !$shield && !$nonMonkWeapon;
        $martialDice = '1d'.($monkLevel >= 17 ? 10 : ($monkLevel >= 11 ? 8 : ($monkLevel >= 5 ? 6 : 4)));
        $tavern = false;
        foreach ($character->getFeats() as $feat) {
            if ($feat->getFeat()->getSlug() === 'tavern-brawler') $tavern = true;
        }
        $proficient = $tavern;
        foreach ($training['proficiencies'] ?? [] as $proficiency) {
            if ($proficiency['type'] === 'WEAPON' && $proficiency['slug'] === 'improvised-weapons') $proficient = true;
        }
        $base = ['dice' => '1', 'damageType' => 'bludgeoning', 'ability' => 'strength', 'proficient' => true];
        $options = [$base];
        if ($tavern) $options[] = array_replace($base, ['dice' => '1d4', 'notes' => ['Bagarreur de tavernes']]);
        $natural = [];
        foreach ($features as $rule) {
            $feature = $rule->getFeatureDefinition();
            if ($feature->getActivationType() !== FeatureActivationType::Passive) continue;
            foreach ($feature->getAttackEffects() ?? [] as $effect) {
                $conditions = $effect['conditions'] ?? [];
                if (($armor && in_array('no-armor', $conditions, true))
                    || ($shield && in_array('no-shield', $conditions, true))
                    || ($hands >= 2 && in_array('free-hand', $conditions, true))) continue;
                $effect['notes'] = [$feature->getName(), ...($effect['notes'] ?? [])];
                if ($effect['type'] === 'improvised-proficiency') $proficient = true;
                elseif ($effect['type'] === 'unarmed-damage') $options[] = array_replace($base, $effect);
                else $natural['feature:'.$feature->getSlug().':'.$effect['key']] = $effect;
            }
        }
        if ($martial) {
            foreach ($options as &$option) $option = $this->martial($option, $martialDice);
            unset($option);
        }
        $best = $options[0];
        foreach ($options as $option) {
            if ($this->expected($option, $scores) > $this->expected($best, $scores)) $best = $option;
        }
        if ($monkLevel > 0 && !$martial) $best['notes'][] = 'Arts martiaux non appliqués : armure, bouclier ou arme autre qu’une arme de moine équipée.';
        $rows = [$this->row('attack:unarmed', 'Attaque à mains nues', $best, $scores, $character)];
        $rows[] = $this->row('attack:improvised', 'Attaque à l’arme improvisée', [
            'dice' => '1d4', 'damageType' => 'gm', 'ability' => 'strength', 'proficient' => $proficient,
            'notes' => ['Profil de mêlée. Type de dégâts déterminé par le MJ.',
                'Si le MJ assimile l’objet à une arme existante, utiliser le profil et la maîtrise de cette arme.',
                ...($tavern ? ['Bagarreur de tavernes : maîtrise des armes improvisées.'] : [])],
        ], $scores, $character);
        foreach ($natural as $id => $effect) {
            if ($martial && ($effect['unarmed'] ?? false)) $effect = $this->martial($effect, $martialDice);
            $rows[] = $this->row($id, $effect['name'], $effect, $scores, $character);
        }
        return $rows;
    }

    private function martial(array $profile, string $dice): array
    {
        if (self::average($dice) > self::average($profile['dice'])) $profile['dice'] = $dice;
        if (($profile['ability'] ?? 'strength') === 'strength') $profile['ability'] = 'best-strength-dexterity';
        $profile['notes'][] = 'Arts martiaux (sans armure ni bouclier, uniquement des armes de moine).';
        return $profile;
    }

    private function ability(array $profile, array $scores): string
    {
        $ability = $profile['ability'] ?? 'strength';
        return $ability === 'best-strength-dexterity'
            ? (($scores['dexterity'] ?? -100) > ($scores['strength'] ?? -100) ? 'dexterity' : 'strength') : $ability;
    }

    private function expected(array $profile, array $scores): float
    {
        return self::average($profile['dice']) + ($scores[$this->ability($profile, $scores)] ?? -100) + ($profile['damageBonus'] ?? 0);
    }

    private static function average(string $dice): float
    {
        if (!str_contains($dice, 'd')) return (float) $dice;
        [$count, $sides] = array_map('intval', explode('d', $dice));
        return $count * ($sides + 1) / 2;
    }

    private function row(string $id, string $name, array $profile, array $scores, Character $character): array
    {
        $ability = $this->ability($profile, $scores);
        $complete = isset($scores[$ability]) && (($profile['ability'] ?? '') !== 'best-strength-dexterity' || isset($scores['strength'], $scores['dexterity']));
        $proficient = $profile['proficient'] ?? true;
        $modifier = $scores[$ability] ?? 0;
        $bonus = $modifier + ($profile['damageBonus'] ?? 0);
        $damage = $profile['dice'].($bonus === 0 ? '' : ($bonus > 0 ? '+' : '').$bonus);
        if (!str_contains($profile['dice'], 'd') && (int)$profile['dice'] + $bonus < 0) $damage = '0';
        $type = match ($profile['damageType']) { 'slashing' => 'tranchant', 'piercing' => 'perforant', 'bludgeoning' => 'contondant', default => '(type au choix du MJ)' };
        $notes = $profile['notes'] ?? [];
        if (!$proficient) $notes[] = 'Maîtrise non acquise';
        if (!$complete) $notes[] = 'Caractéristique non renseignée.';
        if ($bonus < 0) $notes[] = 'Les dégâts ne peuvent pas être inférieurs à 0.';
        return ['id' => $id, 'name' => $name, 'equipped' => false, 'attackBonus' => $complete ? $modifier + ($proficient ? $character->getProficiencyBonus() : 0) + ($profile['attackBonus'] ?? 0) : null,
            'damage' => $complete ? $damage.' '.$type : null, 'notes' => $notes, 'complete' => $complete, 'ability' => $ability, 'proficient' => $proficient];
    }
}
