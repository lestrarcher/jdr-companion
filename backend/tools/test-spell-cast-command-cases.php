<?php

declare(strict_types=1);

// Included by the directory harness, using its isolated temporary schema and fixtures.
$validator = new App\Service\CharacterSpellCastCommandValidator($directory);
$commandState = $stateBefore;
$commandState['resources'] = [['id'=>'spell-slot-1','currentValue'=>1], ['id'=>'spell-slot-2','currentValue'=>1], ['id'=>'warlock-pact-slots','currentValue'=>1]];
// Explicit reconciled knowledge fixture: the validator must still enforce the spell's base level.
$higher=$catalogue->create(['slug'=>'command-higher','name'=>'Higher spell','level'=>2,'school'=>'divination',
    'castingTime'=>'1 action','range'=>'Self','duration'=>'1 minute','description'=>'Fixture','ritual'=>false,
    'classIds'=>[$classes['bard']->getId()]],$owner,true);
$em->persist(new App\Entity\CharacterSpellAcquisition($character,$em->find(App\Entity\SpellDefinition::class,$higher['id']),
    $policies['bard'],$levels['bard'],$owner,'manual-reconciliation','known','Fixture'));
$em->flush();
$entriesByKey = array_column($directory->forCharacter($character,$commandState),null,'key');
$sourceKey = static function (int $spellId, ?int $policyId) use ($entriesByKey): string {
    foreach ($entriesByKey as $entry) if ($entry['spellId']===$spellId && $entry['policyId']===$policyId) return $entry['key'];
    throw new RuntimeException('Missing command fixture');
};
$command = ['revision'=>42,'requestId'=>'7a00a010-0000-4000-8000-000000000001',
    'sourceKey'=>$sourceKey($ordinary['id'],$policies['bard']->getId()),'method'=>'standard-slot','castingLevel'=>1];
$storedBefore=$snapshot();
$validate = static function (array $cmd, array $state, bool $valid, ?string $reason=null) use ($validator,$character,$check): array {
    $before=[$cmd,$state];
    $result=$validator->validate($character,$state,$cmd);
    $check($result['valid']===$valid && $result['reason']===$reason,'Command outcome: '.($reason??'accepted'));
    $check([$cmd,$state]===$before,'Command and session arrays unchanged');
    if (!$valid) $check($result['poolSlug']===null && $result['cost']===null,'Refusal supplies no spend instruction');
    return $result;
};
$result=$validate($command,$commandState,true);
$check($result['poolSlug']==='spell-slot-1' && $result['cost']===1 && $result['source']['spellId']===$ordinary['id'],'Server-derived standard pool, cost and source');
$result=$validate(array_replace($command,['castingLevel'=>2]),$commandState,true);
$check($result['poolSlug']==='spell-slot-2' && $result['castingLevel']===2,'Available upcasting');
$validate(array_replace($command,['sourceKey'=>'acquisition:2147483647']),$commandState,false,'source_not_found');
$validate(array_replace($command,['castingLevel'=>0]),$commandState,false,'casting_level_mismatch');
$validate(array_replace($command,['castingLevel'=>3]),$commandState,false,'inactive_pool');
$higherCommand=array_replace($command,['sourceKey'=>$sourceKey($higher['id'],$policies['bard']->getId())]);
$validate($higherCommand,$commandState,false,'slot_level_too_low');
$validate(array_replace($higherCommand,['castingLevel'=>2]),$commandState,true);
$result=$validate(array_replace($command,['method'=>'pact-slot']),$commandState,true);
$check($result['poolSlug']==='warlock-pact-slots' && $result['cost']===1,'Separate server-derived pact pool');
$validate(array_replace($command,['method'=>'pact-slot','castingLevel'=>2]),$commandState,false,'casting_level_mismatch');
$cantrip=array_replace($command,['sourceKey'=>$sourceKey($cantrips['known']['id'],$policies['bard']->getId()),'method'=>'cantrip','castingLevel'=>0]);
$result=$validate($cantrip,[],true);
$check($result['cost']===0 && $result['poolSlug']===null,'Cantrip spends nothing');
$validate(array_replace($cantrip,['castingLevel'=>1]),$commandState,false,'casting_level_mismatch');
$wizardCommand=array_replace($command,['sourceKey'=>$sourceKey($spell['id'],$policies['wizard']->getId())]);
$validate($wizardCommand,$commandState,false,'spell_not_prepared');
$validate(array_replace($wizardCommand,['sourceKey'=>$sourceKey($spell['id'],$policies['bard']->getId())]),$commandState,true);
$result=$validate(array_replace($wizardCommand,['method'=>'ritual']),[],true);
$check($result['cost']===0 && $result['castingLevel']===1 && $result['source']['policyId']===$policies['wizard']->getId(),'Exact wizard ritual source without preparation or slots');
$validate(array_replace($wizardCommand,['method'=>'ritual','castingLevel'=>2]),$commandState,false,'casting_level_mismatch');
foreach ($entriesByKey as $entry) if (($entry['mode']??null)==='grant-cast') {
    $result=$validate(array_replace($command,['sourceKey'=>$entry['key']]),$commandState,false,'grant_rules_pending');
    $check($result['status']==='unknown','Unresolved grant remains unknown');
}
foreach ([null,0,'1',-1,5] as $remaining) {
    $context=$commandState;
    if ($remaining===null) array_shift($context['resources']);
    else $context['resources'][0]['currentValue']=$remaining;
    $reason=match ($remaining) {null=>'missing_counter',0=>'exhausted',5=>'counter_above_maximum',default=>'invalid_counter'};
    $result=$validate($command,$context,false,$reason);
    $check($result['status']===($remaining===0?'unavailable':'unknown'),'Selected pool controls result despite other available pools');
}
$duplicate=$commandState; $duplicate['resources'][]=$duplicate['resources'][0];
$validate($command,$duplicate,false,'duplicate_counter');
foreach (['cost'=>0,'poolSlug'=>'spell-slot-9','currentValue'=>99] as $field=>$value) $validate($command+[$field=>$value],$commandState,false,'invalid_command');
foreach (['revision'=>0,'requestId'=>'not-a-uuid','castingLevel'=>'1','method'=>'normal'] as $field=>$value) $validate(array_replace($command,[$field=>$value]),$commandState,false,'invalid_command');
$missing=$command; unset($missing['revision']); $validate($missing,$commandState,false,'invalid_command');
$check($snapshot()===$storedBefore,'Command validation never modifies persisted data');
