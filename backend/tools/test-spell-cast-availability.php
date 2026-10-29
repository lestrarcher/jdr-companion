<?php

declare(strict_types=1);

require __DIR__.'/../src/Service/CharacterSpellCastAvailability.php';
require __DIR__.'/../src/Service/CharacterSpellCastEligibility.php';
$service = new App\Service\CharacterSpellCastAvailability();
$eligibility = new App\Service\CharacterSpellCastEligibility();
$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks): void {
    if (!$ok) throw new RuntimeException($label);
    ++$checks;
};
$pool = static fn(string $slug, ?int $level, bool $active, ?int $remaining, string $status, ?string $reason=null): array => [
    'slug'=>$slug, 'definitionId'=>null, 'level'=>$level, 'applicable'=>$active, 'maximum'=>$active?4:0,
    'remaining'=>$remaining, 'status'=>$status, 'reason'=>$reason, 'issues'=>[],
];
$context = ['standardSlots'=>[], 'pactSlots'=>$pool('warlock-pact-slots',null,false,null,'unavailable','inactive_pool'), 'resources'=>[], 'issues'=>[]];
foreach (range(1,9) as $level) $context['standardSlots'][]=$pool('spell-slot-'.$level,$level,false,null,'unavailable','inactive_pool');
$entry = static function (int $level, string $class='bard', bool $known=true, bool $prepared=false, bool $ritual=false) use ($eligibility): array {
    $row=['key'=>'source:'.$class,'policyId'=>$class==='wizard'?2:1,'sourceType'=>'class','level'=>$level,
        'known'=>$known,'inSpellbook'=>$class==='wizard','prepared'=>$prepared,'details'=>['ritual'=>$ritual]];
    $row['castingEligibility']=$eligibility->evaluate($row,$class==='wizard'?'spellbook':'known',$class);
    return $row;
};
$evaluate = static function (array $entry, array $context) use ($service,$check): array {
    $before=[$entry,$context]; $result=$service->evaluate($entry,$context);
    $check([$entry,$context]===$before,'Inputs unchanged');
    $check($result['sourceKey']===$entry['key'] && $result['policyId']===$entry['policyId'],'Source identity preserved');
    return $result;
};
$usable = static fn(array $result): array => array_values(array_filter($result['methods'],static fn(array $method): bool=>$method['status']==='available'));
$check($evaluate($entry(0),$context)['status']==='available','Cantrip without slots');
$check($evaluate($entry(0,'bard',false),$context)['status']==='unavailable','Unknown cantrip denied');
$ritual=$usable($evaluate($entry(2,'wizard',false,false,true),$context));
$check(count($ritual)===1 && $ritual[0]['kind']==='ritual' && $ritual[0]['castingLevel']===2 && $ritual[0]['cost']===0,'Unprepared wizard ritual at base level');
$check($evaluate($entry(2,'sorcerer',true,false,true),$context)['status']==='unavailable','Ritual tag alone does not suffice');
$artificer = $entry(1,'artificer',false,true,true);
$artificer['castingEligibility'] = $eligibility->evaluate($artificer,'prepared','artificer');
$ritual = $usable($evaluate($artificer,$context));
$check(count($ritual)===1 && $ritual[0]['kind']==='ritual'
    && $ritual[0]['castingLevel']===1 && $ritual[0]['cost']===0 && $ritual[0]['poolSlug']===null,
    'Prepared artificer ritual needs no slot pool and costs zero');
