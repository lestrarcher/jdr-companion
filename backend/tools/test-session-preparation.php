<?php

declare(strict_types=1);

// Run: docker compose exec backend php tools/test-session-preparation.php
// Transaction-local tables isolate every fixture from campaign data and sequences.
use App\Controller\{GameSessionController, CharacterSessionStateController};
use App\Entity\{Campaign, Character, CharacterSessionState, GameSession, User};
use App\Kernel;
use App\Repository\{CampaignRepository, GameSessionRepository, CharacterSessionStateRepository, CharacterFeatureRuleRepository, TrackableResourceRuleRepository};
use App\Service\{CharacterAbilityCalculator, CharacterHitPointCalculator, CharacterSpellSlotCalculator, CharacterFeatureResolver, CharacterResourceResolver, CharacterProfileSerializer, CharacterSessionStateSynchronizer, PlayerCharacterAccess, PlayerCharacterStateUpdater};
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\{AccessDecisionManager, AuthorizationChecker};

require __DIR__ . '/../vendor/autoload.php';
(new Dotenv())->bootEnv(__DIR__ . '/../.env');
$kernel = new Kernel('dev', true);
$kernel->boot();
$registry = $kernel->getContainer()->get('doctrine');
$em = $registry->getManager();
$db = $em->getConnection();
$checks = 0;
$check = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) throw new RuntimeException($label);
    ++$checks;
};
$body = static fn ($response) => json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
$request = static fn (array $payload) => Request::create('/', 'PATCH', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload, JSON_THROW_ON_ERROR));
$tokens = new TokenStorage();
$container = new Container();
$container->set('security.authorization_checker', new AuthorizationChecker($tokens, new AccessDecisionManager([new App\Security\Voter\CampaignVoter()])));
$controller = new GameSessionController();
$controller->setContainer($container);
$sessions = new GameSessionRepository($registry);
$campaigns = new CampaignRepository($registry);
$denied = static function (callable $operation) use ($check): void {
    try { $operation(); }
    catch (Symfony\Component\Security\Core\Exception\AccessDeniedException) { $check(true, 'Foreign or anonymous access rejected'); return; }
    throw new RuntimeException('Expected campaign access rejection');
};

