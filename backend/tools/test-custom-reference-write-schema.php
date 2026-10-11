<?php

declare(strict_types=1);

use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\Exception\IrreversibleMigration;
use DoctrineMigrations\Version20260928120000;
use Psr\Log\NullLogger;

require __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/../migrations/Version20260928120000.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv(__DIR__.'/../.env');
putenv('SHELL_VERBOSITY=-1');
$_SERVER['SHELL_VERBOSITY'] = $_ENV['SHELL_VERBOSITY'] = -1;
$kernel = new App\Kernel('dev', true);
$kernel->boot();
$db = $kernel->getContainer()->get('doctrine')->getConnection();
$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void {
    if (!$ok) throw new RuntimeException($message);
    ++$checks;
};
$snapshot = static function () use ($db): array {
    $result = [];
    foreach ($db->fetchFirstColumn("SELECT tablename FROM pg_tables WHERE schemaname='public' AND tablename <> 'doctrine_migration_versions' ORDER BY tablename") as $table) {
        $quoted = $db->quoteIdentifier($table);
        $result[$table] = $db->fetchAssociative("SELECT count(*) n, md5(COALESCE(string_agg(to_jsonb(t)::text, E'\\n' ORDER BY to_jsonb(t)::text), '')) hash FROM $quoted t");
    }
    return $result;
};
$reject = static function (callable $operation, string $state, string $constraint) use ($db, $check): void {
    $db->createSavepoint('expected_failure');
    $error = null;
    try {
        $operation();
    } catch (DriverException $caught) {
        $error = $caught;
    } finally {
        $db->rollbackSavepoint('expected_failure');
        $db->releaseSavepoint('expected_failure');
    }
    $check($error !== null && $error->getSQLState() === $state
        && str_contains($error->getMessage(), $constraint), 'Expected '.$state.' / '.$constraint);
};
// Explicit negative IDs avoid advancing non-transactional production sequences.
$nextId = -1900000000;
$clone = static function (string $table, int $sourceId, array $changes = []) use ($db, &$nextId): int {
    $id = --$nextId;
    $changes['id'] = $id;
    $quoted = $db->quoteIdentifier($table);
    $count = $db->executeStatement(
        "INSERT INTO $quoted SELECT (jsonb_populate_record(NULL::$quoted, to_jsonb(t) || ?::jsonb)).* FROM $quoted t WHERE id = ?",
        [json_encode($changes, JSON_THROW_ON_ERROR), $sourceId],
    );
    if ($count !== 1) throw new RuntimeException('Missing fixture '.$table.'/'.$sourceId);
    return $id;
};
$before = $snapshot();
try {
    $db->beginTransaction();
    $db->executeStatement('LOCK TABLE "user", character_subclass, character_feature_rule, trackable_resource_rule, character_action_class_rule IN ACCESS EXCLUSIVE MODE');
    $check((int) $db->fetchOne('SELECT count(*) FROM "user" WHERE id < -1900000000') === 0, 'Reserved test IDs unused');
    // Exercise actual migration before installation, or actual installed schema after.
    $installed = (bool) $db->fetchOne("SELECT EXISTS (SELECT 1 FROM pg_constraint WHERE conrelid='character_subclass'::regclass AND conname='chk_subclass_slug_namespace')");
    if (!$installed) {
        $migration = new Version20260928120000($db, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $sql) {
            $check((bool) preg_match('/^(LOCK TABLE|ALTER TABLE|CREATE UNIQUE INDEX|DROP INDEX) /', $sql->getStatement()), 'DDL only');
            $db->executeStatement($sql->getStatement(), $sql->getParameters(), $sql->getTypes());
        }
    }
    $check($before === $snapshot(), 'Migration preserves all business rows');
    try {
        (new Version20260928120000($db, new NullLogger()))->down(new Schema());
        $check(false, 'Down must explicitly refuse');
    } catch (IrreversibleMigration $error) {
        $check(str_contains($error->getMessage(), 'Owner-scoped'), 'Explicit irreversible reason');
    }

    $check((int) $db->fetchOne("SELECT count(*) FROM character_subclass WHERE id IN (3,28) AND slug='wild-magic' AND origin='OFFICIAL' AND owner_id IS NULL") === 2, 'Both wild-magic preserved');
    $check((int) $db->fetchOne("SELECT count(*) FROM character_subclass WHERE id=20 AND slug='arch-hag' AND origin='CUSTOM' AND owner_id=1") === 1, 'Legacy arch-hag preserved');
    $userTemplate = (int) $db->fetchOne('SELECT min(id) FROM "user"');
    $a = $clone('user', $userTemplate, ['email' => 'custom-schema-a@example.invalid']);
    $b = $clone('user', $userTemplate, ['email' => 'custom-schema-b@example.invalid']);
    $slug = 'custom-'.bin2hex(random_bytes(16));
    $sub = $clone('character_subclass', 3, ['slug' => $slug, 'origin' => 'CUSTOM', 'owner_id' => $a]);
    $clone('character_subclass', 28, ['slug' => 'custom-'.bin2hex(random_bytes(16)), 'origin' => 'CUSTOM', 'owner_id' => $b]);
    $check(true, 'Two owners can create subclasses');
    // Different parent deliberately avoids the old composite index masking this assertion.
    foreach ([$a, $b] as $owner) {
        $reject(fn () => $clone('character_subclass', 28, ['slug' => $slug, 'origin' => 'CUSTOM', 'owner_id' => $owner]), '23505', 'uniq_subclass_custom_slug');
    }
    $reject(fn () => $clone('character_subclass', 28, ['slug' => $slug]), '23514', 'chk_subclass_slug_namespace');
    $reject(fn () => $clone('character_subclass', 3, ['slug' => 'arch-hag', 'origin' => 'CUSTOM', 'owner_id' => $b]), '23505', 'uniq_subclass_custom_slug');
    $reject(fn () => $clone('character_subclass', 3, ['slug' => 'arch-hag']), '23514', 'chk_subclass_slug_namespace');
    foreach (['wild-magic', 'custom-abc', 'custom-'.str_repeat('A', 32), 'custom-'.str_repeat('a', 33)] as $badSlug) {
        $reject(fn () => $clone('character_subclass', 3, ['slug' => $badSlug, 'origin' => 'CUSTOM', 'owner_id' => $a]), '23514', 'chk_subclass_slug_namespace');
    }

    // Independent fixtures are OFFICIAL references; no cross-owner relation is used.
    $progression = $clone('progression_definition', 1, ['slug' => 'schema-test-progression', 'origin' => 'OFFICIAL', 'owner_id' => null]);
    $parents = [
        'character_class_id' => 3, 'character_subclass_id' => 3,
        'character_race_id' => (int) $db->fetchOne("SELECT min(id) FROM character_race WHERE origin='OFFICIAL'"),
        'feat_id' => (int) $db->fetchOne("SELECT min(id) FROM feat WHERE origin='OFFICIAL'"),
        'progression_definition_id' => $progression,
    ];
    $families = [
        ['character_feature_rule', 'feature_definition_id', 'character_feature_definition', [
            'class_feature_level' => 'character_class_id', 'subclass_feature_level' => 'character_subclass_id',
            'race_feature_level' => 'character_race_id', 'feat_feature_level' => 'feat_id',
            'progression_feature_threshold' => 'progression_definition_id',
        ]],
        ['trackable_resource_rule', 'resource_definition_id', 'trackable_resource_definition', [
            'class_resource_level' => 'character_class_id', 'subclass_resource_level' => 'character_subclass_id',
            'race_resource_level' => 'character_race_id', 'feat_resource_level' => 'feat_id',
        ]],
        ['character_action_class_rule', 'action_definition_id', 'character_action_definition', [
            'character_action_class' => 'character_class_id',
        ]],
    ];
    foreach ($families as [$table, $target, $definitionTable, $sources]) {
        $template = (int) $db->fetchOne("SELECT min(id) FROM $table");
        $targetTemplate = (int) $db->fetchOne("SELECT min(id) FROM $definitionTable WHERE origin='OFFICIAL'");
        $targetId = $clone($definitionTable, $targetTemplate, ['slug' => 'schema-test-'.$definitionTable]);
        foreach ($sources as $family => $source) {
            $row = array_fill_keys(array_values($sources), null);
            $row += [$target => $targetId, 'unlock_level' => 1, 'origin' => 'OFFICIAL', 'owner_id' => null];
            $row[$source] = $parents[$source];
            if ($table === 'character_feature_rule') $row['progression_threshold'] = $source === 'progression_definition_id' ? 0 : null;
            $official = $clone($table, $template, $row);
            $check(true, $family.' OFFICIAL accepted');
            $reject(fn () => $clone($table, $official), '23505', 'uniq_'.$family.'_off');
            $own = $clone($table, $official, ['origin' => 'CUSTOM', 'owner_id' => $a]);
            $clone($table, $official, ['origin' => 'CUSTOM', 'owner_id' => $b]);
            $check(true, $family.' A/B coexist with OFFICIAL');
            $reject(fn () => $clone($table, $own), '23505', 'uniq_'.$family.'_own');
            if ($table !== 'character_action_class_rule') {
                $levelColumn = $source === 'progression_definition_id' ? 'progression_threshold' : 'unlock_level';
                $clone($table, $own, [$levelColumn => 2]);
                $check(true, $family.' distinct level/threshold accepted');
            } else {
                $reject(fn () => $clone($table, $own, ['unlock_level' => 2]), '23505', 'uniq_'.$family.'_own');
            }
            if ($source === 'character_class_id' && $table !== 'character_action_class_rule') {
                $constraint = $table === 'character_feature_rule' ? 'chk_feature_rule_one_source' : 'chk_resource_rule_one_source';
                $reject(fn () => $clone($table, $own, ['character_class_id' => null]), '23514', $constraint);
                $reject(fn () => $clone($table, $own, ['character_subclass_id' => 3]), '23514', $constraint);
                if ($table === 'character_feature_rule') {
                    $reject(fn () => $clone($table, $own, ['progression_threshold' => 1]), '23514', 'chk_feature_rule_threshold');
                }
            }
            if ($source === 'progression_definition_id') {
                $reject(fn () => $clone($table, $own, ['progression_threshold' => null]), '23514', 'chk_feature_rule_threshold');
                $reject(fn () => $clone($table, $own, ['progression_threshold' => -1]), '23514', 'chk_feature_rule_threshold');
            }
        }
    }
} finally {
    if ($db->isTransactionActive()) $db->rollBack();
    $check($before === $snapshot(), 'Full rollback: all business table fingerprints unchanged');
    $kernel->shutdown();
}
echo "CUSTOM write schema: $checks assertions passed; full rollback.\n";
