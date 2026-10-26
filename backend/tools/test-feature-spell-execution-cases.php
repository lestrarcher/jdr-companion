<?php
declare(strict_types=1);

use App\Entity\{TrackableResourceDefinition, CharacterFeatureRule};
use App\Enum\ResourceRechargeType;

// Included after committing the directory harness's verified temporary fixtures.
$secondResource=new TrackableResourceDefinition('independent-grant-use','Independent use',ResourceRechargeType::LongRest);
$secondResource->setBaseMaximum(1);
$em->persist($secondResource);
$em->persist(App\Entity\TrackableResourceRule::forClass($secondResource,$classes['wizard'],1));
$em->flush();
$rules=['version'=>1,'methods'=>[['kind'=>'resource','cost'=>2,'castingLevel'=>2],['kind'=>'standard-slot']]];
$configure=static function(array $config, int $grant=1, ?int $resourceId=null) use($db,$resource):void {
    $db->update('feature_spell_grant',['casting_rules'=>json_encode($config,JSON_THROW_ON_ERROR),
        'resource_definition_id'=>$resourceId??$resource->getId()],['id'=>$grant]);
};
$configure($rules);
$configure(['version'=>1,'methods'=>[['kind'=>'resource','cost'=>1,'castingLevel'=>1]]],2,$secondResource->getId());
$initial=$commandState;
$initial['resources'][0]['flexibleCastingBonus']=1;
$initial['resources'][]=['id'=>'directory-charge','currentValue'=>2,'notes'=>'Keep this metadata'];
$initial['resources'][]=['id'=>'independent-grant-use','currentValue'=>1];
$initial['resources'][]=['id'=>'unrelated','currentValue'=>7];
$game=(new App\Entity\GameSession($campaign,'feature-execution','Feature execution'))->setStatus(App\Entity\GameSession::STATUS_LIVE);
$castSession=new App\Entity\CharacterSessionState($game,$character,$initial);
$em->persist($game); $em->persist($castSession); $em->flush();
$untouchedBefore=$snapshot(); unset($untouchedBefore['character_session_state'],$untouchedBefore['feature_spell_grant']);
$executor=new App\Service\CharacterSpellCastExecutor($em,$validator);
$stored=static fn():array=>$db->fetchAssociative('SELECT state,revision,updated_at FROM character_session_state WHERE id=?',[$castSession->getId()]);
$reset=static function(array $next) use($castSession,$em):void { $castSession->setState($next); $em->flush(); };
$make=static fn(array $replace=[]):array=>array_replace(['revision'=>$castSession->getRevision(),
    'requestId'=>'b0000000-0000-4000-8000-000000000001','sourceKey'=>'feature:1','method'=>'resource','castingLevel'=>2],$replace);
$reject=static function(array $cmd,int $status,string $reason) use($executor,$castSession,$stored,$check,$db):void {
    $before=$stored();
    try { $executor->execute($castSession,$cmd); throw new RuntimeException('Expected feature refusal'); }
    catch(Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
        $check($e->getStatusCode()===$status && $e->getMessage()===$reason,'Feature refusal '.$reason.': '.$e->getMessage());
    }
    $check($stored()===$before && !$db->isTransactionActive(),'Refusal preserves state/revision/receipts and ends transaction');
};
$replay=static function(array $cmd,array $cast) use($executor,$castSession,$stored,$check):void {
    $before=$stored(); $result=$executor->execute($castSession,$cmd);
    $check($result['replayed']===true && $result['cast']===$cast,'Historical resource cast replayed');
    $check($result['revision']===$castSession->getRevision() && $result['state']===$castSession->getState() && $stored()===$before,'Replay returns current state without mutation');
};
$entries=array_column($directory->forCharacter($character,$initial),null,'key');
$check(array_column($entries['feature:1']['castingAvailability']['methods'],'kind')===['resource','standard-slot','standard-slot'],'Public directory exposes configured resource and accessible standard levels only');
$check($entries['feature:1']['castingEligibility']['canCastNormally'] && !$entries['feature:1']['castingEligibility']['canCastAsRitual'],'No implicit ritual for a ritual-tagged feature spell');
$check($entries['feature:2']['castingAvailability']['methods'][0]['poolSlug']==='independent-grant-use','Same spell, independent feature source and resource');
$cmd=$make(); $accepted=$executor->execute($castSession,$cmd);
$expected=$initial['resources']; $expected[3]['currentValue']=0;
$check($accepted['state']['resources']===$expected && $accepted['cast']['cost']===2 && $accepted['cast']['poolSlug']==='directory-charge','Configured cost consumed only from linked pool, preserving other resources/metadata');
$check(!$accepted['replayed'] && $accepted['revision']===$cmd['revision']+1,'One durable revision for resource acceptance');
$check(count(App\Service\SpellCastReceipts::validateState($accepted['state']))===1,'Resource receipt validates without a catalogue lookup');
$replay($cmd,$accepted['cast']);
$reject(array_replace($cmd,['castingLevel'=>1]),409,'request_id_conflict');
$reject(array_replace($cmd,['revision'=>$accepted['revision']]),409,'request_id_conflict');
$reject(array_replace($cmd,['requestId'=>'b0000000-0000-4000-8000-000000000002']),409,'stale_revision');
$reject($make(['requestId'=>'b0000000-0000-4000-8000-000000000002']),422,'exhausted');
$other=$executor->execute($castSession,$make(['requestId'=>'b0000000-0000-4000-8000-000000000002','sourceKey'=>'feature:2','castingLevel'=>1]));
$check($other['state']['resources'][4]['currentValue']===0 && $other['state']['resources'][3]['currentValue']===0,'Second grant spends its independent use');
$slot=$executor->execute($castSession,$make(['requestId'=>'b0000000-0000-4000-8000-000000000003','method'=>'standard-slot','castingLevel'=>2]));
$check($slot['state']['resources'][1]['currentValue']===0 && $slot['state']['resources'][3]['currentValue']===0 && $slot['cast']['cost']===1,'Explicit standard upcasting works after free uses are exhausted');
$replay($cmd,$accepted['cast']);

