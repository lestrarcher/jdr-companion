<?php
declare(strict_types=1);
namespace App\Service;

/** Fixed subclass spells for the 2014 rules (PHB, XGtE and TCoE).
 * Keys are explicit reference slugs, never runtime name matching.
 * Choice-dependent features (Land terrain, Divine Soul affinity, Genie kind)
 * deliberately do not grant every alternative at once.
 */
final class Dnd2014SubclassSpellCatalogue
{
    public static function entries(): array
    {
        $groups = [
            ['warlock', 'add-to-list', [1,3,5,7,9], [
                'archfey' => ['faerie-fire sleep','calm-emotions phantasmal-force','blink plant-growth','dominate-beast greater-invisibility','dominate-person seeming'],
                'fiend' => ['burning-hands command','blindness-deafness scorching-ray','fireball stinking-cloud','fire-shield wall-of-fire','flame-strike hallow'],
                'great-old-one' => ['dissonant-whispers tasha-s-hideous-laughter','detect-thoughts phantasmal-force','clairvoyance sending','dominate-beast evard-s-black-tentacles','dominate-person telekinesis'],
                'celestial' => ['cure-wounds guiding-bolt','flaming-sphere lesser-restoration','daylight revivify','guardian-of-faith wall-of-fire','flame-strike greater-restoration'],
                'hexblade' => ['shield wrathful-smite','blur branding-smite','blink elemental-weapon','phantasmal-killer staggering-smite','banishing-smite cone-of-cold'],
                'fathomless' => ['create-or-destroy-water thunderwave','gust-of-wind silence','lightning-bolt sleet-storm','control-water summon-elemental','bigby-s-hand cone-of-cold'],
                'undead' => ['bane false-life','blindness-deafness phantasmal-force','phantom-steed speak-with-dead','death-ward greater-invisibility','antilife-shell cloudkill'],
                'genie' => ['detect-evil-and-good','phantasmal-force','create-food-and-water','phantasmal-killer','creation'],
            ]],
            ['sorcerer', 'grant-known', [1,3,5,7,9], [
                'aberrant-mind' => ['arms-of-hadar dissonant-whispers mind-sliver','calm-emotions detect-thoughts','hunger-of-hadar sending','evard-s-black-tentacles summon-aberration','rary-s-telepathic-bond telekinesis'],
                'clockwork-soul' => ['alarm protection-from-evil-and-good','aid lesser-restoration','dispel-magic protection-from-energy','freedom-of-movement summon-construct','greater-restoration wall-of-force'],
            ]],
            ['cleric', 'grant-always-prepared', [1,3,5,7,9], [
                'knowledge-domain' => ['command identify','augury suggestion','nondetection speak-with-dead','arcane-eye confusion','legend-lore scrying'],
                'life-domain' => ['bless cure-wounds','lesser-restoration spiritual-weapon','beacon-of-hope revivify','death-ward guardian-of-faith','mass-cure-wounds raise-dead'],
                'light-domain' => ['burning-hands faerie-fire','flaming-sphere scorching-ray','daylight fireball','guardian-of-faith wall-of-fire','flame-strike scrying'],
                'nature-domain' => ['animal-friendship speak-with-animals','barkskin spike-growth','plant-growth wind-wall','dominate-beast grasping-vine','insect-plague tree-stride'],
                'tempest-domain' => ['fog-cloud thunderwave','gust-of-wind shatter','call-lightning sleet-storm','control-water ice-storm','destructive-wave insect-plague'],
                'trickery-domain' => ['charm-person disguise-self','mirror-image pass-without-trace','blink dispel-magic','dimension-door polymorph','dominate-person modify-memory'],
                'war-domain' => ['divine-favor shield-of-faith','magic-weapon spiritual-weapon','crusader-s-mantle spirit-guardians','freedom-of-movement stoneskin','flame-strike hold-monster'],
                'forge-domain' => ['identify searing-smite','heat-metal magic-weapon','elemental-weapon protection-from-energy','fabricate wall-of-fire','animate-objects creation'],
                'grave-domain' => ['bane false-life','gentle-repose ray-of-enfeeblement','revivify vampiric-touch','blight death-ward','antilife-shell raise-dead'],
                'order-domain' => ['command heroism','hold-person zone-of-truth','mass-healing-word slow','compulsion locate-creature','commune dominate-person'],
                'peace-domain' => ['heroism sanctuary','aid warding-bond','beacon-of-hope sending','aura-of-purity otiluke-s-resilient-sphere','greater-restoration rary-s-telepathic-bond'],
                'twilight-domain' => ['faerie-fire sleep','moonbeam see-invisibility','aura-of-vitality leomund-s-tiny-hut','aura-of-life greater-invisibility','circle-of-power mislead'],
            ]],
            ['paladin', 'grant-always-prepared', [3,5,9,13,17], [
                'ancients' => ['ensnaring-strike speak-with-animals','moonbeam misty-step','plant-growth protection-from-energy','ice-storm stoneskin','commune-with-nature tree-stride'],
                'devotion' => ['protection-from-evil-and-good sanctuary','lesser-restoration zone-of-truth','beacon-of-hope dispel-magic','freedom-of-movement guardian-of-faith','commune flame-strike'],
                'vengeance' => ['bane hunter-s-mark','hold-person misty-step','haste protection-from-energy','banishment dimension-door','hold-monster scrying'],
                'conquest' => ['armor-of-agathys command','hold-person spiritual-weapon','bestow-curse fear','dominate-beast stoneskin','cloudkill dominate-person'],
                'redemption' => ['sanctuary sleep','calm-emotions hold-person','counterspell hypnotic-pattern','otiluke-s-resilient-sphere stoneskin','hold-monster wall-of-force'],
                'glory' => ['guiding-bolt heroism','enhance-ability magic-weapon','haste protection-from-energy','compulsion freedom-of-movement','commune flame-strike'],
                'watchers' => ['alarm detect-magic','moonbeam see-invisibility','counterspell nondetection','aura-of-purity banishment','hold-monster scrying'],
            ]],
            ['ranger', 'grant-known', [3,5,9,13,17], [
                'gloom-stalker' => ['disguise-self','rope-trick','fear','greater-invisibility','seeming'],
                'horizon-walker' => ['protection-from-evil-and-good','misty-step','haste','banishment','teleportation-circle'],
                'monster-slayer' => ['protection-from-evil-and-good','zone-of-truth','magic-circle','banishment','hold-monster'],
                'fey-wanderer' => ['charm-person','misty-step','dispel-magic','dimension-door','mislead'],
                'swarmkeeper' => ['faerie-fire','web','gaseous-form','arcane-eye','insect-plague'],
            ]],
            ['artificer', 'grant-always-prepared', [3,5,9,13,17], [
                'alchemist' => ['healing-word ray-of-sickness','flaming-sphere melf-s-acid-arrow','gaseous-form mass-healing-word','blight death-ward','cloudkill raise-dead'],
                'armorer' => ['magic-missile thunderwave','mirror-image shatter','hypnotic-pattern lightning-bolt','fire-shield greater-invisibility','passwall wall-of-force'],
                'artillerist' => ['shield thunderwave','scorching-ray shatter','fireball wind-wall','ice-storm wall-of-fire','cone-of-cold wall-of-force'],
                'battle-smith' => ['heroism shield','branding-smite warding-bond','aura-of-vitality conjure-barrage','aura-of-purity fire-shield','banishing-smite mass-cure-wounds'],
            ]],
            ['druid', 'grant-always-prepared', [3,5,7,9], [
                'circle-of-spores' => ['blindness-deafness gentle-repose','animate-dead gaseous-form','blight confusion','cloudkill contagion'],
            ]],
            ['druid', 'grant-always-prepared', [2,3,5,7,9], [
                'circle-of-wildfire' => ['burning-hands cure-wounds','flaming-sphere scorching-ray','plant-growth revivify','aura-of-life fire-shield','flame-strike mass-cure-wounds'],
            ]],
        ];
        $entries = [];
        foreach ($groups as [$class, $mode, $levels, $subclasses]) {
            foreach ($subclasses as $subclass => $rows) {
                foreach ($rows as $index => $spells) {
                    foreach (explode(' ', $spells) as $spell) $entries[] = [$class, $subclass, $levels[$index], $spell, $mode];
                }
            }
        }
        return [...$entries,
            ['warlock','genie',17,'wish','add-to-list'],
            ['warlock','fathomless',10,'evard-s-black-tentacles','grant-known'],
            ['ranger','fey-wanderer',11,'summon-fey','grant-known'],
            ['warlock','celestial',1,'light','grant-known'],
            ['warlock','celestial',1,'sacred-flame','grant-known'],
            ['sorcerer','shadow-magic',3,'darkness','grant-known'],
            ['cleric','light-domain',1,'light','grant-known'],
            ['cleric','grave-domain',1,'spare-the-dying','grant-known'],
            ['ranger','swarmkeeper',3,'mage-hand','grant-known'],
            ['druid','circle-of-spores',2,'chill-touch','grant-known'],
            ['druid','circle-of-stars',2,'guidance','grant-known'],
            ['druid','circle-of-stars',2,'guiding-bolt','grant-always-prepared'],
        ];
    }
}
