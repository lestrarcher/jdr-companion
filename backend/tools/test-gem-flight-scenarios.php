<?php

declare(strict_types=1);

// Included by test-spell-slot-synchronization.php in its isolated temporary schema.
(function ($em, $db, $check, $resources, $sync, $rest, $updater, $pool): void {
    $checks = 0;
    $assert = static function (bool $ok, string $message) use ($check, &$checks): void {
        $check($ok, 'Gem Flight: ' . $message);
        ++$checks;
    };
    $slug = 'gem-flight-use';
    $canonicalSlug = 'racial-dragonborn-gem-ftd-gem-flight-5';
    $race = new App\Entity\CharacterRace('dragonborn-gem-ftd', 'Drakéide cristallin');
    $child = (new App\Entity\CharacterRace('gem-flight-child', 'Variante synthétique'))->setParentRace($race);
    $other = new App\Entity\CharacterRace('gem-flight-other', 'Autre race');
    $resource = new App\Entity\TrackableResourceDefinition($slug, 'Vol cristallin', App\Enum\ResourceRechargeType::LongRest, App\Enum\ResourceMaximumType::Fixed, 1);
    $legacy = (new App\Entity\CharacterFeatureDefinition('gem-flight', 'Vol diamantin'))->setResourceDefinition($resource);
    $canonical = new App\Entity\CharacterFeatureDefinition($canonicalSlug, 'Vol cristallin');
    $rule = App\Entity\CharacterFeatureRule::forRace($canonical, $race, 5);
    $homonym = new App\Entity\CharacterFeatureDefinition('gem-flight-unrelated', 'Vol cristallin');
    $owner = (new App\Entity\User())->setEmail('gem-flight@example.invalid')->setPassword('unused');
    $campaign = new App\Entity\Campaign($owner, 'gem-flight', 'Vol cristallin');
    $game = new App\Entity\GameSession($campaign, 'gem-flight', 'Vol cristallin');
    foreach ([$race, $child, $other, $resource, $legacy, $canonical, $rule, $homonym, $owner, $campaign, $game] as $entity) $em->persist($entity);
    $em->flush();
    $fighter = $em->getRepository(App\Entity\CharacterClass::class)->findOneBy(['slug' => 'fighter']);
    $paladin = $em->getRepository(App\Entity\CharacterClass::class)->findOneBy(['slug' => 'paladin']);
    $cases = [];
    foreach ([[$other, 5, 0, false], [null, 5, 0, false], [$race, 4, 0, false], [$race, 5, 0, true],
        [$race, 20, 0, true], [$child, 4, 0, false], [$child, 5, 0, true], [$race, 2, 2, false],
        [$race, 2, 3, true], [$child, 2, 3, true]] as $i => [$chosenRace, $f, $p, $active]) {
        $character = (new App\Entity\Character($campaign, 'gem-case-' . $i, 'Test', App\Entity\Character::TYPE_PLAYER))->setRace($chosenRace);
        $em->persist($character);
        foreach ([[$fighter, $f], [$paladin, $p]] as [$class, $count]) {
            for ($n = 0; $n < $count; ++$n) {
                $level = new App\Entity\CharacterClassLevel($character, $class, $character->getTotalLevel() + 1,
                    null, intdiv($class->getHitDie(), 2) + 1, App\Enum\HitPointGainMethod::Average);
                $character->addClassLevel($level);
                $em->persist($level);
            }
        }
        $cases[] = [$character, $active];
        $assert(!isset($resources->resolve($character)[$slug]), 'reproduces unavailable resource before transfer');
    }
    $em->flush();
    require_once __DIR__ . '/../migrations/Version20260926110000.php';
    $migration = new DoctrineMigrations\Version20260926110000($db, new Psr\Log\NullLogger());
    $migration->up(new Doctrine\DBAL\Schema\Schema());
    $migrate = static function () use ($migration, $db): void {
        foreach ($migration->getSql() as $q) $db->executeStatement($q->getStatement(), $q->getParameters(), $q->getTypes());
    };
    $reject = static function (string $sql) use ($db, $migrate, $assert): void {
        $db->createSavepoint('gem_guard');
        try {
            $db->executeStatement($sql);
            $failed = false;
            try { $migrate(); } catch (Doctrine\DBAL\Exception $error) {
                $failed = str_contains($error->getMessage(), 'Gem Flight:');
            }
            $assert($failed, 'rejects ambiguous or unexpected configuration');
        } finally {
            $db->rollbackSavepoint('gem_guard');
            $db->releaseSavepoint('gem_guard');
        }
    };
    foreach ([['character_race', 'dragonborn-gem-ftd'], ['trackable_resource_definition', $slug],
        ['character_feature_definition', 'gem-flight'], ['character_feature_definition', $canonicalSlug]] as [$table, $identity]) {
        $reject("UPDATE $table SET slug='missing-gem-identity' WHERE slug='$identity'");
    }
    $reject('UPDATE character_race SET parent_race_id=' . $other->getId() . ' WHERE id=' . $race->getId());
    $reject('UPDATE character_feature_rule SET unlock_level=4 WHERE id=' . $rule->getId());
    $reject('UPDATE character_feature_rule SET character_race_id=' . $other->getId() . ' WHERE id=' . $rule->getId());
    $reject('INSERT INTO character_feature_rule (feature_definition_id,character_race_id,unlock_level,display_order) VALUES (' . $legacy->getId() . ',' . $race->getId() . ',5,0)');
    $reject('INSERT INTO character_feature_rule (feature_definition_id,character_race_id,unlock_level,display_order) VALUES (' . $canonical->getId() . ',' . $other->getId() . ',5,0)');
    foreach (["base_maximum=2", "minimum_maximum=2", "maximum_type='proficiency-bonus'", "recharge_type='short-rest'", "scaling_ability='wisdom'", 'multiplier=2'] as $change) {
        $reject("UPDATE trackable_resource_definition SET $change WHERE slug='$slug'");
    }
    $reject('INSERT INTO trackable_resource_rule (resource_definition_id,character_race_id,unlock_level,maximum_bonus) VALUES (' . $resource->getId() . ',' . $race->getId() . ',5,0)');
    $reject("UPDATE character_feature_definition SET resource_definition_id=NULL WHERE slug='gem-flight'");
    $reject('UPDATE character_feature_definition SET resource_definition_id=' . $resource->getId() . ' WHERE id=' . $canonical->getId());
    $reject('UPDATE character_feature_definition SET resource_definition_id=' . $resource->getId() . ' WHERE id=' . $homonym->getId());
    $reject("UPDATE character_feature_definition SET resource_definition_id=(SELECT id FROM trackable_resource_definition WHERE slug='warding-flare-uses') WHERE slug='$canonicalSlug'");
    $reject('CREATE TEMP TABLE extra_gem_reference (feature_id INT REFERENCES character_feature_definition(id))');
    $before = [];
    foreach (['character_feature_definition', 'character_feature_rule', 'trackable_resource_definition', 'trackable_resource_rule', 'character_race', 'character_session_state'] as $table) {
        $before[$table] = $db->fetchAllAssociative('SELECT * FROM ' . $table . ' ORDER BY id');
    }
    $controls = [];
    foreach ($em->getRepository(App\Entity\Character::class)->findAll() as $character) {
        $controls[$character->getId()] = array_map(static fn ($r) => $r->getMaximum(), $resources->resolve($character));
    }
    $migrate();
    $first = $db->fetchAllAssociative('SELECT * FROM character_feature_definition ORDER BY id');
    $migrate();
    $assert($db->fetchAllAssociative('SELECT * FROM character_feature_definition ORDER BY id') === $first, 'already migrated is unchanged');
    foreach ($before as $table => $rows) {
        if ($table === 'character_feature_definition') {
            foreach ($rows as &$row) {
                if ((int) $row['id'] === $legacy->getId()) $row['resource_definition_id'] = null;
                if ((int) $row['id'] === $canonical->getId()) $row['resource_definition_id'] = $resource->getId();
            }
            unset($row);
        }
        $assert($db->fetchAllAssociative('SELECT * FROM ' . $table . ' ORDER BY id') === $rows, 'only two expected links changed: ' . $table);
    }
    $em->refresh($legacy);
    $em->refresh($canonical);
    $assert($legacy->getResourceDefinition() === null && $canonical->getResourceDefinition() === $resource, 'historical definition retained, canonical provider only');
    foreach ($em->getRepository(App\Entity\Character::class)->findAll() as $character) {
        $resolved = array_map(static fn ($r) => $r->getMaximum(), $resources->resolve($character));
        unset($resolved[$slug]);
        $assert($resolved === $controls[$character->getId()], 'all other fixture resources unchanged');
    }
    $features = new App\Service\CharacterFeatureResolver($em->getRepository(App\Entity\CharacterFeatureRule::class));
    foreach ($cases as [$character, $active]) {
        $assert(($resources->resolve($character)[$slug] ?? null)?->getMaximum() === ($active ? 1 : null), 'race, inheritance, total-level threshold and fixed maximum');
        $resolved = $features->resolve($character);
        $assert(!isset($resolved['gem-flight']) && isset($resolved[$canonicalSlug]) === $active, 'legacy not needed at runtime');
        $state = $sync->synchronize($character, ['hitPoints' => ['current' => 1], 'resources' => []]);
        if (!$active) {
            $assert(!in_array($slug, array_column($state['resources'], 'id'), true), 'sync does not create locked resource');
            continue;
        }
        $assert($pool($state, $slug)['currentValue'] === 1, 'new resource initializes full');
        $session = new App\Entity\CharacterSessionState($game, $character, $state);
        $session->setState($updater->merge($session, ['resources' => [['id' => $slug, 'currentValue' => 0]]]));
        $assert($pool($session->getState(), $slug)['currentValue'] === 0, 'consumption');
        $assert($pool($sync->synchronize($character, $session->getState()), $slug)['currentValue'] === 0, 'sync preserves exhausted resource');
        $rest->apply($session, App\Entity\RestRequest::TYPE_SHORT_REST);
        $assert($pool($session->getState(), $slug)['currentValue'] === 0, 'short rest preserves zero');
        $rest->apply($session, App\Entity\RestRequest::TYPE_LONG_REST);
        $assert($pool($session->getState(), $slug)['currentValue'] === 1, 'long rest restores one');
    }
    echo "OK: $checks Gem Flight assertions (migration, race inheritance, total level and resource lifecycle).\n";
})($em, $db, $check, $resources, $sync, $rest, $updater, $pool);
