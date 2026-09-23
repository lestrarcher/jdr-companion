<?php

declare(strict_types=1);

// Included by test-player-security.php inside its temporary-table transaction.
// Run: docker compose exec -T backend php tools/test-player-security.php
if (!isset($db, $check, $s, $controller)) {
    throw new LogicException('Run this scenario through test-player-security.php.');
}

$divination = new App\Entity\CharacterSubclass($wizard, 'divination', 'Divination');
$otherSubclass = new App\Entity\CharacterSubclass($wizard, 'evocation', 'Evocation');
$portentResource = new App\Entity\TrackableResourceDefinition('des-de-presage', 'Portent dice', App\Enum\ResourceRechargeType::LongRest, App\Enum\ResourceMaximumType::Fixed, 2);
$oldPortent = (new App\Entity\CharacterFeatureDefinition('presage', 'Old Portent'))->setResourceDefinition($portentResource);
$oldGreater = new App\Entity\CharacterFeatureDefinition('presage-superieur', 'Old Greater Portent');
$portent = new App\Entity\CharacterFeatureDefinition('divination-portent', 'Portent');
$greater = new App\Entity\CharacterFeatureDefinition('divination-greater-portent', 'Greater Portent');
$canonicalRules = [App\Entity\CharacterFeatureRule::forSubclass($portent, $divination, 2), App\Entity\CharacterFeatureRule::forSubclass($greater, $divination, 14)];
foreach ([$divination, $otherSubclass, $portentResource, $oldPortent, $oldGreater, $portent, $greater,
    ...$canonicalRules, App\Entity\CharacterFeatureRule::forSubclass($oldPortent, $divination, 2),
    App\Entity\CharacterFeatureRule::forSubclass($oldGreater, $divination, 14)] as $entity) $em->persist($entity);
$em->flush();
$resourceId = $portentResource->getId();
$canonicalIds = array_map(static fn ($rule) => $rule->getId(), $canonicalRules);
$canonicalRows = $db->fetchAllAssociative('SELECT * FROM character_feature_rule WHERE feature_definition_id IN (?, ?) ORDER BY id', [$portent->getId(), $greater->getId()]);
$resourceBefore = $db->fetchAssociative('SELECT * FROM trackable_resource_definition WHERE id = ?', [$resourceId]);
$statesBefore = $db->fetchAllAssociative('SELECT * FROM character_session_state ORDER BY id');

