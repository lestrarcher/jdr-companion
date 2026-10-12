<?php

declare(strict_types=1);

// Included by test-player-security.php, using transaction-local tables only.
if (!isset($db, $check, $s, $controller, $inspiration)) throw new LogicException('Run test-player-security.php.');
$inspirationChecksBefore = $checks;
[$hero, , $heroCampaign] = $fixture($s, 1);
$effectRepo = new App\Repository\CharacterActiveEffectRepository($registry);
$stateRepo = new App\Repository\CharacterSessionStateRepository($registry);
$factory = new App\Service\CharacterSessionStateFactory($s['hp'], $s['resources'], $s['slots'], $effectRepo);
$gameA = (new App\Entity\GameSession($heroCampaign, 'inspiration-a', 'Inspiration A'))->setStatus(App\Entity\GameSession::STATUS_LIVE);
$gameB = (new App\Entity\GameSession($heroCampaign, 'inspiration-b', 'Inspiration B'))->setStatus(App\Entity\GameSession::STATUS_LIVE);
$stateA = new App\Entity\CharacterSessionState($gameA, $hero, $factory->create($hero));
$stateB = new App\Entity\CharacterSessionState($gameB, $hero, ['notes' => 'Separate session']);
foreach ([$gameA, $gameB, $stateA, $stateB] as $entity) $em->persist($entity);
$em->flush();
$check(!$hero->hasInspiration() && $stateA->getState()['inspiration'] === false, 'Existing/missing inspiration is false');
$check($factory->create($hero)['inspiration'] === false, 'Factory starts without inspiration');
$check($serializer->serialize($stateA)['state']['inspiration'] === false, 'Player and GM serializer exposes boolean');
$check(!array_key_exists('inspiration', json_decode($db->fetchOne('SELECT state FROM character_session_state WHERE id = ?', [$stateA->getId()]), true)), 'One canonical value, no session duplicate');

$gm = new App\Controller\CharacterInspirationController();
$tokens = new Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage();
$tokens->setToken(new Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken($heroCampaign->getOwner(), 'main', ['ROLE_USER']));
$gmContainer = new Symfony\Component\DependencyInjection\Container();
$gmContainer->set('security.authorization_checker', new Symfony\Component\Security\Core\Authorization\AuthorizationChecker($tokens,
    new Symfony\Component\Security\Core\Authorization\AccessDecisionManager([new App\Security\Voter\CampaignVoter()])));
$gm->setContainer($gmContainer);
$inspirationRequest = static fn (array $body) => Symfony\Component\HttpFoundation\Request::create('/', 'PATCH', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR));
$gmCall = static fn (array $body) => $gm->update($gameA->getId(), $hero->getId(), $inspirationRequest($body), $stateRepo, $em, $inspiration, $serializer);
$playerCall = static fn (array $patch, ?int $revision = null) => $controller->updatePublic($stateA->getAccessToken(), $inspirationRequest(['revision' => $revision ?? $stateA->getRevision(), 'state' => $patch]), $access, $em, $updater, $inspiration);
$httpStatus = static function (callable $operation): int {
    try { return $operation()->getStatusCode(); }
    catch (Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) { return $e->getStatusCode(); }
};
$check($httpStatus(fn () => $playerCall(['inspiration' => true])) === 403, 'Player cannot grant inspiration');
foreach ([null, 0, 1, 'true', [], ['value' => true]] as $invalid) {
    $check($httpStatus(fn () => $playerCall(['inspiration' => $invalid])) === 422, 'Player inspiration must be boolean');
    $check($gmCall(['inspiration' => $invalid, 'revision' => $stateA->getRevision()])->getStatusCode() === 422, 'GM inspiration must be boolean');
}
$check($gmCall(['inspiration' => true])->getStatusCode() === 422, 'GM revision required');
$tokens->setToken(new Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken($campaign->getOwner(), 'main', ['ROLE_USER']));
try { $gmCall(['inspiration' => true, 'revision' => 1]); throw new RuntimeException('Foreign GM accepted'); }
catch (Symfony\Component\Security\Core\Exception\AccessDeniedException) { $check(true, 'Foreign GM denied by campaign voter'); }
$tokens->setToken(new Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken($heroCampaign->getOwner(), 'main', ['ROLE_USER']));

