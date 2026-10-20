<?php
declare(strict_types=1);
// Read-only checks; the snapshot is stored only in the local development container.
require __DIR__.'/../vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv(__DIR__.'/../.env');
$kernel = new App\Kernel('dev', true); $kernel->boot();
$db = $kernel->getContainer()->get('doctrine')->getConnection();
$identity = $db->fetchAssociative('SELECT current_database() AS database, current_user AS username');
if (($_SERVER['APP_ENV'] ?? getenv('APP_ENV')) !== 'dev' || ($db->getParams()['host'] ?? '') !== 'database'
    || $identity !== ['database'=>'jdr_companion','username'=>'jdr']) throw new RuntimeException('Unexpected development connection');
$version = 'DoctrineMigrations\\Version20261017140000';
$mode = $argv[1] ?? '';
$snapshot = [];
foreach ($db->fetchFirstColumn("SELECT tablename FROM pg_tables WHERE schemaname='public' ORDER BY tablename") as $table) {
    $quoted = $db->quoteIdentifier($table);
    $row = $table === 'character_spell_acquisition' ? "(to_jsonb(t) - 'revision_id' - 'retirement_id')::text" : 'to_jsonb(t)::text';
    $snapshot[$table] = $db->fetchAssociative("SELECT COUNT(*) AS count, md5(COALESCE(string_agg($row, '' ORDER BY $row), '')) AS hash FROM public.$quoted t");
}
$versions = $db->fetchAllAssociative('SELECT * FROM doctrine_migration_versions ORDER BY version');
$path = '/tmp/jdr-spell-revision-before.json';
if (in_array($mode, ['before','recheck'], true)) {
    foreach (['spellcasting_policy_definition','character_spell_acquisition','spell_definition','spell_subclass_grant'] as $table)
        if ((int)$snapshot[$table]['count'] !== 0) throw new RuntimeException('Audit baseline changed: '.$table);
    if (isset($snapshot['character_spell_revision']) || $db->fetchOne('SELECT 1 FROM doctrine_migration_versions WHERE version=?',[$version])) throw new RuntimeException('Migration already present');
    foreach (['20261017120000','20261017130000'] as $dependency)
        if (!$db->fetchOne('SELECT 1 FROM doctrine_migration_versions WHERE version=?',['DoctrineMigrations\\Version'.$dependency])) throw new RuntimeException('Missing dependency');
    if ($db->fetchOne("SELECT count(*) FROM pg_stat_activity WHERE datname=current_database() AND pid<>pg_backend_pid() AND (state IS DISTINCT FROM 'idle' OR xact_start IS NOT NULL)") != 0) throw new RuntimeException('Concurrent database activity');
    if ($db->fetchOne("SELECT count(*) FROM information_schema.columns WHERE table_schema='public' AND table_name='character_spell_acquisition' AND column_name IN ('revision_id','retirement_id')") != 0) throw new RuntimeException('Partial migration');
    $record = ['tables'=>$snapshot,'migrations'=>$versions];
    if ($mode === 'before') file_put_contents($path,json_encode($record,JSON_THROW_ON_ERROR));
    elseif ($record !== json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR)) throw new RuntimeException('Database changed since snapshot');
    echo json_encode(['connection'=>$identity,'tables'=>count($snapshot),'counts'=>array_map(static fn($r)=>$r['count'],$snapshot),'migrations'=>array_column($versions,'version'),'concurrentOperations'=>0],JSON_PRETTY_PRINT)."\n";
} elseif ($mode === 'after') {
    $before = json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
    if (array_values(array_diff(array_keys($snapshot),array_keys($before['tables']))) !== ['character_spell_revision']) throw new RuntimeException('Unexpected tables');
    foreach ($before['tables'] as $table=>$fingerprint) if ($table !== 'doctrine_migration_versions' && ($snapshot[$table] ?? null) !== $fingerprint) throw new RuntimeException('Data changed: '.$table);
    $oldVersions = array_values(array_filter($versions,static fn($row)=>$row['version'] !== $version));
    if ($oldVersions !== $before['migrations'] || count($versions) !== count($oldVersions)+1) throw new RuntimeException('Unexpected migration history');
    if ((int)$snapshot['character_spell_revision']['count'] !== 0) throw new RuntimeException('Unexpected revision data');
    if ($db->fetchOne('SELECT count(*) FROM character_spell_acquisition WHERE revision_id IS NOT NULL OR retirement_id IS NOT NULL') != 0) throw new RuntimeException('Existing acquisition changed');
    echo (count($before['tables'])-1)." existing application tables unchanged; only expected migration history and empty revision table added.\n";
} else throw new RuntimeException('Use before, recheck or after');
$kernel->shutdown();
