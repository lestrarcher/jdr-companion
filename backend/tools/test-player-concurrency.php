<?php

declare(strict_types=1);

// Real independent PHP processes/connections. Uses a unique disposable PostgreSQL
// schema (not public data or public sequences); workers start from deliberately stale entities.
use App\Entity\{Campaign, Character, CharacterClass, CharacterClassLevel, CharacterSessionState, GameSession, MagicItem, CharacterMagicItem, RestRequest, User};
use App\Enum\{HitPointGainMethod, MagicItemRarity, SpellcastingProgressionType};
use App\Repository\{CharacterSessionStateRepository, CharacterClassLevelRuleRepository, CharacterFeatureRuleRepository, TrackableResourceRuleRepository, TrackableResourceDefinitionRepository, CharacterMagicItemRepository, RestRequestRepository};
use App\Service\{CharacterAbilityCalculator, CharacterHitPointCalculator, CharacterSpellSlotCalculator, CharacterFeatureResolver, CharacterResourceResolver, CharacterSessionStateSynchronizer, CharacterProfileSerializer, CharacterMulticlassEligibilityService, CharacterLevelUpService, CharacterLevelUpRequestResolver, CharacterRestService, PlayerCharacterAccess, PlayerCharacterStateUpdater};
use App\Controller\{CharacterSessionStateController, CharacterWalletController, PublicCharacterMagicItemController, RestRequestController};
use App\Kernel;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

require __DIR__ . '/../vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv(__DIR__ . '/../.env');
$kernel = new Kernel('dev', true);
$kernel->boot();
$registry = $kernel->getContainer()->get('doctrine');
$em = $registry->getManager();
$db = $em->getConnection();
$worker = ($argv[1] ?? '') === 'worker';
$schema = $worker ? $argv[2] : 'test_concurrency_' . bin2hex(random_bytes(8));
if (!preg_match('/^test_concurrency_[a-f0-9]{16}$/D', $schema)) throw new RuntimeException('Unsafe test schema');
$quotedSchema = $db->getDatabasePlatform()->quoteIdentifier($schema);

if (!$worker) $db->executeStatement('CREATE SCHEMA ' . $quotedSchema);
$db->executeStatement('SET search_path TO ' . $quotedSchema);
$db->executeStatement("SET statement_timeout = '20s'");

function services($registry): array
{
    $em = $registry->getManager();
    $ability = new CharacterAbilityCalculator();
    $hp = new CharacterHitPointCalculator($ability);
    $slots = new CharacterSpellSlotCalculator();
    $features = new CharacterFeatureResolver(new CharacterFeatureRuleRepository($registry));
    $resources = new CharacterResourceResolver(new TrackableResourceRuleRepository($registry), $ability, $features);
    $sync = new CharacterSessionStateSynchronizer($hp, $resources, new CharacterSessionStateRepository($registry), $slots);
    $access = new PlayerCharacterAccess(new CharacterSessionStateRepository($registry));
    $updater = new PlayerCharacterStateUpdater($sync);
    $levelUp = new CharacterLevelUpService($em, new CharacterClassLevelRuleRepository($registry), $sync, new CharacterMulticlassEligibilityService($ability), $ability);
    $rest = new CharacterRestService($hp, $resources, $sync, $slots, new TrackableResourceDefinitionRepository($registry), $ability);
    $controller = new CharacterSessionStateController(new CharacterProfileSerializer($ability, $features, $resources, $hp, $slots), $sync);
    $controller->setContainer(new Container());
    return compact('em', 'ability', 'access', 'updater', 'levelUp', 'rest', 'controller');
}

