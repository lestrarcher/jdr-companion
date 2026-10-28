<?php

declare(strict_types=1);

require_once __DIR__.'/../src/Service/CharacterSpellCastEligibility.php';
require_once __DIR__.'/../src/Enum/Ability.php';

use App\Service\CharacterSpellCastEligibility;

$service = new CharacterSpellCastEligibility();
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    ++$checks;
    if (!$condition) throw new RuntimeException($message);
};
$entry = static fn (int $level, bool $known, bool $book, bool $prepared, bool $ritual): array => [
    'sourceType'=>'class', 'level'=>$level, 'known'=>$known, 'inSpellbook'=>$book,
    'prepared'=>$prepared, 'alwaysPrepared'=>false, 'details'=>['ritual'=>$ritual],
];
$w = $service->evaluate($entry(1, false, true, false, true), 'spellbook', 'wizard');
$assert(!$w['canCastNormally'] && $w['canCastAsRitual'], 'Wizard unprepared spellbook ritual');
$w = $service->evaluate($entry(1, false, true, true, true), 'spellbook', 'wizard');
$assert($w['canCastNormally'] && $w['canCastAsRitual'], 'Wizard prepared ritual');
$c = $service->evaluate($entry(1, false, false, false, true), 'prepared', 'cleric');
$assert(!$c['canCastNormally'] && !$c['canCastAsRitual'], 'Cleric unprepared ritual');
$c = $service->evaluate($entry(1, false, false, true, true), 'prepared', 'cleric');
$assert($c['canCastNormally'] && $c['canCastAsRitual'], 'Cleric prepared ritual');
$b = $service->evaluate($entry(1, true, false, false, true), 'known', 'bard');
$assert($b['canCastNormally'] && $b['canCastAsRitual'], 'Bard known ritual');
$s = $service->evaluate($entry(1, true, false, false, true), 'known', 'sorcerer');
$assert($s['canCastNormally'] && !$s['canCastAsRitual'], 'Sorcerer ritual-tagged spell');
$cantrip = $service->evaluate($entry(0, true, false, false, false), 'known', 'warlock');
$assert($cantrip['canCastNormally'] && !$cantrip['canCastAsRitual'], 'Known cantrip');
$unknown = $service->evaluate($entry(1, false, false, false, false), 'known', 'warlock');
$assert(!$unknown['canCastNormally'], 'Unknown spell');
$grant = $entry(1, true, false, true, true);
$grant['sourceType'] = 'feature';
$g = $service->evaluate($grant, null, null);
$assert(!$g['canCastNormally'] && !$g['canCastAsRitual'] && $g['requiresGrantReview'], 'Grant requires review');
foreach (['wizard'=>'spellbook','bard'=>'known','cleric'=>'prepared','druid'=>'prepared','sorcerer'=>'known','warlock'=>'known','paladin'=>'prepared','ranger'=>'known','artificer'=>'prepared'] as $slug=>$style) {
    $result = $service->evaluate($entry(1,true,true,true,true),$style,$slug);
    $assert($result['canCastNormally'], 'Normal casting for '.$slug);
    $assert($result['canCastAsRitual'] === in_array($slug,['wizard','bard','cleric','druid','artificer'],true), 'Only explicitly supported ritual features: '.$slug);
    $result = $service->evaluate($entry(1,true,true,true,false),$style,$slug);
    $assert(!$result['canCastAsRitual'] && $result['ritualReason']==='not_a_ritual','Ritual feature cannot replace ritual tag');
}
foreach ([false, true] as $prepared) {
    $result = $service->evaluate($entry(1,false,false,$prepared,true),'prepared','artificer');
    $assert($result['canCastAsRitual'] === $prepared, 'Artificer class-list ritual requires preparation');
}
$specialty = array_replace($entry(1,false,false,false,true), [
    'sourceType'=>'subclass', 'mode'=>'grant-always-prepared', 'alwaysPrepared'=>true,
    'castingAbility'=>'intelligence',
]);
$result = $service->evaluate($specialty,'prepared','artificer');
$assert($result['canCastNormally'] && $result['canCastAsRitual'], 'Artificer specialty always-prepared ritual');
$specialty['details']['ritual'] = false;
$assert(!$service->evaluate($specialty,'prepared','artificer')['canCastAsRitual'], 'Specialty does not supply the ritual tag');
$result = $service->evaluate($entry(1,true,true,false,true),'prepared','artificer');
$assert(!$result['canCastAsRitual'], 'Known or spellbook artificer spell does not replace preparation');
$result = $service->evaluate($entry(1,true,true,true,true),'known','artificer');
$assert(!$result['canCastAsRitual'], 'Artificer ritual requires the prepared policy');
$result = $service->evaluate($entry(0,false,false,true,true),'prepared','cleric');
$assert(!$result['canCastNormally'] && $result['reason']==='cantrip_not_known','Preparation cannot invent a known cantrip');
$result = $service->evaluate($entry(1,false,false,false,true),'known','bard');
$assert(!$result['canCastNormally'] && !$result['canCastAsRitual'],'Unknown bard ritual');
$result = $service->evaluate($entry(1,false,false,true,true),'spellbook','wizard');
$assert(!$result['canCastNormally'] && !$result['canCastAsRitual'],'Wizard needs this source in the spellbook');
foreach (['subclass','feature'] as $source) {
    foreach ([0,1,null] as $level) {
        $grant = array_replace($entry(1,true,true,true,true),['sourceType'=>$source,'level'=>$level]);
        $before = $grant;
        $result = $service->evaluate($grant,'spellbook','wizard');
        $assert(!$result['canCastNormally'] && !$result['canCastAsRitual'] && $result['requiresGrantReview'],'Grant always needs review, including missing level');
        $assert($grant === $before, 'Eligibility never mutates its input');
    }
}
$result = $service->evaluate($entry(1,true,true,true,true),null,'wizard');
$assert($result['reason']==='source_policy_unknown' && !$result['canCastAsRitual'],'No invented policy');
echo "PASS: {$checks} spell casting eligibility assertions; no database.\n";
