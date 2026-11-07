<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\{Campaign, Character, CharacterClass, CharacterClassLevel, CharacterMagicItem, MagicItem, MagicItemAbilityEffect, User};
use App\Enum\{Ability, AbilityEffectOperation};
use App\Service\{CharacterAbilityCalculator, CharacterArmorClassCalculator, CharacterEquipmentService};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CharacterArmorClassTest extends TestCase
{
    private function character(int $dex = 16): Character
    {
        $character = new Character($this->createMock(Campaign::class), 'test', 'Test', Character::TYPE_PLAYER);
        $character->getAbilityScore(Ability::Dexterity)->setBaseValue($dex);
        $character->getAbilityScore(Ability::Constitution)->setBaseValue(14);
        $character->getAbilityScore(Ability::Wisdom)->setBaseValue(18);
        return $character;
    }

    private function calculator(): CharacterArmorClassCalculator
    {
        return new CharacterArmorClassCalculator(new CharacterAbilityCalculator(), new CharacterEquipmentService());
    }

    private function item(Character $character, string $category, ?string $type = null, ?int $base = null, string $hands = 'one'): CharacterMagicItem
    {
        $definition = new MagicItem(null, $category, null, $this->createMock(User::class));
        $definition->classify($category, true, true, $category === 'armor' ? 'armor' : 'hand', $category === 'armor' ? null : $hands);
        $definition->configureArmor($type, $base);
        $owned = new CharacterMagicItem($character, $definition);
        $character->addMagicItem($owned);
        return $owned;
    }

    private function level(Character $character, string $slug, int $position): void
    {
        $class = new CharacterClass($slug, $slug, 12, 3);
        $character->addClassLevel(new CharacterClassLevel($character, $class, $position));
    }

    public static function armors(): array
    {
        return [
            ['light', 11, 16, 14], ['light', 11, 8, 10],
            ['medium', 14, 18, 16], ['medium', 14, 8, 13],
            ['heavy', 18, 18, 18], ['heavy', 18, 8, 18],
        ];
    }

    #[DataProvider('armors')]
    public function testArmorFormula(string $type, int $base, int $dex, int $expected): void
    {
        $character = $this->character($dex);
        $this->level($character, 'barbarian', 1);
        $armor = $this->item($character, 'armor', $type, $base);
        (new CharacterEquipmentService())->equip($character, $armor, true);
        self::assertSame($expected, $this->calculator()->calculate($character)['value']);
    }

    public static function defenses(): array
    {
        return [
            [[], false, 13], [[], true, 15],
            [['barbarian'], false, 15], [['barbarian'], true, 17],
            [['monk'], false, 17], [['monk'], true, 15],
            [['barbarian', 'monk'], false, 15], [['barbarian', 'monk'], true, 17],
            [['monk', 'barbarian'], false, 17], [['monk', 'barbarian'], true, 15],
            [['fighter', 'monk', 'barbarian'], false, 17],
            [['fighter', 'barbarian', 'monk'], false, 15],
        ];
    }

    #[DataProvider('defenses')]
    public function testAcquisitionAndShieldRestrictions(array $classes, bool $shield, int $expected): void
    {
        $character = $this->character();
        // Deliberately insert in reverse order: position must decide acquisition.
        foreach (array_reverse($classes, true) as $position => $slug) $this->level($character, $slug, $position + 1);
        if ($shield) $this->item($character, 'shield', 'shield', 2)->setEquipped(true);
        self::assertSame($expected, $this->calculator()->calculate($character)['value']);
    }

    public function testMonkWearingArmorAndStandardFallback(): void
    {
        $character = $this->character();
        $this->level($character, 'monk', 1);
        $armor = $this->item($character, 'armor', 'light', 11);
        $armor->setEquipped(true);
        self::assertSame(14, $this->calculator()->calculate($character)['value']);
        $armor->setEquipped(false);
        $character->getAbilityScore(Ability::Wisdom)->setBaseValue(8);
        self::assertSame(13, $this->calculator()->calculate($character)['value']);
    }

    public function testUnknownArmorAndShieldAreIncomplete(): void
    {
        foreach (['armor', 'shield'] as $category) {
            $character = $this->character();
            $owned = $this->item($character, $category);
            $owned->setEquipped(true);
            $result = $this->calculator()->calculate($character);
            self::assertFalse($result['complete']);
            self::assertNull($result['value']);
            self::assertNotEmpty($result['reasons']);
        }
    }

    public function testEffectiveAbilitiesAndAttunementArePreserved(): void
    {
        $character = $this->character();
        $this->level($character, 'barbarian', 1);
        $item = new MagicItem(null, 'Dextérité', null, $this->createMock(User::class));
        $item->setRequiresAttunement(true);
        $item->addAbilityEffect(new MagicItemAbilityEffect($item, Ability::Dexterity, AbilityEffectOperation::Minimum, 20));
        $owned = new CharacterMagicItem($character, $item);
        $character->addMagicItem($owned);
        $equipment = new CharacterEquipmentService();
        $equipment->equip($character, $owned, true);
        self::assertSame(15, $this->calculator()->calculate($character)['value']);
        $owned->setAttuned(true);
        self::assertSame(17, $this->calculator()->calculate($character)['value']);
        $equipment->equip($character, $owned, false);
        self::assertTrue($owned->isAttuned());
        self::assertSame(15, $this->calculator()->calculate($character)['value']);
    }

    public static function conflicts(): array
    {
        return [['armor', 'armor', 'one'], ['shield', 'shield', 'one'], ['weapon', 'shield', 'two']];
    }

    #[DataProvider('conflicts')]
    public function testConflictsDoNotChangeEquipment(string $first, string $second, string $hands): void
    {
        $character = $this->character();
        $existing = $this->item($character, $first, hands: $hands);
        $next = $this->item($character, $second);
        $equipment = new CharacterEquipmentService();
        $equipment->equip($character, $existing, true);
        try {
            $equipment->equip($character, $next, true);
            self::fail('Expected equipment conflict');
        } catch (\InvalidArgumentException) {
            self::assertTrue($existing->isEquipped());
            self::assertFalse($next->isEquipped());
        }
        $equipment->equip($character, $existing, false);
        $equipment->equip($character, $next, true);
        self::assertTrue($next->isEquipped());
        self::assertNull($existing->getEquippedSlot());
        self::assertNull($existing->getHandsUsed());
    }

    public function testThreeHandsAndForeignPossessionAreRejected(): void
    {
        $character = $this->character();
        $equipment = new CharacterEquipmentService();
        $equipment->equip($character, $this->item($character, 'weapon'), true);
        $equipment->equip($character, $this->item($character, 'shield', 'shield', 2), true);
        self::assertSame(15, $this->calculator()->calculate($character)['value']);
        $third = $this->item($character, 'weapon');
        try { $equipment->equip($character, $third, true); self::fail(); } catch (\InvalidArgumentException) {}
        self::assertFalse($third->isEquipped());
        $this->expectException(\InvalidArgumentException::class);
        $equipment->equip($this->character(), $third, false);
    }

    public function testLegacyConflictsAreIncompleteAndCanBeRemoved(): void
    {
        $character = $this->character();
        $a = $this->item($character, 'armor', 'light', 11);
        $b = $this->item($character, 'armor', 'heavy', 18);
        $a->setEquipped(true);
        $b->setEquipped(true);
        self::assertFalse($this->calculator()->calculate($character)['complete']);
        (new CharacterEquipmentService())->equip($character, $a, false);
        self::assertSame(18, $this->calculator()->calculate($character)['value']);
    }

    public function testPhysicalArmorAndShieldDoNotRequireAttunement(): void
    {
        $character = $this->character();
        $armor = $this->item($character, 'armor', 'heavy', 18);
        $armor->getMagicItem()->setRequiresAttunement(true);
        $armor->setEquipped(true);
        $shield = $this->item($character, 'shield', 'shield', 2);
        $shield->getMagicItem()->setRequiresAttunement(true);
        $shield->setEquipped(true);
        self::assertSame(20, $this->calculator()->calculate($character)['value']);
        self::assertFalse($armor->isEffectActive());
        self::assertFalse($shield->isEffectActive());
    }

    public function testLegacyStackCannotBeNewlyEquipped(): void
    {
        $character = $this->character();
        $owned = $this->item($character, 'armor', 'light', 11);
        (new \ReflectionProperty($owned, 'quantity'))->setValue($owned, 2);
        (new \ReflectionProperty($owned, 'legacyStack'))->setValue($owned, true);
        $this->expectException(\InvalidArgumentException::class);
        (new CharacterEquipmentService())->equip($character, $owned, true);
    }

    public static function invalidProfiles(): array
    {
        return [['light', null], [null, 11], ['unknown', 11], ['light', 0], ['heavy', 31], ['shield', 2]];
    }

    #[DataProvider('invalidProfiles')]
    public function testInvalidArmorProfileIsRejected(?string $type, ?int $base): void
    {
        $owned = $this->item($this->character(), 'armor');
        $this->expectException(\InvalidArgumentException::class);
        $owned->getMagicItem()->configureArmor($type, $base);
    }

    public function testMagicalShieldBonusCannotEnterPhysicalProfile(): void
    {
        $owned = $this->item($this->character(), 'shield');
        $this->expectException(\InvalidArgumentException::class);
        $owned->getMagicItem()->configureArmor('shield', 3);
    }
}