$artificer['prepared'] = false;
$artificer['castingEligibility'] = $eligibility->evaluate($artificer,'prepared','artificer');
$check($usable($evaluate($artificer,$context))===[], 'Unprepared artificer ritual unavailable even with ritual tag');
$context['standardSlots'][0]=$pool('spell-slot-1',1,true,1,'available');
$check($evaluate($entry(2),$context)['status']==='unavailable','Lower slot insufficient');
$context['standardSlots'][2]=$pool('spell-slot-3',3,true,2,'available');
$normal=$usable($evaluate($entry(1),$context));
$check(array_column($normal,'castingLevel')===[1,3],'Equal level and upcasting offered');
$check($evaluate($entry(9),$context)['status']==='unavailable','Ninth-level spell cannot use lower pools');
$context['pactSlots']=$pool('warlock-pact-slots',2,true,1,'available');
$normal=$usable($evaluate($entry(2),$context));
$check(array_column($normal,'kind')===['standard-slot','pact-slot'],'Class spell can use independent pact pool');
$warlock=$usable($evaluate($entry(1,'warlock'),$context));
$check(array_column($warlock,'kind')===['standard-slot','standard-slot','pact-slot'],'Warlock spell can use shared standard pools');
$check($evaluate($entry(1,'wizard',false,false),$context)['status']==='unavailable','Other source eligibility cannot authorize wizard');
$check($evaluate($entry(1,'bard'),$context)['status']==='available','Same level from known source remains available');
foreach ([1,3] as $level) $context['standardSlots'][$level-1]=$pool('spell-slot-'.$level,$level,true,0,'unavailable','exhausted');
$context['pactSlots']=$pool('warlock-pact-slots',2,true,0,'unavailable','exhausted');
$result=$evaluate($entry(1),$context);
$check($result['status']==='unavailable' && count(array_filter($result['methods'],static fn($m)=>$m['reason']==='exhausted'))===3,'All eligible pools exhausted');
foreach (['missing_counter','duplicate_counter','invalid_counter','counter_above_maximum'] as $reason) {
    $context['standardSlots'][2]=$pool('spell-slot-3',3,true,null,'unknown',$reason);
    $result=$evaluate($entry(1),$context);
    $check($result['status']==='unknown' && $result['methods'][2]['reason']===$reason,'Unknown pool reason preserved: '.$reason);
    $check($evaluate($entry(4),$context)['status']==='unavailable','Unknown lower pool cannot cast higher spell');
}
$context['pactSlots']=$pool('warlock-pact-slots',2,true,1,'available');
$check($evaluate($entry(1),$context)['status']==='available','Available alternative wins over unknown pool');
$context['pactSlots']=$pool('warlock-pact-slots',2,true,null,'unknown','missing_counter');
$check($evaluate($entry(1),$context)['status']==='unknown','Unknown pact is not exhausted');
$check($evaluate($entry(1,'wizard',false,false,true),$context)['status']==='available','Ritual bypasses unknown counters');
foreach (['grant-cast','grant-known','grant-always-prepared'] as $mode) {
    $grant=$entry(1); $grant['sourceType']='subclass'; $grant['mode']=$mode;
    $grant['castingEligibility']=$eligibility->evaluate($grant,null,null);
    $result=$evaluate($grant,$context);
    $check($result['status']==='unknown' && $result['reason']==='grant_rules_pending' && $result['methods']===[],'Unresolved grant never gets implicit methods');
}
$grant=$entry(0); $grant['sourceType']='feature';
$check($evaluate($grant,$context)['status']==='unknown','Feature grant cannot bypass review even with a contradictory eligibility');
$bad=$entry(1); unset($bad['castingEligibility']);
$check($evaluate($bad,$context)['reason']==='eligibility_unknown','Missing eligibility fails closed');
$bad=$entry(1); $bad['level']='1';
$check($evaluate($bad,$context)['reason']==='spell_level_unknown','Invalid spell level fails closed');
$check($evaluate($entry(1),[])['status']==='unknown','Absent context cannot invent availability');
foreach (range(1,9) as $spellLevel) {
    $single=['standardSlots'=>[], 'pactSlots'=>$pool('warlock-pact-slots',null,false,null,'unavailable','inactive_pool')];
    foreach (range(1,9) as $slotLevel) $single['standardSlots'][]=$pool('spell-slot-'.$slotLevel,$slotLevel,true,1,'available');
    $methods=$usable($evaluate($entry($spellLevel),$single));
    $check(array_column($methods,'castingLevel')===range($spellLevel,9),'All and only sufficient standard levels: '.$spellLevel);
}
foreach ([null, -1, 0, '1', 5] as $invalid) {
    $single['standardSlots'][8]['remaining']=$invalid;
    $check($evaluate($entry(9),$single)['status']==='unknown','Contradictory available counter is rejected');
}
// Isolate pact uncertainty: no standard pool is unknown or available.
$review=['standardSlots'=>[], 'pactSlots'=>$pool('warlock-pact-slots',3,true,null,'unknown','missing_counter')];
foreach (range(1,9) as $level) $review['standardSlots'][]=$pool('spell-slot-'.$level,$level,true,0,'unavailable','exhausted');
$result=$evaluate($entry(3),$review);
$check($result['status']==='unknown' && $result['methods'][9]['reason']==='missing_counter','Exhausted standards plus compatible unknown pact yields unknown');
$review['standardSlots'][2]=$pool('spell-slot-3',3,true,4,'available');
$check($evaluate($entry(3),$review)['status']==='available','Available standard wins over unknown pact');
$review['standardSlots'][2]=$pool('spell-slot-3',3,true,0,'unavailable','exhausted');
$review['standardSlots'][1]=$pool('spell-slot-2',2,true,4,'available');
$review['pactSlots']=$pool('warlock-pact-slots',2,true,4,'available');
$result=$evaluate($entry(3),$review);
$check($result['status']==='unavailable' && $result['methods'][1]['reason']==='slot_level_too_low'
    && $result['methods'][9]['reason']==='slot_level_too_low','Level-three spell rejects both level-two pools');
$review['standardSlots'][4]=$pool('spell-slot-5',5,true,4,'available');
$ritualMethods=array_values(array_filter($evaluate($entry(2,'wizard',false,true,true),$review)['methods'],static fn($m)=>$m['kind']==='ritual'));
$check(count($ritualMethods)===1 && $ritualMethods[0]['castingLevel']===2 && $ritualMethods[0]['cost']===0
    && $ritualMethods[0]['poolSlug']===null,'Higher available slots never upcast the ritual method');
$check($evaluate($entry(0,'bard',false),$review)['status']==='unavailable','Full standard and pact pools cannot authorize an unknown cantrip');
$grant=$entry(2); $grant['sourceType']='subclass'; $grant['mode']='grant-cast';
$grant['castingEligibility']=$eligibility->evaluate($grant,null,null);
$result=$evaluate($grant,$review);
$check($result['status']==='unknown' && $result['reason']==='grant_rules_pending' && $usable($result)===[],'Full compatible pools never authorize unresolved grant-cast');
echo "PASS: $checks spell cast availability assertions; no database.\n";