require_once __DIR__ . '/../migrations/Version20260923120000.php';
$migration = new DoctrineMigrations\Version20260923120000($db, new Psr\Log\NullLogger());
$migration->up(new Doctrine\DBAL\Schema\Schema());
$migrate = static function () use ($migration, $db): void {
    foreach ($migration->getSql() as $query) $db->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
};
$failsMigration = static function (callable $prepare, string $message) use ($db, $migrate, $check): void {
    $db->createSavepoint('portent_migration');
    try {
        $prepare();
        $failed = false;
        try { $migrate(); } catch (Doctrine\DBAL\Exception $error) {
            $failed = str_contains($error->getMessage(), 'Portent consolidation:');
        }
        $check($failed, $message);
    } finally {
        $db->rollbackSavepoint('portent_migration');
        $db->releaseSavepoint('portent_migration');
    }
};
$failsMigration(fn () => $db->executeStatement("UPDATE character_feature_definition SET slug = 'missing-portent' WHERE slug = 'divination-portent'"), 'Migration rejects missing canonical feature');
$failsMigration(fn () => $db->executeStatement("UPDATE trackable_resource_definition SET base_maximum = 4 WHERE slug = 'des-de-presage'"), 'Migration rejects inconsistent resource');
$failsMigration(fn () => $db->executeStatement('DELETE FROM character_feature_rule WHERE id = ?', [$canonicalIds[0]]), 'Migration rejects missing canonical assignment');
$failsMigration(fn () => $db->executeStatement("UPDATE character_feature_rule SET unlock_level = 3 WHERE feature_definition_id = (SELECT id FROM character_feature_definition WHERE slug = 'presage')"), 'Migration refuses to discard unexpected legacy assignments');
$failsMigration(fn () => $db->executeStatement('INSERT INTO trackable_resource_rule (resource_definition_id, character_subclass_id, character_class_id, unlock_level, maximum_override, maximum_bonus) VALUES (?, ?, ?, 14, 3, 0)', [$resourceId, $divination->getId(), $wizard->getId()]), 'Migration rejects a class plus subclass rule');
$failsMigration(fn () => $db->executeStatement('CREATE TEMP TABLE extra_portent_reference (feature_id INT REFERENCES character_feature_definition(id) ON DELETE CASCADE)'), 'Migration refuses unexpected cascading references');
$migrate();
$migrate(); // Equivalent existing rule and already removed legacy features are safe.
$check((int) $db->fetchOne("SELECT COUNT(*) FROM character_feature_definition WHERE slug IN ('presage','presage-superieur')") === 0, 'Legacy features removed');
$check($db->fetchAssociative('SELECT * FROM trackable_resource_definition WHERE id = ?', [$resourceId]) === $resourceBefore, 'Original resource and identity preserved');
$check($db->fetchAllAssociative('SELECT * FROM character_feature_rule WHERE feature_definition_id IN (?, ?) ORDER BY id', [$portent->getId(), $greater->getId()]) === $canonicalRows, 'Canonical assignments preserved exactly');
$check((int) $db->fetchOne('SELECT COUNT(*) FROM trackable_resource_rule WHERE resource_definition_id = ?', [$resourceId]) === 1, 'Migration is idempotent for the tier rule');
$check($db->fetchAllAssociative('SELECT * FROM character_session_state ORDER BY id') === $statesBefore, 'Migration preserves every session state');
$em->clear();
$wizard = $em->getRepository(App\Entity\CharacterClass::class)->findOneBy(['slug' => 'wizard']);
$divination = $em->getRepository(App\Entity\CharacterSubclass::class)->findOneBy(['slug' => 'divination']);
$otherSubclass = $em->getRepository(App\Entity\CharacterSubclass::class)->findOneBy(['slug' => 'evocation']);
$campaign = $em->getRepository(App\Entity\Campaign::class)->find($campaign->getId());
$addPortentLevel = static function ($character, $subclass) use ($em, $wizard): void {
    $position = $character->getTotalLevel() + 1;
    $level = new App\Entity\CharacterClassLevel($character, $wizard, $position, $position >= 2 ? $subclass : null,
        $position === 1 ? 6 : 4, $position === 1 ? App\Enum\HitPointGainMethod::FirstLevel : App\Enum\HitPointGainMethod::Average);
    $character->addClassLevel($level);
    $em->persist($level);
};
$portentSessions = [];
foreach ([2, 6, 13, 14, 'other'] as $tier) {
    $character = new App\Entity\Character($campaign, 'portent-' . $tier, 'Portent ' . $tier, App\Entity\Character::TYPE_PLAYER);
    $em->persist($character);
    for ($i = 0; $i < ($tier === 'other' ? 14 : $tier); ++$i) $addPortentLevel($character, $tier === 'other' ? $otherSubclass : $divination);
    $game = (new App\Entity\GameSession($campaign, 'portent-' . $tier, 'Portent'))->setStatus(App\Entity\GameSession::STATUS_LIVE);
    $session = new App\Entity\CharacterSessionState($game, $character, $s['sync']->synchronize($character, ['hitPoints' => ['current' => 1, 'temporary' => 0]]));
    $em->persist($game); $em->persist($session); $em->flush();
    $portentSessions[$tier] = $session;
    $response = json_decode($controller->showPublic($session->getAccessToken(), $access)->getContent(), true, 512, JSON_THROW_ON_ERROR);
    $resolved = array_column($response['character']['resources'], null, 'slug');
    $configured = array_column($response['character']['definition']['resources'] ?? [], null, 'id');
    if ($tier === 'other') {
        $check(!isset($resolved['des-de-presage']) && !isset($configured['des-de-presage']), 'Other Wizard subclass has no Portent resource or runtime config');
        continue;
    }
    $maximum = $tier === 14 ? 3 : 2;
    $check($resolved['des-de-presage']['maximum'] === $maximum, "Level $tier resolved maximum");
    $check($configured['des-de-presage']['storedValuesConfig'] === ['requiredCount' => $maximum, 'minimumValue' => 1, 'maximumValue' => 20], "Level $tier API configuration uses resolved maximum");
    $check($character->getDefinition() === [], 'Runtime configuration never mutates character definition');
    $slugs = array_column($response['character']['features'], 'slug');
    $check(in_array('divination-portent', $slugs, true) && !in_array('presage', $slugs, true), 'Only canonical Portent appears');
    $check(in_array('divination-greater-portent', $slugs, true) === ($tier === 14), 'Greater Portent activates at 14');
}
$pool = static fn ($session) => array_column($session->getState()['resources'], null, 'id')['des-de-presage'];
$saveRolls = static function ($session, array $values, int $expected = 200) use ($controller, $access, $em, $updater, $status, $check): void {
    $before = $session->getState();
    $body = ['revision' => $session->getRevision(), 'state' => ['resources' => [['id' => 'des-de-presage', 'currentValue' => count($values), 'storedValues' => $values]]]];
    $request = Symfony\Component\HttpFoundation\Request::create('/', 'PATCH', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR));
    $check($status(fn () => $controller->updatePublic($session->getAccessToken(), $request, $access, $em, $updater)) === $expected, 'Portent PATCH returns ' . $expected);
    if ($expected !== 200) $check($session->getState() === $before, 'Invalid Portent input leaves state untouched');
};
$session = $portentSessions[6];
foreach ([[0, 12], [7, 21], [7], [7, 12, 15], [7, 1.5], [7, '12']] as $invalidRolls) $saveRolls($session, $invalidRolls, 422);
$saveRolls($session, [7, 12]);
$em->refresh($session);
$response = json_decode($controller->showPublic($session->getAccessToken(), $access)->getContent(), true);
$check(array_column($response['state']['resources'], null, 'id')['des-de-presage']['storedValues'] === [7, 12], 'Rolls persist and survive API reload');
$saveRolls($session, [8, 12], 403);
$saveRolls($session, [7, 7], 403);
$saveRolls($session, [12]);
$check($pool($session)['currentValue'] === 1 && $pool($session)['storedValues'] === [12], 'Consuming a roll removes it and updates count');
$rest = new App\Service\CharacterRestService($s['hp'], $s['hpState'], $s['resources'], $s['sync'], $s['slots'], new App\Repository\TrackableResourceDefinitionRepository($registry), $s['ability'], new App\Repository\CharacterActiveEffectRepository($registry), new App\Service\CharacterActiveEffectService($s['hpState']), $em);
$before = $pool($session);
$rest->apply($session, App\Entity\RestRequest::TYPE_SHORT_REST);
$check($pool($session) === $before, 'Short rest preserves partial Portent series');
$saveRolls($session, []);
$check($pool($session)['storedValues'] === [] && $pool($session)['currentValue'] === 0, 'Exhausted is an empty series, not absent');
$saveRolls($session, [7, 12], 403);
$rest->apply($session, App\Entity\RestRequest::TYPE_LONG_REST);
$em->flush(); $em->refresh($session);
$check(!array_key_exists('storedValues', $pool($session)) && $pool($session)['currentValue'] === 2, 'Long rest removes series and restores resolved maximum');
$saveRolls($session, [1, 20]);
$rest->apply($session, App\Entity\RestRequest::TYPE_LONG_REST);
$check(!array_key_exists('storedValues', $pool($session)), 'Long rest also discards unconsumed results');

