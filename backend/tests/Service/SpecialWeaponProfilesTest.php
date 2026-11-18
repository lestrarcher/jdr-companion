<?php
declare(strict_types=1);
namespace App\Tests\Service;

use App\Entity\{Campaign,Character,CharacterMagicItem,MagicItem,User};
use App\Enum\ReferenceOrigin;
use App\Service\SpecialWeaponProfiles;
use PHPUnit\Framework\TestCase;

final class SpecialWeaponProfilesTest extends TestCase
{
    private function item(string $slug): MagicItem
    {
        $item=new MagicItem(null,$slug,null,null,ReferenceOrigin::Official);
        $item->setImportMetadata('srd-5.1:magic-item:'.$slug,'SRD 5.1');
        $item->classify('weapon',true,true,'hand','one');
        return $item;
    }
    public function testDragonSlayerVariantsAreIndependent(): void
    {
        $definition=$this->item('dragon-slayer');
        $character=new Character(new Campaign(new User(),'test','Test'),'x','X',Character::TYPE_PLAYER);
        $longsword=new CharacterMagicItem($character,$definition);
        $rapier=new CharacterMagicItem($character,$definition);
        $longsword->setBaseWeaponKey('longsword');
        $rapier->setBaseWeaponKey('rapier');
        self::assertSame('longsword',$longsword->getBaseWeaponKey());
        self::assertSame('rapier',$rapier->getBaseWeaponKey());
        self::assertNull($definition->getWeaponProfile());
        self::assertSame('1d8',SpecialWeaponProfiles::profile($definition,'longsword')['dice']);
        self::assertContains('finesse',SpecialWeaponProfiles::profile($definition,'rapier')['properties']);
        self::assertSame(1,SpecialWeaponProfiles::profile($definition,'rapier')['attackBonus']);
    }
    public function testVorpalSwordRejectsPiercingSword(): void
    {
        $definition=$this->item('vorpal-sword');
        self::assertNotContains('rapier',array_column(SpecialWeaponProfiles::choices($definition),'key'));
        $this->expectException(\InvalidArgumentException::class);
        SpecialWeaponProfiles::profile($definition,'rapier');
    }
    public function testFixedWeaponsDoNotRequireBaseChoice(): void
    {
        $definition=$this->item('scimitar-of-speed');
        self::assertSame([],SpecialWeaponProfiles::choices($definition));
        self::assertSame(2,SpecialWeaponProfiles::profile($definition,null)['damageBonus']);
    }
    public function testSunBladeIsNotVersatile(): void
    {
        $profile=SpecialWeaponProfiles::profile($this->item('sun-blade'),null);
        self::assertSame('1d8',$profile['dice']);
        self::assertArrayNotHasKey('versatile',$profile);
        self::assertContains('finesse',$profile['properties']);
        self::assertSame(2,$profile['attackBonus']);
        self::assertSame(2,$profile['damageBonus']);
    }

    public function testAllSpecialWeaponProfilesAndChoicesAreValid(): void
    {
        $fixed=['oathbow','scimitar-of-speed','dagger-of-venom','javelin-of-lightning',
            'dwarven-thrower','hammer-of-thunderbolts','mace-of-disruption',
            'mace-of-smiting','mace-of-terror','trident-of-fish-command','sun-blade'];
        $variable=['vicious-weapon','frost-brand','defender','berserker-axe','luck-blade',
            'dragon-slayer','giant-slayer','holy-avenger','nine-lives-stealer',
            'sword-of-sharpness','flame-tongue','dancing-sword','sword-of-wounding',
            'sword-of-life-stealing','vorpal-sword'];
        foreach ($fixed as $slug) {
            $item=$this->item($slug);
            self::assertTrue(SpecialWeaponProfiles::isFixed($item),$slug);
            self::assertNotNull(SpecialWeaponProfiles::profile($item,null),$slug);
            self::assertSame([],SpecialWeaponProfiles::choices($item),$slug);
        }
        foreach ($variable as $slug) {
            $item=$this->item($slug);
            self::assertTrue(SpecialWeaponProfiles::isVariable($item),$slug);
            $choices=SpecialWeaponProfiles::choices($item);
            self::assertNotEmpty($choices,$slug);
            foreach ($choices as $choice) {
                self::assertNotNull(SpecialWeaponProfiles::profile($item,$choice['key']),$slug.' / '.$choice['key']);
            }
        }
    }

}
