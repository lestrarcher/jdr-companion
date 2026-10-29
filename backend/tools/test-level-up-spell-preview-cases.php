<?php
declare(strict_types=1);

// Included only in the acquisition harness's temporary/isolated schema.
foreach (['campaign','owner','admin','other','advancedClass','advancedSub','advancedPolicy','bookClass'] as $name) {
    $$name=$em->find(($$name)::class,($$name)->getId());
}
$makeAdvancedCharacter=static function($slug,$class,$count,$sub) use ($em,$campaign) {
    $character=new App\Entity\Character($campaign,$slug,$slug,App\Entity\Character::TYPE_PLAYER); $em->persist($character);
    for($i=1;$i<=$count;++$i) { $level=new App\Entity\CharacterClassLevel($character,$class,$i,$i>=3?$sub:null); $character->addClassLevel($level); $em->persist($level); }
    return $character;
};
$previewOptions = new App\Service\CharacterLevelUpOptionsService($em, new App\Repository\CharacterClassLevelRuleRepository($registry), new App\Service\CharacterMulticlassEligibilityService(new App\Service\CharacterAbilityCalculator()));
$previewService = new App\Service\CharacterLevelUpSpellPreview($em, $previewOptions, $service, $access);
$previewCharacter = $makeAdvancedCharacter('preview-six', $advancedClass, 6, $advancedSub);
$em->flush();
$previewSpells = [];
foreach ([0,0,1,1,1,1,2,3] as $i=>$spellLevel) {
    $previewSpells[] = $catalogue->create(['slug'=>'preview-spell-'.$i,'name'=>'Preview '.$i,'level'=>$spellLevel,'school'=>'abjuration','castingTime'=>'Fixture','range'=>'Fixture','duration'=>'Fixture','description'=>'Synthetic','classIds'=>[$bookClass->getId()]],$admin,true);
}
$previewLevels = $previewCharacter->getClassLevels()->toArray();
$previewChoices = [];
foreach (array_slice($previewSpells,0,6) as $i=>$spell) $previewChoices[] = $advancedChoice($spell['id'],$previewLevels[5]->getId(),$byKey[$i<2?'cantrips':($i===5?'unrestricted':'restricted')]);
$service->acquire($previewCharacter,$advancedPolicy->getId(),$previewChoices,'manual-reconciliation','Synthetic six known spells',$owner);
$previewSnapshot = static function () use ($db,$metadata): array {
    $result=[];
    foreach ($metadata as $m) {
        $q=$db->quoteIdentifier($m->getTableName());
        $result[$m->getTableName()]=$db->fetchOne("SELECT md5(COALESCE(string_agg(row_to_json(x)::text,'' ORDER BY row_to_json(x)::text),'')) FROM $q x");
    }
    return $result;
};
$beforePreview=$previewSnapshot();
$check($previewService->preview($previewCharacter,[])['status']==='selection-required','Preview requires explicit class selection');
$preview=$previewService->preview($previewCharacter,['classId'=>(string)$advancedClass->getId()]);
$p=$preview['policies'][0]; $routes=array_column($p['choices'],null,'key');
$check($p['classLevel']===7 && $p['classLevelId']===null,'Future level has no persistent identity');
$check($p['limits']['known']===5 && $p['used']['known']===4 && $p['used']['cantrips']===2 && count($p['acquisitions'])===6,'Preview preserves four known spells and two cantrips');
$check($routes['restricted']['remaining']===1 && $routes['unrestricted']['remaining']===0 && $routes['cantrips']['remaining']===0,'Exactly one restricted future opportunity');
$check(in_array($previewSpells[6]['id'],array_column($routes['restricted']['available'],'id'),true),'Level two wizard abjuration offered');
$check(!in_array($previewSpells[7]['id'],array_column($routes['restricted']['available'],'id'),true),'Level three excluded');
foreach ($routes['restricted']['available'] as $candidate) $check(in_array($candidate['school'],['abjuration','evocation'],true) && in_array($candidate['level'],[1,2],true),'All candidates respect schools and levels');
$check($routes['restricted']['replacement']['maximum']===1 && $routes['restricted']['replacement']['budgetKey']===$routes['unrestricted']['replacement']['budgetKey'],'Replacement budget is shared, not one per rule');
$check($routes['restricted']['replacement']['acquisitions'][0]['requiresQualification']===true,'Manual ambiguous origins are explicitly qualified before replacement');
$check($previewCharacter->getClassLevels()->toArray()===$previewLevels && $previewCharacter->getTotalLevel()===6,'Projection restores original level collection');
$check($beforePreview===$previewSnapshot(),'Preview changes no ORM table including sessions and acquisitions');
$plainClass=new App\Entity\CharacterClass('preview-plain','No policy',8,20); $em->persist($plainClass);
$plainCharacter=$makeAdvancedCharacter('preview-plain',$plainClass,2,null); $em->flush();
$check($previewService->preview($plainCharacter,['classId'=>$plainClass->getId()])['policies']===[],'Class without policy has no invented choices');
$previewPreparedClass=new App\Entity\CharacterClass('preview-prepared','Prepared fixture',8,20); $em->persist($previewPreparedClass);
$previewPreparedCharacter=$makeAdvancedCharacter('preview-prepared',$previewPreparedClass,3,null); $em->flush();
$service->definePolicy($previewPreparedClass,App\Service\Dnd2014SpellPolicies::get('cleric-2014'),$admin);
$preparedPreview=$previewService->preview($previewPreparedCharacter,['classId'=>$previewPreparedClass->getId()])['policies'][0];
$check($preparedPreview['learningStyle']==='prepared' && !in_array('known',array_column($preparedPreview['choices'],'quota'),true) && !in_array('spellbook',array_column($preparedPreview['choices'],'quota'),true),'Prepared policy does not require learning its spell list');
$previewSession=new App\Entity\GameSession($campaign,'preview-session','Synthetic preview');
$previewSession->setStatus(App\Entity\GameSession::STATUS_LIVE);
$previewState=new App\Entity\CharacterSessionState($previewSession,$previewCharacter,['resources'=>[['id'=>'spell-slot-1','currentValue'=>1]]]);
$previewState->setLevelUpAllowed(true);
$em->persist($previewSession); $em->persist($previewState); $em->flush();
$httpBefore=$previewSnapshot();
$mjUrl='/campaigns/'.$campaign->getId().'/characters/'.$previewCharacter->getId().'/level-up/options?classId='.$advancedClass->getId();
$mjResponse=$http($owner,'GET',$mjUrl);
$check($mjResponse->getStatusCode()===200,'MJ GET preview succeeds');
$mjData=json_decode($mjResponse->getContent(),true);
$check($mjData['spellPreview']['policies'][0]['classLevel']===7 && $mjData['nextTotalLevel']===7,'MJ GET preserves ordinary options and adds preview');
$check($http($other,'GET',$mjUrl)->getStatusCode()===403,'MJ GET retains campaign authorization');
$playerUrl='/public/characters/'.$previewState->getAccessToken().'/level-up/options?classId='.$advancedClass->getId();
$playerResponse=$http(null,'GET',$playerUrl);
$check($playerResponse->getStatusCode()===200,'Player GET preview succeeds with existing permission');
$playerData=json_decode($playerResponse->getContent(),true);
$check($playerData['spellPreview']===$mjData['spellPreview'] && isset($playerData['training']),'Player and MJ receive identical spell previews and training remains present');
$check($httpBefore===$previewSnapshot(),'Both GET requests leave all tables unchanged');
$em->find(App\Entity\CharacterSessionState::class,$previewState->getId())->setLevelUpAllowed(false); $em->flush();
$check($http(null,'GET',$playerUrl)->getStatusCode()===403,'Player GET still requires level-up permission');