try {
    $db->beginTransaction();
    $metadata = $em->getMetadataFactory()->getAllMetadata();
    foreach ((new SchemaTool($em))->getCreateSchemaSql($metadata) as $sql) {
        $sql = preg_replace('/^CREATE TABLE /', 'CREATE TEMP TABLE ', $sql);
        $sql = preg_replace('/^CREATE SEQUENCE /', 'CREATE TEMP SEQUENCE ', $sql);
        $db->executeStatement($sql);
    }
    foreach ($metadata as $class) {
        $table = $db->getDatabasePlatform()->quoteIdentifier($class->getTableName());
        if ($db->fetchOne('SELECT relpersistence FROM pg_class WHERE oid = to_regclass(?)', [$table]) !== 't') throw new RuntimeException('Unsafe fixture table');
    }
    $gm = (new User())->setEmail('preparation-gm@example.invalid')->setPassword('unused');
    $other = (new User())->setEmail('preparation-other@example.invalid')->setPassword('unused');
    $campaign = new Campaign($gm, 'preparation', 'Preparation');
    $session = new GameSession($campaign, 'one', 'One');
    $second = new GameSession($campaign, 'two', 'Two');
    foreach ([$gm, $other, $campaign, $session, $second] as $entity) $em->persist($entity);
    $em->flush();
    $tokens->setToken(new UsernamePasswordToken($gm, 'main', ['ROLE_USER']));
    $id = $session->getId();
    $check($body($controller->show($id, $sessions))['session']['preparationNotes'] === null, 'GM reads default null');
    $markdown = "# SECRET-PREPARATION\n\n**bold** and *italic*\n\n- [x] Door\n\n    indented code\nHard break  \nnext\n";
    $response = $controller->update($id, $request(['preparationNotes' => $markdown]), $sessions, $em);
    $check($response->getStatusCode() === 200, 'GM updates notes');
    $check($body($response)['session']['preparationNotes'] === $markdown, 'PATCH returns raw Markdown');
    $check($db->fetchOne('SELECT preparation_notes FROM game_session WHERE id = ?', [$id]) === $markdown, 'Database preserves raw Markdown and significant whitespace');
    $em->refresh($session);
    $check($body($controller->show($id, $sessions))['session']['preparationNotes'] === $markdown, 'GM reads persisted notes');
    $listed = $body($controller->list($campaign->getId(), $campaigns, $sessions))['sessions'];
    $check(in_array($markdown, array_column($listed, 'preparationNotes'), true), 'GM list includes notes');
    $check($second->getPreparationNotes() === null, 'Notes belong only to selected session');
    $controller->update($id, $request(['status' => 'live', 'displayState' => ['weather' => 'rain']]), $sessions, $em);
    $check($session->getPreparationNotes() === $markdown, 'Omitted notes are preserved');

    foreach ([null, '', " \t\r\n"] as $blank) {
        $controller->update($id, $request(['preparationNotes' => $blank]), $sessions, $em);
        $check($session->getPreparationNotes() === null, 'Null and blank normalize to null');
    }
    $controller->update($id, $request(['preparationNotes' => $markdown]), $sessions, $em);
    foreach ([42, false, [], ['text' => 'no'], str_repeat('a', 100_001), str_repeat('é', 50_001)] as $invalid) {
        $response = $controller->update($id, $request(['preparationNotes' => $invalid, 'status' => 'closed']), $sessions, $em);
        $check($response->getStatusCode() === 422, 'Reject invalid notes');
        $check($session->getPreparationNotes() === $markdown && $session->getStatus() === 'live', 'Invalid PATCH does not partially mutate session');
    }
    $check($controller->update($id, $request(['preparationNotes' => str_repeat('a', 100_000)]), $sessions, $em)->getStatusCode() === 200, 'Maximum size accepted');
    $controller->update($id, $request(['preparationNotes' => $markdown]), $sessions, $em);

    foreach ([$other, null] as $actor) {
        $tokens->setToken($actor === null ? null : new UsernamePasswordToken($actor, 'main', ['ROLE_USER']));
        $denied(fn () => $controller->show($id, $sessions));
        $denied(fn () => $controller->list($campaign->getId(), $campaigns, $sessions));
        $denied(fn () => $controller->update($id, $request(['preparationNotes' => 'forged']), $sessions, $em));
        $denied(fn () => $controller->display($id, $sessions));
    }
    $check($session->getPreparationNotes() === $markdown, 'Denied writes preserve notes');
    $public = $body($controller->publicShow($session->getDisplayAccessToken(), $sessions))['session'];
    $check(array_keys($public) === ['id', 'campaignId', 'status', 'displayState', 'updatedAt'], 'Public display has exact safe allowlist');
    $check(!str_contains(json_encode($public), 'SECRET-PREPARATION'), 'Public session cannot leak secret');
    $tokens->setToken(new UsernamePasswordToken($gm, 'main', ['ROLE_USER']));
    $check($body($controller->display($id, $sessions))['session'] === $public, 'Authenticated shared screen has same safe allowlist');

    $character = new Character($campaign, 'notes-player', 'Notes player', Character::TYPE_PLAYER);
    $state = new CharacterSessionState($session, $character, []);
    $em->persist($character); $em->persist($state); $em->flush();
    $ability = new CharacterAbilityCalculator();
    $hp = new CharacterHitPointCalculator($ability);
    $slots = new CharacterSpellSlotCalculator();
    $features = new CharacterFeatureResolver(new CharacterFeatureRuleRepository($registry));
    $resources = new CharacterResourceResolver(new TrackableResourceRuleRepository($registry), $ability, $features);
    $states = new CharacterSessionStateRepository($registry);
    $sync = new CharacterSessionStateSynchronizer($hp, $resources, $states, $slots);
    $player = new CharacterSessionStateController(new CharacterProfileSerializer($ability, $features, $resources, $hp, $slots), $sync);
    $player->setContainer(new Container());
    $access = new PlayerCharacterAccess($states);
    foreach ([$player->showPublic($state->getAccessToken(), $access), $player->updatePublic($state->getAccessToken(), $request(['state' => [], 'revision' => $state->getRevision()]), $access, $em, new PlayerCharacterStateUpdater($sync))] as $response) {
        $check($response->getStatusCode() === 200, 'Player request still succeeds');
        $check(!str_contains($response->getContent(), 'preparationNotes') && !str_contains($response->getContent(), 'SECRET-PREPARATION'), 'Player read/update response never exposes notes');
    }
    echo "OK: $checks session preparation assertions; temporary tables rolled back.\n";
} finally {
    while ($db->isTransactionActive()) $db->rollBack();
    $kernel->shutdown();
}
