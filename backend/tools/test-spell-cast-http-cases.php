<?php

declare(strict_types=1);

// Included by test-player-spell-directory-eligibility.php --cast-http, after
// committing TEMP fixtures: real HTTP kernel and executor-owned commits.
$httpGame=(new App\Entity\GameSession($campaign,'cast-http','Cast HTTP'))->setStatus(App\Entity\GameSession::STATUS_LIVE);
$httpInitial=$commandState;
$httpInitial['resources'][0]['flexibleCastingBonus']=1;
$httpInitial['resources'][0]['notes']='Preserve metadata';
$httpSession=new App\Entity\CharacterSessionState($httpGame,$character,$httpInitial);
$em->persist($httpGame); $em->persist($httpSession); $em->flush();
$httpId=$httpSession->getId(); $httpGameId=$httpGame->getId();
$httpToken=$httpSession->getAccessToken();
$httpPath='/public/characters/'.$httpToken.'/spells/cast';
$otherHttpBefore=$snapshot(); unset($otherHttpBefore['character_session_state'],$otherHttpBefore['game_session']);
$httpStored=static function() use($db,$httpId):array {
    $row=$db->fetchAssociative('SELECT state,revision,updated_at FROM character_session_state WHERE id=?',[$httpId]);
    $row['state']=json_decode($row['state'],true,512,JSON_THROW_ON_ERROR);
    $row['revision']=(int)$row['revision']; return $row;
};
$httpReset=static function(?array $state=null) use($em,$httpId,$httpInitial):void {
    $session=$em->find(App\Entity\CharacterSessionState::class,$httpId); $em->refresh($session);
    $session->setState($state??$httpInitial); $em->flush();
};
$httpCall=static function(array|string $payload,int $expected=200,?string $path=null,string $contentType='application/json') use($kernel,$httpPath,$check,$httpStored,$db):array {
    $before=$httpStored();
    $request=Symfony\Component\HttpFoundation\Request::create($path??$httpPath,'POST',server:['CONTENT_TYPE'=>$contentType,'HTTP_ACCEPT'=>'application/json'],
        content:is_array($payload)?json_encode($payload,JSON_THROW_ON_ERROR):$payload);
    $request->setRequestFormat('json');
    $response=$kernel->handle($request);
    $body=json_decode($response->getContent(),true,512,JSON_THROW_ON_ERROR);
    $check($response->getStatusCode()===$expected,'HTTP expected '.$expected.', got '.$response->getStatusCode().': '.json_encode($body));
    $check(!$db->isTransactionActive(),'HTTP leaves no transaction open');
    $check(!str_contains($response->getContent(),'spellCastReceipts') && !str_contains($response->getContent(),'7a00a010-0000-4000-8000-00000000000'),'Public response exposes no receipts or request UUIDs');
    if ($expected!==200) {
        $check(is_string($body['message']??null) && array_keys($body)===['message'],'Errors use the public message contract without internal traces');
        $check($httpStored()===$before,'HTTP refusal preserves state, receipts, revision and timestamp');
    }
    return $body;
};
$httpCommand=static fn():array=>array_replace($command,['revision'=>$httpStored()['revision']]);
$first=$httpCommand(); $before=$httpStored();
$body=$httpCall($first);
$check($body['cast']['poolSlug']==='spell-slot-1' && $body['cast']['cost']===1 && $body['replayed']===false,'Standard HTTP cast');
$check($body['revision']===$before['revision']+1 && $body['revision']===$httpStored()['revision'],'Response revision committed');
$check($body['state']['resources'][0]['currentValue']===0 && $httpStored()['state']['resources'][0]['currentValue']===0,'Response and database counters agree');
$check(isset($body['character'],$body['session'],$body['spells'],$body['updatedAt']) && !isset($body['accessToken']),'Existing public session envelope, no token');
$check(!isset($body['state']['resources'][0]['flexibleCastingBonus']) && $httpStored()['state']['resources'][0]['flexibleCastingBonus']===1,'Public bonus filtering preserves internal metadata');
$storedAfterFirst=$httpStored();
$replay=$httpCall($first);
$check($replay['replayed']===true && $replay['cast']===$body['cast'] && $replay['revision']===$body['revision'],'Identical replay returns historical cast and current revision');
$check($httpStored()===$storedAfterFirst,'HTTP replay changes no resource, revision or timestamp');
$conflict=$httpCall(array_replace($first,['castingLevel'=>2]),409);
$check($conflict['message']==='request_id_conflict','UUID content conflict');
$stale=$httpCall(array_replace($first,['requestId'=>'7a00a010-0000-4000-8000-000000000002']),409);
$check($stale['message']==='stale_revision','New UUID with stale revision refused');
// A second accepted request changes the current state but not the first receipt.
$second=$httpCall(array_replace($httpCommand(),['requestId'=>'7a00a010-0000-4000-8000-000000000002','castingLevel'=>2]));
$storedAfterSecond=$httpStored(); $replay=$httpCall($first);
$check($replay['cast']===$body['cast'] && $replay['revision']===$second['revision'] && $replay['state']===$second['state'],'Replay carries current public state and historical cast');
$check($httpStored()===$storedAfterSecond && count($storedAfterSecond['state']['spellCastReceipts'])===2,'Two receipts retained internally, replay does not consume');
foreach ([array_replace($command,['method'=>'pact-slot']),$cantrip,array_replace($wizardCommand,['method'=>'ritual'])] as $variant) {
    $httpReset(); $before=$httpStored(); $cmd=array_replace($variant,['revision'=>$before['revision']]);
    $result=$httpCall($cmd); $after=$httpStored();
    $expectedResources=$before['state']['resources'];
    if ($variant['method']==='pact-slot') --$expectedResources[2]['currentValue'];
    $check($after['state']['resources']===$expectedResources,'Exact resource change for '.$variant['method']);
    $check($result['cast']['method']===$variant['method'] && $result['revision']===$before['revision']+1,'Accepted method and durable revision');
    $repeat=$httpCall($cmd);
    $check($repeat['replayed']===true && $repeat['cast']===$result['cast'] && $httpStored()===$after,'Free/pact replay cannot spend twice');
}
$httpReset();
foreach (['{','[]','null','{}'] as $raw) $httpCall($raw,400);
$httpCall($httpCommand(),400,null,'text/plain');
foreach ([['cost'=>0],['revision'=>'1'],['requestId'=>'invalid'],['castingLevel'=>'1'],['method'=>'invented']] as $invalid) {
    $httpCall(array_replace($httpCommand(),$invalid),400);
}
$missing=$httpCommand(); unset($missing['sourceKey']); $httpCall($missing,400);
$httpCall($httpCommand(),404,'/public/characters/'.str_repeat('0',64).'/spells/cast');
$session=$em->find(App\Entity\CharacterSessionState::class,$httpId); $session->setParticipating(false); $em->flush();
$httpCall($httpCommand(),404);
$session=$em->find(App\Entity\CharacterSessionState::class,$httpId); $session->setParticipating(true); $em->flush();
$game=$em->find(App\Entity\GameSession::class,$httpGameId); $game->setStatus(App\Entity\GameSession::STATUS_CLOSED); $em->flush();
$httpCall($httpCommand(),409);
$game=$em->find(App\Entity\GameSession::class,$httpGameId); $game->setStatus(App\Entity\GameSession::STATUS_LIVE); $em->flush();
foreach ([['sourceKey'=>'acquisition:2147483647'],['method'=>'ritual'],['castingLevel'=>3]] as $invalid) $httpCall(array_replace($httpCommand(),$invalid),422);
foreach ($entriesByKey as $entry) if (($entry['mode']??null)==='grant-cast') {
    $refusal=$httpCall(array_replace($httpCommand(),['sourceKey'=>$entry['key']]),422);
    $check($refusal['message']==='grant_rules_pending','Unresolved grant never implicitly authorized');
}
foreach ([null,0,'1'] as $remaining) {
    $state=$httpInitial;
    if ($remaining===null) array_shift($state['resources']); else $state['resources'][0]['currentValue']=$remaining;
    $httpReset($state); $httpCall($httpCommand(),422);
}
$malformed=$httpInitial; $malformed['spellCastReceipts']=[$command['requestId']=>['version'=>1]];
$httpReset($malformed); $refusal=$httpCall($httpCommand(),409);
$check($refusal['message']==='malformed_spell_cast_receipts','Malformed receipt explicitly refused without revealing its content');
$otherHttpAfter=$snapshot(); unset($otherHttpAfter['character_session_state'],$otherHttpAfter['game_session']);
$check($otherHttpAfter===$otherHttpBefore,'HTTP spells never change acquisitions, preparation records or unrelated tables');

