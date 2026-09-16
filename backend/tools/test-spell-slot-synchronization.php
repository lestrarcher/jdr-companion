<?php

declare(strict_types=1);

// Run: docker compose exec -T backend php tools/test-spell-slot-synchronization.php
// ORM tables and sequences are shadowed by temporary objects, then rolled back.
use App\Entity\{Campaign, Character, CharacterClass, CharacterClassLevel, CharacterSessionState, GameSession, TrackableResourceDefinition, TrackableResourceRule, User};
use App\Enum\{HitPointGainMethod, ResourceRechargeType, SpellcastingProgressionType};
use App\Repository\{CharacterFeatureRuleRepository, CharacterSessionStateRepository, TrackableResourceRuleRepository};
use App\Service\{CharacterAbilityCalculator, CharacterFeatureResolver, CharacterHitPointCalculator, CharacterHitPointStateService, CharacterResourceResolver, CharacterSessionStateSynchronizer, CharacterSpellSlotCalculator, CharacterSpellSlotStateService};
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
    echo "OK: $checks assertions; five requested scenarios and resource regressions passed.\n";
} finally {
    while ($db->isTransactionActive()) $db->rollBack();
    $kernel->shutdown();
}
