<?php
declare(strict_types=1);
// Read-only database checks, snapshot written only inside the development container.
require __DIR__.'/../vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv(__DIR__.'/../.env');
$kernel = new App\Kernel('dev', true); $kernel->boot();
$db = $kernel->getContainer()->get('doctrine')->getConnection();
if ($kernel->getEnvironment() !== 'dev' || ($db->getParams()['host'] ?? '') !== 'database') throw new RuntimeException('Docker development only');
$snapshot = [];
foreach ($db->fetchFirstColumn("SELECT tablename FROM pg_tables WHERE schemaname='public' ORDER BY tablename") as $table) {
    $quoted = $db->quoteIdentifier($table);
    $row = $table === 'character_spell_acquisition' ? "(to_jsonb(t) - 'copy_details')::text" : 'to_jsonb(t)::text';
    $snapshot[$table] = $db->fetchAssociative("SELECT COUNT(*) AS count, md5(COALESCE(string_agg($row, '' ORDER BY $row), '')) AS hash FROM public.$quoted t");
}
$path = '/tmp/jdr-spell-copy-before.json';
if (($argv[1] ?? '') === 'before') {
    if ($db->fetchOne("SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='character_spell_acquisition' AND column_name='copy_details'")) throw new RuntimeException('Migration already present');
    file_put_contents($path, json_encode($snapshot, JSON_THROW_ON_ERROR));
    echo count($snapshot)." public table fingerprints recorded.\n";
} elseif (($argv[1] ?? '') === 'after') {
    $before = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (array_keys($before) !== array_keys($snapshot)) throw new RuntimeException('Unexpected table change');
    foreach ($before as $table => $fingerprint) if ($table !== 'doctrine_migration_versions' && $snapshot[$table] !== $fingerprint) throw new RuntimeException('Data changed: '.$table);
    if ((int)$db->fetchOne('SELECT COUNT(*) FROM public.character_spell_acquisition WHERE copy_details IS NOT NULL') !== 0) throw new RuntimeException('Unexpected copy data');
    echo (count($before)-1)." application tables unchanged; no existing acquisition converted.\n";
} else throw new RuntimeException('Use before or after');
$kernel->shutdown();