// Catalogue changes never reinterpret or charge historical receipts.
$db->update('feature_spell_grant',['casting_rules'=>null,'resource_definition_id'=>null,'casting_ability'=>null],['id'=>1]);
$replay($cmd,$accepted['cast']);
$reject($make(['requestId'=>'b0000000-0000-4000-8000-000000000004']),422,'grant_rules_pending');
$db->update('feature_spell_grant',['casting_ability'=>'INT'],['id'=>1]);
$configure($rules); $reset($initial);
foreach ([null,0,1,'2',-1,3] as $value) {
    $next=$initial;
    if($value===null) array_splice($next['resources'],3,1); else $next['resources'][3]['currentValue']=$value;
    $reset($next);
    $reject($make(),422,match($value){null=>'missing_counter',0=>'exhausted',1=>'insufficient_resource',3=>'counter_above_maximum',default=>'invalid_counter'});
}
$next=$initial; $next['resources'][]=$next['resources'][3]; $reset($next);
$reject($make(),422,'duplicate_counter');
$reset($initial);
$reject($make(['castingLevel'=>1]),422,'casting_level_mismatch');
$reject($make(['method'=>'pact-slot','castingLevel'=>1]),422,'method_not_allowed');
$reject($make(['method'=>'ritual','castingLevel'=>1]),422,'method_not_allowed');
$reject($make(['cost'=>0]),422,'invalid_command');
$configure($rules+['restrictions'=>'Only under an unvalidated condition']);
$reject($make(),422,'grant_rules_pending');
$configure($rules);
$db->update('feature_spell_grant',['casting_ability'=>null],['id'=>1]);
$reject($make(),422,'grant_rules_pending');
$db->update('feature_spell_grant',['casting_ability'=>'INT'],['id'=>1]);
$next=$initial; $next['progressions'][0]['currentValue']=2; $reset($next);
$reject($make(),422,'inactive_pool');
$reject($make(['sourceKey'=>'feature:2','castingLevel'=>1]),422,'source_not_found');
$reset($initial);
$ambiguousRule=CharacterFeatureRule::forClass($feature,$classes['bard'],1); $em->persist($ambiguousRule); $em->flush();
$reject($make(),422,'grant_rules_pending');
$em->remove($ambiguousRule); $em->flush();
$configure(['version'=>1,'methods'=>[['kind'=>'resource','cost'=>2,'castingLevel'=>2]]]);
$reject($make(['method'=>'standard-slot','castingLevel'=>1]),422,'method_not_allowed');
$configure($rules);

// Existing rests recover independent long-rest pools, preserving the receipt.
$restCommand=$make(); $restCast=$executor->execute($castSession,$restCommand)['cast'];
$hp=new App\Service\CharacterHitPointCalculator($ability); $hpState=new App\Service\CharacterHitPointStateService($hp);
$slotState=new App\Service\CharacterSpellSlotStateService($slotCalculator);
$sync=new App\Service\CharacterSessionStateSynchronizer($hp,$hpState,$resources,new App\Repository\CharacterSessionStateRepository($registry),$slotState);
$rest=new App\Service\CharacterRestService($hp,$hpState,$resources,$sync,$slotCalculator,$definitions,$ability,
    new App\Repository\CharacterActiveEffectRepository($registry),new App\Service\CharacterActiveEffectService($hpState),$em);
foreach ([App\Entity\RestRequest::TYPE_SHORT_REST=>0,App\Entity\RestRequest::TYPE_LONG_REST=>2] as $type=>$remaining) {
    $rest->apply($castSession,$type); $em->flush();
    $check(array_column($castSession->getState()['resources'],'currentValue','id')['directory-charge']===$remaining,'Grant recovery follows '.$type);
    $replay($restCommand,$restCast);
}

// Failure after flush, at commit, must roll back both the resource and its receipt.
$malformed=$initial;
$malformed['spellCastReceipts'][$restCommand['requestId']]=App\Service\SpellCastReceipts::create($restCommand,$restCast);
$malformed['spellCastReceipts'][$restCommand['requestId']]['cast']['cost']=0;
$reset($malformed);
$reject($make(),409,'malformed_spell_cast_receipts');
$untouchedAfter=$snapshot(); unset($untouchedAfter['character_session_state'],$untouchedAfter['feature_spell_grant']);
$check($untouchedAfter===$untouchedBefore,'Feature execution preserves acquisitions, preparations and all unrelated tables');
$reset($initial);
$db->executeStatement("CREATE FUNCTION pg_temp.reject_feature_commit() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN RAISE EXCEPTION ''synthetic feature commit failure''; RETURN NEW; END;'");
$db->executeStatement('CREATE CONSTRAINT TRIGGER reject_feature_commit AFTER UPDATE ON character_session_state DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION pg_temp.reject_feature_commit()');
$before=$stored();
try { $executor->execute($castSession,$make()); throw new RuntimeException('Expected feature commit failure'); }
catch(Doctrine\DBAL\Exception $e) { $check(str_contains($e->getMessage(),'synthetic feature commit failure'),'Failure occurs at commit'); }
$check($stored()===$before && !$db->isTransactionActive() && !$em->isOpen(),'Resource, receipt, revision and timestamp roll back together');
$db->executeStatement('DROP TRIGGER reject_feature_commit ON character_session_state');
