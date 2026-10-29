<?php
// Included only in the isolated fixed-grant fixture.
$reconciler = new App\Service\FeatureSpellGrantReconciler($em, $features, $repairService);
$editor = new App\Service\CustomReferenceFeatureRuleService($db, $reconciler);
$actor = $em->find(App\Entity\User::class, $campaign->getOwner()->getId());
$fixtureAssignment = $em->find(App\Entity\CharacterProgression::class, $assignment->getId());
$fixtureAssignment->recordReachedValue(15); $em->flush();
$edit = static fn(array $payload) => $editor->saveEditor($rule->getId(), $payload, $actor, true);
$active = static fn(int $grant, int $who): bool => (bool)$db->fetchOne('SELECT active FROM character_feature_spell WHERE grant_id=? AND character_id=?', [$grant,$who]);
$idOf = static fn(int $grant): int => (int)$db->fetchOne('SELECT id FROM character_feature_spell WHERE grant_id=? AND character_id=?', [$grant,$character->getId()]);
$sessionSnapshot = static fn(): array => $db->fetchAllAssociative('SELECT id,state FROM character_session_state ORDER BY id');
$edit(['progressionThreshold'=>15]);
$check($active($managedId,$character->getId()), 'Threshold 15 active at historical maximum 15');
$savedId = $idOf($managedId); $savedSessions = $sessionSnapshot();
$edit(['progressionThreshold'=>20]);
$check(!$active($managedId,$character->getId()), 'Raising threshold retires the acquisition');
$check($db->fetchOne('SELECT maximum_reached FROM character_progression WHERE id=?',[$assignment->getId()]) === 15
    && $sessionSnapshot() === $savedSessions, 'Reference edit never changes maximum, current values, counters or receipts');
$edit(['progressionThreshold'=>15]);
$check($active($managedId,$character->getId()) && $idOf($managedId) === $savedId && $sessionSnapshot() === $savedSessions, 'Lowering threshold restores the same row without a refill');
$call('PATCH',$base.'/'.$managedId,['enabled'=>false]);
$check(!$active($managedId,$character->getId()), 'Disabling grant retires its acquisition');
$call('PATCH',$base.'/'.$managedId,['enabled'=>true]);
$check($active($managedId,$character->getId()) && $idOf($managedId) === $savedId && $sessionSnapshot() === $savedSessions, 'Re-enabling restores without consuming or refilling');

// Moving the rule must visit both old and new owners, even without prior rows.
$newProgression = (new App\Entity\ProgressionDefinition('moved-shadow','Moved shadow'))->setIrreversible(true);
$newOwner = $em->find(App\Entity\Character::class, $ineligible->getId());
$newLevel = new App\Entity\CharacterClassLevel($newOwner, $em->find(App\Entity\CharacterClass::class,$class->getId()), 1);
$newOwner->addClassLevel($newLevel); $em->persist($newLevel);
$newAssignment = new App\Entity\CharacterProgression($newOwner,$newProgression);
$newOwner->addProgression($newAssignment); $newAssignment->recordReachedValue(15);
$em->persist($newProgression); $em->persist($newAssignment); $em->flush();
$edit(['sourceId'=>$newProgression->getId()]);
$check(!$active($managedId,$character->getId()) && $active($managedId,$newOwner->getId()), 'Moving source retires old owner and acquires for new owner');
$edit(['sourceId'=>$progression->getId()]);
$check($active($managedId,$character->getId()) && !$active($managedId,$newOwner->getId()), 'Moving source back restores old ownership');

// A second independent rule keeps the feature available when the first is moved.
$alternative = $editor->saveEditor(null,['featureDefinitionId'=>$feature->getId(),'sourceType'=>'progression','sourceId'=>$progression->getId(),'progressionThreshold'=>10],$actor,true);
$edit(['progressionThreshold'=>20]);
$check($active($managedId,$character->getId()), 'Another qualifying attribution prevents retirement');
$edit(['progressionThreshold'=>15]);
$editor->saveEditor($alternative['id'],['progressionThreshold'=>30],$actor,true);

$choiceSpell = (new App\Service\SpellCatalogue($db))->create(['slug'=>'reconcile-choice','name'=>'Reconcile choice','level'=>1,'school'=>'conjuration',
    'castingTime'=>'1 action','range'=>'Self','duration'=>'Instantaneous','description'=>'Fixture','classIds'=>[]], $actor, true);
