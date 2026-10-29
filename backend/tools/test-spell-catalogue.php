<?php

declare(strict_types=1);

// All fixtures, including synthetic OFFICIAL rows, are temporary and rolled back.
// No spell text is imported; no application table or sequence is changed.
use App\Entity\{SpellDefinition, User};
use App\Enum\{ReferenceOrigin, SpellSchool};
use App\Service\ReferenceVisibility;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\Tools\{SchemaTool, SchemaValidator};
use Symfony\Component\Dotenv\Dotenv;

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/../migrations/Version20261016120000.php';
(new Dotenv())->bootEnv(__DIR__.'/../.env');
$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void {
    if (!$ok) throw new RuntimeException($message);
    ++$checks;
};
$reject = static function (callable $operation, string $message) use ($check): void {
    try { $operation(); } catch (InvalidArgumentException|ValueError $error) { $check(true, $message); return; }
    throw new RuntimeException('Expected rejection: '.$message);
};
$make = static fn (string $slug = 'test-spell', int $level = 0) => new SpellDefinition($slug, 'Sort fictif de test', $level, SpellSchool::Illusion, 'Texte de test', 'Portée de test', 'Durée de test', 'Description fictive, aucun contenu officiel.');
$owner = (new User())->setEmail('spell-owner@example.invalid')->setPassword('unused');
$other = (new User())->setEmail('spell-other@example.invalid')->setPassword('unused');
$official = $make();
$custom = SpellDefinition::forOwner($owner, 'test-custom', 'Sort personnel fictif', 9, SpellSchool::Abjuration, 'Test', 'Test', 'Test', 'Fiction de test');
$foreign = SpellDefinition::forOwner($other, 'test-foreign', 'Sort tiers fictif', 1, SpellSchool::Evocation, 'Test', 'Test', 'Test', 'Fiction de test');
$check($official->getLevel() === 0 && $custom->getLevel() === 9, 'Level boundaries');
$check($official->getEdition() === '2014' && $official->getOrigin() === ReferenceOrigin::Official && $official->getOwner() === null, 'Official identity');
$check($custom->getOrigin() === ReferenceOrigin::Custom && $custom->getOwner() === $owner, 'Custom identity');
foreach ([-1, 10] as $level) $reject(fn () => $make(level: $level), 'Invalid level');
foreach (['', 'bad slug', '-bad', 'bad-', str_repeat('a', 101)] as $slug) $reject(fn () => $make($slug), 'Invalid slug');
$check($make(' Test-Slug ')->getSlug() === 'test-slug', 'Stable normalized slug');
$check(count(SpellSchool::cases()) === 8, 'Eight schools');
foreach (SpellSchool::cases() as $school) {
    $official->setSchool($school);
    $check($official->getSchool() === $school && $school->label() !== '', 'Typed school');
}
$reject(fn () => SpellSchool::from('unknown'), 'Unknown school');
foreach (['', '   ', "bad\0text", str_repeat('a', 151)] as $name) $reject(fn () => $official->setName($name), 'Invalid name');
$reject(fn () => $official->setDescription(str_repeat('x', 50001)), 'Bounded description');
$reject(fn () => $official->setDuration(' '), 'Duration required');
$reject(fn () => $official->setRange(''), 'Range required');
$reject(fn () => $official->setCastingTime(''), 'Casting time required');
$custom->setComponents(true, true, true, 'Objet de test', 150, true);
foreach ([
    [true, false, false, 'Objet'], [false, false, false, null, 0],
    [false, false, false, null, null, true], [true, false, true],
    [true, false, true, 'Objet', -1], [true, false, true, 'Objet', 2147483648],
] as $arguments) {
    $reject(fn () => $custom->setComponents(...$arguments), 'Invalid material group');
    $check($custom->isMaterial() && $custom->getMaterialCostCp() === 150 && $custom->isMaterialConsumed(), 'Rejected component edit is atomic');
}
$custom->setComponents(true, false, false);
$check(!$custom->isMaterial() && $custom->getMaterialDescription() === null && $custom->getMaterialCostCp() === null && !$custom->isMaterialConsumed(), 'Remove material atomically');
$custom->setComponents(false, true, true, 'Coût variable décrit en texte');
$check($custom->getMaterialCostCp() === null, 'Unknown cost stays unknown');
$custom->setComponents(false, true, true, 'Objet sans coût', 0);
$check($custom->getMaterialCostCp() === 0, 'Zero differs from unknown');
$custom->setRulesVariant('Variante de test')->setConcentration(true)->setRitual(true)->setOriginalName('Test original')->setHigherLevels('Texte fictif');
$reject(fn () => $official->setRulesVariant('Maison'), 'Official cannot be house rule');
$reject(fn () => $custom->setImportKey('fixture'), 'Custom cannot receive official import identity');
$official->setImportKey('fixture:test-spell')->setImportKey('fixture:test-spell');
$reject(fn () => $official->setImportKey('fixture:other'), 'Import identity cannot change');
$official->setSource('TEST', 'Fixture uniquement', 'https://example.invalid/fixture');
$reject(fn () => $official->setSource('CHANGED', null, 'javascript:alert(1)'), 'Unsafe source URL');
$check($official->getSourceCode() === 'TEST', 'Rejected source edit is atomic');
$check(ReferenceVisibility::allows($official, null), 'Official visible without owner');
$check(ReferenceVisibility::allows($custom, $owner), 'Custom visible to owner');
$check(!ReferenceVisibility::allows($custom, $other) && !ReferenceVisibility::allows($custom, null), 'Custom hidden to other and anonymous');
$reject(fn () => ReferenceVisibility::requireVisible($custom, $other), 'Required visibility enforced');
$corrupt = clone $custom;
(new ReflectionProperty(SpellDefinition::class, 'owner'))->setValue($corrupt, null);
try { $corrupt->validateReferenceOwnership(); throw new RuntimeException('Ownership callback missing'); }
catch (LogicException $error) { $check(true, 'Lifecycle ownership guard'); }

