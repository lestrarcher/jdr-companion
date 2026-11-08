<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Character;
use App\Entity\CharacterMagicItem;

final class CharacterEquipmentService
{
    /** Caller holds the character mutation lock and refreshes its possessions. */
    public function equip(Character $character, CharacterMagicItem $item, bool $equipped): void
    {
        if ($item->getCharacter() !== $character) {
            throw new \InvalidArgumentException('Cet objet n’appartient pas à ce personnage.');
        }
        if ($equipped && !$item->isEquipped()) {
            if (!$item->getMagicItem()?->isEquippable()) {
                throw new \InvalidArgumentException('Cet objet ne peut pas être équipé.');
            }
            if (in_array($item->getMagicItem()->getCategory(), ['armor', 'shield', 'weapon'], true)
                && ($item->getQuantity() !== 1 || $item->isLegacyStack())) {
                throw new \InvalidArgumentException('Séparez cette pile historique avant de l’équiper.');
            }
            $active = array_values(array_filter($character->getMagicItems()->toArray(),
                static fn (CharacterMagicItem $owned): bool => $owned !== $item && $owned->isEquipped()));
            $errors = $this->conflicts([...$active, $item]);
            if ($errors !== []) throw new \InvalidArgumentException(implode(' ', $errors));
        }
        $item->setEquipped($equipped);
    }

    /** @param list<CharacterMagicItem> $items @return list<string> */
    public function conflicts(array $items): array
    {
        $armors = $shields = $hands = 0;
        $errors = [];
        foreach ($items as $owned) {
            $item = $owned->getMagicItem();
            if ($item === null) continue;
            $armors += (int) ($item->getCategory() === 'armor');
            $shields += (int) ($item->getCategory() === 'shield');
            if ($item->getEquipmentSlot() === 'hand') {
                $hands += $owned->getHandsUsed() ?? ($item->getHandUsage() === 'two' ? 2 : 1);
            }
            if (in_array($item->getCategory(), ['armor', 'shield', 'weapon'], true) && $owned->getQuantity() !== 1) {
                $errors[] = 'Équipement historique empilé : configuration à vérifier.';
            }
        }
        if ($armors > 1) $errors[] = 'Une seule armure peut être équipée.';
        if ($shields > 1) $errors[] = 'Un seul bouclier peut être équipé.';
        if ($hands > 2) $errors[] = 'Les deux mains sont déjà occupées.';
        return $errors;
    }
}
