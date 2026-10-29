<?php
declare(strict_types=1);

// Included within the CRUD harness: every table and sequence is temporary, rolled back.
$db->executeStatement('DROP TABLE pg_temp.character_spell_substitution');
$db->executeStatement('DROP TABLE pg_temp.spell_grant_substitution_list');
$db->executeStatement('DROP TABLE pg_temp.spell_grant_substitution_rule');
$db->executeStatement('DROP TABLE pg_temp.spell_subclass_grant');
require __DIR__.'/../migrations/Version20261016140000.php';
$migration = new DoctrineMigrations\Version20261016140000($db, new Psr\Log\NullLogger());
$migration->up(new Doctrine\DBAL\Schema\Schema());
foreach ($migration->getSql() as $query) {
    $sql = $query->getStatement();
    $check(preg_match('/^CREATE (TABLE spell_subclass_grant|(?:UNIQUE )?INDEX \w+ ON spell_subclass_grant)\b/', $sql) === 1, 'Grant migration additive only');
    $db->executeStatement(str_replace('CREATE TABLE ', 'CREATE TEMP TABLE ', $sql));
}
$db->executeStatement("ALTER TABLE pg_temp.spell_subclass_grant ADD quota_impact VARCHAR(24) DEFAULT 'unspecified' NOT NULL");
foreach ((new Doctrine\ORM\Tools\SchemaTool($em))->getCreateSchemaSql([
    $em->getClassMetadata(App\Entity\SpellGrantSubstitutionRule::class),
    $em->getClassMetadata(App\Entity\CharacterSpellSubstitution::class),
]) as $sql) {
    $db->executeStatement(str_replace('CREATE TABLE ', 'CREATE TEMP TABLE ', $sql));
}
$actual = $db->createSchemaManager()->introspectTable($temporarySchema.'.spell_subclass_grant');
$expected = (new Doctrine\ORM\Tools\SchemaTool($em))->getSchemaFromMetadata([$em->getClassMetadata(App\Entity\SpellSubclassGrant::class)])->getTable('spell_subclass_grant');
$check($db->createSchemaManager()->createComparator()->compareTables($actual, $expected)->isEmpty(), 'Grant migration matches ORM');
$check($db->fetchOne("SELECT relpersistence FROM pg_class WHERE oid = pg_get_serial_sequence('spell_subclass_grant', 'id')::regclass") === 't', 'Grant identity sequence isolated');
$classA = $em->find(App\Entity\CharacterClass::class, $a);
$managedOwner = $em->find(App\Entity\User::class, $owner->getId());
$managedOther = $em->find(App\Entity\User::class, $other->getId());
$subOfficial = new App\Entity\CharacterSubclass($classA, 'grant-official', 'Source officielle fictive');
$subOwn = new App\Entity\CharacterSubclass($classA, 'grant-own', 'Source personnelle fictive');
$subOther = new App\Entity\CharacterSubclass($classA, 'grant-other', 'Source secrète');
foreach ([[$subOwn, $managedOwner], [$subOther, $managedOther]] as [$sub, $subOwner]) {
    (new ReflectionProperty($sub, 'origin'))->setValue($sub, App\Enum\ReferenceOrigin::Custom);
    (new ReflectionProperty($sub, 'owner'))->setValue($sub, $subOwner);
}
$resource = new App\Entity\TrackableResourceDefinition('grant-resource', 'Ressource fictive', App\Enum\ResourceRechargeType::LongRest);
$ownResource = new App\Entity\TrackableResourceDefinition('grant-own-resource', 'Ressource personnelle', App\Enum\ResourceRechargeType::LongRest);
$otherResource = new App\Entity\TrackableResourceDefinition('grant-other-resource', 'Ressource secrète', App\Enum\ResourceRechargeType::LongRest);
foreach ([[$ownResource, $managedOwner], [$otherResource, $managedOther]] as [$r, $rOwner]) {
    (new ReflectionProperty($r, 'origin'))->setValue($r, App\Enum\ReferenceOrigin::Custom);
    (new ReflectionProperty($r, 'owner'))->setValue($r, $rOwner);
}
foreach ([$subOfficial, $subOwn, $subOther, $resource, $ownResource, $otherResource] as $entity) $em->persist($entity);
$em->flush();
$fingerprintOtherTables = static function () use ($db, $metadata): array {
    $result = [];
    foreach ($metadata as $meta) {
        $table = $meta->getTableName();
        if ($table === 'spell_subclass_grant') continue;
        $result[$table] = $db->fetchOne('SELECT md5(COALESCE(string_agg(to_jsonb(t)::text, \'\' ORDER BY to_jsonb(t)::text), \'\')) FROM '.$db->quoteIdentifier($table).' t');
    }
    return $result;
};
$otherTablesBefore = $fingerprintOtherTables();
$grants = new App\Service\SpellSubclassGrants($db, $service);
$payload = ['subclassId' => $subOwn->getId(), 'mode' => 'add-to-list', 'unlockLevel' => 1, 'castingClassId' => $a];
$spellBefore = $db->fetchAssociative('SELECT * FROM spell_definition WHERE id = ?', [$official['id']]);
$listBefore = $db->fetchAllAssociative('SELECT * FROM spell_class_list_entry');
$customGrant = $grants->save($official['id'], null, $payload, $owner);
$check($customGrant['origin'] === 'CUSTOM' && $customGrant['editable'], 'Custom subclass grants official spell with own provenance');
$check($grants->list($official['id'], $other) === [] && $grants->list($official['id'], $admin, true) === [], 'Custom attribution never leaks to other users or Symfony');
$officialPayload = array_replace($payload, ['subclassId' => $subOfficial->getId()]);
$officialGrant = $grants->save($official['id'], null, $officialPayload, $admin, true);
$check(count($grants->list($official['id'], $owner)) === 2 && !$grants->list($official['id'], $admin)[0]['editable'], 'Official grants readable without API edit rights');
foreach ([$owner, $admin] as $actor) {
    $refuse(fn () => $grants->save($official['id'], $officialGrant['id'], ['unlockLevel' => 2], $actor), 403);
    $refuse(fn () => $grants->delete($official['id'], $officialGrant['id'], $actor), 403);
}
$refuse(fn () => $grants->save($official['id'], null, $officialPayload, $owner, true), 403);
$refuse(fn () => $grants->save($official['id'], null, $officialPayload, $owner), 404);
$refuse(fn () => $grants->save($official['id'], null, $payload, $admin, true), 404);
$refuse(fn () => $grants->save($own['id'], null, $officialPayload, $admin, true), 404);
$refuse(fn () => $grants->save($foreign['id'], null, $payload, $owner), 404);
$refuse(fn () => $grants->save($official['id'], null, array_replace($payload, ['subclassId' => $subOther->getId()]), $owner), 404);
foreach ([$other, $admin] as $actor) {
    $refuse(fn () => $grants->save($official['id'], $customGrant['id'], ['unlockLevel' => 2], $actor), 404);
    $refuse(fn () => $grants->delete($official['id'], $customGrant['id'], $actor), 404);
}
$refuse(fn () => $grants->save($official['id'], null, $payload, $owner), 409);
foreach (['grant-known', 'grant-always-prepared'] as $mode) {
    $g = $grants->save($official['id'], null, array_replace($payload, ['mode' => $mode, 'unlockLevel' => 20]), $owner);
    $check($g['mode'] === $mode && $g['unlockLevel'] === 20, 'Distinct mode and level 20 persisted');
}
$cast = $grants->save($official['id'], null, ['subclassId' => $subOwn->getId(), 'mode' => 'grant-cast', 'unlockLevel' => 3, 'slotUsage' => 'not-required', 'restrictions' => 'Condition descriptive fictive', 'resourceId' => $resource->getId(), 'resourceCost' => 2], $owner);
$check($cast['resourceCost'] === 2 && $cast['castingClassId'] === null, 'Special cast has explicit resource, restriction and optional class');
$saved = $grants->save($official['id'], $cast['id'], ['unlockLevel' => 4, 'slotUsage' => 'either'], $owner);
$check($saved['id'] === $cast['id'] && $saved['resourceId'] === $resource->getId(), 'Partial update preserves identity and omitted fields');
$check($grants->save($official['id'], $cast['id'], ['resourceId' => $ownResource->getId(), 'slotUsage' => 'required'], $owner)['resourceName'] === 'Ressource personnelle', 'Own resource accepted with explicit slot requirement');
$refuse(fn () => (new App\Service\CustomReferenceResourceService($db))->delete($ownResource->getId(), $owner), 409);
$refuse(fn () => $grants->save($official['id'], $cast['id'], ['resourceId' => $otherResource->getId()], $owner), 404);
$refuse(fn () => $grants->save($official['id'], $cast['id'], ['resourceId' => 2147483647], $owner), 404);
$refuse(fn () => $grants->save($official['id'], null, array_replace($officialPayload, ['mode' => 'grant-cast', 'slotUsage' => 'required', 'resourceId' => $ownResource->getId(), 'resourceCost' => 1]), $admin, true), 404);
$check(!in_array($otherResource->getId(), array_column($grants->options($owner)['resources'], 'id'), true) && !in_array($ownResource->getId(), array_column($grants->options($admin, true)['resources'], 'id'), true), 'Resource options respect owner and official scope');
$grants->save($official['id'], $cast['id'], ['resourceId' => $resource->getId()], $owner);
foreach ([['unlockLevel' => 0], ['unlockLevel' => 21], ['unlockLevel' => '2'], ['mode' => 'unknown'], ['castingClassId' => null], ['slotUsage' => 'required'], ['origin' => 'OFFICIAL'], ['ownerId' => $owner->getId()], ['subclassId' => $subOther->getId()], ['spellId' => $own['id']]] as $invalid) $refuse(fn () => $grants->save($official['id'], $customGrant['id'], $invalid, $owner), 400);
foreach ([['slotUsage' => null], ['slotUsage' => 'free'], ['resourceCost' => 0], ['resourceCost' => null], ['resourceId' => null], ['restrictions' => []], ['restrictions' => str_repeat('a', 10001)]] as $invalid) $refuse(fn () => $grants->save($official['id'], $cast['id'], $invalid, $owner), 400);
$refuse(fn () => $grants->save($official['id'], $customGrant['id'], ['castingClassId' => $d], $owner), 404);
$refuse(fn () => $grants->save($official['id'], $officialGrant['id'], ['castingClassId' => $c], $admin, true), 404);
$check($grants->save($own['id'], null, array_replace($payload, ['castingClassId' => $c]), $owner)['origin'] === 'CUSTOM', 'Own custom target and casting class accepted');
$check(array_column($grants->options($owner)['subclasses'], 'id') === [$subOwn->getId()], 'Only own custom sources offered');
$check(array_column($grants->options($admin, true)['subclasses'], 'id') === [$subOfficial->getId()], 'Symfony only official sources');
$refuse(fn () => $service->delete($official['id'], $admin, true), 409);
$entity = new App\Entity\SpellSubclassGrant($subOwn, $officialEntity, App\Enum\SpellGrantMode::Known, 1, $classA);
$check($entity->getOrigin() === App\Enum\ReferenceOrigin::Custom && $entity->getOwner() === $managedOwner, 'Entity provenance belongs to source');
try { new App\Entity\SpellSubclassGrant($subOfficial, $customEntity, App\Enum\SpellGrantMode::Known, 1, $classA); throw new RuntimeException('ORM invalid dependency accepted'); }
catch (InvalidArgumentException) { $check(true, 'ORM rejects official source to custom target'); }
$ormGrant = new App\Entity\SpellSubclassGrant($subOwn, $em->find(App\Entity\SpellDefinition::class, $official['id']), App\Enum\SpellGrantMode::Known, 11, $classA);
$em->persist($ormGrant); $em->flush(); $ormId = $ormGrant->getId(); $em->clear();
$loaded = $em->find(App\Entity\SpellSubclassGrant::class, $ormId);
$check($loaded->getUnlockLevel() === 11 && $loaded->getCastingClass()->getId() === $a && $loaded->getOwner()->getId() === $owner->getId(), 'ORM roundtrip and lifecycle validation');
foreach ([['unlock_level' => 0], ['mode' => 'invalid'], ['resource_cost' => 0], ['owner_id' => null], ['slot_usage' => 'invalid']] as $invalid) {
    $db->createSavepoint('grant_check');
    try { $db->update('spell_subclass_grant', $invalid, ['id' => $cast['id']]); throw new RuntimeException('SQL constraint missing'); }
    catch (Doctrine\DBAL\Exception\DriverException) { $check(true, 'SQL CHECK enforced'); }
    finally { $db->rollbackSavepoint('grant_check'); $db->releaseSavepoint('grant_check'); }
}
foreach ([['character_subclass', $subOwn->getId()], ['trackable_resource_definition', $resource->getId()]] as [$table, $refId]) {
    $db->createSavepoint('grant_fk');
    try { $db->delete($table, ['id' => $refId]); throw new RuntimeException('FK RESTRICT missing'); }
    catch (Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException) { $check(true, 'SQL dependency RESTRICT'); }
    finally { $db->rollbackSavepoint('grant_fk'); $db->releaseSavepoint('grant_fk'); }
}
$db->update('spell_subclass_grant', ['casting_class_id' => $d], ['id' => $customGrant['id']]);
$check(!in_array($customGrant['id'], array_column($grants->list($official['id'], $owner), 'id'), true), 'Malformed foreign dependency hidden defensively');
$db->update('spell_subclass_grant', ['casting_class_id' => $a], ['id' => $customGrant['id']]);
$db->update('spell_subclass_grant', ['subclass_id' => $subOther->getId()], ['id' => $customGrant['id']]);
$refuse(fn () => $grants->delete($official['id'], $customGrant['id'], $owner), 404);
$check(!in_array($customGrant['id'], array_column($grants->list($official['id'], $owner), 'id'), true), 'Provenance must match the real source on reads and deletion');
$db->update('spell_subclass_grant', ['subclass_id' => $subOwn->getId()], ['id' => $customGrant['id']]);
$db->update('character_subclass', ['character_class_id' => $d], ['id' => $subOwn->getId()]);
$check($grants->options($owner)['subclasses'] === [], 'Invisible source parent excludes source options');
$refuse(fn () => $grants->save($official['id'], $customGrant['id'], ['unlockLevel' => 9], $owner), 404);
$db->update('character_subclass', ['character_class_id' => $a], ['id' => $subOwn->getId()]);
$apiPath = '/reference/custom/spells/'.$official['id'].'/grants';
$adminPath = '/admin/reference/spells/'.$official['id'].'/grants';
$login(null); $check($request('GET', $apiPath)->getStatusCode() === 401, 'Grant API authenticated');
$login($owner);
$check($request('GET', $adminPath, null, true)->getStatusCode() === 403, 'Symfony grants require role');
$check($request('GET', '/reference/custom/spells/grant-options')->getStatusCode() === 200, 'API grant options');
$response = $request('POST', $apiPath, array_replace($payload, ['unlockLevel' => 7]));
$check($response->getStatusCode() === 201, 'API custom source on official target created');
$apiId = json_decode($response->getContent(), true)['grant']['id'];
$check($request('PATCH', $apiPath.'/'.$apiId, ['unlockLevel' => 8])->getStatusCode() === 200, 'API patch');
$check($request('DELETE', $apiPath.'/'.$apiId)->getStatusCode() === 204, 'API delete');
$login($other); $check($request('DELETE', $apiPath.'/'.$customGrant['id'])->getStatusCode() === 404, 'HTTP ownership enforced');
$login($admin);
$check($request('PATCH', $apiPath.'/'.$officialGrant['id'], ['unlockLevel' => 2])->getStatusCode() === 403, 'Admin API cannot edit official grant');
$page = $request('GET', $adminPath.'?classId='.$a, null, true);
$check($page->getStatusCode() === 200 && !str_contains($page->getContent(), 'Source personnelle fictive'), 'Symfony grant form only official');
$check($request('POST', $adminPath, ['_token' => 'bad'], true)->getStatusCode() === 403, 'Grant CSRF enforced');
$grantForm = ['_token' => $token($page->getContent()), 'subclassId' => (string) $subOfficial->getId(), 'mode' => 'grant-known', 'unlockLevel' => '6', 'castingClassId' => (string) $a, 'slotUsage' => '', 'restrictions' => '', 'resourceId' => '', 'resourceCost' => ''];
$response = $request('POST', $adminPath.'?classId='.$a, $grantForm, true);
$check($response->getStatusCode() === 303 && str_contains($response->headers->get('Location'), 'classId='.$a), 'Symfony create and filter PRG');
$grantForm['unlockLevel'] = '21';
$errorPage = $request('POST', $adminPath, $grantForm, true);
$check($errorPage->getStatusCode() === 400 && str_contains($errorPage->getContent(), 'value="21"'), 'Grant errors preserve draft');
$editGrant = ['_token' => $token($page->getContent()), 'id' => (string) $officialGrant['id'], 'unlockLevel' => '5'];
$check($request('POST', $adminPath, $editGrant, true)->getStatusCode() === 303, 'Symfony edits existing grant');
$check((int) $db->fetchOne('SELECT unlock_level FROM spell_subclass_grant WHERE id = ?', [$officialGrant['id']]) === 5, 'Symfony editing preserves stable grant ID');
$editGrant['unlockLevel'] = '21';
$rejectedEdit = $request('POST', $adminPath, $editGrant, true);
$check($rejectedEdit->getStatusCode() === 400 && substr_count($rejectedEdit->getContent(), 'Modifier l’attribution #'.$officialGrant['id'].'<') === 1, 'Invalid edit renders one retained draft, not a conflicting duplicate');
$check($request('POST', $adminPath, ['_token' => $token($page->getContent()), '_action' => 'delete', 'id' => (string) $customGrant['id']], true)->getStatusCode() === 404, 'Symfony cannot delete a CUSTOM grant on an OFFICIAL spell');
$check($request('POST', $adminPath, ['_token' => $token($page->getContent()), '_action' => 'delete', 'id' => (string) $officialGrant['id']], true)->getStatusCode() === 303, 'Symfony deletes OFFICIAL grant');
$check($spellBefore === $db->fetchAssociative('SELECT * FROM spell_definition WHERE id = ?', [$official['id']]) && $listBefore === $db->fetchAllAssociative('SELECT * FROM spell_class_list_entry'), 'Grant operations never modify spell definitions or class lists');
$check($otherTablesBefore === $fingerprintOtherTables(), 'All other tables unchanged, including characters, sessions, acquisitions and resource definitions');
foreach ($db->fetchAllAssociative('SELECT id, spell_id, origin FROM spell_subclass_grant') as $row) $grants->delete((int) $row['spell_id'], (int) $row['id'], $row['origin'] === 'OFFICIAL' ? $admin : $owner, $row['origin'] === 'OFFICIAL');
$check((int) $db->fetchOne('SELECT COUNT(*) FROM spell_subclass_grant') === 0, 'Explicit rule deletion leaves spells intact');