$kernel = new App\Kernel('dev', true);
$kernel->boot();
$em = $kernel->getContainer()->get('doctrine')->getManager();
$db = $em->getConnection();
try {
    $check((new SchemaValidator($em))->validateMapping() === [], 'Doctrine mapping valid');
    $db->beginTransaction();
    $metadata = $em->getMetadataFactory()->getAllMetadata();
    $schemaTool = new SchemaTool($em);
    foreach ($schemaTool->getCreateSchemaSql($metadata) as $sql) {
        $db->executeStatement(preg_replace(['/^CREATE TABLE /', '/^CREATE SEQUENCE /'], ['CREATE TEMP TABLE ', 'CREATE TEMP SEQUENCE '], $sql));
    }
    foreach ($metadata as $meta) {
        if ($db->fetchOne('SELECT relpersistence FROM pg_class WHERE oid = to_regclass(?)', [$meta->getTableName()]) !== 't') throw new RuntimeException('Non-isolated table: '.$meta->getTableName());
    }
    // Test the actual migration, not only the schema generated by the ORM.
    $db->executeStatement('DROP TABLE pg_temp.character_spell_qualification');
    foreach (['character_spell_substitution','spell_grant_substitution_list','spell_grant_substitution_rule'] as $table) $db->executeStatement('DROP TABLE pg_temp.'.$table);
    $db->executeStatement('DROP TABLE pg_temp.character_spell_choice');
    $db->executeStatement('DROP TABLE pg_temp.spell_policy_choice_rule');
    $db->executeStatement('DROP TABLE pg_temp.character_spell_acquisition');
    $db->executeStatement('DROP TABLE pg_temp.character_spell_revision');
    $db->executeStatement('DROP TABLE pg_temp.spell_subclass_grant');
    $db->executeStatement('DROP TABLE pg_temp.spell_class_list_entry');
    $db->executeStatement('DROP TABLE pg_temp.spell_definition');
    $migration = new DoctrineMigrations\Version20261016120000($db, new Psr\Log\NullLogger());
    $migration->up(new Schema());
    foreach ($migration->getSql() as $query) {
        $sql = $query->getStatement();
        $check(preg_match('/^\s*CREATE (TABLE spell_definition|(?:UNIQUE )?INDEX \w+ ON spell_definition)\b/', $sql) === 1, 'Migration only creates spell catalogue');
        $db->executeStatement(preg_replace('/CREATE TABLE spell_definition/', 'CREATE TEMP TABLE spell_definition', $sql));
    }
    $check($db->fetchOne("SELECT relpersistence FROM pg_class WHERE oid = 'spell_definition'::regclass") === 't', 'Migrated table temporary');
    $check($db->fetchOne("SELECT relpersistence FROM pg_class WHERE oid = pg_get_serial_sequence('spell_definition', 'id')::regclass") === 't', 'Migrated identity sequence temporary');
    $temporarySchema = $db->fetchOne('SELECT nspname FROM pg_namespace WHERE oid = pg_my_temp_schema()');
    $actual = $db->createSchemaManager()->introspectTable($temporarySchema.'.spell_definition');
    $expected = $schemaTool->getSchemaFromMetadata([$em->getClassMetadata(SpellDefinition::class)])->getTable('spell_definition');
    $diff = $db->createSchemaManager()->createComparator()->compareTables($actual, $expected);
    $check($diff->isEmpty(), 'Migration matches Doctrine columns, indexes and foreign key');
    foreach ([$owner, $other, $official, $custom, $foreign] as $entity) $em->persist($entity);
    $em->flush();
    $customId = $custom->getId();
    $officialId = $official->getId();
    $ownerId = $owner->getId();
    $em->clear();
    $loaded = $em->find(SpellDefinition::class, $customId);
    $owner = $em->find(User::class, $ownerId);
    $check($loaded->getSchool() === SpellSchool::Abjuration && $loaded->getLevel() === 9, 'ORM level and enum roundtrip');
    $check($loaded->isConcentration() && $loaded->isRitual() && $loaded->getMaterialCostCp() === 0, 'ORM mechanics roundtrip');
    $check($loaded->getOriginalName() === 'Test original' && $loaded->getHigherLevels() === 'Texte fictif' && $loaded->getRulesVariant() === 'Variante de test', 'ORM optional text roundtrip');
    $visible = ReferenceVisibility::choices($em, SpellDefinition::class, $owner);
    $ids = array_map(static fn ($s) => $s->getId(), $visible); sort($ids);
    $expectedIds = [$customId, $officialId]; sort($expectedIds);
    $check($ids === $expectedIds, 'SQL visibility includes only official and own custom');
    $check(count(ReferenceVisibility::choices($em, SpellDefinition::class, null)) === 1, 'SQL anonymous choice scope is official only');

    $rejectSql = static function (string $sql, array $params, string $message) use ($db, $check): void {
        $db->createSavepoint('spell_invalid');
        try {
            $db->executeStatement($sql, $params);
        } catch (Doctrine\DBAL\Exception\DriverException $error) {
            $check(in_array($error->getSQLState(), ['23514', '23505', '23503'], true), $message.' must fail a CHECK, unique or foreign key constraint');
            return;
        } finally {
            $db->rollbackSavepoint('spell_invalid');
            $db->releaseSavepoint('spell_invalid');
        }
        throw new RuntimeException('Expected SQL rejection: '.$message);
    };
    foreach ([
        'level = -1', 'level = 10', "school = 'unknown'", "edition = '2024'",
        "origin = 'UNKNOWN'", "origin = 'CUSTOM'", "rules_variant = 'house'",
        'material_cost_cp = -1', 'material_cost_cp = 0', 'material_consumed = true',
        'material = true', "material_description = 'Unexpected'", "slug = 'bad slug'", "name = ''",
    ] as $change) $rejectSql('UPDATE spell_definition SET '.$change.' WHERE id = ?', [$officialId], $change);
    $rejectSql('UPDATE spell_definition SET owner_id = ? WHERE id = ?', [$ownerId, $officialId], 'Official cannot have owner');
    $rejectSql('UPDATE spell_definition SET owner_id = NULL WHERE id = ?', [$customId], 'Custom requires owner');
    $rejectSql("UPDATE spell_definition SET import_key = 'custom-key' WHERE id = ?", [$customId], 'Custom import key forbidden');
    $rejectSql("UPDATE spell_definition SET slug = 'test-spell' WHERE id = ?", [$customId], 'Global slug uniqueness');
    $rejectSql("INSERT INTO spell_definition (slug, name, level, school, casting_time, range, duration, description, import_key) VALUES ('duplicate-import', 'Test', 1, 'illusion', 'Test', 'Test', 'Test', 'Test', 'fixture:test-spell')", [], 'Import identity unique');
    $rejectSql('DELETE FROM "user" WHERE id = ?', [$ownerId], 'Owner deletion restricted');
    $check((int) $db->fetchOne('SELECT COUNT(*) FROM spell_definition') === 3, 'Rejected writes leave catalogue unchanged');
    echo "OK: $checks spell catalogue assertions; temporary tables and sequences only.\n";
} finally {
    if ($db->isTransactionActive()) $db->rollBack();
    $em->clear();
    $kernel->shutdown();
}