if ($worker) {
    $action = $argv[3];
    $data = json_decode(base64_decode($argv[4]), true, 512, JSON_THROW_ON_ERROR);
    $db->fetchOne("SELECT set_config('application_name', ?, false)", [$schema . '_' . $data['worker']]);
    $s = services($registry);
    $session = $em->find(CharacterSessionState::class, $data['session']);
    $character = $session->getCharacter();
    $character->getClassLevels()->toArray();
    $character->getMagicItems()->toArray();
    $character->getAttunedMagicItemCount();
    $character->getWallet()->getGoldPieces();
    if (isset($data['rest'])) $em->find(RestRequest::class, $data['rest'])->isPending();
    $request = Request::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($data['body'] ?? []));
    $wallet = new CharacterWalletController(); $wallet->setContainer(new Container());
    $items = new PublicCharacterMagicItemController(); $items->setContainer(new Container());
    $rests = new RestRequestController();
    $tokens = new Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage();
    $tokens->setToken(new Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken($character->getCampaign()->getOwner(), 'main', ['ROLE_USER']));
    $container = new Container();
    $container->set('security.token_storage', $tokens);
    $container->set('security.authorization_checker', new Symfony\Component\Security\Core\Authorization\AuthorizationChecker($tokens,
        new Symfony\Component\Security\Core\Authorization\AccessDecisionManager([new App\Security\Voter\CampaignVoter()])));
    $rests->setContainer($container);
    $s['controller']->setContainer($container);
    echo "READY\n"; flush();
    if (trim((string) fgets(STDIN)) !== 'GO') exit(2);
    try {
        $response = match ($action) {
            'state' => $s['controller']->updatePublic($session->getAccessToken(), $request, $s['access'], $em, $s['updater']),
            'level' => $s['controller']->publicLevelUp($session->getAccessToken(), $request, $s['access'], new CharacterLevelUpRequestResolver($em), $s['levelUp'], $em),
            'rest-create' => $rests->create($session->getAccessToken(), $request, $s['access'], new RestRequestRepository($registry), $em),
            'rest-resolve' => $rests->resolve($data['rest'], $request, new RestRequestRepository($registry), $s['rest'], $em),
            'wallet' => $wallet->update($session->getAccessToken(), $request, $s['access'], $em),
            'item' => $items->update($session->getAccessToken(), $data['item'], $request, $s['access'], new CharacterMagicItemRepository($registry), $s['ability'], $em),
            'gm-permission' => $s['controller']->updateLevelUpPermission($session->getGameSession()->getId(), $character->getId(), $request,
                new App\Repository\GameSessionRepository($registry), new App\Repository\CharacterRepository($registry), new CharacterSessionStateRepository($registry), $em),
        };
        echo json_encode(['status' => $response->getStatusCode(), 'body' => json_decode($response->getContent(), true)]) . "\n";
    } catch (HttpExceptionInterface $error) {
        echo json_encode(['status' => $error->getStatusCode(), 'message' => $error->getMessage()]) . "\n";
    } catch (Throwable $error) {
        echo json_encode(['status' => 500, 'error' => $error::class . ': ' . $error->getMessage()]) . "\n";
    }
    $kernel->shutdown();
    exit;
}

$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) throw new RuntimeException($message);
    ++$checks;
};
$processes = [];
$run = static function (string $action, array $requests, string $lockTable = 'character', bool $expectedFailure = false) use ($db, $schema, &$processes, $check): array {
    $started = [];
    // Hold the row to force BOTH workers to preload the same old data and wait.
    $db->beginTransaction();
    $id = $lockTable === 'character' ? $requests[0]['character'] : $requests[0]['session'];
    $db->fetchOne('SELECT id FROM ' . $lockTable . ' WHERE id = ? FOR UPDATE', [$id]);
    foreach ($requests as $index => $data) {
        $data['worker'] = (string) $index;
        $process = proc_open([PHP_BINARY, __FILE__, 'worker', $schema, $action, base64_encode(json_encode($data))],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) throw new RuntimeException('Cannot start worker');
        $processes[] = $process;
        stream_set_timeout($pipes[1], 25);
        $ready = fgets($pipes[1]);
        if (trim((string) $ready) !== 'READY') throw new RuntimeException('Worker failed: ' . $ready . stream_get_contents($pipes[2]));
        $started[] = [$process, $pipes];
    }
    foreach ($started as [$process, $pipes]) { fwrite($pipes[0], "GO\n"); fflush($pipes[0]); }
    // Wait until the database confirms the contenders really overlap at a lock.
    $deadline = microtime(true) + 10;
    do {
        $db->fetchOne('SELECT pg_stat_clear_snapshot()');
        $waiting = (int) $db->fetchOne("SELECT count(*) FROM pg_stat_activity WHERE application_name LIKE ? AND wait_event_type = 'Lock'", [$schema . '_%']);
        if ($waiting === count($requests)) break;
        usleep(10000);
    } while (microtime(true) < $deadline);
    $check($waiting === count($requests), "$action: all independent connections overlapped at the lock");
    $db->commit();
    $results = [];
    foreach ($started as [$process, $pipes]) {
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $exit = proc_close($process);
        $result = json_decode(trim($out), true);
        if ($exit !== 0 || !is_array($result) || (!$expectedFailure && ($result['status'] ?? 500) === 500)) throw new RuntimeException("$action worker failed: $out $err");
        $results[] = $result;
    }
    return $results;
};

