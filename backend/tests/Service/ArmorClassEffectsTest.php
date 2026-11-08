<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\{Campaign, Character, CharacterClass, CharacterClassLevel, CharacterMagicItem, MagicItem, User};
use App\Enum\Ability;
use App\Service\{ArmorClassEffectSchema, CharacterAbilityCalculator, CharacterArmorClassCalculator, CharacterEquipmentService};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ArmorClassEffectsTest extends TestCase
{
    public function testPermanentFeatureFormulasCompeteWithItemsAndArmor(): void
    {
        foreach (['dragon-hide', 'draconic-resilience'] as $slug) {
            $feature = new \App\Entity\CharacterFeatureDefinition($slug, $slug);
            $feature->configureArmorClassEffects([['key'=>'natural-armor', 'label'=>'Armure naturelle', 'type'=>'formula', 'value'=>13, 'dexterity'=>'full', 'conditions'=>['no-armor']]]);
            $context = [['identity'=>'feature:'.$slug, 'source'=>$slug, 'effects'=>$feature->getArmorClassEffects()]];
            $character = $this->character();
            self::assertSame(16, $this->calculate($character, $context)['value']);
            self::assertSame(16, $this->calculate($character, [...$context, ...$context])['value']);
            $shield = $this->item($character, [], 'shield', 'hand');
            self::assertSame(18, $this->calculate($character, $context)['value']);
            $armor = $this->item($character, [], 'armor', 'armor');
            self::assertSame(16, $this->calculate($character, $context)['value']);
            (new CharacterEquipmentService())->equip($character, $armor, false);
            self::assertSame(18, $this->calculate($character, $context)['value']);
            $this->item($character, [['key'=>'alternative','label'=>'Alternative','type'=>'formula','value'=>15,'dexterity'=>'capped','dexterityCap'=>2,'conditions'=>['no-armor']]]);
            $result = $this->calculate($character, $context);
            self::assertSame(19, $result['value']);
            self::assertCount(1, array_filter($result['contributions'], fn ($p) => $p['kind'] === 'base'));
        }
    }

    public function testContextIdentitiesAndSharedStackingGroups(): void
    {
        $a = ['identity'=>'feature:a','source'=>'Same display name','effects'=>[$this->bonus()]];
        $b = ['identity'=>'feature:b','source'=>'Same display name','effects'=>[$this->bonus()]];
        self::assertSame(15, $this->calculate($this->character(), [$a,$a,$b])['value']);
        $a['effects'] = $b['effects'] = [$this->bonus(1, [], 'shared')];
        self::assertSame(14, $this->calculate($this->character(), [$a,$b])['value']);
    }

    private function character(): Character
    {
        $character = new Character($this->createMock(Campaign::class), 'fixture', 'Fixture', Character::TYPE_PLAYER);
        $character->getAbilityScore(Ability::Dexterity)->setBaseValue(16);
        $character->getAbilityScore(Ability::Wisdom)->setBaseValue(18);
        return $character;
    }

    private function calculate(Character $character, array $context = []): array
    {
        $result = (new CharacterArmorClassCalculator(new CharacterAbilityCalculator(), new CharacterEquipmentService()))->calculate($character, $context);
        self::assertSame($result['value'], array_sum(array_column($result['contributions'], 'value')));
        return $result;
    }

    private function bonus(int $value = 1, array $conditions = [], ?string $group = null): array
    {
        return ['key' => 'protection', 'label' => 'Protection fictive', 'type' => 'bonus', 'value' => $value, 'conditions' => $conditions,
            ...($group === null ? [] : ['stackingKey' => $group])];
    }

    private function item(Character $character, array $effects, string $category = 'other', string $slot = 'free'): CharacterMagicItem
    {
        $definition = new MagicItem(null, 'Objet fictif', null, $this->createMock(User::class));
        $definition->classify($category, true, true, $slot, $slot === 'hand' ? 'one' : null);
        if ($category === 'armor') $definition->configureArmor('light', 11);
        if ($category === 'shield') $definition->configureArmor('shield', 2);
        $definition->configureArmorClassEffects($effects);
        $owned = new CharacterMagicItem($character, $definition);
        $character->addMagicItem($owned);
        (new CharacterEquipmentService())->equip($character, $owned, true);
        return $owned;
    }

    public function testMagicArmorShieldAndIndependentBonusesAreAddedOnce(): void
    {
        $character = $this->character();
        $armor = $this->item($character, [$this->bonus(1)], 'armor', 'armor');
        $this->item($character, [$this->bonus(2)], 'shield', 'hand');
        $this->item($character, [$this->bonus(1)]);
        $this->item($character, [$this->bonus(1)]);
        $result = $this->calculate($character);
        self::assertSame(21, $result['value']); // 11 + 3 + 2 + 1 + 2 + 1 + 1
        self::assertCount(7, $result['contributions']);
        self::assertSame($armor->getDisplayName(), $result['source']);
        self::assertSame([], $result['warnings']);
    }

    public function testNoArmorNoShieldConditionsAndImmediateEquipmentRecalculation(): void
    {
        $character = $this->character();
        $bracers = $this->item($character, [$this->bonus(2, ['no-armor', 'no-shield'])]);
        self::assertSame(15, $this->calculate($character)['value']);
        $armor = $this->item($character, [], 'armor', 'armor');
        $result = $this->calculate($character);
        self::assertSame(14, $result['value']);
        self::assertSame('Armure équipée.', $result['excluded'][0]['reason']);
        $equipment = new CharacterEquipmentService();
        $equipment->equip($character, $armor, false);
        self::assertSame(15, $this->calculate($character)['value']);
        $shield = $this->item($character, [], 'shield', 'hand');
        self::assertSame('Bouclier équipé.', $this->calculate($character)['excluded'][0]['reason']);
        $equipment->equip($character, $shield, false);
        $equipment->equip($character, $bracers, false);
        self::assertSame(13, $this->calculate($character)['value']);
        $equipment->equip($character, $bracers, true);
        self::assertSame(15, $this->calculate($character)['value']);
    }

    public function testHeldRequiresAnOccupiedHandAndAttunement(): void
    {
        $character = $this->character();
        $item = $this->item($character, [$this->bonus(2, ['held'])]);
        self::assertSame(13, $this->calculate($character)['value']);
        self::assertSame('Objet non tenu en main.', $this->calculate($character)['excluded'][0]['reason']);
        $item->setEquipped(false);
        $item->getMagicItem()->classify('other', true, true, 'hand', 'one');
        $item->getMagicItem()->setRequiresAttunement(true);
        $item->setEquipped(true);
        self::assertSame('Harmonisation requise.', $this->calculate($character)['excluded'][0]['reason']);
        $item->setAttuned(true);
        self::assertSame(15, $this->calculate($character)['value']);
        $item->setEquipped(false);
        self::assertTrue($item->isAttuned());
        self::assertSame(13, $this->calculate($character)['value']);
    }

    public function testDuplicateCopiesAndSharedGroupsDoNotStack(): void
    {
        $character = $this->character();
        $first = $this->item($character, [$this->bonus()]);
        $copy = new CharacterMagicItem($character, $first->getMagicItem());
        $character->addMagicItem($copy);
        $copy->setEquipped(true);
        self::assertSame(14, $this->calculate($character)['value']);
        $this->item($character, [$this->bonus(1, [], 'exclusive-protection')]);
        $this->item($character, [$this->bonus(3, [], 'exclusive-protection')]);
        $result = $this->calculate($character);
        self::assertSame(17, $result['value']);
        self::assertCount(2, $result['excluded']);
    }

    public function testMageArmorContextCompetesWithUnarmoredDefenseAndAllowsShield(): void
    {
        $character = $this->character();
        $context = [['source' => 'Effet actif fictif', 'effects' => [[
            'key' => 'mage-armor', 'label' => 'Armure de mage', 'type' => 'formula', 'value' => 13,
            'dexterity' => 'full', 'conditions' => ['no-armor'],
        ]]]];
        self::assertSame(13, $this->calculate($character)['value']); // Nothing inferred from known spells.
        self::assertSame(16, $this->calculate($character, $context)['value']);
        $character->addClassLevel(new CharacterClassLevel($character, new CharacterClass('monk', 'Moine', 8, 3), 1));
        self::assertSame(17, $this->calculate($character, $context)['value']);
        $this->item($character, [], 'shield', 'hand');
        self::assertSame(18, $this->calculate($character, $context)['value']);
        $this->item($character, [], 'armor', 'armor');
        self::assertSame(16, $this->calculate($character, $context)['value']);
    }

    public function testItemFormulaAndBonusesWithNegativeDexterity(): void
    {
        $character = $this->character();
        $character->getAbilityScore(Ability::Dexterity)->setBaseValue(8);
        $this->item($character, [[
            'key' => 'alternate', 'label' => 'Protection alternative', 'type' => 'formula', 'value' => 15,
            'dexterity' => 'full', 'conditions' => ['no-armor'],
        ], $this->bonus(2, ['no-armor', 'no-shield'])]);
        self::assertSame(16, $this->calculate($character)['value']);
    }

    public function testMissingConfigurationIsNotInferredFromNameOrDescription(): void
    {
        $character = $this->character();
        $item = $this->item($character, []);
        $item->getMagicItem()->setName('Armure +3')->setDescription('CA +3');
        $item->getMagicItem()->configureArmorClassEffects(null);
        self::assertSame(13, $this->calculate($character)['value']);
        self::assertCount(1, $this->calculate($character)['warnings']);
    }

    public static function invalidEffects(): array
    {
        $valid = ['key' => 'test', 'label' => 'Test', 'type' => 'bonus', 'value' => 1];
        return [
            [[$valid, $valid]], [[array_replace($valid, ['value' => '1'])]],
            [[array_replace($valid, ['conditions' => ['unknown']])]], [[array_replace($valid, ['conditions' => ['armor', 'no-armor']])]],
            [[array_replace($valid, ['conditions' => ['held', 'held']])]], [[array_replace($valid, ['dexterity' => 'full'])]],
            [[array_replace($valid, ['type' => 'formula', 'dexterity' => 'capped'])]],
            [[array_replace($valid, ['type' => 'formula', 'dexterity' => 'full', 'ability' => 'dexterity'])]],
            [[array_replace($valid, ['unexpected' => true])]], [[array_replace($valid, ['stackingKey' => ''])]],
            [['key' => $valid]], [[array_replace($valid, ['conditions' => [5]])]],
        ];
    }

    #[DataProvider('invalidEffects')]
    public function testInvalidConfigurationIsRejected(array $effects): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ArmorClassEffectSchema::validate($effects);
    }
}
