<?php
declare(strict_types=1);
namespace App\Service;
use App\Entity\Character;
use App\Enum\Ability;

final readonly class CharacterWeaponCalculator
{
    public function __construct(private CharacterAbilityCalculator $abilities, private CharacterNaturalAttackCalculator $naturalAttacks = new CharacterNaturalAttackCalculator()) {}

    /** Pure read model; unequipped weapons show their theoretical use, not global bonuses. */
    public function calculate(Character $character, array $training, array $infusions = [], array $features = []): array
    {
        $scores = [];
        foreach ($character->getAbilityScores() as $score) {
            $ability = $score->getAbility();
            if (in_array($ability, [Ability::Strength, Ability::Dexterity], true)) $scores[$ability->value] = $this->abilities->calculate($character, $ability)->modifier();
        }
        $proficiencies = array_column(array_filter($training['proficiencies'] ?? [], static fn ($p) => in_array($p['type'], ['WEAPON','WEAPON_GROUP'], true)), 'slug');
        $result = [];
        foreach ($character->getMagicItems() as $owned) {
            $item = $owned->getMagicItem();
            $focusStaff = $item !== null && $item->getOrigin() === \App\Enum\ReferenceOrigin::Official
                && $item->getEdition() === '2014' && !$item->isMagical() && $item->getCategory() === 'gear'
                && in_array($item->getEnglishName(), ['Staff', 'Wooden staff'], true);
            if ($item !== null && $item->getCategory() !== 'weapon' && !$focusStaff) continue;
            // Unclassified custom possessions cannot safely be identified as weapons.
            if ($item === null) continue;
            $row = ['id'=>$owned->getId(), 'name'=>$owned->getDisplayName(), 'equipped'=>$owned->isEquipped(), 'attackBonus'=>null, 'damage'=>null, 'notes'=>[], 'complete'=>false];
            $p = $item->getWeaponProfile();
            if (SpecialWeaponProfiles::isVariable($item)) {
                $key=$owned->getBaseWeaponKey();
                $p=$key === null ? null : SpecialWeaponProfiles::profile($item,$key);
            } elseif ($p === null && SpecialWeaponProfiles::isFixed($item)) {
                $p=SpecialWeaponProfiles::profile($item,null);
            }
            if ($focusStaff) {
                $profiles = json_decode(file_get_contents(__DIR__.'/../../data/weapon-profiles-2014.json'), true, 512, JSON_THROW_ON_ERROR);
                $p = $profiles['phb2014-weapon-quarterstaff'];
            }
            if ($p === null) { $row['notes'][] = 'Profil d’arme incomplet : type physique ou bonus non renseignés.'; $result[] = $row; continue; }
            $ability = $p['mode'] === 'ranged' ? 'dexterity' : 'strength';
            if (in_array('finesse', $p['properties'], true)) {
                if (!isset($scores['strength'], $scores['dexterity'])) { $row['notes'][] = 'FOR/DEX non renseignées.'; $result[] = $row; continue; }
                $ability = $scores['dexterity'] > $scores['strength'] ? 'dexterity' : 'strength';
            }
            if (!isset($scores[$ability])) { $row['notes'][] = 'Caractéristique non renseignée.'; $result[] = $row; continue; }
            $modifier = $scores[$ability];
            $proficient = in_array($p['proficiency'], $proficiencies, true) || in_array($p['group'], $proficiencies, true);
            $magicActive = !$item->requiresAttunement() || $owned->isAttuned();
            $attack = $magicActive ? $p['attackBonus'] : 0;
            $damage = $magicActive ? $p['damageBonus'] : 0;
            if (!$magicActive) $row['notes'][] = 'Bonus magique non appliqué : harmonisation requise.';
            $infusion = $infusions[$owned->getId()] ?? null;
            // An intrinsic magical bonus and an infusion never stack, even on misconfigured definitions.
            if (!$item->isMagical() && $p['attackBonus'] === 0 && $p['damageBonus'] === 0 && $infusion !== null) {
                $attack += $infusion['weaponBonuses']['attack'] ?? 0;
                $damage += $infusion['weaponBonuses']['damage'] ?? 0;
                $row['notes'][] = $infusion['name'].' — '.$infusion['creatorName'];
                if (($infusion['requiresAttunement'] ?? false) && !$owned->isAttuned()) {
                    $attack -= $infusion['weaponBonuses']['attack'] ?? 0;
                    $damage -= $infusion['weaponBonuses']['damage'] ?? 0;
                    $row['notes'][] = 'Harmonisation requise : bonus d’imprégnation non appliqué.';
                }
                if (isset($infusion['weaponNote'])) $row['notes'][] = $infusion['weaponNote'];
            }
            $row['attackBonus'] = $modifier + ($proficient ? $character->getProficiencyBonus() : 0) + $attack;
            $row['damage'] = $p['dice'] === null ? 'Aucun dégât' : $p['dice'].self::signed($modifier + $damage, true).' '.(str_ends_with($item->getImportKey() ?? '', ':sun-blade') ? 'radiant' : match($p['damageType']) { 'slashing'=>'tranchant', 'piercing'=>'perforant', 'bludgeoning'=>'contondant' });
            $row['complete'] = true;
            $row['ability'] = $ability;
            $row['proficient'] = $proficient;
            if (!$proficient) $row['notes'][] = 'Maîtrise non acquise';
            foreach ($p['properties'] as $property) {
                if ($property === 'loading' && !$item->isMagical() && $p['attackBonus'] === 0 && $p['damageBonus'] === 0 && ($infusion['ignoresLoading'] ?? false)) continue;
                $row['notes'][] = match($property) {
                'finesse'=>'Finesse (meilleur modificateur FOR/DEX)', 'light'=>'Légère', 'heavy'=>'Lourde', 'two-handed'=>'Deux mains',
                'reach'=>'Allonge', 'thrown'=>'Lancer', 'ammunition'=>'Munitions', 'loading'=>'Chargement', 'special'=>'Spéciale',
                };
            }
            if (isset($p['range'])) $row['notes'][] = 'Portée '.implode('/', $p['range']).' m';
            if (isset($p['versatile'])) $row['notes'][] = 'Polyvalente ('.$p['versatile'].self::signed($modifier + $damage, true).')';
            if (!empty($p['notes'])) $row['notes'][] = $p['notes'];
            $result[] = $row;
        }
        return [...$result, ...$this->naturalAttacks->calculate($character, $scores, $features, $training)];
    }
    private static function signed(int $value, bool $omitZero = false): string { return $value === 0 && $omitZero ? '' : ($value >= 0 ? '+' : '').$value; }
}
