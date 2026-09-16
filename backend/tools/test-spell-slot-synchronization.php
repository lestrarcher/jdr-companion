<?php

declare(strict_types=1);

// Run: docker compose exec -T backend php tools/test-spell-slot-synchronization.php
// ORM tables and sequences are shadowed by temporary objects, then rolled back.
use App\Entity\{Campaign, Character, CharacterClass, CharacterClassLevel, CharacterSessionState, GameSession, TrackableResourceDefinition, TrackableResourceRule, User};
use App\Enum\{HitPointGainMethod, ResourceRechargeType, SpellcastingProgressionType};
use App\Repository\{CharacterFeatureRuleRepository, CharacterSessionStateRepository, TrackableResourceRuleRepository};
use App\Service\{CharacterAbilityCalculator, CharacterFeatureResolver, CharacterHitPointCalculator, CharacterHitPointStateService, CharacterResourceResolver, CharacterSessionStateSynchronizer, CharacterSpellSlotCalculator, CharacterSpellSlotStateService};
use App\Kernel;
use App\Service\PlayerCharacterStateUpdater;
use App\Service\{CharacterActionResolver, CharacterProfileSerializer, CharacterSessionStateSerializer};
use App\Repository\{CharacterActionClassRuleRepository, CharacterActiveEffectRepository};
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
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
$pool = static function (array $state, string $id): array {
    foreach ($state['resources'] as $resource) {
        if ($resource['id'] === $id) return $resource;
    }
    throw new RuntimeException('Missing pool: ' . $id);
};