$revisionA = $stateA->getRevision(); $revisionB = $stateB->getRevision();
$granted = $gmCall(['inspiration' => true, 'revision' => $revisionA]);
$check($granted->getStatusCode() === 200 && json_decode($granted->getContent(), true)['state']['inspiration'] === true, 'GM grant response contains true');
$check($stateA->getRevision() === $revisionA + 1 && $stateB->getRevision() === $revisionB + 1, 'Grant invalidates revisions in all sessions');
$check($stateB->getState()['inspiration'] === true && $stateB->getState()['notes'] === 'Separate session', 'Other session shares inspiration but preserves its own state');
$check($gmCall(['inspiration' => false, 'revision' => $revisionA])->getStatusCode() === 409 && $hero->hasInspiration(), 'Stale GM request rejected');
$revisionA = $stateA->getRevision();
$check($gmCall(['inspiration' => true, 'revision' => $revisionA])->getStatusCode() === 200 && $stateA->getRevision() === $revisionA, 'Granting twice is an idempotent boolean assignment');
$check($playerCall(['inspiration' => true])->getStatusCode() === 200 && $hero->hasInspiration(), 'Player may echo unchanged true in a snapshot');
$check($playerCall([])->getStatusCode() === 200 && $hero->hasInspiration(), 'Omitted inspiration is preserved');
$stateA->setState(array_replace($stateA->getState(), ['inspiration' => false]));
$em->flush();
$check($hero->hasInspiration(), 'Generic state setter cannot overwrite canonical inspiration');
$check($factory->create($hero)['inspiration'] === true, 'New session inherits character inspiration');
$rest = new App\Service\CharacterRestService($s['hp'], $s['hpState'], $s['resources'], $s['sync'], $s['slots'],
    new App\Repository\TrackableResourceDefinitionRepository($registry), $s['ability'], $effectRepo,
    new App\Service\CharacterActiveEffectService($s['hpState']), $em);
foreach ([App\Entity\RestRequest::TYPE_SHORT_REST, App\Entity\RestRequest::TYPE_LONG_REST] as $type) {
    $rest->apply($stateA, $type); $em->flush();
    $check($stateA->getState()['inspiration'] === true && $stateB->getState()['inspiration'] === true, $type.' preserves inspiration');
}
$revisionA = $stateA->getRevision(); $revisionB = $stateB->getRevision();
$spent = $playerCall(['inspiration' => false], $revisionA);
$check($spent->getStatusCode() === 200 && json_decode($spent->getContent(), true)['state']['inspiration'] === false, 'Player spend response is immediately false');
$check(!$hero->hasInspiration() && $stateA->getRevision() === $revisionA + 1 && $stateB->getRevision() === $revisionB + 1, 'Spend persists and synchronizes all revisions exactly once');
$check($playerCall(['inspiration' => false], $revisionA)->getStatusCode() === 409, 'Replayed spend rejected');
$check($httpStatus(fn () => $playerCall(['inspiration' => true])) === 403, 'Spent inspiration cannot be restored by player');
$check($playerCall(['inspiration' => false])->getStatusCode() === 200 && !$hero->hasInspiration(), 'Echo of false is harmless');
$rest->apply($stateA, App\Entity\RestRequest::TYPE_LONG_REST); $em->flush();
$check(!$hero->hasInspiration(), 'Long rest does not grant inspiration');
$check($gmCall(['inspiration' => true, 'revision' => $stateA->getRevision()])->getStatusCode() === 200, 'GM can grant again after spend');
$check($gmCall(['inspiration' => false, 'revision' => $stateA->getRevision()])->getStatusCode() === 200 && !$hero->hasInspiration(), 'GM can withdraw inspiration');
$gameA->setStatus(App\Entity\GameSession::STATUS_CLOSED); $em->flush();
$check($httpStatus(fn () => $playerCall(['inspiration' => false])) === 409, 'Closed session cannot spend');
$check($gmCall(['inspiration' => true, 'revision' => $stateA->getRevision()])->getStatusCode() === 200, 'GM may grant outside a live session');
$stateA->setParticipating(false); $em->flush();
$check($httpStatus(fn () => $playerCall(['inspiration' => false])) === 404, 'Inactive player link cannot spend');
$check($gmCall(['inspiration' => false, 'revision' => $stateA->getRevision()])->getStatusCode() === 404, 'Inactive session assignment cannot be edited');
$check($stateB->getState()['inspiration'] === true, 'Removing a session participant preserves inspiration');
$em->refresh($hero); $em->refresh($stateB);
$check($hero->hasInspiration() && $stateB->getState()['inspiration'] === true, 'Persisted value survives ORM refresh');

$revisionA = $stateA->getRevision(); $revisionB = $stateB->getRevision();
$db->beginTransaction();
try {
    $em->lock($hero, Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);
    $inspiration->changeLocked($hero, false);
    $em->flush();
    $check(!$hero->hasInspiration(), 'Change flushed inside rollback scenario');
} finally {
    $db->rollBack();
    $em->refresh($hero); $em->refresh($stateA); $em->refresh($stateB);
}
$check($hero->hasInspiration() && $stateA->getRevision() === $revisionA && $stateB->getRevision() === $revisionB, 'Rollback restores inspiration and every session revision atomically');

echo 'OK: '.($checks - $inspirationChecksBefore)." inspiration assertions (temporary tables).\n";
echo 'Inspiration response sample: '.json_encode(['id' => $stateB->getId(), 'revision' => $stateB->getRevision(), 'character' => ['id' => $hero->getId(), 'name' => $hero->getName()], 'state' => ['inspiration' => $serializer->serialize($stateB)['state']['inspiration']]], JSON_THROW_ON_ERROR)."\n";
