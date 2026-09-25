<?php

declare(strict_types=1);

// Included by test-spell-slot-synchronization.php, after its temporary-table guards.
// All fixtures, migration queries and sessions use those temporary tables and are rolled back.
(function ($em, $db, $check, $resources, $sync, $rest, $updater, $pool): void {
    $start = 0;
    $assert = static function (bool $condition, string $message) use ($check, &$start): void {
        $check($condition, 'Fougue: ' . $message);
        ++$start;
    };
    $fighter = new App\Entity\CharacterClass('fighter', 'Guerrier', 10, 3);
    $otherClass = new App\Entity\CharacterClass('fougue-control', 'Autre classe', 8, 3);
    $subclass = new App\Entity\CharacterSubclass($fighter, 'battle-master', 'Maître de guerre');
    $resource = (new App\Entity\TrackableResourceDefinition('utilisation-de-fougue', 'Fougue',
        App\Enum\ResourceRechargeType::ShortRest, App\Enum\ResourceMaximumType::Fixed, 1))->setMinimumMaximum(1);
    $feature = (new App\Entity\CharacterFeatureDefinition('fougue', 'Fougue'))->setResourceDefinition($resource);
    $assignments = [
        App\Entity\CharacterFeatureRule::forClass($feature, $fighter, 2),
        App\Entity\CharacterFeatureRule::forClass($feature, $fighter, 17),
    ];
    $second = new App\Entity\TrackableResourceDefinition('utilisation-de-second-souffle', 'Second souffle', App\Enum\ResourceRechargeType::ShortRest, App\Enum\ResourceMaximumType::Fixed, 1);
    $indomitable = new App\Entity\TrackableResourceDefinition('utilisation-de-inflexible', 'Inflexible', App\Enum\ResourceRechargeType::LongRest, App\Enum\ResourceMaximumType::Fixed, 1);
    $dice = new App\Entity\TrackableResourceDefinition('superiority-dice', 'Dés de supériorité', App\Enum\ResourceRechargeType::ShortRest, App\Enum\ResourceMaximumType::Fixed, 4);
    $secondFeature = (new App\Entity\CharacterFeatureDefinition('fougue-control-second', 'Second souffle'))->setResourceDefinition($second);
    $indomitableFeature = (new App\Entity\CharacterFeatureDefinition('fougue-control-indomitable', 'Inflexible'))->setResourceDefinition($indomitable);
    $controls = [
        App\Entity\CharacterFeatureRule::forClass($secondFeature, $fighter, 1),
        App\Entity\CharacterFeatureRule::forClass($indomitableFeature, $fighter, 9),
        App\Entity\TrackableResourceRule::forSubclass($dice, $subclass, 3, 4),
        App\Entity\TrackableResourceRule::forSubclass($dice, $subclass, 7, 5),
        App\Entity\TrackableResourceRule::forSubclass($dice, $subclass, 15, 6),
    ];
    $owner = (new App\Entity\User())->setEmail('fougue@example.invalid')->setPassword('unused');
    $campaign = new App\Entity\Campaign($owner, 'fougue', 'Fougue');
    foreach ([$fighter, $otherClass, $subclass, $resource, $feature, ...$assignments,
        $second, $indomitable, $dice, $secondFeature, $indomitableFeature, ...$controls,
        $owner, $campaign] as $entity) $em->persist($entity);
    $em->flush();
    $addLevel = static function ($character, $class, $subclass = null) use ($em): void {
        $level = new App\Entity\CharacterClassLevel($character, $class, $character->getTotalLevel() + 1,
            $subclass, intdiv($class->getHitDie(), 2) + 1, App\Enum\HitPointGainMethod::Average);
        $character->addClassLevel($level);
        $em->persist($level);
    };
    $characters = [];
    $maximums = static function ($character) use ($resources): array {
        return array_map(static fn ($resource) => $resource->getMaximum(), $resources->resolve($character));
    };
    $beforeMaximums = [];
    foreach ([1, 2, 16, 17, 20] as $level) {
        $character = new App\Entity\Character($campaign, 'fighter-' . $level, 'Guerrier', App\Entity\Character::TYPE_PLAYER);
        $em->persist($character);
        for ($i = 1; $i <= $level; ++$i) $addLevel($character, $fighter, $i >= 3 ? $subclass : null);
        $characters[$level] = $character;
        $beforeMaximums[$level] = $maximums($character);
        $assert(($beforeMaximums[$level]['utilisation-de-fougue'] ?? null) === ($level === 1 ? null : 1), 'reproduce missing tier before migration, level ' . $level);
    }
    $em->flush();
    $sessions = [];
    foreach ([0, 1] as $current) {
        $game = new App\Entity\GameSession($campaign, 'fougue-' . $current, 'Fougue');
        $em->persist($game);
        $state = $sync->synchronize($characters[16], ['hitPoints' => ['current' => 10], 'progressions' => []]);
        foreach ($state['resources'] as &$entry) $entry['currentValue'] = $entry['id'] === 'utilisation-de-fougue' ? $current : 0;
        unset($entry);
        $sessions[$current] = new App\Entity\CharacterSessionState($game, $characters[16], $state);
        $em->persist($sessions[$current]);
    }
    $em->flush();

    require_once __DIR__ . '/../migrations/Version20260925120000.php';
    $migration = new DoctrineMigrations\Version20260925120000($db, new Psr\Log\NullLogger());
    $migration->up(new Doctrine\DBAL\Schema\Schema());
    $migrate = static function () use ($migration, $db): void {
        foreach ($migration->getSql() as $query) $db->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
    };
    $rejectMigration = static function (string $sql) use ($db, $migrate, $assert): void {
        $db->createSavepoint('fougue_guard');
        try {
            $db->executeStatement($sql);
            $failed = false;
            try { $migrate(); } catch (Doctrine\DBAL\Exception $error) {
                $failed = str_contains($error->getMessage(), 'Fougue tier:');
            }
            $assert($failed, 'migration rejects inconsistent input');
        } finally {
            $db->rollbackSavepoint('fougue_guard');
            $db->releaseSavepoint('fougue_guard');
        }
    };
    $rejectMigration("UPDATE character_class SET slug = 'missing-fighter' WHERE slug = 'fighter'");
    $rejectMigration("UPDATE trackable_resource_definition SET base_maximum = 3 WHERE slug = 'utilisation-de-fougue'");
    $rejectMigration("UPDATE trackable_resource_definition SET recharge_type = 'long-rest' WHERE slug = 'utilisation-de-fougue'");
    $rejectMigration("UPDATE character_feature_definition SET resource_definition_id = NULL WHERE slug = 'fougue'");
    $rejectMigration("DELETE FROM character_feature_rule WHERE feature_definition_id = " . $feature->getId() . " AND unlock_level = 17");
    $rejectMigration("INSERT INTO trackable_resource_rule (resource_definition_id, character_class_id, unlock_level, maximum_override, maximum_bonus) VALUES (" . $resource->getId() . ", " . $fighter->getId() . ", 17, 3, 0)");
    $rejectMigration("INSERT INTO trackable_resource_rule (resource_definition_id, character_class_id, character_subclass_id, unlock_level, maximum_override, maximum_bonus) VALUES (" . $resource->getId() . ", " . $fighter->getId() . ", " . $subclass->getId() . ", 17, 2, 0)");

    $untouched = [];
    foreach (['trackable_resource_definition', 'character_feature_definition', 'character_feature_rule', 'character_session_state', 'character_class_level'] as $table) {
        $untouched[$table] = $db->fetchAllAssociative('SELECT * FROM ' . $table . ' ORDER BY id');
    }
    $otherRules = $db->fetchAllAssociative('SELECT * FROM trackable_resource_rule ORDER BY id');
    $migrate();
    $afterFirst = $db->fetchAllAssociative('SELECT * FROM trackable_resource_rule ORDER BY id');
    $migrate();
    $assert($db->fetchAllAssociative('SELECT * FROM trackable_resource_rule ORDER BY id') === $afterFirst, 'already-correct rule is a no-op');
    $assert(count($afterFirst) === count($otherRules) + 1, 'exactly one rule inserted');
    $assert($db->fetchAllAssociative('SELECT * FROM trackable_resource_rule WHERE resource_definition_id <> ? ORDER BY id', [$resource->getId()]) === $otherRules, 'all other resource rules unchanged');
    foreach ($untouched as $table => $rows) $assert($db->fetchAllAssociative('SELECT * FROM ' . $table . ' ORDER BY id') === $rows, $table . ' unchanged');
    foreach ($characters as $level => $character) {
        $after = $maximums($character);
        $assert(($after['utilisation-de-fougue'] ?? null) === ($level === 1 ? null : ($level < 17 ? 1 : 2)), 'correct maximum at level ' . $level);
        unset($after['utilisation-de-fougue'], $beforeMaximums[$level]['utilisation-de-fougue']);
        $assert($after === $beforeMaximums[$level], 'other fighter resource maximums unchanged, level ' . $level);
    }
    foreach ([1, 16] as $fighterLevels) {
        $mixed = new App\Entity\Character($campaign, 'mixed-' . $fighterLevels, 'Multiclassé', App\Entity\Character::TYPE_PLAYER);
        $em->persist($mixed);
        for ($i = 1; $i <= 20; ++$i) $addLevel($mixed, $i <= $fighterLevels ? $fighter : $otherClass);
        $assert(($maximums($mixed)['utilisation-de-fougue'] ?? null) === ($fighterLevels === 1 ? null : 1), 'uses fighter level, not total level 20');
    }

    $snapshots = $sync->snapshotForLevelUp($characters[16]);
    $assert(count($snapshots) === 2, 'captures both sessions independently');
    $addLevel($characters[16], $fighter, $subclass);
    foreach ($sessions as $current => $session) {
        $ordinary = $sync->synchronize($characters[16], $session->getState());
        $assert($pool($ordinary, 'utilisation-de-fougue')['currentValue'] === $current, 'ordinary sync does not replenish on maximum increase');
    }
    $sync->synchronizeAfterLevelUp($characters[16], $snapshots);
    foreach ($sessions as $current => $session) {
        // Existing generic level-up convention preserves spent uses, not absolute current:
        // 0/1 -> 1/2 (one use still spent); 1/1 -> 2/2. No full refill for a consumed pool.
        $assert($pool($session->getState(), 'utilisation-de-fougue')['currentValue'] === $current + 1, 'level-up grants delta only');
        foreach (['utilisation-de-second-souffle', 'utilisation-de-inflexible', 'superiority-dice'] as $slug) {
            $assert($pool($session->getState(), $slug)['currentValue'] === 0, 'level-up preserves other consumed pool ' . $slug);
        }
    }
    $session = $sessions[1];
    foreach ([1, 0] as $remaining) {
        $session->setState($updater->merge($session, ['resources' => [['id' => 'utilisation-de-fougue', 'currentValue' => $remaining]]]));
        $assert($pool($session->getState(), 'utilisation-de-fougue')['currentValue'] === $remaining, 'consumption accepted');
    }
    foreach ([1 => 403, 3 => 422] as $requested => $status) {
        $before = $session->getState();
        $failed = false;
        try { $updater->merge($session, ['resources' => [['id' => 'utilisation-de-fougue', 'currentValue' => $requested]]]); }
        catch (Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $error) { $failed = $error->getStatusCode() === $status; }
        $assert($failed && $session->getState() === $before, 'refill/over-maximum rejected without mutation');
    }
    $rest->apply($session, App\Entity\RestRequest::TYPE_SHORT_REST);
    $assert($pool($session->getState(), 'utilisation-de-fougue')['currentValue'] === 2, 'short rest restores two uses');
    $assert($pool($session->getState(), 'utilisation-de-second-souffle')['currentValue'] === 1, 'short rest restores Second souffle');
    $assert($pool($session->getState(), 'superiority-dice')['currentValue'] === 6, 'short rest preserves superiority tier');
    $assert($pool($session->getState(), 'utilisation-de-inflexible')['currentValue'] === 0, 'short rest preserves spent Inflexible');
    $session->setState($updater->merge($session, ['resources' => [['id' => 'utilisation-de-fougue', 'currentValue' => 0]]]));
    $rest->apply($session, App\Entity\RestRequest::TYPE_LONG_REST);
    $assert($pool($session->getState(), 'utilisation-de-fougue')['currentValue'] === 2, 'long rest restores two uses');
    $assert($pool($session->getState(), 'utilisation-de-inflexible')['currentValue'] === 1, 'long rest retains existing Inflexible maximum');
    echo "OK: $start Fougue assertions (migration, tiers, multiclass, consumption, level-up and rests).\n";
})($em, $db, $check, $resources, $sync, $rest, $updater, $pool);
