<?php
// Included at the end of the isolated progression fixture. The casting executor owns its transactions.
$db->commit();
$character = $em->find(App\Entity\Character::class, $character->getId());
$caster = new App\Entity\CharacterClass('managed-caster', 'Managed caster', 6, 1, App\Enum\SpellcastingProgressionType::Full);
$em->persist($caster);
for ($i=2; $i<=4; ++$i) {
    $level = new App\Entity\CharacterClassLevel($character, $caster, $i);
    $character->addClassLevel($level); $em->persist($level);
}
$em->flush();
foreach ([$live->getId(), $oldSession->getId()] as $id) {
    $session = $em->find(App\Entity\CharacterSessionState::class, $id);
    $session->setState($synchronizer->synchronizeResources($character, $session->getState()));
}
$em->flush();
$call = static function(string $method, string $path, ?array $payload=null, int $status=200) use ($kernel,$authSession,$check): array {
    $request = Symfony\Component\HttpFoundation\Request::create($path, $method, [], [$authSession->getName()=>$authSession->getId()], [], ['CONTENT_TYPE'=>'application/json','HTTP_ACCEPT'=>'application/json'], $payload === null ? null : json_encode($payload));
    $request->setSession($authSession);
    $response = $kernel->handle($request);
    $check($response->getStatusCode() === $status, $method.' '.$path.' status '.$response->getStatusCode().': '.substr($response->getContent(),0,300));
    return json_decode($response->getContent(),true) ?? [];
};
$base = '/reference/official/features/'.$feature->getId().'/spell-grants';
$casting = ['freeUses'=>1,'rechargeType'=>'long-rest','castingLevel'=>2,'standardSlot'=>true];
$created = $call('POST', $base, ['mode'=>'fixed','spellId'=>$spell['id'],'castingAbility'=>null,'casting'=>$casting], 201);
$managed = end($created['grants']); $managedId = $managed['id'];
$check($managed['managedResourceId'] !== null && $managed['managedResourceId'] === $managed['resourceDefinitionId'], 'HTTP create generates a grant-owned resource');
$slug = $db->fetchOne('SELECT slug FROM trackable_resource_definition WHERE id=?',[$managed['managedResourceId']]);
$storedState = static fn(int $id): array => json_decode($db->fetchOne('SELECT state FROM character_session_state WHERE id=?',[$id]),true);
$current = static fn(int $id, string $pool): int => array_column($storedState($id)['resources'],'currentValue','id')[$pool];
$check($current($live->getId(),$slug) === 1 && $current($oldSession->getId(),$slug) === 1,'Both sessions initialize independent counters');
$profile = $call('GET','/public/characters/'.$live->getAccessToken());
$resource = array_values(array_filter($profile['character']['resources'],static fn($row)=>$row['slug']===$slug))[0];
$check($resource['hiddenFromTracker'] === true, 'Explicit technical metadata exposed to mapper');
$castPath = '/public/characters/'.$live->getAccessToken().'/spells/cast';
$command = ['revision'=>(int)$db->fetchOne('SELECT revision FROM character_session_state WHERE id=?',[$live->getId()]), 'requestId'=>'da000001-0000-4000-8000-000000000001', 'sourceKey'=>'feature:'.$managedId,'method'=>'resource','castingLevel'=>2];
$cast = $call('POST',$castPath,$command);
$check($current($live->getId(),$slug) === 0 && $current($oldSession->getId(),$slug) === 1, 'Free cast consumes only this session');
$call('POST',$castPath,$command);
$check($current($live->getId(),$slug) === 0,'Replay does not spend twice');
$beforeSlot=$current($live->getId(),'spell-slot-2');
$slotCommand = array_replace($command,['revision'=>(int)$db->fetchOne('SELECT revision FROM character_session_state WHERE id=?',[$live->getId()]),'requestId'=>'da000001-0000-4000-8000-000000000002','method'=>'standard-slot']);
$call('POST',$castPath,$slotCommand);
$check($current($live->getId(),'spell-slot-2') === $beforeSlot-1 && $current($live->getId(),$slug)===0,'Slot cast remains available after free exhaustion');
$call('PATCH',$base.'/'.$managedId,['casting'=>$casting]);
$check($current($live->getId(),$slug)===0,'Repeated save preserves consumption');
$second=$call('POST',$base,['mode'=>'fixed','spellId'=>$spell['id'],'castingAbility'=>'WIS','casting'=>$casting],201);
$second=end($second['grants']);
$check($second['managedResourceId']!==$managed['managedResourceId'],'Independent grant resources');
$resourceCount=(int)$db->fetchOne('SELECT COUNT(*) FROM trackable_resource_definition');
$call('PATCH',$base.'/'.$managedId,['casting'=>array_replace($casting,['freeUses'=>0])]);
$call('PATCH',$base.'/'.$managedId,['casting'=>$casting]);
$check($current($live->getId(),$slug)===0 && (int)$db->fetchOne('SELECT COUNT(*) FROM trackable_resource_definition')===$resourceCount,'Free off/on reuses resource without resetting counter');
$call('DELETE',$base.'/'.$managedId);
$check((bool)$db->fetchOne('SELECT 1 FROM character_feature_spell WHERE grant_id=? AND character_id=?',[$managedId,$character->getId()]),'Soft deletion preserves acquisition');
$call('POST',$castPath,$command); // Historical receipt survives disabled source.
$call('PATCH',$base.'/'.$managedId,['enabled'=>true]);
$check($current($live->getId(),$slug)===0,'Grant reactivation does not replenish');
$check($db->fetchOne('SELECT COUNT(*) FROM trackable_resource_definition') === $resourceCount,'Repeated lifecycle does not duplicate resources');
// Reuse the real rest service on managed fixtures.
$hp = new App\Service\CharacterHitPointCalculator(new App\Service\CharacterAbilityCalculator());
$hpState = new App\Service\CharacterHitPointStateService($hp);
$resourceResolver = new App\Service\CharacterResourceResolver(new App\Repository\TrackableResourceRuleRepository($registry),new App\Service\CharacterAbilityCalculator(),$features,$em);
$rest = new App\Service\CharacterRestService($hp,$hpState,$resourceResolver,$synchronizer,new App\Service\CharacterSpellSlotCalculator(),new App\Repository\TrackableResourceDefinitionRepository($registry),new App\Service\CharacterAbilityCalculator(),new App\Repository\CharacterActiveEffectRepository($registry),new App\Service\CharacterActiveEffectService($hpState),$em);
$session=$em->find(App\Entity\CharacterSessionState::class,$live->getId()); $em->refresh($session);
$rest->apply($session,App\Entity\RestRequest::TYPE_SHORT_REST); $em->flush();
$check($current($live->getId(),$slug)===0,'Long rest pool not replenished by short rest');
$call('PATCH',$base.'/'.$managedId,['casting'=>array_replace($casting,['rechargeType'=>'short-rest'])]);
$session=$em->find(App\Entity\CharacterSessionState::class,$live->getId()); $em->refresh($session);
$rest->apply($session,App\Entity\RestRequest::TYPE_SHORT_REST); $em->flush();
$check($current($live->getId(),$slug)===1,'Configured short rest replenishes free pool');
$next=$session->getState();
foreach ($next['resources'] as &$counter) if (str_starts_with($counter['id'],'spell-slot-')) $counter['currentValue']=0;
unset($counter); $session->setState($next); $em->flush();
$freeCommand=array_replace($command,['revision'=>(int)$db->fetchOne('SELECT revision FROM character_session_state WHERE id=?',[$live->getId()]),'requestId'=>'da000001-0000-4000-8000-000000000003']);
$call('POST',$castPath,$freeCommand);
$check($current($live->getId(),$slug)===0 && $current($live->getId(),'spell-slot-2')===0,'Free method works while all standard slots are exhausted');
$session=$em->find(App\Entity\CharacterSessionState::class,$live->getId()); $em->refresh($session);
$receipts=$session->getState()['spellCastReceipts'];
$rest->apply($session,App\Entity\RestRequest::TYPE_LONG_REST); $em->flush();
$check($current($live->getId(),$slug)===1 && $session->getState()['spellCastReceipts']===$receipts,'Long rest recharges without deleting receipts');
$call('PATCH',$base.'/'.$managedId,['casting'=>array_replace($casting,['freeUses'=>2])]);
$check($current($live->getId(),$slug)===1,'Increasing maximum does not replenish existing counters');
$call('PATCH',$base.'/'.$managedId,['castingAbility'=>'INT']);
$check($db->fetchOne('SELECT casting_ability FROM feature_spell_grant WHERE id=?',[$managedId])==='INT','Existing grant ability editable independently');
$call('PATCH',$base.'/'.$managedId,['castingAbility'=>null]);
$beforeInvalid=$db->fetchAssociative('SELECT * FROM feature_spell_grant WHERE id=?',[$managedId]);
$call('PATCH',$base.'/'.$managedId,['casting'=>array_replace($casting,['freeUses'=>0,'standardSlot'=>false])],400);
$check($db->fetchAssociative('SELECT * FROM feature_spell_grant WHERE id=?',[$managedId])===$beforeInvalid,'Invalid no-method configuration rolls back');

