<?php
declare(strict_types=1);
use App\Entity\{Character,CharacterClassLevel,CharacterFeatureDefinition,CharacterFeatureRule,SpellDefinition};
use App\Enum\Ability;
// Included by the isolated directory harness. No real character/reference writes.
$fey = new App\Entity\Feat('fey-touched','Faveur des Fées');
$fey->setRequiresAbilityChoice(true)->setAllowedAbilities(Ability::Intelligence,Ability::Wisdom,Ability::Charisma);
$anatoly = new Character($campaign,'anatoly-fixture','Anatoly fixture',Character::TYPE_PLAYER);
$featAssignment = new App\Entity\CharacterFeat($anatoly,$fey);
$featAssignment->setChosenAbility(Ability::Wisdom); $anatoly->addFeat($featAssignment);
$feyFeature = new CharacterFeatureDefinition('fey-fixture','Faveur des Fées');
$feyRule = CharacterFeatureRule::forFeat($feyFeature,$fey);
foreach ([$fey,$anatoly,$featAssignment,$feyFeature,$feyRule] as $entity) $em->persist($entity);
for ($i=1;$i<=3;++$i) { $l=new CharacterClassLevel($anatoly,$classes['wizard'],$i); $anatoly->addClassLevel($l); $em->persist($l); }
$misty = new SpellDefinition('misty-fey-fixture','Foulée brumeuse',2,App\Enum\SpellSchool::Conjuration,'1 bonus action','Self','Instantaneous','Fixture');
$cure = new SpellDefinition('cure-fey-fixture','Soins',1,App\Enum\SpellSchool::Evocation,'1 action','Touch','Instantaneous','Intentional GM choice');
$em->persist($misty); $em->persist($cure); $em->flush();
foreach ([13=>$misty,14=>$cure] as $g=>$s) {
    $db->insert('feature_spell_grant',['id'=>$g,'feature_id'=>$feyFeature->getId(),'mode'=>$g===13?'fixed':'choice',
        'spell_id'=>$g===13?$s->getId():null,'spell_level'=>$g===14?1:null,'schools'=>$g===14?'["evocation"]':null,'unlock_level'=>1,'casting_ability'=>$g===14?'CHOICE':null]);
    $db->insert('character_feature_spell',['id'=>$g,'character_id'=>$anatoly->getId(),'grant_id'=>$g,'spell_id'=>$s->getId()]);
}
$game=(new App\Entity\GameSession($campaign,'fey-game','Fey test'))->setStatus(App\Entity\GameSession::STATUS_LIVE);
$initial=['resources'=>[['id'=>'spell-slot-1','currentValue'=>4],['id'=>'spell-slot-2','currentValue'=>2],['id'=>'unrelated','currentValue'=>7]],'hitPoints'=>['current'=>1,'temporary'=>0],'hitDice'=>[],'progressions'=>[]];
$session=new App\Entity\CharacterSessionState($game,$anatoly,$initial); $em->persist($game); $em->persist($session); $em->flush();
$hp=new App\Service\CharacterHitPointCalculator($ability); $hpState=new App\Service\CharacterHitPointStateService($hp);
$slotState=new App\Service\CharacterSpellSlotStateService($slotCalculator);
$sync=new App\Service\CharacterSessionStateSynchronizer($hp,$hpState,$resources,new App\Repository\CharacterSessionStateRepository($registry),$slotState);
$repair=new App\Service\FeyTouchedCastingRepair($em,$sync,new App\Service\CharacterFeatureSpellCastingResolver($db,$features),$directory);
$before=$snapshot(); $acquired=$db->fetchAllAssociative('SELECT * FROM character_feature_spell ORDER BY id');
$preview=$repair->run($anatoly->getId(),$session->getId());
$check($snapshot()===$before,'Preview has no durable writes');
$check(array_column(array_column($preview['before'],'castingAvailability'),'status')===['unknown','unknown'],'Both cards disabled before configuration despite slots');
$done=$repair->run($anatoly->getId(),$session->getId(),true);
$check(array_column(array_column($done['after'],'castingAvailability'),'status')===['available','available'],'Both cards executable after configuration');
$check(array_column($done['after'],'castingAbility')===['wisdom','wisdom'],'Same feat choice supplies both casting abilities');
$after=$snapshot(); $repair->run($anatoly->getId(),$session->getId(),true);
$check($snapshot()===$after,'Second repair is fully idempotent including revision');
$check(array_slice($session->getState()['resources'],0,3)===$initial['resources'],'Existing counters preserved');
$check($db->fetchAllAssociative('SELECT * FROM character_feature_spell ORDER BY id')===$acquired,'All acquisitions including GM Soins preserved');
$executor=new App\Service\CharacterSpellCastExecutor($em,new App\Service\CharacterSpellCastCommandValidator($directory),new App\Service\CharacterSpellHitPointEffects($em,new App\Service\CharacterHitPointBoostService(new App\Service\CharacterHitPointStateService(new App\Service\CharacterHitPointCalculator(new App\Service\CharacterAbilityCalculator())),new App\Repository\CharacterActiveEffectRepository($registry),$em))); $request=0;
$cast=static function(int $grant,string $method,int $level) use($executor,$session,&$request):array {
    return $executor->execute($session,['revision'=>$session->getRevision(),'requestId'=>sprintf('e0000000-0000-4000-8000-%012d',++$request),'sourceKey'=>'feature:'.$grant,'method'=>$method,'castingLevel'=>$level]);
};
$values=static fn()=>array_column($session->getState()['resources'],'currentValue','id');
$cast(13,'standard-slot',2);
$check($values()['spell-slot-2']===1 && $values()['fey-touched-grant-13']===1 && $values()['fey-touched-grant-14']===1,'Slot casting consumes neither free use');
$cast(13,'resource',2);
$check($values()['fey-touched-grant-13']===0 && $values()['fey-touched-grant-14']===1 && $values()['spell-slot-2']===1,'Free misty step spends only its own use');
$cast(14,'resource',1);
$check($values()['fey-touched-grant-14']===0 && $values()['spell-slot-1']===4,'Free Soins spends no slot');
$spent=$snapshot(); $repair->run($anatoly->getId(),$session->getId(),true);
$check($snapshot()===$spent,'Re-running repair cannot refill spent uses');
$cast(14,'standard-slot',2);
for($i=0;$i<4;++$i) $cast(14,'standard-slot',1);
foreach ([[13,'resource',2],[14,'resource',1],[13,'standard-slot',2],[14,'standard-slot',1]] as $intent) {
    $unchanged=$session->getState();
    try { $cast(...$intent); throw new RuntimeException('Expected exhaustion refusal'); }
    catch (Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException $e) { $check($e->getMessage()==='exhausted','Exhausted method rejected'); }
    $check($session->getState()===$unchanged,'Refusal never mutates state');
}
$rest=new App\Service\CharacterRestService($hp,$hpState,$resources,$sync,$slotCalculator,$definitions,$ability,new App\Repository\CharacterActiveEffectRepository($registry),new App\Service\CharacterActiveEffectService($hpState),$em);
$rest->apply($session,App\Entity\RestRequest::TYPE_SHORT_REST);
$check($values()['fey-touched-grant-13']===0 && $values()['fey-touched-grant-14']===0,'Short rest does not restore Fey Touched');
$rest->apply($session,App\Entity\RestRequest::TYPE_LONG_REST);
$check($values()['fey-touched-grant-13']===1 && $values()['fey-touched-grant-14']===1,'Long rest restores both independent uses');
$check($db->fetchAllAssociative('SELECT * FROM character_feature_spell ORDER BY id')===$acquired,'Casting/rest never changes acquisitions');
// A second holder must turn a targeted repair into a refusal, never a global backfill.
$otherAssignment=new App\Entity\CharacterFeat($character,$fey); $character->addFeat($otherAssignment); $em->persist($otherAssignment); $em->flush();
$before=$snapshot();
try { $repair->run($anatoly->getId(),$session->getId(),true); throw new RuntimeException('Expected shared feat refusal'); }
catch (RuntimeException $e) { $check(str_contains($e->getMessage(),'Shared feat'),'Shared feat requires manual review'); }
$check($snapshot()===$before,'Shared feat refusal preserves all characters');
