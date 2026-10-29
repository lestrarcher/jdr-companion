<?php

declare(strict_types=1);

use App\Entity\User;

// Included by test-spell-catalogue-crud.php inside its isolated transaction.
$db->executeStatement('DROP TABLE pg_temp.spell_class_list_entry');
require __DIR__.'/../migrations/Version20261016130000.php';
$listMigration = new DoctrineMigrations\Version20261016130000($db, new Psr\Log\NullLogger());
$listMigration->up(new Doctrine\DBAL\Schema\Schema());
foreach ($listMigration->getSql() as $query) {
    $sql = $query->getStatement();
    $check(preg_match('/^CREATE (TABLE spell_class_list_entry|(?:UNIQUE )?INDEX \w+ ON spell_class_list_entry)\b/', $sql) === 1, 'Additive list migration only');
    $db->executeStatement(str_replace('CREATE TABLE ', 'CREATE TEMP TABLE ', $sql));
}
$temporarySchema = $db->fetchOne('SELECT nspname FROM pg_namespace WHERE oid = pg_my_temp_schema()');
$actual = $db->createSchemaManager()->introspectTable($temporarySchema.'.spell_class_list_entry');
$expected = (new Doctrine\ORM\Tools\SchemaTool($em))->getSchemaFromMetadata([$em->getClassMetadata(App\Entity\SpellClassListEntry::class)])->getTable('spell_class_list_entry');
$check($db->createSchemaManager()->createComparator()->compareTables($actual, $expected)->isEmpty(), 'List migration matches ORM');
$check($db->fetchOne("SELECT relpersistence FROM pg_class WHERE oid = pg_get_serial_sequence('spell_class_list_entry', 'id')::regclass") === 't', 'List sequence isolated');
$classA = new App\Entity\CharacterClass('spell-fixture-a', 'Classe A', 6, 1);
$classB = new App\Entity\CharacterClass('spell-fixture-b', 'Classe B', 6, 1);
$ownClass = new App\Entity\CharacterClass('spell-fixture-own', 'Classe personnelle', 6, 1);
$foreignClass = new App\Entity\CharacterClass('spell-fixture-foreign', 'Classe secrète', 6, 1);
// No CUSTOM class CRUD is introduced: construct owned fixtures only.
$managedOwner = $em->find(User::class, $owner->getId());
$managedOther = $em->find(User::class, $other->getId());
foreach ([[$ownClass, $managedOwner], [$foreignClass, $managedOther]] as [$class, $classOwner]) {
    (new ReflectionProperty(App\Entity\CharacterClass::class, 'origin'))->setValue($class, App\Enum\ReferenceOrigin::Custom);
    (new ReflectionProperty(App\Entity\CharacterClass::class, 'owner'))->setValue($class, $classOwner);
}
foreach ([$classA, $classB, $ownClass, $foreignClass] as $class) $em->persist($class);
$em->flush();
$a = $classA->getId(); $b = $classB->getId(); $c = $ownClass->getId(); $d = $foreignClass->getId();
$officialEntity = $em->find(App\Entity\SpellDefinition::class, $official['id']);
$customEntity = $em->find(App\Entity\SpellDefinition::class, $own['id']);
$entry = new App\Entity\SpellClassListEntry($officialEntity, $classA);
$check($entry->getCharacterClass() === $classA && $entry->getSpell() === $officialEntity, 'Entity describes list membership only');
try { new App\Entity\SpellClassListEntry($officialEntity, $ownClass); throw new RuntimeException('Official dependency accepted'); }
catch (InvalidArgumentException $error) { $check(true, 'ORM official cannot depend on custom'); }
try { new App\Entity\SpellClassListEntry($customEntity, $foreignClass); throw new RuntimeException('Foreign dependency accepted'); }
catch (InvalidArgumentException $error) { $check(true, 'ORM foreign class refused'); }
$check((new App\Entity\SpellClassListEntry($customEntity, $ownClass))->getCharacterClass() === $ownClass, 'ORM custom may use own class');
$options = array_column($service->classOptions($owner), 'id');
$check(in_array($a, $options, true) && in_array($c, $options, true) && !in_array($d, $options, true), 'Options scoped to owner');
$check(!in_array($c, array_column($service->classOptions($admin, true), 'id'), true), 'Symfony options official only');
$refuse(fn () => $service->classOptions($owner, true), 403);
$refuse(fn () => $service->search([], $owner, true), 403);
$saved = $service->patch($official['id'], ['classIds' => [$a, $b]], $admin, true);
$check($saved['classIds'] === [$a, $b] && !$saved['deletable'], 'Official memberships persisted');
$entryIds = $db->fetchAllKeyValue('SELECT character_class_id, id FROM spell_class_list_entry WHERE spell_id = ?', [$official['id']]);
$service->patch($official['id'], ['classIds' => [$b, $a]], $admin, true);
$check($entryIds === $db->fetchAllKeyValue('SELECT character_class_id, id FROM spell_class_list_entry WHERE spell_id = ?', [$official['id']]), 'Retained memberships keep IDs');
$refuse(fn () => $service->patch($official['id'], ['classIds' => [$c]], $admin, true), 400);
$refuse(fn () => $service->patch($official['id'], ['classIds' => [$a]], $admin), 403);
$refuse(fn () => $service->patch($own['id'], ['classIds' => [$a]], $admin, true), 404);
$customClasses = $service->patch($own['id'], ['classIds' => [$a, $c]], $owner);
$check($customClasses['classIds'] === [$a, $c], 'Custom spell accepts official and own custom classes');
foreach ([[$d], [$a, $a], ['1'], [999999], null, false] as $invalid) $refuse(fn () => $service->patch($own['id'], ['classIds' => $invalid, 'name' => 'Do not save'], $owner), 400);
$check($service->get($own['id'], $owner)['name'] === 'Nom modifié', 'Invalid relation patch rolls back descriptive changes');
$refuse(fn () => $service->patch($foreign['id'], ['classIds' => [$a]], $owner), 404);
$check($service->search(['classId' => $a], $owner)['total'] === 2, 'Class filter includes visible custom and official');
$check($service->search(['classId' => $a], $other)['total'] === 1, 'Custom membership does not enlarge global list');
$check($service->search(['classId' => $c], $owner)['total'] === 1 && $service->search(['classId' => $c], $other)['total'] === 0, 'Custom class filter isolated');
$check($service->search(['classId' => $a, 'pageSize' => 1, 'page' => 2], $owner)['total'] === 2, 'Class filter count before pagination');
$check($service->search(['classId' => $a, 'origin' => 'CUSTOM'], $admin, true)['total'] === 1, 'Admin cannot force custom catalogue through filter');
// Batch reads retain the exact get() contract, while issuing a constant number of queries.
$holder=null;
foreach ($db->getConfiguration()->getMiddlewares() as $middleware) if ($middleware instanceof Doctrine\Bundle\DoctrineBundle\Middleware\DebugMiddleware) {
    $holder=(new ReflectionProperty($middleware,'debugDataHolder'))->getValue($middleware);
}
$check($holder!==null,'SQL profiler enabled for batch query regression');
$queryCount=static function() use($holder):int {
    $data=(new ReflectionProperty(Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder::class,'data'))->getValue($holder);
    return array_sum(array_map('count',$data));
};
$start=$queryCount(); $batch=$service->search(['classId'=>$a,'pageSize'=>100],$owner);
$check($queryCount()-$start===3,'Search reads all memberships in one query per page');
foreach ($batch['spells'] as $spell) $check($spell===$service->get($spell['id'],$owner),'Batched catalogue preserves every field and class order');
$start=$queryCount(); $entries=$service->entriesForClasses([$a,$b,$c],$owner);
$check($queryCount()-$start===3,'Class resolution reads entries, spells and memberships in three queries');
foreach ($entries as $entry) $check($entry['spell']===$service->get($entry['spell']['id'],$owner),'Batched memberships preserve exact spell projection');
$ids=array_column($entries,'id'); $sorted=$ids; sort($sorted); $check($ids===$sorted,'Membership entry order unchanged');
$check($service->search(['page'=>999,'pageSize'=>100],$owner)['spells']===[],'Empty page needs no memberships');
$check($service->entriesForClasses([], $owner)===[],'Empty class set remains empty');
$check(!in_array($own['id'],array_column(array_column($service->entriesForClasses([$a,$c],$other),'spell'),'id'),true),'Batch resolver cannot expose another owner spell');
$service->patch($own['id'], ['description' => 'Description seule'], $owner);
$check($service->get($own['id'], $owner)['classIds'] === [$a, $c], 'Omitted classIds preserve memberships');
$refuse(fn () => $service->delete($own['id'], $owner), 409);
$refuse(fn () => $service->delete($official['id'], $admin, true), 409);
$db->createSavepoint('list_constraint');
try { $db->insert('spell_class_list_entry', ['spell_id' => $official['id'], 'character_class_id' => $a]); throw new RuntimeException('Duplicate accepted'); }
catch (Doctrine\DBAL\Exception\UniqueConstraintViolationException $error) { $check(true, 'SQL membership unique'); }
finally { $db->rollbackSavepoint('list_constraint'); $db->releaseSavepoint('list_constraint'); }
$db->createSavepoint('list_class_fk');
try { $db->delete('character_class', ['id' => $a]); throw new RuntimeException('Referenced class deleted'); }
catch (Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException $error) { $check(true, 'SQL class RESTRICT'); }
finally { $db->rollbackSavepoint('list_class_fk'); $db->releaseSavepoint('list_class_fk'); }
// Defensive reading of malformed data, independently from write validation.
$db->insert('spell_class_list_entry', ['spell_id' => $own['id'], 'character_class_id' => $d]);
$refuse(fn () => $service->get($own['id'], $owner), 404);
$check($service->search(['classId' => $a], $owner)['total'] === 1, 'Invalid dependency hidden before pagination');
$check(!in_array($own['id'],array_column(array_column($service->entriesForClasses([$a],$owner),'spell'),'id'),true),'Batch resolver hides malformed foreign dependencies');
$db->delete('spell_class_list_entry', ['spell_id' => $own['id'], 'character_class_id' => $d]);
$countBefore = (int) $db->fetchOne('SELECT COUNT(*) FROM spell_definition');
$refuse(fn () => $service->create(array_replace($fixture, ['slug' => 'invalid-class', 'classIds' => [$d]]), $owner), 400);
$check((int) $db->fetchOne('SELECT COUNT(*) FROM spell_definition') === $countBefore, 'Creation with invalid relations is atomic');
$login($owner);
$apiOptions = json_decode($request('GET', '/reference/custom/spells/options')->getContent(), true);
$check(in_array($c, array_column($apiOptions['classes'], 'id'), true) && !in_array($d, array_column($apiOptions['classes'], 'id'), true), 'Angular options enforce visibility');
$check($request('PATCH', '/reference/custom/spells/'.$own['id'], ['classIds' => [$d]])->getStatusCode() === 400, 'Angular foreign relation forbidden');
$check($request('PATCH', '/reference/custom/spells/'.$own['id'], ['classIds' => [$a]])->getStatusCode() === 200, 'Angular relation mutation');
$login($admin);
$adminOwn = $service->create(array_replace($fixture, ['slug' => 'admin-own-custom']), $admin);
$check($request('GET', '/admin/reference/spells/'.$adminOwn['id'], null, true)->getStatusCode() === 404, 'Even admin own CUSTOM is absent from Symfony');
$check($request('GET', '/admin/reference/spells/new/CUSTOM', null, true)->getStatusCode() === 404, 'Symfony CUSTOM creation route removed');
$listing = $request('GET', '/admin/reference/spells?classId='.$a, null, true);
$check($listing->getStatusCode() === 200 && !str_contains($listing->getContent(), 'Créer un sort personnalisé'), 'Official list and class filter');
$page = $request('GET', '/admin/reference/spells/'.$official['id'], null, true);
$check(str_contains($page->getContent(), 'name="classIds[]"') && !str_contains($page->getContent(), 'Classe secrète'), 'Symfony relation controls and official options');
$relationForm = $officialForm; $relationForm['_token'] = $token($page->getContent()); $relationForm['classIds'] = [(string) $b];
$check($request('POST', '/admin/reference/spells/'.$official['id'].'?classId='.$a, $relationForm, true)->getStatusCode() === 303, 'Symfony list update');
$check($service->get($official['id'], $admin, true)['classIds'] === [$b], 'Symfony detaches removed membership');
$relationForm['classIds'] = [(string) $c];
$check($request('POST', '/admin/reference/spells/'.$official['id'], $relationForm, true)->getStatusCode() === 400, 'Symfony rejects forged custom class');
$service->patch($official['id'], ['classIds' => []], $admin, true);
$service->patch($own['id'], ['classIds' => []], $owner);
$check($service->get($own['id'], $owner)['deletable'], 'Explicit detach permits later deletion');
