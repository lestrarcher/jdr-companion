<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\{Campaign, Character, CharacterClass, CharacterClassLevel, CharacterMagicItem, MagicItem};
use App\Enum\{Ability, ReferenceOrigin};
use App\Service\{ArmorClassCatalogueSynchronizer, ArmorClassEffectSchema, CharacterAbilityCalculator, CharacterArmorClassCalculator, CharacterEquipmentService, Dnd2014ArmorClassCatalogue};
use PHPUnit\Framework\TestCase;

final class ArmorClassCatalogueTest extends TestCase
{
    private function character(int $dex = 16): Character
    {
        $character = new Character($this->createMock(Campaign::class), 'fixture', 'Fixture', Character::TYPE_PLAYER);
        $character->getAbilityScore(Ability::Dexterity)->setBaseValue($dex);
        return $character;
    }

    private function own(Character $character, string $key, bool $attuned = true): CharacterMagicItem
    {
        $values = Dnd2014ArmorClassCatalogue::rules()[$key];
        $item = new MagicItem(null, $key, null, null, ReferenceOrigin::Official);
        $item->setRequiresAttunement($values['requires_attunement']);
        $item->classify($values['category'], true, $values['equippable'], $values['equipment_slot'], $values['hand_usage']);
        if (isset($values['armor_type'])) $item->configureArmor($values['armor_type'], $values['base_armor_class']);
        $item->configureArmorClassEffects($values['armor_class_effects']);
        $owned = new CharacterMagicItem($character, $item);
        $character->addMagicItem($owned);
        if ($attuned && $item->requiresAttunement()) $owned->setAttuned(true);
        (new CharacterEquipmentService())->equip($character, $owned, true);
        return $owned;
    }

    private function calculate(Character $character): array
    {
        return (new CharacterArmorClassCalculator(new CharacterAbilityCalculator(), new CharacterEquipmentService()))->calculate($character);
    }

    public function testReviewedManifestAndSeedsAreStrictlyScoped(): void
    {
        $rules = Dnd2014ArmorClassCatalogue::rules();
        self::assertCount(66, $rules);
        foreach ($rules as $values) ArmorClassEffectSchema::validate($values['armor_class_effects']);
        $seeds = (new ArmorClassCatalogueSynchronizer())->loadSeeds(__DIR__.'/../../data/armor-class-import-2014.json');
        self::assertCount(42, $seeds);
        foreach ($seeds as $key => $seed) {
            self::assertArrayHasKey($key, $rules);
            self::assertArrayNotHasKey('id', $seed);
            self::assertDoesNotMatchRegularExpression('/potion|weapon|ammunition/', $key);
        }
    }

    public function testRareTattooAndBracersUseSeparateFormulaAndBonus(): void
    {
        foreach ([8 => 16, 10 => 17, 14 => 19, 20 => 19] as $dex => $expected) {
            $character = $this->character($dex);
            $tattoo = $this->own($character, 'tcoe:barrier-tattoo-rare');
            $bracers = $this->own($character, 'srd-5.1:magic-item:bracers-of-defense');
            $result = $this->calculate($character);
            self::assertSame($expected, $result['value']);
            self::assertSame(['base', 'ability', 'bonus'], array_column($result['contributions'], 'kind'));
            self::assertSame('other', $tattoo->getMagicItem()->getCategory());
            $bracers->setEquipped(false);
            self::assertSame($expected - 2, $this->calculate($character)['value']);
            $tattoo->setAttuned(false);
            self::assertSame(10 + (int) floor(($dex - 10) / 2), $this->calculate($character)['value']);
        }
    }

    public function testTattooAllowsShieldButBracersDoNotAndArmorBlocksBoth(): void
    {
        $character = $this->character();
        $this->own($character, 'tcoe:barrier-tattoo-rare');
        $this->own($character, 'srd-5.1:magic-item:bracers-of-defense');
        $this->own($character, 'phb2014-magic-shield-plus-1');
        self::assertSame(20, $this->calculate($character)['value']); // 15 + 2 + shield 2 + 1
        $this->own($character, 'phb2014-magic-armor-leather-plus-1');
        self::assertSame(18, $this->calculate($character)['value']); // 11 + 3 + 1 + 2 + 1
    }

    public function testAllPhysicalEnchantmentsDoNotDoubleCountAndProtectionItemsStack(): void
    {
        foreach ([1, 2, 3] as $bonus) {
            $character = $this->character();
            $this->own($character, 'phb2014-magic-armor-plate-plus-'.$bonus);
            $this->own($character, 'phb2014-magic-shield-plus-'.$bonus);
            $this->own($character, 'srd-5.1:magic-item:ring-of-protection');
            $this->own($character, 'srd-5.1:magic-item:cloak-of-protection');
            self::assertSame(22 + 2 * $bonus, $this->calculate($character)['value']);
        }
    }

    public function testStaffAndRobeRequireEligibleClassInMulticlassAndActualHolding(): void
    {
        $character = $this->character();
        $staff = $this->own($character, 'srd-5.1:magic-item:staff-of-power');
        $this->own($character, 'srd-5.1:magic-item:robe-of-the-archmagi');
        self::assertSame(13, $this->calculate($character)['value']);
        $character->addClassLevel(new CharacterClassLevel($character, new CharacterClass('fighter', 'Fighter', 10, 2), 1));
        $character->addClassLevel(new CharacterClassLevel($character, new CharacterClass('wizard', 'Wizard', 6, 2), 2));
        self::assertSame(20, $this->calculate($character)['value']); // robe 15 + DEX 3 + staff 2
        $staff->setEquipped(false);
        self::assertSame(18, $this->calculate($character)['value']);
    }

    public function testAmbiguousAndCircumstantialDefinitionsAreNotApproximated(): void
    {
        $rules = Dnd2014ArmorClassCatalogue::rules();
        foreach (['defender', 'arrow-catching-shield', 'rod-of-alertness', 'ioun-stone', 'armor'] as $key) {
            self::assertArrayNotHasKey('srd-5.1:magic-item:'.$key, $rules);
            self::assertArrayHasKey('srd-5.1:magic-item:'.$key, Dnd2014ArmorClassCatalogue::limitations());
        }
        $character = $this->character();
        $this->own($character, 'srd-5.1:magic-item:armor-1');
        self::assertFalse($this->calculate($character)['complete']);
    }

    public function testPlannerPreservesCustomAndExistingConflictingMechanics(): void
    {
        $key = 'phb2014-magic-armor-plate-plus-1';
        $row = ['id' => 57, 'import_key' => $key, 'origin' => 'OFFICIAL', 'edition' => '2014', 'owner_id' => null,
            'campaign_id' => null, 'archived_at' => null, ...Dnd2014ArmorClassCatalogue::rules()[$key]];
        $planner = new ArmorClassCatalogueSynchronizer();
        $entry = static fn ($rows) => array_values(array_filter($planner->plan($rows, []), static fn ($e) => $e['key'] === $key))[0];
        self::assertSame('unchanged', $entry([$row])['status']);
        foreach ([['origin' => 'CUSTOM'], ['owner_id' => 1], ['base_armor_class' => 19], ['armor_class_effects' => []]] as $override) {
            self::assertSame('conflict', $entry([array_replace($row, $override)])['status']);
        }
        self::assertSame('conflict', $entry([$row, $row])['status']);
        self::assertSame('configure', $entry([array_replace($row, ['armor_class_effects' => null])])['status']);
    }
}
