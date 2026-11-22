<?php
declare(strict_types=1);
namespace App\Tests\Service;
use App\Entity\{Campaign, Character, CharacterMagicItem, MagicItem};
use App\Enum\Ability;
use App\Service\{CharacterAbilityCalculator, CharacterWeaponCalculator, WeaponProfileSchema};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CharacterWeaponCalculatorTest extends TestCase
{
    private function profiles(): array { return json_decode(file_get_contents(__DIR__.'/../../data/weapon-profiles-2014.json'), true, 512, JSON_THROW_ON_ERROR); }
    public function testOnlyOfficialOrdinaryFocusStavesReuseQuarterstaffProfile(): void
    {
        $character = new Character(new Campaign(new \App\Entity\User(), 'test', 'Test'), 'x', 'X', Character::TYPE_PLAYER);
        $character->getAbilityScore(Ability::Strength)->setBaseValue(16);
        foreach (['Wooden staff','Staff','Wand','Rod'] as $reference) {
            $item = new MagicItem(null, $reference, null, null, \App\Enum\ReferenceOrigin::Official);
            $item->classify('gear',false,false); $item->setReferenceDetails($reference,null,null);
            $character->addMagicItem(new CharacterMagicItem($character,$item));
        }
        $custom = new MagicItem($character->getCampaign(),'Bâton',null);
        $custom->classify('gear',false,false); $custom->setReferenceDetails('Staff',null,null);
        $character->addMagicItem(new CharacterMagicItem($character,$custom));
        $rows = (new CharacterWeaponCalculator(new CharacterAbilityCalculator()))->calculate($character, ['proficiencies'=>[['type'=>'WEAPON','slug'=>'quarterstaff']]]);
        self::assertCount(4,$rows);
        foreach (array_slice($rows, 0, 2) as $row) {
            self::assertSame(5,$row['attackBonus']); self::assertSame('1d6+3 contondant',$row['damage']);
            self::assertContains('Polyvalente (1d8+3)',$row['notes']);
        }
        foreach ($character->getMagicItems() as $owned) {
            self::assertSame('gear',$owned->getMagicItem()->getCategory());
            self::assertNull($owned->getMagicItem()->getWeaponProfile());
        }
    }
    public static function cases(): array
    {
        return [
            ['longsword',16,12,true,0,5,'1d8+3 tranchant','strength'],
            ['longsword',16,12,false,0,3,'1d8+3 tranchant','strength'],
            ['dagger',12,18,true,0,6,'1d4+4 perforant','dexterity'],
            ['dagger',18,12,true,0,6,'1d4+4 perforant','strength'],
            ['shortbow',18,14,true,0,4,'1d6+2 perforant','dexterity'],
            ['handaxe',16,18,true,0,5,'1d6+3 tranchant','strength'],
            ['dart',18,12,true,0,6,'1d4+4 perforant','strength'],
            ['longsword',16,12,true,1,6,'1d8+4 tranchant','strength'],
            ['longsword',16,12,true,2,7,'1d8+5 tranchant','strength'],
            ['longsword',16,12,true,3,8,'1d8+6 tranchant','strength'],
            ['mace',8,12,true,0,1,'1d6-1 contondant','strength'],
            ['blowgun',10,14,true,0,4,'1+2 perforant','dexterity'],
        ];
    }
    #[DataProvider('cases')]
    public function testTotals(string $key, int $str, int $dex, bool $proficient, int $magic, int $attack, string $damage, string $ability): void
    {
        $character = new Character(new Campaign(new \App\Entity\User(), 'test', 'Test'), 'test', 'Test', Character::TYPE_PLAYER);
        $character->getAbilityScore(Ability::Strength)->setBaseValue($str);
        $character->getAbilityScore(Ability::Dexterity)->setBaseValue($dex);
        $p = $this->profiles()['phb2014-weapon-'.$key]; $p['attackBonus'] = $p['damageBonus'] = $magic;
        $item = new MagicItem($character->getCampaign(), 'Weapon', null); $item->classify('weapon', $magic>0, true, 'hand','one'); $item->configureWeaponProfile($p);
        $owned = new CharacterMagicItem($character, $item); $character->addMagicItem($owned);
        $training = ['proficiencies'=>$proficient ? [['slug'=>$p['group'],'type'=>'WEAPON_GROUP']] : []];
        $calculator = new CharacterWeaponCalculator(new CharacterAbilityCalculator());
        foreach ([false,true] as $equipped) {
            $owned->setEquipped($equipped);
            $row = $calculator->calculate($character,$training)[0];
            self::assertSame($attack,$row['attackBonus']); self::assertSame($damage,$row['damage']);
            self::assertSame($ability,$row['ability']); self::assertSame($equipped,$row['equipped']);
            if ($key==='longsword') self::assertContains('Polyvalente (1d10+'.(intdiv($str-10,2)+$magic).')',$row['notes']);
        }
        if ($proficient) {
            $specific = ['proficiencies'=>[['slug'=>$p['proficiency'],'type'=>'WEAPON']]];
            self::assertSame($attack,$calculator->calculate($character,$specific)[0]['attackBonus']);
        }
    }
    public function testIncompleteCustomDefinitionAndNoFabricatedAbility(): void
    {
        $character = new Character(new Campaign(new \App\Entity\User(), 'test', 'Test'),'x','X',Character::TYPE_PLAYER);
        $character->getAbilityScores()->clear();
        $item = new MagicItem($character->getCampaign(),'Custom weapon',null); $item->classify('weapon',false,true,'hand','one');
        $character->addMagicItem(new CharacterMagicItem($character,$item));
        $calc = new CharacterWeaponCalculator(new CharacterAbilityCalculator());
        self::assertNull($calc->calculate($character,[])[0]['attackBonus']);
        $item->configureWeaponProfile($this->profiles()['phb2014-weapon-dagger']);
        self::assertFalse($calc->calculate($character,[])[0]['complete']);
        self::assertCount(0,$character->getAbilityScores());
    }
    public function testIntrinsicBonusDoesNotStackWithInfusion(): void
    {
        $character = new Character(new Campaign(new \App\Entity\User(), 'test', 'Test'),'x','X',Character::TYPE_PLAYER);
        $character->getAbilityScore(Ability::Strength)->setBaseValue(16);
        $item = new MagicItem($character->getCampaign(),'Weapon',null); $item->classify('weapon',false,true,'hand','one');
        $p = $this->profiles()['phb2014-weapon-longsword']; $p['attackBonus']=$p['damageBonus']=2; $item->configureWeaponProfile($p);
        $owned = new CharacterMagicItem($character,$item); $character->addMagicItem($owned);
        $calc = new CharacterWeaponCalculator(new CharacterAbilityCalculator());
        $infusions = [''=>['name'=>'Infusion','creatorName'=>'Other','weaponBonuses'=>['attack'=>2,'damage'=>2]]];
        self::assertSame(5,$calc->calculate($character,[],$infusions)[0]['attackBonus']);
        self::assertSame('1d8+5 tranchant',$calc->calculate($character,[],$infusions)[0]['damage']);
    }
    public function testReferenceProfilesAreValidAndInvalidBonusesRejected(): void
    {
        foreach ($this->profiles() as $p) self::assertSame($p,WeaponProfileSchema::validate($p));
        $p['attackBonus']=99;
        $this->expectException(\InvalidArgumentException::class); WeaponProfileSchema::validate($p);
    }
}
