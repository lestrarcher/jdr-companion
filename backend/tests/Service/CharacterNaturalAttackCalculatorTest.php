<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\{Campaign, Character, CharacterClass, CharacterClassLevel, CharacterFeat, CharacterFeatureDefinition, CharacterFeatureRule, CharacterMagicItem, Feat, MagicItem, User};
use App\Enum\Ability;
use App\Service\{CharacterAbilityCalculator, CharacterWeaponCalculator, FeatureAttackEffectSchema};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CharacterNaturalAttackCalculatorTest extends TestCase
{
    private function character(int $monk = 0, int $other = 0): Character
    {
        $character = new Character(new Campaign(new User(), 'test', 'Test'), 'hero', 'Hero', Character::TYPE_PLAYER);
        $character->getAbilityScore(Ability::Strength)->setBaseValue(14);
        $character->getAbilityScore(Ability::Dexterity)->setBaseValue(18);
        $class = new CharacterClass('monk', 'Monk', 8, 3);
        for ($i = 1; $i <= $monk; ++$i) $character->addClassLevel(new CharacterClassLevel($character, $class, $i));
        $class = new CharacterClass('fighter', 'Fighter', 10, 2);
        for ($i = 1; $i <= $other; ++$i) $character->addClassLevel(new CharacterClassLevel($character, $class, $monk + $i));
        return $character;
    }

    private function rows(Character $character, array $features = [], array $training = []): array
    {
        return array_column((new CharacterWeaponCalculator(new CharacterAbilityCalculator()))->calculate($character, $training, [], $features), null, 'id');
    }

    private function tavern(Character $character): void
    {
        $character->addFeat(new CharacterFeat($character, new Feat('tavern-brawler', 'Bagarreur de tavernes')));
    }

    public function testAutomaticAttacksWithoutInventoryAndRecalculation(): void
    {
        $c = $this->character();
        $rows = $this->rows($c);
        self::assertCount(2, $rows);
        self::assertSame(4, $rows['attack:unarmed']['attackBonus']);
        self::assertSame('1+2 contondant', $rows['attack:unarmed']['damage']);
        self::assertSame(2, $rows['attack:improvised']['attackBonus']);
        self::assertSame('1d4+2 (type au choix du MJ)', $rows['attack:improvised']['damage']);
        self::assertCount(0, $c->getMagicItems());
        $c->getAbilityScore(Ability::Strength)->setBaseValue(8);
        self::assertSame(1, $this->rows($c)['attack:unarmed']['attackBonus']);
        $c->getAbilityScore(Ability::Strength)->setBaseValue(6);
        self::assertSame('0 contondant', $this->rows($c)['attack:unarmed']['damage']);
        $c->getAbilityScores()->clear();
        foreach ($this->rows($c) as $row) { self::assertFalse($row['complete']); self::assertNull($row['attackBonus']); self::assertNull($row['damage']); }
        self::assertCount(0, $c->getAbilityScores());
    }

    public static function monkLevels(): array
    {
        return [[1, 0, '1d4'], [4, 8, '1d4'], [5, 0, '1d6'], [10, 0, '1d6'], [11, 0, '1d8'], [16, 0, '1d8'], [17, 0, '1d10'], [20, 0, '1d10']];
    }

    #[DataProvider('monkLevels')]
    public function testMonkLevelAndTavernDoNotStack(int $monk, int $other, string $dice): void
    {
        $c = $this->character($monk, $other);
        $this->tavern($c);
        $rows = $this->rows($c);
        self::assertSame($dice.'+4 contondant', $rows['attack:unarmed']['damage']);
        self::assertSame(4 + $c->getProficiencyBonus(), $rows['attack:unarmed']['attackBonus']);
        self::assertSame('dexterity', $rows['attack:unarmed']['ability']);
        self::assertSame(2 + $c->getProficiencyBonus(), $rows['attack:improvised']['attackBonus']);
        self::assertSame('strength', $rows['attack:improvised']['ability']);
        $c->getAbilityScore(Ability::Strength)->setBaseValue(20);
        self::assertSame($dice.'+5 contondant', $this->rows($c)['attack:unarmed']['damage']);
    }

    public static function equipmentCases(): array
    {
        return [['armor', null, false], ['shield', null, false], ['weapon', 'longsword', false], ['weapon', 'greatclub', false],
            ['weapon', 'shortbow', false], ['weapon', 'dagger', true], ['weapon', 'shortsword', true], ['weapon', 'quarterstaff', true], ['weapon', null, false]];
    }

    #[DataProvider('equipmentCases')]
    public function testEquipmentRestrictionsAndUnequipping(string $category, ?string $key, bool $allowed): void
    {
        $c = $this->character(5);
        $item = new MagicItem($c->getCampaign(), 'Equipment', null);
        $item->classify($category, false, true, $category === 'armor' ? 'armor' : 'hand', $category === 'armor' ? null : 'one');
        if ($key !== null) {
            $profiles = json_decode(file_get_contents(__DIR__.'/../../data/weapon-profiles-2014.json'), true, 512, JSON_THROW_ON_ERROR);
            $item->configureWeaponProfile($profiles['phb2014-weapon-'.$key]);
        }
        $owned = new CharacterMagicItem($c, $item);
        $c->addMagicItem($owned);
        $owned->setEquipped(true);
        self::assertSame($allowed ? '1d6+4 contondant' : '1+2 contondant', $this->rows($c)['attack:unarmed']['damage']);
        $this->tavern($c);
        self::assertSame($allowed ? '1d6+4 contondant' : '1d4+2 contondant', $this->rows($c)['attack:unarmed']['damage']);
        $owned->setEquipped(false);
        self::assertSame('1d6+4 contondant', $this->rows($c)['attack:unarmed']['damage']);
    }

    public function testGenericEffectsAndDuplicateResolvedFeature(): void
    {
        $c = $this->character(5);
        $feature = (new CharacterFeatureDefinition('custom-claws', 'Claws'))->configureAttackEffects([
            ['key' => 'claws', 'type' => 'natural-attack', 'name' => 'Griffes', 'dice' => '1d4', 'damageType' => 'slashing', 'unarmed' => true, 'attackBonus' => 1],
            ['key' => 'bite', 'type' => 'natural-attack', 'name' => 'Morsure', 'dice' => '1d8', 'damageType' => 'piercing', 'damageBonus' => 2],
            ['key' => 'training', 'type' => 'improvised-proficiency'],
            ['key' => 'fists', 'type' => 'unarmed-damage', 'dice' => '1d10', 'damageType' => 'bludgeoning'],
        ]);
        $rule = CharacterFeatureRule::forCharacter($feature, $c);
        $rows = $this->rows($c, [$rule, $rule]);
        self::assertCount(4, $rows);
        self::assertSame('1d10+4 contondant', $rows['attack:unarmed']['damage']);
        self::assertSame('1d6+4 tranchant', $rows['feature:custom-claws:claws']['damage']);
        self::assertSame(8, $rows['feature:custom-claws:claws']['attackBonus']);
        self::assertSame('1d8+4 perforant', $rows['feature:custom-claws:bite']['damage']);
        self::assertTrue($rows['attack:improvised']['proficient']);
        self::assertCount(2, $this->rows($c));
    }

    public static function invalidEffects(): array
    {
        $valid = ['key' => 'claws', 'type' => 'natural-attack', 'name' => 'Claws', 'dice' => '1d4', 'damageType' => 'slashing'];
        return [[[$valid, $valid]], [[array_replace($valid, ['dice' => '1d4+99'])]], [[array_replace($valid, ['attackBonus' => 1.5])]],
            [[array_replace($valid, ['conditions' => ['unknown']])]], [[array_replace($valid, ['unknown' => true])]], [[array_replace($valid, ['ability' => 'charisma'])]]];
    }

    public function testFeatureEquipmentConditionsAndOrdinaryClawsInArmor(): void
    {
        $c = $this->character();
        $effect = ['key'=>'claws', 'type'=>'natural-attack', 'name'=>'Griffes', 'dice'=>'1d4', 'damageType'=>'slashing', 'unarmed'=>true];
        $feature = (new CharacterFeatureDefinition('claws', 'Claws'))->configureAttackEffects([$effect]);
        $rule = CharacterFeatureRule::forCharacter($feature, $c);
        $armor = new MagicItem($c->getCampaign(), 'Armor', null);
        $armor->classify('armor', false, true, 'armor');
        $owned = new CharacterMagicItem($c, $armor); $c->addMagicItem($owned); $owned->setEquipped(true);
        self::assertSame('1d4+2 tranchant', $this->rows($c, [$rule])['feature:claws:claws']['damage']);
        $feature->configureAttackEffects([array_replace($effect, ['conditions'=>['no-armor']])]);
        self::assertCount(2, $this->rows($c, [$rule]));
        $owned->setEquipped(false);
        self::assertCount(3, $this->rows($c, [$rule]));
        $shield = new MagicItem($c->getCampaign(), 'Shield', null); $shield->classify('shield', false, true, 'hand', 'one');
        $held = new CharacterMagicItem($c, $shield); $c->addMagicItem($held); $held->setEquipped(true);
        $feature->configureAttackEffects([array_replace($effect, ['conditions'=>['no-shield']])]);
        self::assertCount(2, $this->rows($c, [$rule]));
        $feature->configureAttackEffects([array_replace($effect, ['conditions'=>['free-hand']])]);
        self::assertCount(3, $this->rows($c, [$rule]));
        $gear = new MagicItem($c->getCampaign(), 'Held gear', null); $gear->classify('gear', false, true, 'hand', 'one');
        $second = new CharacterMagicItem($c, $gear); $c->addMagicItem($second); $second->setEquipped(true);
        self::assertCount(2, $this->rows($c, [$rule]));
    }

    #[DataProvider('invalidEffects')]
    public function testInvalidConfigurationIsRejected(array $effects): void
    {
        $this->expectException(\InvalidArgumentException::class);
        FeatureAttackEffectSchema::validate($effects);
    }
}