// Historical fixed grant with a missing acquisition: CLI and PATCH repair only it.
$ineligible = new App\Entity\Character($em->find(App\Entity\Campaign::class, $campaign->getId()), 'repair-ineligible', 'Repair ineligible', App\Entity\Character::TYPE_PLAYER);
$em->persist($ineligible); $em->flush();
$db->delete('character_feature_spell', ['character_id'=>$character->getId(), 'grant_id'=>$managedId]);
$preserved = static fn(): array => [
    $db->fetchAllAssociative('SELECT * FROM character_progression ORDER BY id'),
    $db->fetchAllAssociative('SELECT * FROM character_session_state ORDER BY id'),
    $db->fetchAllAssociative('SELECT * FROM trackable_resource_definition ORDER BY id'),
    $db->fetchAllAssociative('SELECT * FROM feature_spell_grant ORDER BY id'),
    $db->fetchAllAssociative('SELECT * FROM character_feature_spell WHERE NOT (grant_id='.$managedId.' AND character_id='.$character->getId().') ORDER BY id'),
];
$beforeRepair = $preserved();
$repairService = new App\Service\CharacterFeatureSpellService($db, $features, new App\Service\CharacterAbilityCalculator(),
    new App\Service\FeatureSpellGrantConfiguration($db, $em, $synchronizer));