// Old character JSON must neither override Portent's runtime count nor lose other resources.
$character = $session->getCharacter();
$legacyDefinition = ['portraitUrl' => '/portrait.png', 'resources' => [
    ['id' => 'des-de-presage', 'notesEditable' => true, 'storedValuesConfig' => ['requiredCount' => 99, 'minimumValue' => 0, 'maximumValue' => 100]],
    ['id' => 'stored', 'storedValuesConfig' => ['requiredCount' => 2, 'minimumValue' => 1, 'maximumValue' => 20]],
]];
$character->setDefinition($legacyDefinition);
$definition = $profile->serialize($character)['definition'];
$check($definition['resources'][0]['storedValuesConfig']['requiredCount'] === 2, 'Runtime Portent overrides stale personal configuration');
$check($definition['resources'][1] === $legacyDefinition['resources'][1] && $definition['portraitUrl'] === '/portrait.png', 'Other legacy configuration preserved exactly');
$saveRolls($session, [7, 12]);
$check($character->getDefinition() === $legacyDefinition, 'Serialization and validation do not persist the overlay');

// Each session has its own rolls: full, partially consumed, exhausted and pending.
$session13 = $portentSessions[13];
$character13 = $session13->getCharacter();
$upgradeSessions = [];
foreach ([[7, 12], [12], [], null] as $index => $rolls) {
    $game = new App\Entity\GameSession($campaign, 'upgrade-' . $index, 'Upgrade');
    $game->setStatus(App\Entity\GameSession::STATUS_LIVE);
    $state = $session13->getState();
    foreach ($state['resources'] as &$entry) {
        if ($entry['id'] !== 'des-de-presage') continue;
        $entry['currentValue'] = $rolls === null ? 2 : count($rolls);
        if ($rolls !== null) $entry['storedValues'] = $rolls;
    }
    unset($entry);
    $upgradeSession = new App\Entity\CharacterSessionState($game, $character13, $state);
    $em->persist($game); $em->persist($upgradeSession);
    $upgradeSessions[] = [$upgradeSession, $rolls];
}
$em->flush();
$snapshots = $s['sync']->snapshotForLevelUp($character13);
$addPortentLevel($character13, $divination);
$s['sync']->synchronizeAfterLevelUp($character13, $snapshots);
$em->flush();
foreach ($upgradeSessions as [$upgradeSession, $rolls]) {
    $em->refresh($upgradeSession);
    $check(($pool($upgradeSession)['storedValues'] ?? null) === $rolls, '13 to 14 preserves each session series without inventing a roll');
    $check($pool($upgradeSession)['currentValue'] === ($rolls === null ? 3 : count($rolls)), '13 to 14 keeps current count coherent; pending series uses new maximum');
    $definition = $profile->serialize($character13)['definition'];
    $check(array_column($definition['resources'], null, 'id')['des-de-presage']['storedValuesConfig']['requiredCount'] === 3, 'Next series requires three results');
    if ($rolls !== null) {
        $saveRolls($upgradeSession, [1, 2, 3], 403);
        $saveRolls($upgradeSession, array_slice($rolls, 1));
    }
    $rest->apply($upgradeSession, App\Entity\RestRequest::TYPE_LONG_REST);
    $em->flush();
    $check($pool($upgradeSession)['currentValue'] === 3 && !array_key_exists('storedValues', $pool($upgradeSession)), 'Level 14 long rest opens a fresh three-roll series');
    $saveRolls($upgradeSession, [7, 12], 422);
    $saveRolls($upgradeSession, [1, 12, 20]);
}
