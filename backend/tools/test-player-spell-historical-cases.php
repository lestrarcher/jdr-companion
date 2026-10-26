<?php
declare(strict_types=1);
// Real examples are read from TEMP copies; original tables and sequences are fingerprinted by the harness.
foreach([21=>'Jakharis',24=>'Frank'] as $id=>$name) {
    $character=$em->find(App\Entity\Character::class,$id); $check($character?->getName()===$name,'Expected local character');
    $game=(new App\Entity\GameSession($character->getCampaign(),'global-http-'.$id,'Global HTTP'))->setStatus('live');
    $session=new App\Entity\CharacterSessionState($game,$character,['resources'=>[['id'=>'untouched','currentValue'=>2]],'progressions'=>[]]);
    $em->persist($game); $em->persist($session); $em->flush(); $path=$url($session); $state=$session->getState();
    $original=$db->fetchAllAssociative('SELECT * FROM character_spell_acquisition WHERE character_id=? ORDER BY id',[$id]);
    [$code,$p]=$request('GET',$path.'/historical'); $check($code===200 && $p['editable'],'Global player preview');
    $source=$p['classes'][0]; $cat=array_values(array_filter($source['categories'],static fn($c)=>$c['remaining']>0 && $c['available']))[0];
    $choices=array_map(static fn($s)=>['spellId'=>$s['id'],'ruleId'=>$cat['ruleId']],array_slice($cat['available'],0,min(2,$cat['remaining'])));
    $command=['policyId'=>$source['policyId'],'choices'=>$choices,'expectedAcquisitionIds'=>$source['expectedAcquisitionIds'],'confirmed'=>true,'revision'=>$p['revision']];
    $check($request('POST',$path.'/historical-completion',array_replace($command,['confirmed'=>false]))[0]===400,'Explicit confirmation is mandatory');
    [$code,$after]=$request('POST',$path.'/historical-completion',$command); $check($code===200,'Player global batch succeeds: '.json_encode($after));
    $check($after['revision']>$p['revision'],'Revision lifecycle preserved');
    $check($request('POST',$path.'/historical-completion',$command)[0]===409,'Stale session revision rejected');
    $command['revision']=$after['revision'];
    [$code,$replay]=$request('POST',$path.'/historical-completion',$command); $check($code===200 && $replay['revision']===$after['revision'],'Exact replay makes no mutation or revision bump');
    foreach($original as $row) $check($db->fetchAssociative('SELECT * FROM character_spell_acquisition WHERE id=?',[$row['id']])===$row,'Original acquisition unchanged');
    $session=$em->find(App\Entity\CharacterSessionState::class,$session->getId()); $check($session->getState()===$state,'Resources and session JSON unchanged');
    $check($request('POST',$path.'/historical-completion',$command+['replacesId'=>1])[0]===400,'Implicit replacement cannot be requested');
    $check($request('POST',$path.'/learn',['policyId'=>$source['policyId'],'spellId'=>$choices[0]['spellId'],'ruleId'=>$cat['ruleId'],'revision'=>$after['revision']])[0]===400,'Classic learn cannot double count a reconciled spell');
    $db->update('game_session',['status'=>'closed'],['id'=>$game->getId()]); $em->clear();
    $check($request('POST',$path.'/historical-completion',$command)[0]===409,'Closed session refuses reconciliation');
    $db->update('character_session_state',['participating'=>false],['id'=>$session->getId()],['participating'=>Doctrine\DBAL\ParameterType::BOOLEAN]); $em->clear();
    $check($request('GET',$path.'/historical')[0]===404,'Removed participant cannot read');
    $check($request('POST',$path.'/historical-completion',$command)[0]===404,'Removed participant cannot write');
}
$check($request('GET','/public/characters/'.str_repeat('0',64).'/spells/historical')[0]===404,'Invalid token rejected');
