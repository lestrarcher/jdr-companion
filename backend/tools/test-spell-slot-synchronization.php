<?php

declare(strict_types=1);

// Run: docker compose exec -T backend php tools/test-spell-slot-synchronization.php
// ORM tables and sequences are shadowed by temporary objects, then rolled back.
use App\Entity\{Campaign, Character, CharacterClass, CharacterClassLevel, CharacterSessionState, GameSession, TrackableResourceDefinition, TrackableResourceRule, User};
use App\Enum\{HitPointGainMethod, ResourceRechargeType, SpellcastingProgressionType};
use App\Repository\{CharacterFeatureRuleRepository, CharacterSessionStateRepository, TrackableResourceRuleRepository};
use App\Service\{CharacterAbilityCalculator, CharacterFeatureResolver, CharacterHitPointCalculator, CharacterHitPointStateService, CharacterResourceResolver, CharacterSessionStateSynchronizer, CharacterSpellSlotCalculator, CharacterSpellSlotStateService};
use App\Kernel;
use App\Service\CharacterAction\CharacterFlexibleCastingActionService;
use App\Entity\RestRequest;
use App\Service\{CharacterRestService, CharacterActiveEffectService};
use App\Repository\TrackableResourceDefinitionRepository;
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
    $classResource = new TrackableResourceDefinition('class-resource', 'Class resource', ResourceRechargeType::ShortRest);
    $unrecharged = new TrackableResourceDefinition('unrecharged', 'Unrecharged', ResourceRechargeType::None);
    foreach ([$classResource, $unrecharged, TrackableResourceRule::forClass($classResource, $class, 1, 3), TrackableResourceRule::forClass($unrecharged, $class, 1, 4)] as $entity) $em->persist($entity);
    $em->flush();
    $hpState = new CharacterHitPointStateService($hp);
    $rest = new CharacterRestService($hp, $hpState, $resources, $sync, new CharacterSpellSlotCalculator(), new TrackableResourceDefinitionRepository($registry), $ability, new CharacterActiveEffectRepository($registry), new CharacterActiveEffectService($hpState), $em);
    require __DIR__ . '/test-fougue-scenarios.php';
    require __DIR__ . '/test-lay-on-hands-scenarios.php';
    require __DIR__ . '/test-cleric-domain-resource-scenarios.php';
    require __DIR__ . '/test-gem-flight-scenarios.php';
    require __DIR__ . '/test-ftd-resource-scenarios.php';
    foreach ([[1, 2], [2, 0], [1, 5]] as [$bonus, $current]) {
        foreach ([1, 0] as $temporaryCurrent) {
            $initial = ['hitPoints' => ['current' => 8], 'progressions' => [['id' => 'story', 'currentValue' => 7]], 'resources' => [
                ['id' => 'spell-slot-1', 'currentValue' => $current, 'flexibleCastingBonus' => $bonus],
                ['id' => 'spell-slot-5', 'currentValue' => $temporaryCurrent, 'flexibleCastingBonus' => 1],
                ['id' => 'sorcery-points', 'currentValue' => 1],
                ['id' => 'class-resource', 'currentValue' => 1],
                ['id' => 'pact-magic', 'currentValue' => 1],
                ['id' => 'unrecharged', 'currentValue' => 1],
                ['id' => 'historical', 'currentValue' => 7],
                ['id' => 'spell-slot-8', 'currentValue' => 0],
                ['id' => 'spell-slot-custom', 'currentValue' => 2],
            ]];
            foreach ([RestRequest::TYPE_SHORT_REST, RestRequest::TYPE_LONG_REST] as $type) {
                $session->setState($initial);
                $rest->apply($session, $type);
                $result = $session->getState();
                $long = $type === RestRequest::TYPE_LONG_REST;
                $check($pool($result, 'spell-slot-1') === ($long ? ['id' => 'spell-slot-1', 'currentValue' => 4] : $initial['resources'][0]), 'Rest preserves effective slots or restores natural maximum');
                $check($slots->effectiveMaximums($character, $result)[1] === ($long ? 4 : 4 + $bonus), 'Rest maximum matches expected');
                if ($long) {
                    $check(!in_array('spell-slot-5', array_column($result['resources'], 'id'), true), 'Long rest deletes temporary pool, including exhausted pool');
                    foreach ($result['resources'] as $entry) {
                        if (preg_match('/\Aspell-slot-[1-9]\z/', $entry['id']) === 1) $check(!array_key_exists('flexibleCastingBonus', $entry), 'No slot bonus remains after long rest');
                    }
                } else {
                    $check($pool($result, 'spell-slot-5') === $initial['resources'][1], 'Short rest preserves temporary pool, including exhausted pool');
                }
                $check($pool($result, 'sorcery-points')['currentValue'] === ($long ? 3 : 1), 'Sorcery points recharge only on long rest');
                $check($pool($result, 'class-resource')['currentValue'] === 3, 'Class short-rest resource recharges on both rests');
                $check($pool($result, 'pact-magic')['currentValue'] === 2, 'Pact Magic recharges independently on both rests');
                $check($pool($result, 'unrecharged')['currentValue'] === 1, 'No-recharge resource remains consumed');
                foreach (['historical', 'spell-slot-8', 'spell-slot-custom'] as $id) $check($pool($result, $id) === $pool($initial, $id), 'Unrelated or non-temporary historical pool preserved');
                $check($result['progressions'] === $initial['progressions'], 'Rests preserve progressions');
                $check(array_is_list($result['resources']), 'Resource list remains a JSON array');
            }
        }
    }
    $override = TrackableResourceRule::forClass($points, $class, 3, 11);
    $extra = TrackableResourceRule::forClass($points, $pactClass, 1);
    $extra->setMaximumBonus(2);
    $em->persist($override);
    $em->persist($extra);
    $em->flush();
    $check($resources->resolve($character)['sorcery-points']->getMaximum() === 13, 'Configured 11 plus bonus 2 resolves to 13');
    $casting = new CharacterFlexibleCastingActionService($resources, $slots, $sync);
    $base = ['progressions' => [['id' => 'story', 'currentValue' => 7]], 'resources' => [
        ['id' => 'sorcery-points', 'currentValue' => 10],
        ['id' => 'spell-slot-1', 'currentValue' => 1],
        ['id' => 'pact-magic', 'currentValue' => 2],
    ]];
    $refuseCasting = static function (callable $operation) use ($session, $check): void {
        $before = $session->getState();
        $updatedAt = $session->getUpdatedAt();
        try {
            $operation();
        } catch (\DomainException) {
            $check($session->getState() === $before && $session->getUpdatedAt() === $updatedAt, 'Rejected conversion leaves entity entirely unchanged');
            return;
        }
        throw new RuntimeException('Expected casting rejection');
    };
    foreach ([1 => 2, 2 => 3, 3 => 5, 4 => 6, 5 => 7] as $level => $cost) {
        $session->setState($base);
        $casting->createSpellSlot($session, $level);
        $result = $session->getState();
        $check($pool($result, 'sorcery-points')['currentValue'] === 10 - $cost, 'Creation cost at level ' . $level);
        $check($pool($result, 'spell-slot-' . $level) === ['id' => 'spell-slot-' . $level, 'currentValue' => $level === 1 ? 2 : 1, 'flexibleCastingBonus' => 1], 'Creation increments current and bonus');
        $check($slots->effectiveMaximums($character, $result)[$level] === ([1 => 4, 2 => 2][$level] ?? 0) + 1, 'Created effective maximum');
        $check($pool($result, 'pact-magic') === $base['resources'][2] && $result['progressions'] === $base['progressions'], 'Creation preserves unrelated state');
    }
    $session->setState($base);
    $casting->createSpellSlot($session, 1);
    $casting->createSpellSlot($session, 1);
    $check($pool($session->getState(), 'spell-slot-1') === ['id' => 'spell-slot-1', 'currentValue' => 3, 'flexibleCastingBonus' => 2] && $slots->effectiveMaximums($character, $session->getState())[1] === 6, 'Successive creations accumulate');
    foreach ([0, 6] as $level) $refuseCasting(fn () => $casting->createSpellSlot($session, $level));
    $session->setState(['resources' => [['id' => 'sorcery-points', 'currentValue' => 1]]]);
    $refuseCasting(fn () => $casting->createSpellSlot($session, 1));
    foreach ([1, 3, 5] as $level) {
        $session->setState(['resources' => [
            ['id' => 'sorcery-points', 'currentValue' => 4],
            ['id' => 'spell-slot-' . $level, 'currentValue' => 1, 'flexibleCastingBonus' => 1],
            ['id' => 'pact-magic', 'currentValue' => 2],
        ]]);
        $casting->convertSpellSlotToSorceryPoints($session, $level);
        $check($pool($session->getState(), 'sorcery-points')['currentValue'] === 4 + $level, 'Conversion grants slot level in points');
        $check($pool($session->getState(), 'spell-slot-' . $level) === ['id' => 'spell-slot-' . $level, 'currentValue' => 0, 'flexibleCastingBonus' => 1], 'Conversion preserves bonus at zero current');
        $check($pool($session->getState(), 'pact-magic')['currentValue'] === 2, 'Conversion never consumes Pact Magic');
        $refuseCasting(fn () => $casting->convertSpellSlotToSorceryPoints($session, $level));
    }
    $session->setState(['resources' => [['id' => 'sorcery-points', 'currentValue' => 12], ['id' => 'spell-slot-2', 'currentValue' => 1]]]);
    $refuseCasting(fn () => $casting->convertSpellSlotToSorceryPoints($session, 2));
    $session->setState(['resources' => [['id' => 'sorcery-points', 'currentValue' => 11], ['id' => 'spell-slot-2', 'currentValue' => 1]]]);
    $casting->convertSpellSlotToSorceryPoints($session, 2);
    $check($pool($session->getState(), 'sorcery-points')['currentValue'] === 13, 'Conversion can reach bonus-adjusted maximum 13');
    $session->setState(['resources' => [['id' => 'sorcery-points', 'currentValue' => 0], ['id' => 'pact-magic', 'currentValue' => 2]]]);
    $refuseCasting(fn () => $casting->convertSpellSlotToSorceryPoints($session, 1));
    foreach ([0, 10] as $level) $refuseCasting(fn () => $casting->convertSpellSlotToSorceryPoints($session, $level));
    $session->setState(['resources' => [['id' => 'spell-slot-1', 'currentValue' => 1]]]);
    $refuseCasting(fn () => $casting->createSpellSlot($session, 1));
    $refuseCasting(fn () => $casting->convertSpellSlotToSorceryPoints($session, 1));
    foreach ($em->getRepository(TrackableResourceRule::class)->findBy(['resourceDefinition' => $points]) as $rule) $em->remove($rule);
    $points->setBaseMaximum(13);
    $progression = new \App\Entity\ProgressionDefinition('casting-context', 'Casting context');
    $feature = new \App\Entity\CharacterFeatureDefinition('casting-context', 'Casting context');
    $feature->setResourceDefinition($points);
    $assignment = new \App\Entity\CharacterProgression($character, $progression);
    $character->addProgression($assignment);
    foreach ([$progression, $feature, $assignment, \App\Entity\CharacterFeatureRule::forProgression($feature, $progression, 0)] as $entity) $em->persist($entity);
    $em->flush();
    $session->setState(['progressions' => [['id' => 'casting-context', 'currentValue' => 0]], 'resources' => [['id' => 'sorcery-points', 'currentValue' => 12], ['id' => 'spell-slot-1', 'currentValue' => 1]]]);
    $casting->convertSpellSlotToSorceryPoints($session, 1);
    $check($pool($session->getState(), 'sorcery-points')['currentValue'] === 13, 'Explicit progression context including zero reaches resolver');
    $session->setState(['resources' => [['id' => 'sorcery-points', 'currentValue' => 12], ['id' => 'spell-slot-1', 'currentValue' => 1]]]);
    $refuseCasting(fn () => $casting->convertSpellSlotToSorceryPoints($session, 1));
    $controller = new \App\Controller\CharacterActionController($serializer);
    $controller->setContainer(new \Symfony\Component\DependencyInjection\Container());
    $access = new \App\Service\PlayerCharacterAccess(new CharacterSessionStateRepository($registry));
    $resolver = new CharacterActionResolver(new CharacterActionClassRuleRepository($registry));
    $class->setSlug('sorcerer');
    $class->setName('Ensorceleur');
    $action = new \App\Entity\CharacterActionDefinition('flexible-casting', 'Conversion flexible', \App\Enum\CharacterActionHandlerType::FlexibleCasting);
    $action->setRequiresPreparation(false);
    $em->persist($action);
    $em->persist(new \App\Entity\CharacterActionClassRule($action, $class, 2));
    $session->getGameSession()->setStatus(GameSession::STATUS_LIVE);
    $em->flush();
    $httpState = ['progressions' => [['id' => 'casting-context', 'currentValue' => 0]], 'characterActions' => ['prepared' => []], 'resources' => [
        ['id' => 'sorcery-points', 'currentValue' => 10], ['id' => 'spell-slot-1', 'currentValue' => 1],
    ]];
    $call = static function (string $method, CharacterSessionState $target, array|string $payload) use ($controller, $access, $resolver, $casting, $em): \Symfony\Component\HttpFoundation\JsonResponse {
        $request = \Symfony\Component\HttpFoundation\Request::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], is_string($payload) ? $payload : json_encode($payload, JSON_THROW_ON_ERROR));
        return $controller->$method($target->getAccessToken(), $request, $access, $resolver, $casting, $em);
    };
    $session->setState($httpState);
    $em->flush();
    $response = $call('createFlexibleCastingSlot', $session, ['level' => 1, 'revision' => $session->getRevision()]);
    $check($response->getStatusCode() === 200, 'Eligible unprepared action succeeds');
    $body = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
    $check($pool($body['state'], 'sorcery-points')['currentValue'] === 8 && $pool($body['state'], 'spell-slot-1')['currentValue'] === 2, 'HTTP creation returns updated currents');
    $check(array_column($body['character']['resources'], 'maximum', 'slug')['spell-slot-1'] === 5, 'HTTP creation exposes effective maximum');
    $check(!str_contains($response->getContent(), 'flexibleCastingBonus'), 'HTTP hides internal bonus');
    $em->refresh($session);
    $check($pool($session->getState(), 'spell-slot-1')['flexibleCastingBonus'] === 1, 'HTTP persists internal bonus');
    $session->setState($httpState);
    $em->flush();
    $response = $call('createFlexibleCastingSlot', $session, ['level' => 5, 'revision' => $session->getRevision()]);
    $body = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
    $check($response->getStatusCode() === 200 && $pool($body['state'], 'spell-slot-5')['currentValue'] === 1 && array_column($body['character']['resources'], 'maximum', 'slug')['spell-slot-5'] === 1, 'HTTP creates temporary pool 1/1');
    $response = $call('convertFlexibleCastingSlot', $session, ['level' => 5, 'revision' => $session->getRevision()]);
    $check($response->getStatusCode() === 200 && $pool($session->getState(), 'sorcery-points')['currentValue'] === 8, 'HTTP conversion succeeds');
    foreach ([['createFlexibleCastingSlot', 6, 10], ['createFlexibleCastingSlot', 1, 1], ['convertFlexibleCastingSlot', 3, 4], ['convertFlexibleCastingSlot', 1, 13]] as [$method, $level, $ps]) {
        $state = $httpState;
        $state['resources'][0]['currentValue'] = $ps;
        $session->setState($state);
        $em->flush();
        $revision = $session->getRevision();
        $response = $call($method, $session, ['level' => $level, 'revision' => $revision]);
        $check($response->getStatusCode() === 422, 'Domain error translated to HTTP 422');
        $em->refresh($session);
        $check($session->getState() === $state && $session->getRevision() === $revision, 'Failed HTTP conversion has no persisted mutation');
    }
    foreach ([['level' => '1', 'revision' => 1], ['level' => 1], '{invalid'] as $payload) {
        $check($call('createFlexibleCastingSlot', $session, $payload)->getStatusCode() === (is_string($payload) ? 400 : 422), 'Malformed payload rejected');
    }
    $action->setActive(false);
    $em->flush();
    $check($call('createFlexibleCastingSlot', $session, ['level' => 1, 'revision' => $session->getRevision()])->getStatusCode() === 422, 'Inactive action denied');
    $action->setActive(true);
    $em->flush();
    foreach ([0, 1, 8] as $otherLevels) {
        $low = new Character($campaign, 'low-' . $otherLevels, 'Low', Character::TYPE_PLAYER);
        $em->persist($low);
        $level = new CharacterClassLevel($low, $class, 1, null, 4, HitPointGainMethod::Average);
        $low->addClassLevel($level);
        $em->persist($level);
        for ($i = 0; $i < $otherLevels; ++$i) {
            $level = new CharacterClassLevel($low, $pactClass, $i + 2, null, 5, HitPointGainMethod::Average);
            $low->addClassLevel($level);
            $em->persist($level);
        }
        $lowSession = new CharacterSessionState($session->getGameSession(), $low, $httpState);
        $em->persist($lowSession);
        $em->flush();
        $check($call('createFlexibleCastingSlot', $lowSession, ['level' => 1, 'revision' => $lowSession->getRevision()])->getStatusCode() === 422, 'Sorcerer 1 denied regardless of total level');
        if ($otherLevels === 0) {
            $level = new CharacterClassLevel($low, $class, 2, null, 4, HitPointGainMethod::Average);
            $low->addClassLevel($level);
            $assignment = new \App\Entity\CharacterProgression($low, $progression);
            $low->addProgression($assignment);
            $em->persist($level);
            $em->persist($assignment);
            $em->flush();
            $check($call('createFlexibleCastingSlot', $lowSession, ['level' => 1, 'revision' => $lowSession->getRevision()])->getStatusCode() === 200, 'Sorcerer exactly 2 accepted without preparation');
        }
    }
    $session->setParticipating(false);
    $em->flush();
    try {
        $call('createFlexibleCastingSlot', $session, ['level' => 1, 'revision' => $session->getRevision()]);
        throw new RuntimeException('Nonparticipant must be refused');
    } catch (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException) {
        $check(true, 'Nonparticipant refused with 404');
    }
    $session->setParticipating(true);
    $session->getGameSession()->setStatus(GameSession::STATUS_CLOSED);
    $em->flush();
    $check($call('createFlexibleCastingSlot', $session, ['level' => 1, 'revision' => $session->getRevision()])->getStatusCode() === 409, 'Closed session denied');
    $session->getGameSession()->setStatus(GameSession::STATUS_LIVE);
    $session->setState($httpState);
    $em->flush();
    $revision = $session->getRevision();
    // Simulate a write committed after the entity was loaded: the locked refresh must see it.
    $db->executeStatement('UPDATE character_session_state SET revision = revision + 1 WHERE id = ?', [$session->getId()]);
    $response = $call('createFlexibleCastingSlot', $session, ['level' => 1, 'revision' => $revision]);
    $check($response->getStatusCode() === 409, 'Stale revision after locked refresh rejected');
    $stored = json_decode($db->fetchOne('SELECT state FROM character_session_state WHERE id = ?', [$session->getId()]), true, 512, JSON_THROW_ON_ERROR);
    $check($stored === $httpState, 'Revision conflict does not spend points or create slots');
    echo "OK: $checks assertions; slot lifecycle, Flexible Casting, Fougue and controller scenarios passed.\n";
} finally {
    while ($db->isTransactionActive()) $db->rollBack();
    $kernel->shutdown();
}
