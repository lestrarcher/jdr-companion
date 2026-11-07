<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Character;
use App\Entity\CharacterMagicItem;
use App\Enum\Ability;

final readonly class CharacterArmorClassCalculator
{
    public function __construct(
        private CharacterAbilityCalculator $abilities,
        private CharacterEquipmentService $equipment,
    ) {}

    /** @return array{value: ?int, complete: bool, source: ?string, formula: ?string, reasons: list<string>, warnings: list<string>} */
    public function calculate(Character $character): array
    {
        $active = array_values(array_filter($character->getMagicItems()->toArray(),
            static fn (CharacterMagicItem $item): bool => $item->isEquipped()));
        $errors = $this->equipment->conflicts($active);
        $warnings = [];
        $armor = $shield = null;
        foreach ($active as $owned) {
            $definition = $owned->getMagicItem();
            if ($definition?->getCategory() === 'armor') $armor = $owned;
            if ($definition?->getCategory() === 'shield') $shield = $owned;
            if (in_array($definition?->getCategory(), ['armor', 'shield'], true) && $definition->isMagical()) {
                $warnings[] = $owned->getDisplayName().' : profil physique uniquement, bonus de CA et effets magiques non calculés.';
            }
            if (in_array($definition?->getCategory(), ['armor', 'shield'], true)
                && ($definition->getArmorType() === null || $definition->getBaseArmorClass() === null)) {
                $errors[] = 'Données mécaniques manquantes : '.$owned->getDisplayName().'.';
            }
        }
        if ($errors !== []) return ['value' => null, 'complete' => false, 'source' => null, 'formula' => null, 'reasons' => $errors, 'warnings' => $warnings];

        $dex = $this->abilities->calculate($character, Ability::Dexterity)->modifier();
        $value = 10 + $dex;
        $source = 'Sans armure';
        $formula = sprintf('10 + DEX (%d)', $dex);
        if ($armor !== null) {
            $definition = $armor->getMagicItem();
            $contribution = match ($definition->getArmorDexterityMode()) {
                'full' => $dex, 'capped' => min($dex, $definition->getArmorDexterityCap()), 'none' => 0,
            };
            $value = $definition->getBaseArmorClass() + $contribution;
            $source = $armor->getDisplayName();
            $formula = sprintf('%d + DEX retenue (%d)', $definition->getBaseArmorClass(), $contribution);
        } else {
            // 2014: the first acquired Unarmored Defense blocks acquisition from another class.
            // Global position, not collection order, total class level or acquisition timestamp.
            $levels = $character->getClassLevels()->toArray();
            usort($levels, static fn ($a, $b): int => $a->getPosition() <=> $b->getPosition());
            foreach ($levels as $level) {
                $slug = $level->getCharacterClass()->getSlug();
                if (!in_array($slug, ['barbarian', 'monk'], true)) continue;
                if ($slug === 'barbarian' || $shield === null) {
                    $ability = $slug === 'barbarian' ? Ability::Constitution : Ability::Wisdom;
                    $modifier = $this->abilities->calculate($character, $ability)->modifier();
                    if (10 + $dex + $modifier > $value) {
                        $value = 10 + $dex + $modifier;
                        $source = 'Défense sans armure — '.($slug === 'barbarian' ? 'barbare' : 'moine');
                        $formula = sprintf('10 + DEX (%d) + %s (%d)', $dex, $slug === 'barbarian' ? 'CON' : 'SAG', $modifier);
                    }
                }
                break;
            }
        }
        if ($shield !== null) {
            $value += $shield->getMagicItem()->getBaseArmorClass();
            $source .= ' + '.$shield->getDisplayName();
            $formula .= ' + bouclier (2)';
        }
        return ['value' => $value, 'complete' => true, 'source' => $source, 'formula' => $formula, 'reasons' => [], 'warnings' => $warnings];
    }
}