$beforeRejected = $db->fetchAllAssociative('SELECT * FROM feature_spell_grant ORDER BY id');
$call('POST',$base,['mode'=>'choice','spellLevel'=>1],400);
$check($db->fetchAllAssociative('SELECT * FROM feature_spell_grant ORDER BY id') === $beforeRejected, 'Irreversible progression rejects choice creation without writes');
$check($call('GET',$base.'/options')['choiceAllowed'] === false, 'Progression options disallow choices');
// Choices retain their lifecycle on compatible class sources.
$choiceFeature = new App\Entity\CharacterFeatureDefinition('reconcile-class-choice','Class choice');
$em->persist($choiceFeature); $em->flush();
$choiceRule = $editor->saveEditor(null,['featureDefinitionId'=>$choiceFeature->getId(),'sourceType'=>'class','sourceId'=>$class->getId(),'unlockLevel'=>1],$actor,true);
$choiceBase = '/reference/official/features/'.$choiceFeature->getId().'/spell-grants';
$check($call('GET',$choiceBase.'/options')['choiceAllowed'] === true, 'Class options still allow choices');
$choiceResponse = $call('POST',$choiceBase,['mode'=>'choice','spellLevel'=>1,'castingAbility'=>'WIS','casting'=>['freeUses'=>0,'standardSlot'=>true]],201);
$choiceGrant = end($choiceResponse['grants'])['id'];
$check(!$active($choiceGrant,$character->getId()), 'Choice is never selected automatically');
$repairService->choose($em->find(App\Entity\Character::class,$character->getId()),$choiceGrant,$choiceSpell['id'],$actor->getId());
$choiceAcquisition = $idOf($choiceGrant);
$call('PATCH',$choiceBase.'/'.$choiceGrant,['enabled'=>false]);
$check(!$active($choiceGrant,$character->getId()), 'Disabled choice retires without deletion');
$call('PATCH',$choiceBase.'/'.$choiceGrant,['enabled'=>true]);
$check($active($choiceGrant,$character->getId()) && $idOf($choiceGrant) === $choiceAcquisition, 'Re-enabled choice restores previous spell');
$call('PATCH',$choiceBase.'/'.$choiceGrant,['spellLevel'=>2]);
$check(!$active($choiceGrant,$character->getId()), 'Invalidated choice is retired');
$check(in_array($choiceGrant,array_column($repairService->status($em->find(App\Entity\Character::class,$character->getId()))['pending'],'grantId'),true), 'Invalid choice becomes selectable again');
$call('PATCH',$choiceBase.'/'.$choiceGrant,['spellLevel'=>1]);
$check($active($choiceGrant,$character->getId()) && $idOf($choiceGrant) === $choiceAcquisition, 'Valid historical choice is restored');
$call('PATCH',$base.'/'.$managedId,['spellId'=>$choiceSpell['id']]);
$check($idOf($managedId) === $savedId && (int)$db->fetchOne('SELECT spell_id FROM character_feature_spell WHERE id=?',[$savedId]) === $choiceSpell['id'], 'Fixed spell replacement reuses acquisition identity');
$check($sessionSnapshot() === $savedSessions, 'All reference lifecycle changes preserve session counters and history');

// Reversible availability is session-specific; the shared acquisition is retained.
$catalogue = new App\Service\CustomReferenceCatalogueService($em,$reconciler);
$catalogue->saveEditor('progressions',$progression->getId(),['irreversible'=>false],$actor,true);
$call('POST',$base,['mode'=>'choice','spellLevel'=>1],400);
$main = $em->find(App\Entity\Character::class,$character->getId());
$check($active($managedId,$character->getId()) && !in_array($managedId,array_column($repairService->status($main,['fixture-shadow'=>6])['known'],'grantId'),true), 'Low reversible session hides but does not retire shared acquisition');
$check(in_array($managedId,array_column($repairService->status($main,['fixture-shadow'=>20])['known'],'grantId'),true), 'Other reversible session still sees the acquired spell');
$check($db->fetchOne('SELECT maximum_reached FROM character_progression WHERE id=?',[$assignment->getId()]) === 15, 'Reversible reference edits never overwrite historical maximum');

// Exercise the personal API service, not only the official editor entry point.
$actor = $em->find(App\Entity\User::class, $actor->getId());
$personalProgression = (new App\Entity\ProgressionDefinition('personal-edit','Personal edit'))->setIrreversible(true);
$personalProgression->markAsPersonalReference($actor);
$personalFeature = new App\Entity\CharacterFeatureDefinition('personal-edit','Personal edit');

