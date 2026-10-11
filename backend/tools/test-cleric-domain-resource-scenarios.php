<?php

declare(strict_types=1);

// Included by the resource harness inside its transaction with temporary ORM tables.
(function ($em, $db, $check, $resources, $sync, $rest, $updater, $pool): void {
    $checks = 0;
    $assert = static function (bool $ok, string $label) use ($check, &$checks): void {
        $check($ok, 'Cleric domains: ' . $label);
        ++$checks;
    };
    $mapping = [
        ['warding-flare-uses', 'warding-flare', 'light-domain-warding-flare', 'light-domain', 1],
        ['eyes-of-the-grave-uses', 'eyes-of-the-grave', 'grave-domain-eyes-of-the-grave', 'grave-domain', 1],
        ['sentinel-at-deaths-door-uses', 'sentinel-at-deaths-door', 'grave-domain-sentinel-at-death-s-door', 'grave-domain', 6],
        ['wrath-of-the-storm-uses', 'wrath-of-the-storm', 'tempest-domain-wrath-of-the-storm', 'tempest-domain', 1],
        ['steps-of-night-uses', 'steps-of-night', 'twilight-domain-steps-of-night', 'twilight-domain', 6],
    ];
    $cleric = new App\Entity\CharacterClass('cleric', 'Clerc', 8, 1, App\Enum\SpellcastingProgressionType::Full);
    $fighter = $em->getRepository(App\Entity\CharacterClass::class)->findOneBy(['slug' => 'fighter']);
    $owner = (new App\Entity\User())->setEmail('cleric-domains@example.invalid')->setPassword('unused');
    $campaign = new App\Entity\Campaign($owner, 'cleric-domains', 'Domaines');
    $game = new App\Entity\GameSession($campaign, 'domains', 'Domaines');
    foreach ([$cleric, $owner, $campaign, $game] as $entity) $em->persist($entity);
    $domains = [];
    foreach (['light-domain', 'grave-domain', 'tempest-domain', 'twilight-domain', 'life-domain'] as $slug) {
        $domains[$slug] = new App\Entity\CharacterSubclass($cleric, $slug, $slug);
        $em->persist($domains[$slug]);
    }
    $entities = [];
    foreach ($mapping as [$slug, $old, $new, $domain, $level]) {
        $pb = $slug === 'steps-of-night-uses';
        $resource = (new App\Entity\TrackableResourceDefinition($slug, $slug, App\Enum\ResourceRechargeType::LongRest,
            $pb ? App\Enum\ResourceMaximumType::ProficiencyBonus : App\Enum\ResourceMaximumType::AbilityModifier, 0))
            ->setMinimumMaximum(1)->setScalingAbility($pb ? null : App\Enum\Ability::Wisdom);
        $legacy = (new App\Entity\CharacterFeatureDefinition($old, $old))->setResourceDefinition($resource);
        $canonical = new App\Entity\CharacterFeatureDefinition($new, $new);
        $rule = App\Entity\CharacterFeatureRule::forSubclass($canonical, $domains[$domain], $level);
        foreach ([$resource, $legacy, $canonical, $rule] as $entity) $em->persist($entity);
        $entities[$slug] = [$resource, $legacy, $canonical, $rule];
    }
    $em->flush();
    $make = static function (string $domain, int $levels, int $other, int $wisdom) use ($em, $cleric, $fighter, $domains, $campaign): App\Entity\Character {
        $character = new App\Entity\Character($campaign, 'probe-' . bin2hex(random_bytes(6)), 'Test', App\Entity\Character::TYPE_PLAYER);
        $character->getAbilityScore(App\Enum\Ability::Wisdom)->setBaseValue($wisdom);
        $em->persist($character);
        foreach ([[$cleric, $levels, $domains[$domain]], [$fighter, $other, null]] as [$class, $count, $subclass]) {
            for ($i = 0; $i < $count; ++$i) {
                $level = new App\Entity\CharacterClassLevel($character, $class, $character->getTotalLevel() + 1,
                    $subclass, intdiv($class->getHitDie(), 2) + 1, App\Enum\HitPointGainMethod::Average);
                $character->addClassLevel($level);
                $em->persist($level);
            }
        }
        return $character;
    };
    $maximums = static fn ($character): array => array_map(static fn ($r) => $r->getMaximum(), $resources->resolve($character));
    $cases = [];
    foreach ($mapping as [$slug, $old, $new, $domain, $level]) {
        foreach ([[$domain, $level - 1, 1, 16, null], [$domain, $level, 0, 16, 3],
            ['life-domain', $level, 0, 16, null], [$domain, $level, 5, 18, 4],
            [$domain, $level, 0, 8, $slug === 'steps-of-night-uses' ? 3 : 1]] as [$d, $l, $f, $w, $expected]) {
            $character = $make($d, $l, $f, $w);
            $cases[] = [$slug, $old, $new, $character, $expected];
            $assert(!isset($maximums($character)[$slug]), 'unavailable before transfer: ' . $slug);
        }
    }
    $em->flush();
    require_once __DIR__ . '/../migrations/Version20260926100000.php';
    $migration = new DoctrineMigrations\Version20260926100000($db, new Psr\Log\NullLogger());
    $migration->up(new Doctrine\DBAL\Schema\Schema());
    $migrate = static function () use ($migration, $db): void {
        foreach ($migration->getSql() as $q) $db->executeStatement($q->getStatement(), $q->getParameters(), $q->getTypes());
    };
    $reject = static function (string $sql) use ($db, $migrate, $assert): void {
        $db->createSavepoint('cleric_guard');
        try {
            $db->executeStatement($sql);
            $failed = false;
            try { $migrate(); } catch (Doctrine\DBAL\Exception $error) {
                $failed = str_contains($error->getMessage(), 'Cleric domains:');
            }
            $assert($failed, 'rejects ambiguous configuration');
        } finally {
            $db->rollbackSavepoint('cleric_guard');
            $db->releaseSavepoint('cleric_guard');
        }
    };
    foreach ($mapping as [$slug, $old, $new, $domain, $level]) {
        [$resource, $legacy, $canonical, $rule] = $entities[$slug];
        $reject("UPDATE character_feature_definition SET slug='missing' WHERE slug='$new'");
        $reject("UPDATE character_feature_definition SET slug='missing' WHERE slug='$old'");
        $reject("UPDATE trackable_resource_definition SET slug='missing' WHERE slug='$slug'");
        $reject("UPDATE character_subclass SET character_class_id=" . $fighter->getId() . " WHERE slug='$domain'");
        $reject("UPDATE character_feature_rule SET unlock_level=20 WHERE id=" . $rule->getId());
        $reject("UPDATE character_feature_rule SET character_subclass_id=" . $domains['life-domain']->getId() . " WHERE id=" . $rule->getId());
        $reject("UPDATE character_feature_definition SET resource_definition_id=NULL WHERE slug='$old'");
        $reject("UPDATE character_feature_definition SET resource_definition_id=" . $resource->getId() . " WHERE slug='$new'");
        $reject("UPDATE trackable_resource_definition SET minimum_maximum=2 WHERE slug='$slug'");
        $reject("UPDATE trackable_resource_definition SET scaling_ability='charisma' WHERE slug='$slug'");
        $reject("UPDATE trackable_resource_definition SET multiplier=2 WHERE slug='$slug'");
        $reject("UPDATE trackable_resource_definition SET recharge_type='short-rest' WHERE slug='$slug'");
        $reject("UPDATE character_feature_definition SET resource_definition_id=" . $resource->getId() . " WHERE slug='lay-on-hands'");
        $reject("INSERT INTO character_feature_rule (feature_definition_id, character_subclass_id, unlock_level, display_order) VALUES (" . $legacy->getId() . ', ' . $domains[$domain]->getId() . ', 1, 0)');
        $reject("INSERT INTO trackable_resource_rule (resource_definition_id, character_class_id, unlock_level, maximum_bonus) VALUES (" . $resource->getId() . ', ' . $cleric->getId() . ', 1, 0)');
    }
    $reject('CREATE TEMP TABLE extra_cleric_reference (feature_id INT REFERENCES character_feature_definition(id))');
    $before = [];
    foreach (['character_feature_definition', 'character_feature_rule', 'trackable_resource_definition', 'trackable_resource_rule', 'character_session_state'] as $table) {
        $before[$table] = $db->fetchAllAssociative('SELECT * FROM ' . $table . ' ORDER BY id');
    }
    // A partially repaired catalogue must also be safe: first pair already transferred.
    $db->executeStatement("UPDATE character_feature_definition SET resource_definition_id=NULL WHERE slug='warding-flare'");
    $db->executeStatement("UPDATE character_feature_definition SET resource_definition_id=? WHERE slug='light-domain-warding-flare'", [$entities['warding-flare-uses'][0]->getId()]);
    $migrate();
    $first = $db->fetchAllAssociative('SELECT * FROM character_feature_definition ORDER BY id');
    $migrate();
    $assert($db->fetchAllAssociative('SELECT * FROM character_feature_definition ORDER BY id') === $first, 'idempotent second execution');
    foreach ($before as $table => $rows) {
        if ($table === 'character_feature_definition') {
            foreach ($rows as &$row) {
                foreach ($mapping as [$slug, $old, $new]) {
                    if ($row['slug'] === $old) $row['resource_definition_id'] = null;
                    if ($row['slug'] === $new) $row['resource_definition_id'] = $entities[$slug][0]->getId();
                }
            }
            unset($row);
        }
        $assert($db->fetchAllAssociative('SELECT * FROM ' . $table . ' ORDER BY id') === $rows, 'only expected changes in ' . $table);
    }
    foreach ($entities as [$resource, $legacy, $canonical]) {
        $em->refresh($legacy);
        $em->refresh($canonical);
        $assert($legacy->getResourceDefinition() === null && $canonical->getResourceDefinition() === $resource, 'historical definition retained without grant');
    }
    $features = new App\Service\CharacterFeatureResolver($em->getRepository(App\Entity\CharacterFeatureRule::class));
    foreach ($cases as [$slug, $old, $new, $character, $expected]) {
        $assert(($maximums($character)[$slug] ?? null) === $expected, 'threshold/domain/ability/multiclass maximum: ' . $slug);
        $resolved = $features->resolve($character);
        $assert(!isset($resolved[$old]) && isset($resolved[$new]) === ($expected !== null), 'canonical alone supplies runtime: ' . $slug);
        if ($expected === null) continue;
        $state = $sync->synchronize($character, ['hitPoints' => ['current' => 1], 'resources' => []]);
        $assert($pool($state, $slug)['currentValue'] === $expected, 'initializes at maximum');
        $session = new App\Entity\CharacterSessionState($game, $character, $state);
        $session->setState($updater->merge($session, ['resources' => [['id' => $slug, 'currentValue' => $expected - 1]]]));
        $assert($pool($session->getState(), $slug)['currentValue'] === $expected - 1, 'consumption');
        $assert($pool($sync->synchronize($character, $session->getState()), $slug)['currentValue'] === $expected - 1, 'sync preserves spending');
        $rest->apply($session, App\Entity\RestRequest::TYPE_SHORT_REST);
        $assert($pool($session->getState(), $slug)['currentValue'] === $expected - 1, 'short rest preserves spending');
        $rest->apply($session, App\Entity\RestRequest::TYPE_LONG_REST);
        $assert($pool($session->getState(), $slug)['currentValue'] === $expected, 'long rest restores');
    }
    echo "OK: $checks cleric domain assertions (migration guards, identity, maximums, lifecycle).\n";
})($em, $db, $check, $resources, $sync, $rest, $updater, $pool);
