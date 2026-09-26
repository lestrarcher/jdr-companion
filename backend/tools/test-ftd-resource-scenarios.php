<?php

declare(strict_types=1);

// Included by the resource harness: all ORM tables/sequences are temporary.
(function ($em, $db, $check, $resources, $sync, $rest, $updater, $pool): void {
    $checks = 0;
    $assert = static function (bool $ok, string $message) use ($check, &$checks): void {
        $check($ok, 'FTD resources: ' . $message);
        ++$checks;
    };
    $mapping = [
        ['breath-weapon-uses', 'racial-dragonborn-chromatic-ftd-breath-weapon-2', 'dragonborn-chromatic-ftd', 1],
        ['breath-weapon-uses', 'racial-dragonborn-gem-ftd-breath-weapon-2', 'dragonborn-gem-ftd', 1],
        ['breath-weapon-uses', 'racial-dragonborn-metallic-ftd-breath-weapon-2', 'dragonborn-metallic-ftd', 1],
        ['chromatic-warding-use', 'racial-dragonborn-chromatic-ftd-chromatic-warding-4', 'dragonborn-chromatic-ftd', 5],
        ['metallic-breath-weapon-use', 'racial-dragonborn-metallic-ftd-metallic-breath-weapon-4', 'dragonborn-metallic-ftd', 5],
    ];
    $races = [];
    foreach (['dragonborn', 'chromatic-dragonborn-red', 'metallic-dragonborn-silver',
        'dragonborn-chromatic-ftd', 'dragonborn-gem-ftd', 'dragonborn-metallic-ftd', 'dragonborn-phb'] as $slug) {
        $races[$slug] = $em->getRepository(App\Entity\CharacterRace::class)->findOneBy(['slug' => $slug])
            ?? new App\Entity\CharacterRace($slug, $slug);
        $em->persist($races[$slug]);
    }
    foreach (['chromatic-dragonborn-red', 'metallic-dragonborn-silver'] as $slug) $races[$slug]->setParentRace($races['dragonborn']);
    $races['ftd-child'] = (new App\Entity\CharacterRace('ftd-child', 'Descendante synthétique'))->setParentRace($races['dragonborn-chromatic-ftd']);
    $em->persist($races['ftd-child']);
    $definitions = [];
    foreach (['breath-weapon-uses', 'chromatic-warding-use', 'metallic-breath-weapon-use'] as $slug) {
        $breath = $slug === 'breath-weapon-uses';
        $definitions[$slug] = (new App\Entity\TrackableResourceDefinition($slug, $slug, App\Enum\ResourceRechargeType::LongRest,
            $breath ? App\Enum\ResourceMaximumType::ProficiencyBonus : App\Enum\ResourceMaximumType::Fixed, $breath ? 0 : 1))
            ->setMinimumMaximum($breath ? 1 : 0);
        $em->persist($definitions[$slug]);
    }
    $legacy = [];
    foreach ([['breath-weapon', 'breath-weapon-uses', ['dragonborn', 'chromatic-dragonborn-red', 'metallic-dragonborn-silver'], 1],
        ['chromatic-warding', 'chromatic-warding-use', ['chromatic-dragonborn-red'], 5],
        ['metallic-breath-weapon', 'metallic-breath-weapon-use', ['metallic-dragonborn-silver'], 5]] as [$slug, $resource, $origins, $level]) {
        $feature = (new App\Entity\CharacterFeatureDefinition($slug, $slug))->setResourceDefinition($definitions[$resource]);
        $legacy[$slug] = $feature;
        $em->persist($feature);
        foreach ($origins as $race) $em->persist(App\Entity\CharacterFeatureRule::forRace($feature, $races[$race], $level));
    }
    $canonical = [];
    $rules = [];
    foreach ($mapping as [$resource, $slug, $race, $level]) {
        $canonical[$slug] = new App\Entity\CharacterFeatureDefinition($slug, $slug);
        $rules[$slug] = App\Entity\CharacterFeatureRule::forRace($canonical[$slug], $races[$race], $level);
        $em->persist($canonical[$slug]);
        $em->persist($rules[$slug]);
    }
    $owner = (new App\Entity\User())->setEmail('ftd@example.invalid')->setPassword('unused');
    $campaign = new App\Entity\Campaign($owner, 'ftd', 'FTD');
    $game = new App\Entity\GameSession($campaign, 'ftd', 'FTD');
    foreach ([$owner, $campaign, $game] as $entity) $em->persist($entity);
    $em->flush();
    $fighter = $em->getRepository(App\Entity\CharacterClass::class)->findOneBy(['slug' => 'fighter']);
    $paladin = $em->getRepository(App\Entity\CharacterClass::class)->findOneBy(['slug' => 'paladin']);
    $addLevel = static function ($character, $class) use ($em): void {
        $level = new App\Entity\CharacterClassLevel($character, $class, $character->getTotalLevel() + 1,
            null, intdiv($class->getHitDie(), 2) + 1, App\Enum\HitPointGainMethod::Average);
        $character->addClassLevel($level);
        $em->persist($level);
    };
    $make = static function (string $race, int $f, int $p = 0) use ($em, $races, $campaign, $fighter, $paladin, $addLevel) {
        $c = (new App\Entity\Character($campaign, 'ftd-' . bin2hex(random_bytes(6)), 'Test', App\Entity\Character::TYPE_PLAYER))->setRace($races[$race]);
        $em->persist($c);
        for ($i = 0; $i < $f; ++$i) $addLevel($c, $fighter);
        for ($i = 0; $i < $p; ++$i) $addLevel($c, $paladin);
        return $c;
    };
    $expected = static function (string $race, int $level): array {
        $out = [];
        if ($race === 'dragonborn-phb') return $out;
        if ($level >= 1) $out['breath-weapon-uses'] = 2 + intdiv($level - 1, 4);
        if ($level >= 5 && in_array($race, ['chromatic-dragonborn-red', 'dragonborn-chromatic-ftd', 'ftd-child'], true)) $out['chromatic-warding-use'] = 1;
        if ($level >= 5 && in_array($race, ['metallic-dragonborn-silver', 'dragonborn-metallic-ftd'], true)) $out['metallic-breath-weapon-use'] = 1;
        return $out;
    };
    $maximums = static fn ($c): array => array_map(static fn ($r) => $r->getMaximum(), $resources->resolve($c));
    $cases = [];
    foreach (array_keys($races) as $race) foreach ([0, 1, 4, 5, 9, 17, 20] as $level) $cases[] = [$race, $make($race, $level)];
    foreach (['dragonborn-chromatic-ftd', 'dragonborn-gem-ftd', 'dragonborn-metallic-ftd'] as $race) {
        $cases[] = [$race, $make($race, 2, 2)];
        $cases[] = [$race, $make($race, 2, 3)];
    }
    foreach ($cases as [$race, $c]) {
        $legacyOrigin = in_array($race, ['dragonborn', 'chromatic-dragonborn-red', 'metallic-dragonborn-silver'], true);
        $beforeExpected = $legacyOrigin ? $expected($race, $c->getTotalLevel()) : [];
        $assert(array_intersect_key($maximums($c), $definitions) == $beforeExpected, 'pre-migration old origins work, FTD unavailable');
    }
    $em->flush();
    require_once __DIR__ . '/../migrations/Version20260926120000.php';
    $migration = new DoctrineMigrations\Version20260926120000($db, new Psr\Log\NullLogger());
    $migration->up(new Doctrine\DBAL\Schema\Schema());
    $migrate = static function () use ($db, $migration): void {
        foreach ($migration->getSql() as $q) $db->executeStatement($q->getStatement(), $q->getParameters(), $q->getTypes());
    };
    $reject = static function (string $sql) use ($db, $migrate, $assert): void {
        $db->createSavepoint('ftd_guard');
        try {
            $db->executeStatement($sql);
            $failed = false;
            try { $migrate(); } catch (Doctrine\DBAL\Exception $error) {
                $failed = str_contains($error->getMessage(), 'FTD resources:');
            }
            $assert($failed, 'rejects unexpected identities, links, origins or mechanics');
        } finally {
            $db->rollbackSavepoint('ftd_guard');
            $db->releaseSavepoint('ftd_guard');
        }
    };
    foreach ($mapping as [$resource, $slug, $race, $level]) {
        $reject("UPDATE character_feature_definition SET slug='missing-ftd' WHERE slug='$slug'");
        $reject("UPDATE character_feature_definition SET resource_definition_id=(SELECT id FROM trackable_resource_definition WHERE slug='gem-flight-use') WHERE slug='$slug'");
        $reject('UPDATE character_feature_rule SET unlock_level=3 WHERE id=' . $rules[$slug]->getId());
        $reject('UPDATE character_feature_rule SET character_race_id=' . $races['dragonborn-phb']->getId() . ' WHERE id=' . $rules[$slug]->getId());
        $reject('INSERT INTO character_feature_rule (feature_definition_id,character_race_id,unlock_level,display_order) VALUES (' . $canonical[$slug]->getId() . ',' . $races['dragonborn-phb']->getId() . ',1,0)');
    }
    foreach ($definitions as $slug => $resource) {
        $reject("UPDATE trackable_resource_definition SET slug='missing-ftd' WHERE slug='$slug'");
        foreach (['base_maximum=7', 'minimum_maximum=7', 'multiplier=2', "scaling_ability='wisdom'", "recharge_type='short-rest'"] as $change) {
            $reject("UPDATE trackable_resource_definition SET $change WHERE slug='$slug'");
        }
        $reject('INSERT INTO trackable_resource_rule (resource_definition_id,character_race_id,unlock_level,maximum_bonus) VALUES (' . $resource->getId() . ',' . $races['dragonborn-phb']->getId() . ',1,0)');
        $reject("UPDATE character_feature_definition SET resource_definition_id=" . $resource->getId() . " WHERE slug='gem-flight-unrelated'");
    }
    foreach ($legacy as $slug => $feature) {
        $reject("UPDATE character_feature_definition SET resource_definition_id=NULL WHERE slug='$slug'");
        $reject('DELETE FROM character_feature_rule WHERE feature_definition_id=' . $feature->getId());
    }
    foreach (['dragonborn', 'chromatic-dragonborn-red', 'metallic-dragonborn-silver', 'dragonborn-chromatic-ftd', 'dragonborn-gem-ftd', 'dragonborn-metallic-ftd'] as $race) {
        $reject("UPDATE character_race SET slug='missing-ftd' WHERE slug='$race'");
        $reject('UPDATE character_race SET parent_race_id=' . $races['dragonborn-phb']->getId() . ' WHERE id=' . $races[$race]->getId());
    }
    $before = [];
    foreach (['character_feature_definition', 'character_feature_rule', 'trackable_resource_definition', 'trackable_resource_rule', 'character_race', 'character_session_state'] as $table) {
        $before[$table] = $db->fetchAllAssociative('SELECT * FROM ' . $table . ' ORDER BY id');
    }
    $controls = [];
    foreach ($em->getRepository(App\Entity\Character::class)->findAll() as $c) $controls[$c->getId()] = array_diff_key($maximums($c), $definitions);
    // Already-correct links, including a partially repaired catalogue, are accepted.
    $db->executeStatement('UPDATE character_feature_definition SET resource_definition_id=? WHERE id=?', [$definitions['breath-weapon-uses']->getId(), $canonical[$mapping[0][1]]->getId()]);
    $migrate();
    $first = $db->fetchAllAssociative('SELECT * FROM character_feature_definition ORDER BY id');
    $migrate();
    $assert($db->fetchAllAssociative('SELECT * FROM character_feature_definition ORDER BY id') === $first, 'idempotent migration');
    foreach ($before as $table => $rows) {
        if ($table === 'character_feature_definition') {
            foreach ($rows as &$row) foreach ($mapping as [$resource, $slug]) {
                if ($row['slug'] === $slug) $row['resource_definition_id'] = $definitions[$resource]->getId();
            }
            unset($row);
        }
        $assert($db->fetchAllAssociative('SELECT * FROM ' . $table . ' ORDER BY id') === $rows, 'only five canonical links change in ' . $table);
    }
    foreach ($canonical as $feature) $em->refresh($feature);
    foreach ($legacy as $feature) {
        $em->refresh($feature);
        $assert($feature->getResourceDefinition() !== null, 'legacy link preserved');
    }
    foreach ($em->getRepository(App\Entity\Character::class)->findAll() as $c) $assert(array_diff_key($maximums($c), $definitions) === $controls[$c->getId()], 'all unrelated resolved resources unchanged, including Gem Flight');
    $featureResolver = new App\Service\CharacterFeatureResolver($em->getRepository(App\Entity\CharacterFeatureRule::class));
    foreach ($cases as [$race, $c]) {
        $wanted = $expected($race, $c->getTotalLevel());
        $assert(array_intersect_key($maximums($c), $definitions) == $wanted, 'correct pools for ' . $race . ' total ' . $c->getTotalLevel());
        $resolved = $featureResolver->resolve($c);
        if (str_ends_with($race, '-ftd') || $race === 'ftd-child') {
            foreach (array_keys($legacy) as $old) $assert(!isset($resolved[$old]), 'FTD does not depend on historical feature');
        }
        $state = $sync->synchronize($c, ['hitPoints' => ['current' => 1], 'resources' => []]);
        foreach ($wanted as $slug => $max) {
            $assert($pool($state, $slug)['currentValue'] === $max, 'initialization at maximum');
            $session = new App\Entity\CharacterSessionState($game, $c, $state);
            $session->setState($updater->merge($session, ['resources' => [['id' => $slug, 'currentValue' => 0]]]));
            $assert($pool($session->getState(), $slug)['currentValue'] === 0, 'consumption');
            foreach ($wanted as $otherSlug => $otherMaximum) if ($otherSlug !== $slug) {
                $assert($pool($session->getState(), $otherSlug)['currentValue'] === $otherMaximum, 'ordinary and special pools remain independent');
            }
            $assert($pool($sync->synchronize($c, $session->getState()), $slug)['currentValue'] === 0, 'sync preserves consumption');
            $rest->apply($session, App\Entity\RestRequest::TYPE_SHORT_REST);
            $assert($pool($session->getState(), $slug)['currentValue'] === 0, 'short rest preserves consumption');
            $rest->apply($session, App\Entity\RestRequest::TYPE_LONG_REST);
            $assert($pool($session->getState(), $slug)['currentValue'] === $max, 'long rest restores maximum');
        }
    }
    $firstCharacter = $make('dragonborn-chromatic-ftd', 5);
    $secondCharacter = $make('dragonborn-metallic-ftd', 5);
    $firstSession = new App\Entity\CharacterSessionState($game, $firstCharacter, ['resources' => [['id' => 'breath-weapon-uses', 'currentValue' => 3]]]);
    $secondSession = new App\Entity\CharacterSessionState($game, $secondCharacter, ['resources' => [['id' => 'breath-weapon-uses', 'currentValue' => 3]]]);
    $firstSession->setState($updater->merge($firstSession, ['resources' => [['id' => 'breath-weapon-uses', 'currentValue' => 0]]]));
    $assert($pool($firstSession->getState(), 'breath-weapon-uses')['currentValue'] === 0
        && $pool($secondSession->getState(), 'breath-weapon-uses')['currentValue'] === 3, 'shared definition never shares mutable uses across characters');
    foreach (['dragonborn-chromatic-ftd', 'dragonborn-gem-ftd', 'dragonborn-metallic-ftd', 'chromatic-dragonborn-red', 'metallic-dragonborn-silver'] as $race) {
        $c = $make($race, 4);
        $session = new App\Entity\CharacterSessionState($game, $c, ['resources' => [['id' => 'breath-weapon-uses', 'currentValue' => 1]]]);
        $snapshot = [['sessionState' => $session, 'before' => $sync->snapshot($c)]];
        $addLevel($c, $paladin);
        $assert($maximums($c)['breath-weapon-uses'] === 3, 'PB grows at total 5 even in multiclass');
        $assert($pool($sync->synchronize($c, $session->getState()), 'breath-weapon-uses')['currentValue'] === 1, 'ordinary sync does not grant new use');
        $sync->synchronizeAfterLevelUp($c, $snapshot);
        $assert($pool($session->getState(), 'breath-weapon-uses')['currentValue'] === 2, 'level-up preserves spent uses: 1/2 becomes 2/3');
        foreach ($expected($race, 5) as $slug => $max) if ($slug !== 'breath-weapon-uses') $assert($pool($session->getState(), $slug)['currentValue'] === 1, 'level 5 special resource initializes');
    }
    echo "OK: $checks FTD resource assertions (five links, legacy races, sharing, total levels and rests).\n";
})($em, $db, $check, $resources, $sync, $rest, $updater, $pool);
