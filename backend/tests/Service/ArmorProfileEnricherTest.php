<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\{ArmorProfileEnricher, Dnd2014ArmorProfiles};
use App\Entity\MagicItem;
use App\Enum\ReferenceOrigin;
use PHPUnit\Framework\TestCase;

final class ArmorProfileEnricherTest extends TestCase
{
    private function row(string $key = 'srd-5.1:magic-item:demon-armor'): array
    {
        return ['id' => 1, 'name' => 'Nom libre, jamais utilisé pour la correspondance', 'origin' => 'OFFICIAL', 'owner_id' => null,
            'campaign_id' => null, 'edition' => '2014', 'import_key' => $key, 'category' => 'armor', 'equippable' => true,
            'equipment_slot' => 'armor', 'hand_usage' => null, 'armor_type' => null, 'base_armor_class' => null,
            'armor_strength_requirement' => null, 'armor_stealth_disadvantage' => null];
    }

    public function testPreciseMagicMappingsAgreeWithCheckedInSrd(): void
    {
        $source = array_column(json_decode(file_get_contents(dirname(__DIR__, 2).'/data/srd-magic-items-2014.json'), true, 512, JSON_THROW_ON_ERROR), null, 'index');
        $labels = ['plate-armor' => 'plate', 'chain-shirt' => 'chain shirt', 'scale-mail' => 'scale mail', 'studded-leather-armor' => 'studded leather', 'shield' => 'shield'];
        $count = 0;
        foreach (Dnd2014ArmorProfiles::mappings() as $key => $profile) {
            if (!str_starts_with($key, 'srd-5.1:magic-item:')) continue;
            $index = substr($key, strlen('srd-5.1:magic-item:'));
            self::assertStringStartsWith('Armor ('.$labels[$profile].'),', $source[$index]['desc'][0]);
            ++$count;
        }
        self::assertSame(22, $count);
    }

    public function testAmbiguousArmorAndPersonalOrDifferentEditionAreNeverInferred(): void
    {
        $planner = new ArmorProfileEnricher();
        foreach (['armor','armor-1','armor-2','armor-3','adamantine-armor','mithral-armor','armor-of-resistance'] as $key) {
            self::assertSame('skipped', $planner->plan([$this->row('srd-5.1:magic-item:'.$key)])[0]['status']);
        }
        foreach ([['import_key' => null, 'name' => 'Plate Armor'], ['origin' => 'CUSTOM', 'owner_id' => 1], ['edition' => '2024'], ['campaign_id' => 1]] as $override) {
            self::assertSame('skipped', $planner->plan([array_replace($this->row(), $override)])[0]['status']);
        }
    }

    public function testExistingValuesAndDuplicateKeysBlockRatherThanOverwrite(): void
    {
        $planner = new ArmorProfileEnricher();
        foreach ([['base_armor_class' => 19], ['armor_stealth_disadvantage' => false], ['category' => 'other'], ['equipment_slot' => 'free']] as $override) {
            self::assertSame('conflict', $planner->plan([array_replace($this->row(), $override)])[0]['status']);
        }
        $rows = [$this->row(), array_replace($this->row(), ['id' => 2, 'category' => 'other'])];
        self::assertSame(['conflict', 'conflict'], array_column($planner->plan($rows), 'status'));
    }

    public function testEnchantedArmorUsesPhysicalBaseAndRerunIsUnchanged(): void
    {
        $planner = new ArmorProfileEnricher();
        $entry = $planner->plan([$this->row()])[0];
        self::assertSame('update', $entry['status']);
        self::assertSame(18, $entry['values']['base_armor_class']); // Demon armor's +1 is deliberately excluded.
        self::assertSame(15, $entry['values']['armor_strength_requirement']);
        self::assertSame('unchanged', $planner->plan([array_replace($this->row(), $entry['values'])])[0]['status']);
    }

    public function testAllOrdinaryPhysicalProfilesAndDerivedDexterity(): void
    {
        self::assertCount(13, Dnd2014ArmorProfiles::PROFILES);
        foreach (Dnd2014ArmorProfiles::PROFILES as $key => [$type, $base, $strength, $stealth]) {
            $item = new MagicItem(null, $key, null, null, ReferenceOrigin::Official);
            $item->classify($type === 'shield' ? 'shield' : 'armor', false, true, $type === 'shield' ? 'hand' : 'armor', $type === 'shield' ? 'one' : null);
            $item->configureArmor($type, $base);
            $item->configureArmorRestrictions($strength, $stealth);
            self::assertSame($strength, $item->getArmorStrengthRequirement());
            self::assertSame($stealth, $item->getArmorStealthDisadvantage());
            self::assertSame(match ($type) { 'light' => 'full', 'medium' => 'capped', default => 'none' }, $item->getArmorDexterityMode());
            self::assertSame($type === 'medium' ? 2 : null, $item->getArmorDexterityCap());
        }
    }

    public function testChangingPhysicalModelClearsStaleRestrictions(): void
    {
        $item = new MagicItem(null, 'Armor', null, null, ReferenceOrigin::Official);
        $item->classify('armor', false, true, 'armor');
        $item->configureArmor('heavy', 18);
        $item->configureArmorRestrictions(15, true);
        $item->configureArmor('heavy', 18);
        self::assertSame(15, $item->getArmorStrengthRequirement());
        $item->configureArmor('light', 11);
        self::assertNull($item->getArmorStrengthRequirement());
        self::assertNull($item->getArmorStealthDisadvantage());
    }
}
