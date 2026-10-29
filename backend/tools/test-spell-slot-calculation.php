<?php

declare(strict_types=1);

// Pure domain tests: no kernel, database, or saved characters.
use App\Entity\{Campaign, Character, CharacterClass, CharacterClassLevel, CharacterSubclass, User};
use App\Enum\SpellcastingProgressionType as Progression;
use App\Service\CharacterSpellSlotCalculator;

require __DIR__.'/../vendor/autoload.php';
$campaign = new Campaign(new User(), 'slots', 'Slots');
$calculator = new CharacterSpellSlotCalculator();
$checks = 0;
$check = static function (array $actual, array $expected, string $label) use (&$checks): void {
    if ($actual !== $expected) throw new RuntimeException($label.': expected '.json_encode($expected).', got '.json_encode($actual));
    ++$checks;
};
$make = static function (array $build) use ($campaign): Character {
    $character = new Character($campaign, 'test', 'Test', Character::TYPE_PLAYER);
    foreach ($build as $index => [$progression, $count]) {
        $class = new CharacterClass('class-'.$index, 'Class '.$index, 8, 3, $progression === Progression::Third ? Progression::None : $progression);
        (new ReflectionProperty(CharacterClass::class, 'id'))->setValue($class, $index + 1);
        $subclass = $progression === Progression::Third
            ? (new CharacterSubclass($class, 'third-'.$index, 'Third'))->setSpellcastingProgression(Progression::Third) : null;
        for ($i = 1; $i <= $count; ++$i) {
            $character->addClassLevel(new CharacterClassLevel($character, $class, $character->getTotalLevel()+1, $i === 3 ? $subclass : null));
        }
    }
    return $character;
};
// Regression: ranger 3 / paladin 1 still uses ranger's class table.
$check($calculator->calculate($make([[Progression::Half,3],[Progression::Half,1]])), [1=>3], 'Half 3 + half 1');

// Published full-caster table, independent of the implementation's private constant.
$rows = ['','2','3','4,2','4,3','4,3,2','4,3,3','4,3,3,1','4,3,3,2','4,3,3,3,1','4,3,3,3,2',
    '4,3,3,3,2,1','4,3,3,3,2,1','4,3,3,3,2,1,1','4,3,3,3,2,1,1','4,3,3,3,2,1,1,1','4,3,3,3,2,1,1,1',
    '4,3,3,3,2,1,1,1,1','4,3,3,3,3,1,1,1,1','4,3,3,3,3,2,1,1,1','4,3,3,3,3,2,2,1,1'];
$table = static fn(int $level): array => $level === 0 ? [] : array_combine(range(1,count(explode(',',$rows[$level]))), array_map('intval',explode(',',$rows[$level])));
$single = [
    'full'=>range(1,20),
    'half'=>[0,1,2,2,3,3,4,4,5,5,6,6,7,7,8,8,9,9,10,10],
    'artificer'=>[1,1,2,2,3,3,4,4,5,5,6,6,7,7,8,8,9,9,10,10],
    'third'=>[0,0,1,2,2,2,3,3,3,4,4,4,5,5,5,6,6,6,7,7],
    'none'=>array_fill(0,20,0), 'pact'=>array_fill(0,20,0),
];
foreach (Progression::cases() as $progression) {
    for ($level=1; $level<=20; ++$level) {
        $expected = $table($single[$progression->value][$level-1]);
        $check($calculator->calculate($make([[$progression,$level]])), $expected, $progression->value.' '.$level);
        if ($level < 20) {
            foreach ([Progression::None,Progression::Pact,Progression::Half] as $dip) {
                $check($calculator->calculate($make([[$progression,$level],[$dip,1]])), $expected, $progression->value.' '.$level.' + '.$dip->value.' 1');
            }
        }
    }
}
// All valid two-class combinations through total level 20, with Spellcasting in both classes.
$minimum = ['full'=>1,'half'=>2,'artificer'=>1,'third'=>3];
foreach ([Progression::Full,Progression::Half,Progression::Artificer,Progression::Third] as $first) {
    foreach ([Progression::Full,Progression::Half,Progression::Artificer,Progression::Third] as $second) {
        for ($a=$minimum[$first->value]; $a<20; ++$a) {
            for ($b=$minimum[$second->value]; $a+$b<=20; ++$b) {
                $contribution = static fn(Progression $type,int $level): int => match($type) {
                    Progression::Full=>$level, Progression::Half=>intdiv($level,2),
                    Progression::Artificer=>intdiv($level+1,2), Progression::Third=>intdiv($level,3), default=>0,
                };
                $check($calculator->calculate($make([[$first,$a],[$second,$b]])), $table($contribution($first,$a)+$contribution($second,$b)), "Multi {$first->value} $a / {$second->value} $b");
            }
        }
    }
}
echo "PASS: $checks spell-slot calculation assertions; no database.\n";
