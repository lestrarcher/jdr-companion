<?php

declare(strict_types=1);

use App\Enum\ReferenceOrigin;
use App\Kernel;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20260927120000;
use Psr\Log\NullLogger;
use Symfony\Component\Dotenv\Dotenv;

require __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/../migrations/Version20260927120000.php';
(new Dotenv())->bootEnv(__DIR__.'/../.env');
$kernel = new Kernel('dev', true);
$kernel->boot();
$em = $kernel->getContainer()->get('doctrine')->getManager();
$db = $em->getConnection();
$tables = ['character_class', 'character_subclass', 'character_race', 'feat',
    'character_feature_definition', 'trackable_resource_definition', 'progression_definition',
    'character_action_definition', 'character_feature_rule', 'trackable_resource_rule', 'character_action_class_rule'];
$custom = ['character_subclass' => [20], 'character_feature_definition' => [247,248,249],
    'trackable_resource_definition' => [54,55,56], 'progression_definition' => [1,2,3],
    'character_feature_rule' => [243,244,245]];
$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks): void {
    if (!$ok) throw new RuntimeException($label);
    ++$checks;
};
$snapshot = static function () use ($db, $tables): array {
    $result = [];
    foreach ([...$tables, 'character_progression', 'character_class_level', 'character_session_state',
        'progression_stage', 'progression_adjustment_rule'] as $table) {
        $result[$table] = $db->fetchOne("SELECT md5(COALESCE(string_agg((to_jsonb(t)-'origin'-'owner_id')::text, E'\n' ORDER BY id),'')) FROM $table t");
    }
    return $result;
};
$runMigration = static function () use ($db): void {
    $migration = new Version20260927120000($db, new NullLogger());
    $migration->up(new Schema());
    foreach ($migration->getSql() as $sql) $db->executeStatement($sql->getStatement(), $sql->getParameters(), $sql->getTypes());
};
$reject = static function (callable $operation, string $label, string $exceptionClass) use ($db, $check): void {
    $db->createSavepoint('invalid_origin');
    $rejected = false;
    try { $operation(); } catch (Throwable $error) {
        if ($exceptionClass === '23514' ? !($error instanceof \Doctrine\DBAL\Exception\DriverException && $error->getSQLState() === '23514') : !$error instanceof $exceptionClass) throw $error;
        $rejected = true;
    } finally {
        $db->rollbackSavepoint('invalid_origin');
        $db->releaseSavepoint('invalid_origin');
    }
    $check($rejected, $label);
};
try {
    $db->beginTransaction();
    $before = $snapshot();
    if (!isset($db->createSchemaManager()->listTableColumns('character_class')['origin'])) {
        $reject(static function () use ($db, $runMigration): void {
            $db->executeStatement("UPDATE character_feature_definition SET slug='unexpected-identity' WHERE id=248");
            $runMigration();
        }, 'Wrong identity rejected', \Doctrine\DBAL\Exception\DriverException::class);
        $reject(static function () use ($db, $runMigration): void {
            $db->executeStatement('UPDATE character_feature_rule SET progression_threshold=99 WHERE id=244');
            $runMigration();
        }, 'Wrong assignment rejected', \Doctrine\DBAL\Exception\DriverException::class);
        $runMigration();
    }
    foreach ($tables as $table) {
        $ids = array_map('intval', $db->fetchFirstColumn("SELECT id FROM $table WHERE origin='CUSTOM' ORDER BY id"));
        $check($ids === ($custom[$table] ?? []), "$table exact CUSTOM identities");
        $check((int) $db->fetchOne("SELECT count(*) FROM $table WHERE NOT ((origin='OFFICIAL' AND owner_id IS NULL) OR (origin='CUSTOM' AND owner_id=1))") === 0, "$table owners");
        $id = (int) $db->fetchOne("SELECT min(id) FROM $table");
        foreach (["origin='OFFICIAL',owner_id=1", "origin='CUSTOM',owner_id=NULL", "origin='INVALID',owner_id=NULL"] as $set) {
            $reject(fn () => $db->executeStatement("UPDATE $table SET $set WHERE id=$id"), "$table rejects $set", '23514');
        }
        // Keep the subclass fixture valid for the independent namespace CHECK.
        // These temporary slug changes are covered by the savepoint rollbacks.
        $customSlug = $table === 'character_subclass' ? ",slug='custom-".str_repeat('0', 32)."'" : '';
        $reject(fn () => $db->executeStatement("UPDATE $table SET origin='CUSTOM',owner_id=-2147483648$customSlug WHERE id=$id"), "$table missing owner", \Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException::class);
        $db->createSavepoint('valid_origin');
        $db->executeStatement("UPDATE $table SET origin='OFFICIAL',owner_id=NULL WHERE id=$id");
        $db->executeStatement("UPDATE $table SET origin='CUSTOM',owner_id=1$customSlug WHERE id=$id");
        $db->rollbackSavepoint('valid_origin');
        $db->releaseSavepoint('valid_origin');
        $check(true, "$table valid pairs accepted");
    }
    foreach ($em->getMetadataFactory()->getAllMetadata() as $metadata) {
        if (!in_array($metadata->getTableName(), $tables, true)) continue;
        $object = $metadata->getReflectionClass()->newInstanceWithoutConstructor();
        $check($object->getOrigin() === ReferenceOrigin::Official && $object->getOwner() === null, 'Official PHP defaults');
        $property = $metadata->getReflectionClass()->getProperty('origin');
        $property->setValue($object, ReferenceOrigin::Custom);
        try {
            $object->validateReferenceOwnership();
            throw new RuntimeException('Invalid PHP pair accepted');
        } catch (LogicException) { ++$checks; }
    }
    $check($snapshot() === $before, 'All legacy columns, acquisitions, stages and states preserved');
    echo "Reference origin: $checks checks passed; transaction rolled back.\n";
} finally {
    while ($db->isTransactionActive()) $db->rollBack();
    $kernel->shutdown();
}