try {
    $db->beginTransaction();
    $metadata = $em->getMetadataFactory()->getAllMetadata();
    foreach ((new SchemaTool($em))->getCreateSchemaSql($metadata) as $sql) {
        $sql = preg_replace('/^CREATE TABLE /', 'CREATE TEMP TABLE ', $sql);
        $sql = preg_replace('/^CREATE SEQUENCE /', 'CREATE TEMP SEQUENCE ', $sql);
        $db->executeStatement($sql);
    }
    foreach ($metadata as $mapping) {
        $table = $db->getDatabasePlatform()->quoteIdentifier($mapping->getTableName());
        $check($db->fetchOne('SELECT relpersistence FROM pg_class WHERE oid = to_regclass(?)', [$table]) === 't', 'Temporary table: ' . $table);
    }

    $ability = new CharacterAbilityCalculator();
    $hp = new CharacterHitPointCalculator($ability);
    $slots = new CharacterSpellSlotStateService(new CharacterSpellSlotCalculator());
    $features = new CharacterFeatureResolver(new CharacterFeatureRuleRepository($registry));
    $resources = new CharacterResourceResolver(new TrackableResourceRuleRepository($registry), $ability, $features);
    $sync = new CharacterSessionStateSynchronizer($hp, new CharacterHitPointStateService($hp), $resources, new CharacterSessionStateRepository($registry), $slots);
    $user = (new User())->setEmail('slots@example.invalid')->setPassword('unused');
    $campaign = new Campaign($user, 'slots', 'Slots');
    $character = new Character($campaign, 'caster', 'Caster', Character::TYPE_PLAYER);
    $class = new CharacterClass('caster', 'Caster', 6, 20, SpellcastingProgressionType::Full);
    $pactClass = new CharacterClass('pact', 'Pact', 8, 20, SpellcastingProgressionType::Pact);
    $points = new TrackableResourceDefinition('sorcery-points', 'Points', ResourceRechargeType::LongRest);
    $pact = new TrackableResourceDefinition('pact-magic', 'Pact', ResourceRechargeType::ShortRest);
    foreach ([$user, $campaign, $character, $class, $pactClass, $points, $pact, TrackableResourceRule::forClass($points, $class, 1, 3), TrackableResourceRule::forClass($pact, $pactClass, 1, 2)] as $entity) $em->persist($entity);
    $addLevel = static function (CharacterClass $class) use ($character, $em): void {
        $level = new CharacterClassLevel($character, $class, $character->getTotalLevel() + 1, null, intdiv($class->getHitDie(), 2) + 1, HitPointGainMethod::Average);
        $character->addClassLevel($level);
        $em->persist($level);
    };
    $addLevel($class);
    $addLevel($class);
    $addLevel($pactClass);
    $em->flush();

    $sessions = [];
    foreach ([2, 0] as $index => $bonus) {
        $game = new GameSession($campaign, 'session-' . $index, 'Session');
        $state = ['hitPoints' => ['current' => 8], 'resources' => [
            ['id' => 'spell-slot-1', 'currentValue' => 1, 'flexibleCastingBonus' => $bonus],
            ['id' => 'spell-slot-5', 'currentValue' => 0, 'flexibleCastingBonus' => 1],
            ['id' => 'sorcery-points', 'currentValue' => 1],
            ['id' => 'pact-magic', 'currentValue' => 1],
        ]];
        $session = new CharacterSessionState($game, $character, $state);
        $em->persist($game);
        $em->persist($session);
        $sessions[] = $session;
    }
    $em->flush();
    $snapshots = $sync->snapshotForLevelUp($character);
    usort($snapshots, static fn (array $a, array $b): int => $a['sessionState']->getId() <=> $b['sessionState']->getId());
    $check($snapshots[0]['before']['resources']['spell-slot-1'] === 5, 'Before: natural 3 + bonus 2');
    $check($snapshots[1]['before']['resources']['spell-slot-1'] === 3, 'Independent session bonus');
    $check($sync->snapshot($character)['resources']['spell-slot-1'] === 3, 'Context-free snapshot remains natural');
    $addLevel($class);
    $sync->synchronizeAfterLevelUp($character, $snapshots);
    foreach ($sessions as $index => $session) {
        $state = $session->getState();
        $check($pool($state, 'spell-slot-1') === ['id' => 'spell-slot-1', 'currentValue' => 2, 'flexibleCastingBonus' => $index === 0 ? 2 : 0], 'Level-up adds natural delta only');
        $check($slots->effectiveMaximums($character, $state)[1] === ($index === 0 ? 6 : 4), 'After: effective maximum');
        $check($pool($state, 'spell-slot-5')['currentValue'] === 0 && $pool($state, 'spell-slot-5')['flexibleCastingBonus'] === 1, 'Exhausted temporary pool survives level-up');
        $check($pool($state, 'sorcery-points')['currentValue'] === 1 && $pool($state, 'pact-magic')['currentValue'] === 1, 'Other consumed resources unchanged by level-up');
    }

    foreach ([[1, 2, 2, 5], [1, 6, 5, 5], [5, 1, 1, 1], [5, 0, 0, 1]] as [$level, $current, $expected, $maximum]) {
        $state = ['resources' => [
            ['id' => 'spell-slot-' . $level, 'currentValue' => $current, 'flexibleCastingBonus' => 1],
            ['id' => 'sorcery-points', 'currentValue' => 9, 'flexibleCastingBonus' => 10],
            ['id' => 'pact-magic', 'currentValue' => 9, 'flexibleCastingBonus' => 10],
            ['id' => 'historical', 'currentValue' => 7],
        ]];
        $result = $sync->synchronize($character, $state);
        $check($pool($result, 'spell-slot-' . $level) === ['id' => 'spell-slot-' . $level, 'currentValue' => $expected, 'flexibleCastingBonus' => 1], 'Slot current clamped, bonus preserved');
        $check($sync->snapshot($character, [], $result)['resources']['spell-slot-' . $level] === $maximum, 'Effective maximum matches expected');
        $check($pool($result, 'sorcery-points')['currentValue'] === 3, 'Trackable resource retains normal cap');
        $check($pool($result, 'pact-magic')['currentValue'] === 2, 'Pact resource retains normal cap');
        $check($pool($result, 'historical')['currentValue'] === 7, 'Historical resource preserved');
        $check($sync->synchronize($character, $result) === $result, 'Synchronization is idempotent');
    }
    $updater = new PlayerCharacterStateUpdater($sync, new CharacterHitPointStateService($hp));
    $session = $sessions[0];
    $server = ['resources' => [
        ['id' => 'spell-slot-1', 'currentValue' => 2, 'flexibleCastingBonus' => 1],
        ['id' => 'spell-slot-5', 'currentValue' => 1, 'flexibleCastingBonus' => 1],
        ['id' => 'sorcery-points', 'currentValue' => 1],
        ['id' => 'pact-magic', 'currentValue' => 1],
    ]];
    $session->setState($server);
    $reject = static function (array $patch, int $status) use ($updater, $session, $check): void {
        $before = $session->getState();
        try {
            $updater->merge($session, $patch);
        } catch (HttpExceptionInterface $exception) {
            $check($exception->getStatusCode() === $status, 'Expected PATCH rejection status');
            $check($session->getState() === $before, 'Rejected PATCH leaves server state intact');
            return;
        }
        throw new RuntimeException('Expected PATCH rejection');
    };
    foreach ([['spell-slot-1', 1], ['spell-slot-1', 2], ['spell-slot-5', 0], ['spell-slot-5', 1]] as [$id, $current]) {
        $result = $updater->merge($session, ['resources' => [['id' => $id, 'currentValue' => $current]]]);
        $check($pool($result, $id) === ['id' => $id, 'currentValue' => $current, 'flexibleCastingBonus' => 1], 'PATCH accepts consumption or unchanged value and preserves bonus');
        $check($session->getState() === $server, 'Merge does not mutate the entity');
    }
    foreach ([['spell-slot-1', 3, 403], ['spell-slot-1', 6, 422], ['spell-slot-5', 2, 422], ['spell-slot-1', -1, 422]] as [$id, $current, $status]) {
        $reject(['resources' => [['id' => $id, 'currentValue' => $current]]], $status);
    }
    foreach ([0, 1, 2, null] as $bonus) {
        $reject(['resources' => [['id' => 'spell-slot-1', 'currentValue' => 2, 'flexibleCastingBonus' => $bonus]]], 422);
    }
    $reject(['resources' => [['id' => 'spell-slot-2', 'currentValue' => 1, 'flexibleCastingBonus' => 1]]], 422);
    foreach ([[], ['resources' => []]] as $patch) {
        $result = $updater->merge($session, $patch);
        $check($pool($result, 'spell-slot-1') === $server['resources'][0] && $pool($result, 'spell-slot-5') === $server['resources'][1], 'Omitting pools cannot delete bonuses');
    }
    foreach (['sorcery-points' => 4, 'pact-magic' => 3] as $id => $overMaximum) {
        $result = $updater->merge($session, ['resources' => [['id' => $id, 'currentValue' => 0]]]);
        $check($pool($result, $id)['currentValue'] === 0, 'Other resource consumption still accepted');
        $reject(['resources' => [['id' => $id, 'currentValue' => 2]]], 403);
        $reject(['resources' => [['id' => $id, 'currentValue' => $overMaximum]]], 422);
    }
    $server['resources'][0]['currentValue'] = 5;
    $server['resources'][1]['currentValue'] = 0;
    $session->setState($server);
    foreach ([5, 4] as $current) {
        $result = $updater->merge($session, ['resources' => [
            ['id' => 'spell-slot-1', 'currentValue' => $current],
            ['id' => 'spell-slot-5', 'currentValue' => 0],
        ]]);
        $check($pool($result, 'spell-slot-1')['currentValue'] === $current, 'Current above natural maximum is preserved');
        $check($pool($result, 'spell-slot-5') === $server['resources'][1], 'Exhausted temporary pool remains valid');
    }
    $reject(['resources' => [['id' => 'spell-slot-5', 'currentValue' => 1]]], 403);
    $profileSerializer = new CharacterProfileSerializer($ability, $features, $resources, $hp, new CharacterSpellSlotCalculator(), new CharacterActionResolver(new CharacterActionClassRuleRepository($registry)));
    $serializer = new CharacterSessionStateSerializer($profileSerializer, new CharacterHitPointStateService($hp), new CharacterActiveEffectRepository($registry), $slots);
    $naturalProfile = $profileSerializer->serialize($character);
    $naturalResources = array_column($naturalProfile['resources'], null, 'slug');
    $check($naturalResources['spell-slot-1']['maximum'] === 4 && !isset($naturalResources['spell-slot-5']), 'Standalone profile exposes natural slots only');
    foreach ([1, 0] as $temporaryCurrent) {
        $server['resources'] = [
            ['id' => 'spell-slot-5', 'currentValue' => $temporaryCurrent, 'flexibleCastingBonus' => 1],
            ['id' => 'spell-slot-1', 'currentValue' => 2, 'flexibleCastingBonus' => 1],
            ['id' => 'spell-slot-3', 'currentValue' => 0, 'flexibleCastingBonus' => 2],
            ['id' => 'sorcery-points', 'currentValue' => 1],
            ['id' => 'pact-magic', 'currentValue' => 1],
        ];
        $session->setState($server);
        foreach ([false, true] as $includeToken) {
            $response = $serializer->serialize($session, $includeToken);
            $exposed = array_column($response['character']['resources'], null, 'slug');
            $check($exposed['spell-slot-1']['maximum'] === 5, 'Session maximum is effective');
            $check($pool($response['state'], 'spell-slot-1')['currentValue'] === 2, 'Current comes from state');
            $check($exposed['spell-slot-5']['maximum'] === 1 && $pool($response['state'], 'spell-slot-5')['currentValue'] === $temporaryCurrent, 'Temporary pool remains exposed even at zero');
            $ids = array_column($response['character']['resources'], 'slug');
            $check(count($ids) === count(array_unique($ids)), 'No duplicate resources');
            $check(array_values(array_filter($ids, static fn (string $id): bool => str_starts_with($id, 'spell-slot-'))) === ['spell-slot-1', 'spell-slot-2', 'spell-slot-3', 'spell-slot-5'], 'Slots sorted numerically');
            $check($exposed['pact-magic'] === $naturalResources['pact-magic'] && $exposed['sorcery-points'] === $naturalResources['sorcery-points'], 'Trackable and Pact profiles unchanged');
            $check($exposed['spell-slot-5']['rechargeType'] === 'long-rest' && isset($exposed['spell-slot-5']['name']), 'Temporary pool supplies mapper metadata');
            foreach ($response['state']['resources'] as $entry) $check(!array_key_exists('flexibleCastingBonus', $entry), 'Internal bonus not exposed');
            $check($session->getState() === $server, 'Serialization never mutates stored bonuses');
            $check(isset($response['accessToken']) === $includeToken, 'Token exposure unchanged');
        }
    }
    foreach ([[], ['resources' => [['id' => 'spell-slot-1', 'currentValue' => 2, 'flexibleCastingBonus' => 0]]]] as $stateWithoutBonus) {
        $session->setState($stateWithoutBonus);
        $check($serializer->serialize($session)['character'] === $naturalProfile, 'No bonus preserves the natural profile');
    }
    $check($profileSerializer->serialize($character) === $naturalProfile, 'Contextual serialization does not affect standalone profile');
    echo "OK: $checks assertions; synchronization, player PATCH and serialization scenarios passed.\n";
} finally {
    while ($db->isTransactionActive()) $db->rollBack();
    $kernel->shutdown();
}
