<?php

declare(strict_types=1);

// Run through test-spell-slot-synchronization.php: its temporary tables and sequences
// isolate both the real migration SQL and the fixture lifecycle from persistent data.
(function ($em, $db, $check, $resources, $sync, $rest, $updater, $pool): void {
    $checks = 0;
    $assert = static function (bool $condition, string $message) use ($check, &$checks): void {
        $check($condition, 'Lay on Hands: ' . $message);
        ++$checks;
    };
    $slug = 'utilisations-d-imposition-des-mains';
    $paladin = new App\Entity\CharacterClass('paladin', 'Paladin', 10, 3, App\Enum\SpellcastingProgressionType::Half);
    $fighter = $em->getRepository(App\Entity\CharacterClass::class)->findOneBy(['slug' => 'fighter']);
    $resource = (new App\Entity\TrackableResourceDefinition($slug, "Utilisations d'Imposition des mains",
        App\Enum\ResourceRechargeType::LongRest, App\Enum\ResourceMaximumType::Fixed, 5))->setMinimumMaximum(5);
    $legacy = (new App\Entity\CharacterFeatureDefinition('imposition-des-mains', 'Imposition des mains'))->setResourceDefinition($resource);
    $canonical = new App\Entity\CharacterFeatureDefinition('lay-on-hands', 'Imposition des mains');
    $rule = App\Entity\CharacterFeatureRule::forClass($canonical, $paladin, 1);
    $homonym = new App\Entity\CharacterFeatureDefinition('unrelated-lay-on-hands', 'Imposition des mains');
    $homonymRule = App\Entity\CharacterFeatureRule::forClass($homonym, $fighter, 1);
    $owner = (new App\Entity\User())->setEmail('lay-on-hands@example.invalid')->setPassword('unused');
    $campaign = new App\Entity\Campaign($owner, 'lay-on-hands', 'Imposition des mains');
    foreach ([$paladin, $resource, $legacy, $canonical, $rule, $homonym, $homonymRule, $owner, $campaign] as $entity) $em->persist($entity);
    $em->flush();
    $addLevel = static function ($character, $class) use ($em): void {
        $level = new App\Entity\CharacterClassLevel($character, $class, $character->getTotalLevel() + 1,
            null, intdiv($class->getHitDie(), 2) + 1, App\Enum\HitPointGainMethod::Average);
        $character->addClassLevel($level);
        $em->persist($level);
    };
    $maximums = static fn ($character): array => array_map(static fn ($r) => $r->getMaximum(), $resources->resolve($character));
    $characters = [];
    foreach ([[1,0], [2,0], [5,0], [10,0], [20,0], [5,5], [10,10], [0,20]] as [$p, $f]) {
        $character = new App\Entity\Character($campaign, "paladin-$p-fighter-$f", 'Test', App\Entity\Character::TYPE_PLAYER);
        $em->persist($character);
        for ($i = 0; $i < $p; ++$i) $addLevel($character, $paladin);
        for ($i = 0; $i < $f; ++$i) $addLevel($character, $fighter);
        $characters["$p/$f"] = $character;
        $assert(!isset($maximums($character)[$slug]), 'reproduces unavailable resource before migration');
    }
    $em->flush();
    $sessions = [];
    foreach ([25, 15, 0] as $current) {
        $game = new App\Entity\GameSession($campaign, 'pool-' . $current, 'Pool');
        $session = new App\Entity\CharacterSessionState($game, $characters['5/0'], [
            'hitPoints' => ['current' => 10], 'progressions' => [],
            'resources' => [['id' => $slug, 'currentValue' => $current]],
        ]);
        $em->persist($game);
        $em->persist($session);
        $sessions[$current] = $session;
    }
    $em->flush();
    require_once __DIR__ . '/../migrations/Version20260925130000.php';
    $migration = new DoctrineMigrations\Version20260925130000($db, new Psr\Log\NullLogger());
    $migration->up(new Doctrine\DBAL\Schema\Schema());
    $migrate = static function () use ($migration, $db): void {
        foreach ($migration->getSql() as $q) $db->executeStatement($q->getStatement(), $q->getParameters(), $q->getTypes());
    };
    $reject = static function (string $sql) use ($db, $migrate, $assert): void {
        $db->createSavepoint('lay_on_hands_guard');
        try {
            $db->executeStatement($sql);
            $failed = false;
            try { $migrate(); } catch (Doctrine\DBAL\Exception $error) {
                $failed = str_contains($error->getMessage(), 'Lay on Hands:');
            }
            $assert($failed, 'rejects unexpected configuration before mutation');
        } finally {
            $db->rollbackSavepoint('lay_on_hands_guard');
            $db->releaseSavepoint('lay_on_hands_guard');
        }
    };
    $reject("UPDATE character_class SET slug='missing-paladin' WHERE slug='paladin'");
    $reject("UPDATE character_feature_definition SET slug='missing-canonical' WHERE slug='lay-on-hands'");
    $reject("UPDATE trackable_resource_definition SET base_maximum=10 WHERE slug='$slug'");
    $reject("UPDATE trackable_resource_definition SET recharge_type='short-rest' WHERE slug='$slug'");
    $reject("UPDATE character_feature_rule SET unlock_level=2 WHERE id=" . $rule->getId());
    $reject("UPDATE character_feature_rule SET character_class_id=" . $fighter->getId() . " WHERE id=" . $rule->getId());
    $reject("INSERT INTO character_feature_rule (feature_definition_id, character_class_id, unlock_level, display_order) VALUES (" . $legacy->getId() . ", " . $fighter->getId() . ", 1, 0)");
    $reject("UPDATE character_feature_definition SET resource_definition_id=" . $resource->getId() . " WHERE id=" . $homonym->getId());
    $reject("INSERT INTO trackable_resource_rule (resource_definition_id, character_class_id, unlock_level, maximum_override, maximum_bonus) VALUES (" . $resource->getId() . ", " . $paladin->getId() . ", 5, 50, 0)");
    $reject("INSERT INTO trackable_resource_rule (resource_definition_id, character_class_id, unlock_level, maximum_override, maximum_bonus) VALUES (" . $resource->getId() . ", " . $fighter->getId() . ", 5, 25, 0)");
    $reject("CREATE TEMP TABLE extra_lay_on_hands_reference (feature_id INT REFERENCES character_feature_definition(id))");

    // A correct tier already present must survive unchanged; missing tiers alone are inserted.
    $existing = App\Entity\TrackableResourceRule::forClass($resource, $paladin, 5, 25);
    $em->persist($existing);
    $em->flush();
    $existingRow = $db->fetchAssociative('SELECT * FROM trackable_resource_rule WHERE id=?', [$existing->getId()]);
    $before = [];
    foreach (['trackable_resource_definition', 'character_feature_definition', 'character_feature_rule', 'character_session_state', 'character_class_level'] as $table) {
        $before[$table] = $db->fetchAllAssociative('SELECT * FROM ' . $table . ' ORDER BY id');
    }
    $otherRules = $db->fetchAllAssociative('SELECT * FROM trackable_resource_rule WHERE resource_definition_id<>? ORDER BY id', [$resource->getId()]);
    $controls = [];
    foreach ($em->getRepository(App\Entity\Character::class)->findAll() as $character) {
        $values = $maximums($character);
        unset($values[$slug]);
        $controls[$character->getId()] = $values;
    }
    $migrate();
    $first = $db->fetchAllAssociative('SELECT * FROM trackable_resource_rule ORDER BY id');
    $migrate();
    $assert($db->fetchAllAssociative('SELECT * FROM trackable_resource_rule ORDER BY id') === $first, 'second execution adds no duplicate');
    $assert($db->fetchAssociative('SELECT * FROM trackable_resource_rule WHERE id=?', [$existing->getId()]) === $existingRow, 'correct existing tier unchanged');
    $assert((int) $db->fetchOne('SELECT count(*) FROM trackable_resource_rule WHERE resource_definition_id=?', [$resource->getId()]) === 19, 'levels 2..20 represented once');
    $assert($db->fetchAllAssociative('SELECT * FROM trackable_resource_rule WHERE resource_definition_id<>? ORDER BY id', [$resource->getId()]) === $otherRules, 'other rules including Fougue unchanged');
    foreach ($before as $table => $rows) {
        if ($table === 'character_feature_definition') {
            foreach ($rows as &$row) {
                if ((int) $row['id'] === $canonical->getId()) $row['resource_definition_id'] = $resource->getId();
                if ((int) $row['id'] === $legacy->getId()) $row['resource_definition_id'] = null;
            }
            unset($row);
        }
        $assert($db->fetchAllAssociative('SELECT * FROM ' . $table . ' ORDER BY id') === $rows, 'only expected changes in ' . $table);
    }
    $em->refresh($canonical);
    $em->refresh($legacy);
    $assert($canonical->getResourceDefinition() === $resource && $legacy->getResourceDefinition() === null, 'canonical link only, legacy definition retained');
    $featureResolver = new App\Service\CharacterFeatureResolver($em->getRepository(App\Entity\CharacterFeatureRule::class));
    foreach ($characters as $key => $character) {
        $level = (int) explode('/', $key)[0];
        $assert(($maximums($character)[$slug] ?? null) === ($level === 0 ? null : 5 * $level), 'maximum for paladin/fighter ' . $key);
        $features = $featureResolver->resolve($character);
        $assert(isset($features['lay-on-hands']) === ($level > 0) && !isset($features['imposition-des-mains']), 'canonical runtime identity for ' . $key);
    }
    foreach ($em->getRepository(App\Entity\Character::class)->findAll() as $character) {
        $values = $maximums($character);
        unset($values[$slug]);
        $assert($values === $controls[$character->getId()], 'all other resolved resources unchanged');
    }

    $fresh = new App\Entity\CharacterSessionState($game, $characters['5/0'], ['resources' => []]);
    $assert($pool($sync->synchronize($characters['5/0'], $fresh->getState()), $slug)['currentValue'] === 25, 'missing pool initializes at calculated maximum');
    $firstConsumption = $updater->merge($fresh, ['resources' => [['id' => $slug, 'currentValue' => 15]]]);
    $assert($pool($firstConsumption, $slug)['currentValue'] === 15 && $fresh->getState() === ['resources' => []], 'first consumption works without rewriting existing sessions in migration');
    $session = $sessions[25];
    $session->setState($updater->merge($session, ['resources' => [['id' => $slug, 'currentValue' => 15]]]));
    $assert($pool($session->getState(), $slug)['currentValue'] === 15, 'spending ten points leaves fifteen');
    $ordinary = $sync->synchronize($characters['5/0'], $session->getState());
    $assert($pool($ordinary, $slug)['currentValue'] === 15, 'ordinary synchronization preserves partial spending');
    $rest->apply($session, App\Entity\RestRequest::TYPE_SHORT_REST);
    $assert($pool($session->getState(), $slug)['currentValue'] === 15, 'short rest does not restore');
    $rest->apply($session, App\Entity\RestRequest::TYPE_LONG_REST);
    $assert($pool($session->getState(), $slug)['currentValue'] === 25, 'long rest restores calculated twenty-five');
    $session->setState($updater->merge($session, ['resources' => [['id' => $slug, 'currentValue' => 0]]]));
    $assert($pool($sync->synchronize($characters['5/0'], $session->getState()), $slug)['currentValue'] === 0, 'exhausted pool remains zero on synchronization');
    $rest->apply($session, App\Entity\RestRequest::TYPE_SHORT_REST);
    $assert($pool($session->getState(), $slug)['currentValue'] === 0, 'short rest preserves exhaustion');

    foreach ([1 => 403, 26 => 422] as $value => $status) {
        $state = $session->getState();
        $failed = false;
        try { $updater->merge($session, ['resources' => [['id' => $slug, 'currentValue' => $value]]]); }
        catch (Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $error) { $failed = $error->getStatusCode() === $status; }
        $assert($failed && $session->getState() === $state, 'unauthorized refill/over-maximum rejected');
    }
    $snapshots = $sync->snapshotForLevelUp($characters['5/0']);
    $assert(count($snapshots) === 3, 'all session contexts captured');
    $addLevel($characters['5/0'], $paladin);
    $assert($maximums($characters['5/0'])[$slug] === 30, 'paladin 6 maximum thirty');
    $assert($pool($sync->synchronize($characters['5/0'], $sessions[15]->getState()), $slug)['currentValue'] === 15, 'ordinary sync at higher maximum does not grant points');
    $sync->synchronizeAfterLevelUp($characters['5/0'], $snapshots);
    // Generic level-up preserves spent points: 15/25 -> 20/30 and 0/25 -> 5/30.
    $assert($pool($sessions[15]->getState(), $slug)['currentValue'] === 20, 'level-up grants delta five to partially spent pool');
    $assert($pool($sessions[0]->getState(), $slug)['currentValue'] === 5, 'level-up grants delta five to exhausted pool');
    $rest->apply($sessions[0], App\Entity\RestRequest::TYPE_LONG_REST);
    $assert($pool($sessions[0]->getState(), $slug)['currentValue'] === 30, 'long rest uses new maximum thirty');
    $mixed = new App\Entity\CharacterSessionState($game, $characters['5/5'], [
        'hitPoints' => ['current' => 10], 'resources' => [['id' => $slug, 'currentValue' => 15]],
    ]);
    $snapshot = [['sessionState' => $mixed, 'before' => $sync->snapshot($characters['5/5'])]];
    $addLevel($characters['5/5'], $fighter);
    $sync->synchronizeAfterLevelUp($characters['5/5'], $snapshot);
    $assert($maximums($characters['5/5'])[$slug] === 25 && $pool($mixed->getState(), $slug)['currentValue'] === 15, 'fighter level gain grants no paladin points');
    echo "OK: $checks Lay on Hands assertions (canonical identity, migration, pool and lifecycle).\n";
})($em, $db, $check, $resources, $sync, $rest, $updater, $pool);