$tester = new Symfony\Component\Console\Tester\CommandTester(new App\Command\ReconcileFixedSpellGrantCommand(
    $db, new App\Repository\CharacterRepository($registry), $repairService));
$args = ['character'=>(string)$character->getId(), 'grant'=>(string)$managedId];
$check($tester->execute($args) === 0, 'Reconciliation defaults to successful preview');
$check(json_decode($tester->getDisplay(),true)['missing'] === 1 && $preserved() === $beforeRepair
    && !$db->fetchOne('SELECT 1 FROM character_feature_spell WHERE grant_id=? AND character_id=?',[$managedId,$character->getId()]), 'Preview has no writes: '.$tester->getDisplay().' preserved='.(int)($preserved() === $beforeRepair).' remaining='.json_encode($db->fetchAllAssociative('SELECT character_id FROM character_feature_spell WHERE grant_id=? AND character_id=?',[$managedId,$character->getId()])));
$check($tester->execute([...$args,'--apply'=>true]) === 0 && json_decode($tester->getDisplay(),true)['created'] === 1, 'Scoped CLI creates missing acquisition');
$check($preserved() === $beforeRepair, 'CLI preserves all progressions, sessions, consumed pools, receipts, grants and other acquisitions');
$acquisition = $db->fetchAssociative('SELECT * FROM character_feature_spell WHERE grant_id=? AND character_id=?',[$managedId,$character->getId()]);
$tester->execute([...$args,'--apply'=>true]);
$check(json_decode($tester->getDisplay(),true)['created'] === 0 && $preserved() === $beforeRepair
    && $db->fetchAssociative('SELECT * FROM character_feature_spell WHERE grant_id=? AND character_id=?',[$managedId,$character->getId()]) === $acquisition, 'CLI apply is idempotent');
$check($tester->execute(['character'=>(string)$ineligible->getId(),'grant'=>(string)$managedId,'--apply'=>true]) === 1
    && $preserved() === $beforeRepair, 'Ineligible character is not reconciled');
$db->delete('character_feature_spell', ['character_id'=>$character->getId(), 'grant_id'=>$managedId]);
$call('PATCH',$base.'/'.$managedId,['castingAbility'=>null]);
$check((int)$db->fetchOne('SELECT COUNT(*) FROM character_feature_spell WHERE grant_id=? AND character_id=?',[$managedId,$character->getId()]) === 1
    && $preserved() === $beforeRepair, 'PATCH reconciles missing fixed acquisition without touching history or other characters');
$acquisition = $db->fetchAssociative('SELECT * FROM character_feature_spell WHERE grant_id=? AND character_id=?',[$managedId,$character->getId()]);
$call('PATCH',$base.'/'.$managedId,['castingAbility'=>null]);
$check($db->fetchAssociative('SELECT * FROM character_feature_spell WHERE grant_id=? AND character_id=?',[$managedId,$character->getId()]) === $acquisition
    && $preserved() === $beforeRepair, 'Repeated PATCH is idempotent');
$db->update('character_feature_spell',['active'=>0,'retired_at'=>date('Y-m-d H:i:s')],['id'=>$acquisition['id']]);
$tester->execute([...$args,'--apply'=>true]);
$call('PATCH',$base.'/'.$managedId,['castingAbility'=>null]);
$check(!$db->fetchOne('SELECT active FROM character_feature_spell WHERE id=?',[$acquisition['id']]), 'Existing inactive acquisition is never reactivated by reconciliation');