// Explicit fixture-only grant configuration; exercise the same public serializer.
$db->update('feature_spell_grant',['casting_rules'=>json_encode(['version'=>1,'methods'=>[['kind'=>'resource','cost'=>2,'castingLevel'=>2]]]),
    'resource_definition_id'=>$resource->getId()],['id'=>1]);
$featureHttpState=$httpInitial;
$featureHttpState['resources'][]=['id'=>'directory-charge','currentValue'=>2];
$httpReset($featureHttpState);
$featureCommand=array_replace($httpCommand(),['sourceKey'=>'feature:1','method'=>'resource','castingLevel'=>2]);
$featureResult=$httpCall($featureCommand);
$check($featureResult['cast']['cost']===2 && $featureResult['cast']['poolSlug']==='directory-charge','Public resource cast uses configured pool and cost');
$check(array_column($featureResult['state']['resources'],'currentValue','id')['directory-charge']===0,'Public resource state reflects committed consumption');
$featureStored=$httpStored();
$featureReplay=$httpCall($featureCommand);
$check($featureReplay['replayed'] && $featureReplay['cast']===$featureResult['cast'] && $httpStored()===$featureStored,'Public resource replay preserves current state');
$db->update('feature_spell_grant',['casting_rules'=>null,'resource_definition_id'=>null],['id'=>1]);
