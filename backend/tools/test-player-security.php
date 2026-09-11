<?php

declare(strict_types=1);

// Run: docker compose exec backend php tools/test-player-security.php
// All ORM tables are shadowed by transaction-local PostgreSQL temporary tables.
// No campaign records or public identity sequences are changed.
use App\Dto\LevelAdvancementSelection as ASI;
use App\Entity\{Campaign, Character, CharacterClass, CharacterClassLevel, CharacterClassLevelRule, CharacterFeatureDefinition, CharacterFeatureRule, CharacterProgression, CharacterRace, CharacterSessionState, GameSession, ProgressionDefinition, TrackableResourceDefinition, TrackableResourceRule, User};
use App\Enum\{Ability, HitPointGainMethod, LevelAdvancementChoice, ResourceMaximumType, ResourceRechargeType, SpellcastingProgressionType};
use App\Repository\{CharacterClassLevelRuleRepository, CharacterFeatureRuleRepository, CharacterRepository, CharacterSessionStateRepository, TrackableResourceRuleRepository};
use App\Service\{CharacterAbilityCalculator, CharacterBuilderService, CharacterFeatureResolver, CharacterHitPointCalculator, CharacterLevelUpService, CharacterMulticlassEligibilityService, CharacterResourceResolver, CharacterSessionStateFactory, CharacterSessionStateSynchronizer, CharacterSpellSlotCalculator};
use App\Kernel;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\Dotenv\Dotenv;

require __DIR__ . '/../vendor/autoload.php';
(new Dotenv())->bootEnv(__DIR__ . '/../.env');
$kernel = new Kernel('dev', true);
$kernel->boot();
$registry = $kernel->getContainer()->get('doctrine');
$em = $registry->getManager();
$db = $em->getConnection();
$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) throw new RuntimeException($message);
    ++$checks;
};

