<?php
declare(strict_types=1);

// Runs inside test-fixed-feature-spell-sync.php's isolated temporary schema.
(static function () use ($em, $db, $registry, $campaign, $class, $spell, $check, $kernel, $synchronizer): void {
    $character = new App\Entity\Character($campaign, 'progression-caster', 'Progression caster', App\Entity\Character::TYPE_PLAYER);
    $outsider = new App\Entity\Character($campaign, 'progression-outsider', 'Outsider', App\Entity\Character::TYPE_PLAYER);
    $progression = (new App\Entity\ProgressionDefinition('fixture-shadow', 'Fixture shadow'))
        ->setDefaultCastingAbility(App\Enum\Ability::Wisdom);
    $assignment = new App\Entity\CharacterProgression($character, $progression);
    $character->addProgression($assignment);
    $feature = new App\Entity\CharacterFeatureDefinition('fixture-shadow-spell', 'Shadow spell');
    $rule = App\Entity\CharacterFeatureRule::forProgression($feature, $progression, 8);
    foreach ([$character, $outsider, $progression, $assignment, $feature, $rule] as $entity) $em->persist($entity);
    foreach ([$character, $outsider] as $candidate) {
        $level = new App\Entity\CharacterClassLevel($candidate, $class, 1);
        $candidate->addClassLevel($level); $em->persist($level);
    }
    $em->flush();
    $db->insert('feature_spell_grant', ['feature_id'=>$feature->getId(), 'mode'=>'fixed', 'spell_id'=>$spell['id'],
        'unlock_level'=>1, 'casting_ability'=>'CHOICE',
        'casting_rules'=>json_encode(['version'=>1,'methods'=>[['kind'=>'standard-slot']]])]);
    $grantId = (int)$db->lastInsertId();
    $features = new App\Service\CharacterFeatureResolver(new App\Repository\CharacterFeatureRuleRepository($registry));
    $service = new App\Service\CharacterFeatureSpellService($db, $features);
    $resolver = new App\Service\CharacterFeatureSpellCastingResolver($db, $features);
    $rows = static fn() => $db->fetchAllAssociative('SELECT * FROM character_feature_spell WHERE grant_id=? ORDER BY id', [$grantId]);
    $above = ['fixture-shadow'=>10]; $below = ['fixture-shadow'=>7];
    $check($service->status($character, $above)['known'] === [] && $rows() === [], 'GET never creates a progression acquisition');
    $check($service->propagateFixedGrant($grantId, [$character, $outsider], true) === ['eligible'=>1,'missing'=>1,'created'=>0], 'Source ownership drives preview without a session');
    $service->propagateFixedGrant($grantId, [$character, $outsider]);
    $initial = $rows();
    $check(count($initial) === 1 && (int)$initial[0]['character_id'] === $character->getId(), 'Fixed acquisition requires ownership, not a session threshold');
    $check(count($service->status($character, $above)['known']) === 1, 'Visible above threshold');
    $check(count($service->status($character, ['fixture-shadow'=>8])['known']) === 1, 'Visible at threshold');
    $check($service->status($character, $below)['known'] === [], 'Reversible progression hidden below threshold');
    $check($service->status($character)['known'] === [], 'No implicit session context');
    $service->synchronize($character);
    $service->synchronize($character);
    $check($rows() === $initial, 'Synchronization preserves acquisition and timestamps across different session reads');
    $check(count($service->status($character, $above)['known']) === 1, 'Lower session did not retire the acquisition for upper session');
    $check($resolver->resolve($character, $grantId, $above)['castingAbility'] === 'wisdom', 'Legacy progression CHOICE inherits WIS');
    $db->update('feature_spell_grant', ['casting_ability'=>null], ['id'=>$grantId]);
    $check($resolver->resolve($character, $grantId, $above)['castingAbility'] === 'wisdom', 'Null explicitly inherits default');
    $db->update('feature_spell_grant', ['casting_ability'=>'INT'], ['id'=>$grantId]);
    $check($resolver->resolve($character, $grantId, $above)['castingAbility'] === 'intelligence', 'Explicit grant overrides progression default');
    $db->update('feature_spell_grant', ['casting_ability'=>null], ['id'=>$grantId]);
    $progression->setDefaultCastingAbility(null);
    $check($resolver->resolve($character, $grantId, $above)['reason'] === 'ability_not_configured', 'No implicit class ability fallback');
    $check($resolver->resolve($character, $grantId, $below)['reason'] === 'provenance_missing', 'Casting also enforces threshold');
    $catalogue = new App\Service\CustomReferenceCatalogueService($em);
    $saved = $catalogue->saveEditor('progressions', $progression->getId(), ['defaultCastingAbility'=>'wisdom'], $campaign->getOwner(), true);
    $check($saved['defaultCastingAbility'] === 'wisdom', 'Reference editor can configure an already assigned progression');
    $check($db->fetchOne('SELECT default_casting_ability FROM progression_definition WHERE id=?', [$progression->getId()]) === 'wisdom', 'Default casting ability persists');
    $check($rows() === $initial, 'Reference configuration never rewrites existing acquisitions');
    $db->insert('feature_spell_grant', ['feature_id'=>$feature->getId(), 'mode'=>'fixed', 'spell_id'=>$spell['id'], 'unlock_level'=>1]);
    $newGrant = (int)$db->lastInsertId();
    $service->synchronizeProgression($character, $progression);
    $created = $db->fetchAllAssociative('SELECT * FROM character_feature_spell WHERE character_id=? AND grant_id=?', [$character->getId(), $newGrant]);
    $check(count($created) === 1 && $rows() === $initial, 'Progression assignment reconciles its fixed grants without rewriting history');
    $service->synchronizeProgression($character, $progression);
    $check($db->fetchAllAssociative('SELECT * FROM character_feature_spell WHERE character_id=? AND grant_id=?', [$character->getId(), $newGrant]) === $created, 'Repeated assignment synchronization is idempotent');

    $catalogue->saveEditor('progressions', $progression->getId(), ['irreversible'=>true], $campaign->getOwner(), true);
    $check($assignment->getMaximumReached() === null, 'Opt-in does not backfill a maximum');
    $check($service->status($character, $above)['known'] === [], 'Historical current value does not prove an irreversible unlock');
    $makeState = static fn(int $value): array => ['progressions'=>[['id'=>'fixture-shadow','currentValue'=>$value]], 'resources'=>[], 'inspiration'=>false];
    $recorder = new App\Service\CharacterProgressionMaximumRecorder($em);
    $recorder->recordChanges($character, $makeState(99), $makeState(99));
    $check($assignment->getMaximumReached() === null, 'Echoing an old snapshot is not a mutation');
    $recorder->recordChanges($character, $makeState(7), $makeState(8)); $em->flush();
    $check($assignment->getMaximumReached() === 8 && count($service->status($character, $below)['known']) === 2, 'Threshold crossed and available in another lower session');
    $recorder->recordChanges($character, $makeState(8), $makeState(7)); $em->flush();
    $check($assignment->getMaximumReached() === 8 && count($features->resolve($character)) === 1, 'Decrease preserves global capability even without a session lookup');
    $service->status($character, ['fixture-shadow'=>99]);
    $synchronizer->synchronize($character, $makeState(99));
    $synchronizer->synchronize($character, $makeState(99));
    $service->synchronize($character);
    $check($assignment->getMaximumReached() === 8 && $rows() === $initial, 'Historical reads and repeated sync preserve maximum and acquisitions');
    $recorder->recordChanges($character, $makeState(7), $makeState(10)); $em->flush();
    $check($assignment->getMaximumReached() === 10, 'A real higher mutation advances maximum');
    $progression->setIrreversible(false); $em->flush();
    $recorder->recordChanges($character, $makeState(7), $makeState(12));
    $check($assignment->getMaximumReached() === 10 && $service->status($character, $below)['known'] === [], 'Reversible mode ignores stored maximum and does not update it');
    $progression->setIrreversible(true); $em->flush();
    $tester = new Symfony\Component\Console\Tester\CommandTester(new App\Command\CorrectProgressionMaximumCommand($em));
    $args = ['assignment'=>(string)$assignment->getId(), 'maximum'=>'8', '--reason'=>'Reviewed fixture evidence'];
    $tester->execute($args);
    $check($assignment->getMaximumReached() === 10, 'Administrative preview is read only');
    $tester->execute($args + ['--apply'=>true, '--expected'=>'10']);
    $check($assignment->getMaximumReached() === 8 && count(json_decode($db->fetchOne('SELECT maximum_corrections FROM character_progression WHERE id=?', [$assignment->getId()]), true)) === 1, 'Explicit correction can lower maximum and retains audit reason');
    $freshAssignment = new App\Entity\CharacterProgression($outsider, $progression);
    $outsider->addProgression($freshAssignment); $em->persist($freshAssignment); $em->flush();
    $init = ['assignment'=>(string)$freshAssignment->getId(), 'maximum'=>'8', '--reason'=>'Explicit historical evidence'];
    $tester->execute($init);
    $check($freshAssignment->getMaximumReached() === null, 'Initialization preview cannot reconstruct history');
    $tester->execute($init + ['--apply'=>true, '--expected'=>'null']);
    $check($freshAssignment->getMaximumReached() === 8, 'Explicit reviewed initialization records a maximum');
    $tester->execute($init + ['--apply'=>true, '--expected'=>'8']);
    $check(count(json_decode($db->fetchOne('SELECT maximum_corrections FROM character_progression WHERE id=?', [$freshAssignment->getId()]), true)) === 1, 'Repeated correction is idempotent');

    $game = (new App\Entity\GameSession($campaign, 'maximum-live', 'Maximum live'))->setStatus(App\Entity\GameSession::STATUS_LIVE);
    $oldGame = (new App\Entity\GameSession($campaign, 'maximum-old', 'Maximum old'))->setStatus(App\Entity\GameSession::STATUS_LIVE);
    $live = new App\Entity\CharacterSessionState($game, $character, $makeState(7));
    $oldSession = new App\Entity\CharacterSessionState($oldGame, $character, $makeState(99));
    foreach ([$game,$oldGame,$live,$oldSession] as $entity) $em->persist($entity);
    $em->flush();
    $authSession = new Symfony\Component\HttpFoundation\Session\Session(new Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage());
    $authSession->start();
    $authSession->set('_security_main', serialize(new Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken($campaign->getOwner(), 'main', $campaign->getOwner()->getRoles())));
    foreach ([9, 5] as $value) {
        $request = Symfony\Component\HttpFoundation\Request::create('/sessions/'.$game->getId().'/characters/'.$character->getId().'/progressions/'.$progression->getId(),
            'PATCH', [], [$authSession->getName()=>$authSession->getId()], [], ['CONTENT_TYPE'=>'application/json'], json_encode(['currentValue'=>$value]));
        $request->setSession($authSession);
        $response = $kernel->handle($request);
        $check($response->getStatusCode() === 200, 'GM progression mutation accepted: '.$response->getStatusCode());
        $check($db->fetchOne('SELECT maximum_reached FROM character_progression WHERE id=?', [$assignment->getId()]) === 9, 'GM mutation also preserves global maximum on decrease');
    }
    $response = $kernel->handle(Symfony\Component\HttpFoundation\Request::create('/public/characters/'.$oldSession->getAccessToken()));
    $check($response->getStatusCode() === 200 && $db->fetchOne('SELECT maximum_reached FROM character_progression WHERE id=?', [$assignment->getId()]) === 9, 'GET of older higher session cannot raise maximum');
    foreach ([11, 6] as $value) {
        $request = Symfony\Component\HttpFoundation\Request::create('/public/characters/'.$live->getAccessToken(), 'PATCH', [], [], [], ['CONTENT_TYPE'=>'application/json'],
            json_encode(['revision'=>(int)$db->fetchOne('SELECT revision FROM character_session_state WHERE id=?', [$live->getId()]), 'state'=>['progressions'=>[['id'=>'fixture-shadow','currentValue'=>$value]]]]));
        $response = $kernel->handle($request);
        $check($response->getStatusCode() === 200, 'Public mutation accepted: '.$response->getContent());
        $storedMaximum = $db->fetchOne('SELECT maximum_reached FROM character_progression WHERE id=?', [$assignment->getId()]);
        $check($storedMaximum === 11, 'Public mutation updates global maximum monotonically: '.var_export($storedMaximum, true));
    }
    $response = $kernel->handle(Symfony\Component\HttpFoundation\Request::create('/public/characters/'.$live->getAccessToken()));
    $body = json_decode($response->getContent(), true);
    $check($response->getStatusCode() === 200 && in_array($feature->getId(), array_column($body['character']['features'], 'id'), true), 'Capability survives decreased current value in serialized portal');
    require __DIR__.'/test-managed-grant-cases.php';
})();