$personalAssignment = new App\Entity\CharacterProgression($main,$personalProgression);
$main->addProgression($personalAssignment); $personalAssignment->recordReachedValue(15);
foreach ([$personalProgression,$personalFeature,$personalAssignment] as $entity) $em->persist($entity);
$em->flush();
$db->update('character_feature_definition', ['origin'=>'CUSTOM','owner_id'=>$actor->getId(),'custom'=>1], ['id'=>$personalFeature->getId()]);
$em->refresh($personalFeature);
$personalRule = $editor->create(['featureDefinitionId'=>$personalFeature->getId(),'sourceType'=>'progression','sourceId'=>$personalProgression->getId(),'progressionThreshold'=>15],$actor);
$personalBase = '/reference/custom/features/'.$personalFeature->getId().'/spell-grants';
$call('POST',$personalBase,['mode'=>'choice','spellLevel'=>1],400);
$response = $call('POST',$personalBase,['mode'=>'fixed','spellId'=>$choiceSpell['id'],'castingAbility'=>'WIS','casting'=>['freeUses'=>0,'standardSlot'=>true]],201);
$personalGrant = end($response['grants'])['id'];
$check($active($personalGrant,$main->getId()), 'Custom fixed grant created after source attribution is acquired automatically');
$editor->patch($personalRule['id'],['progressionThreshold'=>20],$actor);
$check(!$active($personalGrant,$main->getId()), 'Personal PATCH threshold retires acquisition');
$editor->patch($personalRule['id'],['progressionThreshold'=>15],$actor);
$check($active($personalGrant,$main->getId()), 'Personal PATCH restores acquisition');
$catalogue->progressionChild($personalProgression->getId(),'stages',null,['label'=>'First','minimumValue'=>10,'maximumValue'=>14],$actor,false);
$stageId = (int)$db->fetchOne('SELECT id FROM progression_stage WHERE progression_definition_id=?',[$personalProgression->getId()]);
$catalogue->progressionChild($personalProgression->getId(),'stages',$stageId,['minimumValue'=>15,'maximumValue'=>19],$actor,false);
$check((int)$db->fetchOne('SELECT minimum_value FROM progression_stage WHERE id=?',[$stageId]) === 15, 'Assigned personal progression stage range can be moved');
$stranger = (new App\Entity\User())->setEmail('rule-stranger@example.invalid')->setPassword('unused');
$em->persist($stranger); $em->flush();
$denied = false;
try { $editor->patch($personalRule['id'],['progressionThreshold'=>20],$stranger); }
catch (Symfony\Component\HttpKernel\Exception\NotFoundHttpException) { $denied = true; }
$check($denied && $active($personalGrant,$main->getId()), 'Foreign owner cannot edit or reconcile personal attribution');

// Every rule-writing route uses the same validation, including feature replacements.
$reject = static function (callable $operation) use ($db,$check): void {
    $before = $db->fetchAllAssociative('SELECT * FROM character_feature_rule ORDER BY id');
    $denied = false;
    try { $operation(); } catch (Symfony\Component\HttpKernel\Exception\BadRequestHttpException $error) {
        $denied = $error->getMessage() === 'Une progression ne peut accorder que des sorts fixes.';
    }
    $check($denied && $before === $db->fetchAllAssociative('SELECT * FROM character_feature_rule ORDER BY id'), 'Choice-to-progression attachment rejected atomically');
};
$payload = ['featureDefinitionId'=>$choiceFeature->getId(),'sourceType'=>'progression','sourceId'=>$personalProgression->getId(),'progressionThreshold'=>1];
$reject(fn() => $editor->create($payload,$actor));
$reject(fn() => $editor->patch($personalRule['id'],['featureDefinitionId'=>$choiceFeature->getId()],$actor));
$reject(fn() => $editor->saveEditor(null,$payload,$actor,false));
$reject(fn() => $editor->saveEditor($choiceRule['id'],['sourceType'=>'progression','sourceId'=>$progression->getId(),'progressionThreshold'=>1],$actor,true));
$reject(fn() => $editor->saveEditor($rule->getId(),['featureDefinitionId'=>$choiceFeature->getId()],$actor,true));
// Even a disabled choice cannot be attached then re-enabled to bypass the invariant.
$call('PATCH',$choiceBase.'/'.$choiceGrant,['enabled'=>false]);
$reject(fn() => $editor->create($payload,$actor));
// Simulate an invalid legacy relationship in the fixture to exercise PATCH protection.
$db->update('feature_spell_grant',['feature_id'=>$personalFeature->getId()],['id'=>$choiceGrant]);
$call('PATCH',$personalBase.'/'.$choiceGrant,['enabled'=>true],400);
$call('PATCH',$personalBase.'/'.$choiceGrant,['spellLevel'=>2],400);
$check(!(bool)$db->fetchOne('SELECT enabled FROM feature_spell_grant WHERE id=?',[$choiceGrant]), 'Rejected legacy choice edit leaves it disabled');