// Each scenario gets fresh services, including after a rejected transaction closes the EM.
$services = static function () use ($registry): array {
    $em = $registry->resetManager();
    $ability = new CharacterAbilityCalculator();
    $hp = new CharacterHitPointCalculator($ability);
    $slots = new CharacterSpellSlotCalculator();
    $features = new CharacterFeatureResolver(new CharacterFeatureRuleRepository($registry));
    $resources = new CharacterResourceResolver(new TrackableResourceRuleRepository($registry), $ability, $features);
    $sync = new CharacterSessionStateSynchronizer($hp, $resources, new CharacterSessionStateRepository($registry), $slots);
    $eligibility = new CharacterMulticlassEligibilityService($ability);
    $levelUp = new CharacterLevelUpService($em, new CharacterClassLevelRuleRepository($registry), $sync, $eligibility, $ability);
    return compact('em', 'ability', 'hp', 'slots', 'features', 'resources', 'sync', 'eligibility', 'levelUp');
};
$fixture = static function (array $s, int $levels = 0): array {
    static $number = 0;
    ++$number;
    $em = $s['em'];
    $user = (new User())->setEmail("lifecycle-$number@example.invalid")->setPassword('unused');
    $campaign = new Campaign($user, "audit-$number", 'Lifecycle test');
    $character = new Character($campaign, 'test', 'Test', Character::TYPE_PLAYER);
    $wizard = $em->getRepository(CharacterClass::class)->findOneBy(['slug' => 'wizard']);
    foreach ([$user, $campaign, $character] as $entity) $em->persist($entity);
    for ($position = 1; $position <= $levels; ++$position) {
        $level = new CharacterClassLevel($character, $wizard, $position, null, $position === 1 ? 6 : 4, $position === 1 ? HitPointGainMethod::FirstLevel : HitPointGainMethod::Average);
        $character->addClassLevel($level);
        $em->persist($level);
    }
    $em->flush();
    return [$character, $wizard, $campaign];
};
$reject = static function (callable $operation, Character $character, string $label) use ($check, $db): void {
    $levels = $character->getTotalLevel();
    $adjustments = $character->getAbilityAdjustments()->count();
    $persisted = $db->fetchOne('SELECT COUNT(*) FROM character_ability_adjustment');
    try {
        $operation();
    } catch (DomainException) {
        $check($character->getTotalLevel() === $levels, "$label: no level mutation");
        $check($character->getAbilityAdjustments()->count() === $adjustments, "$label: no partial ASI in memory");
        $check($db->fetchOne('SELECT COUNT(*) FROM character_ability_adjustment') === $persisted, "$label: no persisted ASI");
        return;
    }
    throw new RuntimeException("$label: expected rejection");
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
        $check($db->fetchOne('SELECT relpersistence FROM pg_class WHERE oid = to_regclass(?)', [$table]) === 't', "Temporary table: $table");
    }
    // Use real class slugs for eligibility, but defer subclass selection in these focused fixtures.
    $wizard = new CharacterClass('wizard', 'Wizard', 6, 20, SpellcastingProgressionType::Full);
    $fighter = new CharacterClass('fighter', 'Fighter', 10, 20);
    $warlock = new CharacterClass('warlock', 'Warlock', 8, 20, SpellcastingProgressionType::Pact);
    $race = new CharacterRace('test-race', 'Test race');
    foreach ([$wizard, $fighter, $warlock, $race, new CharacterClassLevelRule($wizard, 4, LevelAdvancementChoice::AbilityScoreImprovementOrFeat)] as $entity) $em->persist($entity);
    $em->flush();

    $s = $services();
    [$character, $wizard, $campaign] = $fixture($s, 1);
    $em = $s['em'];
    $progression = (new ProgressionDefinition('security-progress', 'Progress'))->setMaximumValue(5);
    $assignment = new CharacterProgression($character, $progression);
    $character->addProgression($assignment);
    $resource = new TrackableResourceDefinition('ordinary', 'Ordinary', ResourceRechargeType::LongRest, ResourceMaximumType::Fixed, 4);
    $progressResource = new TrackableResourceDefinition('progress-resource', 'Progress resource', ResourceRechargeType::ShortRest, ResourceMaximumType::Fixed, 3);
    $feature = (new CharacterFeatureDefinition('progress-feature', 'Progress feature'))->setResourceDefinition($progressResource);
    $manual = new TrackableResourceDefinition('manual', 'Manual', ResourceRechargeType::LongRest, ResourceMaximumType::Fixed, 3);
    $stored = new TrackableResourceDefinition('stored', 'Stored', ResourceRechargeType::LongRest, ResourceMaximumType::Fixed, 2);
    foreach ([$progression, $assignment, $resource, $progressResource, $feature, $manual, $stored,
        CharacterFeatureRule::forProgression($feature, $progression, 3),
        TrackableResourceRule::forClass($resource, $wizard, 1), TrackableResourceRule::forClass($manual, $wizard, 1),
        TrackableResourceRule::forClass($stored, $wizard, 1)] as $entity) $em->persist($entity);
    $character->setDefinition(['resources' => [
        ['id' => 'manual', 'allowManualIncrease' => true, 'notesEditable' => true],
        ['id' => 'stored', 'storedValuesConfig' => ['requiredCount' => 2, 'minimumValue' => 1, 'maximumValue' => 20]],
    ]]);
    $game = (new GameSession($campaign, 'security', 'Security'))->setStatus(GameSession::STATUS_LIVE);
    $initial = ['hitPoints' => ['current' => 4, 'temporary' => 2], 'hitDice' => [['id' => 'd6', 'current' => 1]],
        'resources' => [['id' => 'ordinary', 'currentValue' => 2], ['id' => 'manual', 'currentValue' => 1],
            ['id' => 'stored', 'currentValue' => 2], ['id' => 'spell-slot-1', 'currentValue' => 1],
            ['id' => 'history', 'currentValue' => 0, 'notes' => 'history']],
        'progressions' => [['id' => 'security-progress', 'currentValue' => 1]], 'serverOnly' => 'preserved'];
    $session = new CharacterSessionState($game, $character, $initial);
    $em->persist($game); $em->persist($session);
    $item = new App\Entity\MagicItem($campaign, 'Test item', App\Enum\MagicItemRarity::Rare);
    $owned = new App\Entity\CharacterMagicItem($character, $item);
    $character->addMagicItem($owned);
    $em->persist($item); $em->persist($owned); $em->flush();
    $access = new App\Service\PlayerCharacterAccess(new CharacterSessionStateRepository($registry));
    $updater = new App\Service\PlayerCharacterStateUpdater($s['sync']);
    $profile = new App\Service\CharacterProfileSerializer($s['ability'], $s['features'], $s['resources'], $s['hp'], $s['slots']);
    $controller = new App\Controller\CharacterSessionStateController($profile, $s['sync']);
    $wallet = new App\Controller\CharacterWalletController();
    $inventory = new App\Controller\PublicCharacterMagicItemController();
    $rests = new App\Controller\RestRequestController();
    foreach ([$controller, $wallet, $inventory, $rests] as $c) $c->setContainer(new Symfony\Component\DependencyInjection\Container());
    $options = new App\Service\CharacterLevelUpOptionsService($em, new CharacterClassLevelRuleRepository($registry), $s['eligibility']);
    $requests = new App\Service\CharacterLevelUpRequestResolver($em);
    $restRepository = new App\Repository\RestRequestRepository($registry);
    $itemRepository = new App\Repository\CharacterMagicItemRepository($registry);
    $request = static fn (array $body) => Symfony\Component\HttpFoundation\Request::create('/', 'PATCH', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(array_key_exists('state', $body) ? $body + ['revision' => $session->getRevision()] : $body, JSON_THROW_ON_ERROR));
    $token = $session->getAccessToken();
    $initialRevision = $session->getRevision();
    $read = json_decode($controller->showPublic($token, $access)->getContent(), true);
    $check($read['revision'] === $initialRevision && $session->getRevision() === $initialRevision, 'Read exposes revision without incrementing it');
    $missingRevision = Symfony\Component\HttpFoundation\Request::create('/', 'PATCH', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"state":{}}');
    $check($controller->updatePublic($token, $missingRevision, $access, $em, $updater)->getStatusCode() === 422, 'Omitted revision cannot bypass concurrency');
    $check($controller->updatePublic($token, $request(['state' => [], 'revision' => $initialRevision]), $access, $em, $updater)->getStatusCode() === 200, 'Current revision accepted');
    $check($session->getRevision() === $initialRevision + 1, 'Successful mutation increments revision');
    $check($controller->updatePublic($token, $request(['state' => [], 'revision' => $initialRevision]), $access, $em, $updater)->getStatusCode() === 409, 'Stale revision is a conflict');
    $routes = [
        'character GET' => fn ($t) => $controller->showPublic($t, $access),
        'character PATCH' => fn ($t) => $controller->updatePublic($t, $request(['state' => []]), $access, $em, $updater),
        'wallet GET' => fn ($t) => $wallet->show($t, $access),
        'wallet PATCH' => fn ($t) => $wallet->update($t, $request(['goldPieces' => 1]), $access, $em),
        'inventory GET' => fn ($t) => $inventory->list($t, $access, $s['ability']),
        'inventory PATCH' => fn ($t) => $inventory->update($t, $owned->getId(), $request(['equipped' => true]), $access, $itemRepository, $s['ability'], $em),
        'rest POST' => fn ($t) => $rests->create($t, $request(['type' => 'short-rest']), $access, $restRepository, $em),
        'rest GET' => fn ($t) => $rests->latest($t, $access, $restRepository),
        'level-up options' => fn ($t) => $controller->publicLevelUpOptions($t, $access, $options),
        'level-up POST' => fn ($t) => $controller->publicLevelUp($t, $request(['classId' => $wizard->getId()]), $access, $requests, $s['levelUp'], $em),
    ];
    $status = static function (callable $operation): int {
        try { return $operation()->getStatusCode(); }
        catch (Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $error) { return $error->getStatusCode(); }
    };
    foreach ($routes as $label => $operation) $check($status(fn () => $operation(str_repeat('0', 64))) === 404, "$label: invalid token");
    $session->setParticipating(false); $em->flush();
    $beforeDenied = $session->getState();
    foreach ($routes as $label => $operation) $check($status(fn () => $operation($token)) === 404, "$label: removed participant");
    $check($session->getState() === $beforeDenied && $character->getTotalLevel() === 1, 'Denied calls cannot mutate state or level');
    $session->setParticipating(true); $game->setStatus(GameSession::STATUS_CLOSED); $em->flush();
    foreach ($routes as $label => $operation) {
        if (str_contains($label, 'PATCH') || str_contains($label, 'POST') || $label === 'level-up options') {
            $check($status(fn () => $operation($token)) === 409, "$label: live restriction preserved");
        } else $check($status(fn () => $operation($token)) === 200, "$label: participating private read outside live session");
    }
    $game->setStatus(GameSession::STATUS_LIVE); $em->flush();
    $check($status(fn () => $routes['level-up POST']($token)) === 403, 'Level-up still requires GM permission');
    foreach ($routes as $label => $operation) {
        if (str_starts_with($label, 'level-up')) continue;
        $check(in_array($status(fn () => $operation($token)), [200, 201], true), "$label: participating token succeeds");
    }

    $invalid = [
        'unknown resource' => ['resources' => [['id' => 'forged', 'currentValue' => 1]]],
        'duplicate resource' => ['resources' => [['id' => 'ordinary', 'currentValue' => 1], ['id' => 'ordinary', 'currentValue' => 1]]],
        'negative resource' => ['resources' => [['id' => 'ordinary', 'currentValue' => -1]]],
        'over-max resource' => ['resources' => [['id' => 'ordinary', 'currentValue' => 5]]],
        'fraction resource' => ['resources' => [['id' => 'ordinary', 'currentValue' => 1.5]]],
        'resource maximum metadata' => ['resources' => [['id' => 'ordinary', 'currentValue' => 1, 'maximumValue' => 99]]],
        'inactive fabrication' => ['resources' => [['id' => 'progress-resource', 'currentValue' => 1]]],
        'history manipulation' => ['resources' => [['id' => 'history', 'currentValue' => 1]]],
        'HP over-max' => ['hitPoints' => ['current' => 7]],
        'HP negative' => ['hitPoints' => ['current' => -1]],
        'HP string' => ['hitPoints' => ['current' => '4']],
        'HP metadata' => ['hitPoints' => ['maximum' => 99]],
        'temp HP negative' => ['hitPoints' => ['temporary' => -1]],
        'temp HP fraction' => ['hitPoints' => ['temporary' => 1.5]],
        'unknown dice' => ['hitDice' => [['id' => 'd20', 'current' => 1]]],
        'excess dice' => ['hitDice' => [['id' => 'd6', 'current' => 2]]],
        'unknown progression' => ['progressions' => [['id' => 'fake', 'currentValue' => 1]]],
        'progression below' => ['progressions' => [['id' => 'security-progress', 'currentValue' => -1]]],
        'progression above' => ['progressions' => [['id' => 'security-progress', 'currentValue' => 6]]],
        'progression definition' => ['progressions' => [['id' => 'security-progress', 'currentValue' => 1, 'stages' => []]]],
        'unknown state key' => ['levelUpAllowed' => true],
        'malformed list' => ['resources' => 'invalid'],
        'stored null' => ['resources' => [['id' => 'stored', 'currentValue' => 2, 'storedValues' => null]]],
        'stored out of bounds' => ['resources' => [['id' => 'stored', 'currentValue' => 2, 'storedValues' => [0, 21]]]],
        'duplicate progression' => ['progressions' => [['id' => 'security-progress', 'currentValue' => 1], ['id' => 'security-progress', 'currentValue' => 1]]],
        'duplicate dice' => ['hitDice' => [['id' => 'd6', 'current' => 1], ['id' => 'd6', 'current' => 1]]],
    ];
    foreach ($invalid as $label => $patch) {
        $before = $session->getState();
        $check($status(fn () => $controller->updatePublic($token, $request(['state' => $patch]), $access, $em, $updater)) === 422, "$label rejected");
        $check($session->getState() === $before, "$label rejected atomically");
    }
    $check($status(fn () => $controller->updatePublic($token, $request(['state' => ['resources' => [['id' => 'ordinary', 'currentValue' => 3]]]]), $access, $em, $updater)) === 403, 'Normal PATCH cannot refill resource');
    $legal = ['hitPoints' => ['current' => 5, 'temporary' => 8], 'hitDice' => [['id' => 'd6', 'current' => 0]],
        'resources' => [['id' => 'ordinary', 'currentValue' => 1], ['id' => 'manual', 'currentValue' => 3, 'notes' => 'player note'],
            ['id' => 'stored', 'currentValue' => 2, 'storedValues' => [7, 12]]]];
    $check($status(fn () => $controller->updatePublic($token, $request(['state' => $legal]), $access, $em, $updater)) === 200, 'Legitimate healing, temp HP, spending, manual resource, notes and stored rolls');
    $check($status(fn () => $controller->updatePublic($token, $request(['state' => ['hitDice' => [['id' => 'd6', 'current' => 1]]]]), $access, $em, $updater)) === 403, 'PATCH cannot recover spent hit dice');
    $check($status(fn () => $controller->updatePublic($token, $request(['state' => ['resources' => [['id' => 'stored', 'currentValue' => 2, 'storedValues' => [20, 20]]]]]), $access, $em, $updater)) === 403, 'Stored rolls cannot be rerolled');
    $check($status(fn () => $controller->updatePublic($token, $request(['state' => ['resources' => [['id' => 'stored', 'currentValue' => 1, 'storedValues' => [12]]]]]), $access, $em, $updater)) === 200, 'Stored result consumption works');
    $check($status(fn () => $controller->updatePublic($token, $request(['state' => ['resources' => [['id' => 'stored', 'currentValue' => 1, 'storedValues' => null]]]]), $access, $em, $updater)) === 422, 'Stored rolls cannot be cleared to enable reroll');
    $check($status(fn () => $controller->updatePublic($token, $request(['state' => ['resources' => [['id' => 'ordinary', 'currentValue' => 1, 'notes' => 'forged']]]]), $access, $em, $updater)) === 403, 'Non-editable resource notes protected');
    $beforePartial = $session->getState();
    $check($status(fn () => $controller->updatePublic($token, $request(['state' => ['resources' => [['id' => 'ordinary', 'currentValue' => 0]]]]), $access, $em, $updater)) === 200, 'Partial resource consumption');
    $afterPartial = $session->getState();
    foreach (['hitPoints', 'hitDice', 'progressions', 'serverOnly'] as $field) $check($afterPartial[$field] === $beforePartial[$field], "Omitted $field preserved");
    $check(array_column($afterPartial['resources'], null, 'id')['history'] === array_column($initial['resources'], null, 'id')['history'], 'Omitted historical entry preserved exactly');
    $activate = ['progressions' => [['id' => 'security-progress', 'currentValue' => 3]]];
    $check($status(fn () => $controller->updatePublic($token, $request(['state' => $activate]), $access, $em, $updater)) === 200, 'Progression activation allowed');
    $check(array_column($session->getState()['resources'], 'currentValue', 'id')['progress-resource'] === 3, 'First activation initializes at maximum');
    $session->setState($updater->merge($session, ['resources' => [['id' => 'progress-resource', 'currentValue' => 1]]]));
    $session->setState($updater->merge($session, ['progressions' => [['id' => 'security-progress', 'currentValue' => 2]], 'resources' => [['id' => 'progress-resource', 'currentValue' => 1]]]));
    $check(array_column($session->getState()['resources'], 'currentValue', 'id')['progress-resource'] === 1, 'Full snapshot can echo unchanged deactivated resource');
    $check($status(fn () => $controller->updatePublic($token, $request(['state' => ['resources' => [['id' => 'progress-resource', 'currentValue' => 2]]]]), $access, $em, $updater)) === 422, 'Inactive historical resource cannot change');
    $session->setState($updater->merge($session, $activate));
    $check(array_column($session->getState()['resources'], 'currentValue', 'id')['progress-resource'] === 1, 'Reactivation preserves consumption');
    $session->setLevelUpAllowed(true); $em->flush();
    $check($status(fn () => $routes['level-up options']($token)) === 200, 'Authorized level-up options');
    $check($status(fn () => $routes['level-up POST']($token)) === 201 && $character->getTotalLevel() === 2, 'Authorized level-up succeeds');
    $em->refresh($session);
    $check(!array_key_exists('levelUpAllowed', $session->getState()), 'Unknown state keys never persisted');
    echo "OK: $checks player security assertions; temporary tables rolled back.\n";
} finally {
    while ($db->isTransactionActive()) $db->rollBack();
    $kernel->shutdown();
}