try {
    (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());
    $user = (new User())->setEmail('concurrency@example.invalid')->setPassword('unused');
    $campaign = new Campaign($user, 'test', 'Test');
    $class = new CharacterClass('wizard', 'Wizard', 6, 20, SpellcastingProgressionType::Full);
    $character = new Character($campaign, 'test', 'Test', Character::TYPE_PLAYER);
    $level = new CharacterClassLevel($character, $class, 1, null, 6, HitPointGainMethod::FirstLevel);
    $character->addClassLevel($level);
    $game = (new GameSession($campaign, 'test', 'Test'))->setStatus(GameSession::STATUS_LIVE);
    $state = new CharacterSessionState($game, $character, ['hitPoints' => ['current' => 4, 'temporary' => 0], 'hitDice' => [['id' => 'd6', 'current' => 0]], 'resources' => [['id' => 'spell-slot-1', 'currentValue' => 0]], 'progressions' => []]);
    $state->setLevelUpAllowed(true);
    foreach ([$user, $campaign, $class, $character, $level, $game, $state] as $entity) $em->persist($entity);
    $owned = [];
    for ($i = 0; $i < 4; ++$i) {
        $item = (new MagicItem($campaign, 'Item ' . $i, MagicItemRarity::Rare))->setMaximumCharges(5)->setRequiresAttunement(true);
        $owned[$i] = (new CharacterMagicItem($character, $item))->setEquipped(true)->setAttuned($i < 2);
        $character->addMagicItem($owned[$i]);
        $em->persist($item); $em->persist($owned[$i]);
    }
    $em->flush();
    $base = ['session' => $state->getId(), 'character' => $character->getId()];
    $statuses = static function (array $results): array { $s = array_column($results, 'status'); sort($s); return $s; };
    $fresh = static function () use ($em, $state): CharacterSessionState { $em->refresh($state); return $state; };

    $revision = $state->getRevision();
    $result = $run('state', [
        $base + ['body' => ['revision' => $revision, 'state' => ['hitPoints' => ['current' => 3]]]],
        $base + ['body' => ['revision' => $revision, 'state' => ['hitPoints' => ['current' => 2]]]],
    ], 'character_session_state');
    $check($statuses($result) === [200, 409], 'Only one stale-snapshot contender succeeds');
    $check($fresh()->getRevision() === $revision + 1, 'Concurrent state write increments once');

    $result = $run('wallet', [$base + ['body' => ['goldPieces' => 3]], $base + ['body' => ['goldPieces' => 7]]]);
    $check($statuses($result) === [200, 200], 'Both wallet deltas succeed');
    $check((int) $db->fetchOne('SELECT gold_pieces FROM character_wallet WHERE character_id = ?', [$character->getId()]) === 10, 'Wallet retains both concurrent deltas');
    $result = $run('wallet', [$base + ['body' => ['goldPieces' => -8]], $base + ['body' => ['goldPieces' => -8]]]);
    $check($statuses($result) === [200, 422], 'Competing withdrawals cannot overdraw');
    $check((int) $db->fetchOne('SELECT gold_pieces FROM character_wallet WHERE character_id = ?', [$character->getId()]) === 2, 'Wallet never negative');

    $itemBase = $base + ['item' => $owned[0]->getId()];
    $result = $run('item', [$itemBase + ['body' => ['chargeChange' => -1]], $itemBase + ['body' => ['chargeChange' => -2]]]);
    $check($statuses($result) === [200, 200], 'Both charge deltas succeed');
    $check((int) $db->fetchOne('SELECT current_charges FROM character_magic_item WHERE id = ?', [$owned[0]->getId()]) === 2, 'Charge consumption not lost');
    $result = $run('item', [$itemBase + ['body' => ['chargeChange' => -2]], $itemBase + ['body' => ['chargeChange' => -2]]]);
    $check($statuses($result) === [200, 422], 'Charge lower bound protected concurrently');
    $result = $run('item', [$itemBase + ['body' => ['chargeChange' => 4]], $itemBase + ['body' => ['chargeChange' => 4]]]);
    $check($statuses($result) === [200, 422], 'Charge upper bound protected concurrently');
    $result = $run('item', [$base + ['item' => $owned[2]->getId(), 'body' => ['attuned' => true]], $base + ['item' => $owned[3]->getId(), 'body' => ['attuned' => true]]]);
    $check($statuses($result) === [200, 422], 'Only one competing attunement succeeds');
    $check((int) $db->fetchOne('SELECT count(*) FROM character_magic_item WHERE character_id = ? AND attuned', [$character->getId()]) === 3, 'Attunement limit remains three');

    $revision = $fresh()->getRevision();
    $result = $run('level', [$base + ['body' => ['classId' => $class->getId()]], $base + ['body' => ['classId' => $class->getId()]]]);
    $check($statuses($result) === [201, 403], 'One permission permits exactly one concurrent level-up');
    $check((int) $db->fetchOne('SELECT count(*) FROM character_class_level WHERE character_id = ?', [$character->getId()]) === 2, 'Character advanced once');
    $check(!$fresh()->isLevelUpAllowed() && $state->getRevision() === $revision + 1, 'Permission and state committed in one revision');

    $revision = $state->getRevision();
    $result = $run('gm-permission', [$base + ['body' => ['allowed' => true]]], 'character_session_state');
    $check($statuses($result) === [200] && $fresh()->getRevision() === $revision + 1, 'GM permission update participates in revision model');
    $revision = $state->getRevision();
    $result = $run('level', [$base + ['body' => ['classId' => $class->getId(), 'advancement' => ['type' => 'ability', 'increases' => [['ability' => 'strength', 'value' => 2]]]]]]);
    $check($statuses($result) === [422], 'Invalid advancement fails inside locked transaction');
    $check($fresh()->isLevelUpAllowed() && $state->getRevision() === $revision, 'Validation failure preserves permission and revision');
    $check((int) $db->fetchOne('SELECT count(*) FROM character_class_level WHERE character_id = ?', [$character->getId()]) === 2, 'Validation failure does not advance character');

    // Force a flush failure after domain validation. It must roll back the whole action.
    $db->executeStatement("CREATE FUNCTION reject_test_level() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN RAISE EXCEPTION ''test forced rollback''; END'");
    $db->executeStatement('CREATE TRIGGER reject_test_level BEFORE INSERT ON character_class_level FOR EACH ROW EXECUTE FUNCTION reject_test_level()');
    try {
        $result = $run('level', [$base + ['body' => ['classId' => $class->getId()]]], 'character', true);
        $check($result[0]['status'] === 500 && str_contains($result[0]['error'], 'test forced rollback'), 'Forced flush failure exercised');
        $check($fresh()->isLevelUpAllowed() && $state->getRevision() === $revision, 'Rollback preserves permission and state revision');
        $check((int) $db->fetchOne('SELECT count(*) FROM character_class_level WHERE character_id = ?', [$character->getId()]) === 2, 'Rollback leaves no partial level');
    } finally {
        $db->executeStatement('DROP TRIGGER reject_test_level ON character_class_level');
        $db->executeStatement('DROP FUNCTION reject_test_level()');
    }

    $result = $run('rest-create', [$base + ['body' => ['type' => 'long-rest']], $base + ['body' => ['type' => 'long-rest']]]);
    $check($statuses($result) === [201, 409], 'One concurrent pending request');
    $restId = (int) $db->fetchOne("SELECT id FROM rest_request WHERE status = 'pending'");
    $check((int) $db->fetchOne("SELECT count(*) FROM rest_request WHERE status = 'pending'") === 1, 'Exactly one pending row');
    $fresh();
    $restState = $state->getState();
    $restState['hitDice'][0]['current'] = 0;
    $state->setState($restState); $em->flush();
    $revision = $fresh()->getRevision();
    $result = $run('rest-resolve', [$base + ['rest' => $restId, 'body' => ['status' => 'approved']], $base + ['rest' => $restId, 'body' => ['status' => 'approved']]]);
    $check($statuses($result) === [200, 409], 'Concurrent approval is single-use');
    $check($fresh()->getRevision() === $revision + 1, 'Rest changes state revision once');
    $check($state->getState()['hitDice'][0]['current'] === 1, 'Rest pool recovery applied once, not twice');

    $result = $run('rest-create', [$base + ['body' => ['type' => 'short-rest']]]);
    $restId = (int) $db->fetchOne("SELECT id FROM rest_request WHERE status = 'pending'");
    $revision = $fresh()->getRevision();
    $result = $run('rest-resolve', [$base + ['rest' => $restId, 'body' => ['status' => 'rejected']], $base + ['rest' => $restId, 'body' => ['status' => 'rejected']]]);
    $check($statuses($result) === [200, 409], 'Concurrent rejection is single-use');
    $result = $run('rest-resolve', [$base + ['rest' => $restId, 'body' => ['status' => 'approved']]]);
    $check($statuses($result) === [409] && $fresh()->getRevision() === $revision, 'Rejected rest cannot later be approved or change state');

    // Database constraint is independent of controller prechecks and locks.
    $db->beginTransaction();
    $db->executeStatement("INSERT INTO rest_request (character_session_state_id, type, status, requested_at) VALUES (?, 'short-rest', 'pending', NOW())", [$state->getId()]);
    try {
        $db->executeStatement("INSERT INTO rest_request (character_session_state_id, type, status, requested_at) VALUES (?, 'short-rest', 'pending', NOW())", [$state->getId()]);
        throw new RuntimeException('Missing unique pending constraint');
    } catch (Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
        $check(true, 'Database rejects duplicate pending rows');
    } finally { $db->rollBack(); }

    echo "OK: $checks concurrency assertions using independent connections; disposable schema removed.\n";
} finally {
    while ($db->isTransactionActive()) $db->rollBack();
    foreach ($processes as $process) if (is_resource($process)) { proc_terminate($process); proc_close($process); }
    $db->executeStatement('SET search_path TO public');
    $db->executeStatement('DROP SCHEMA ' . $quotedSchema . ' CASCADE');
    $kernel->shutdown();
}
