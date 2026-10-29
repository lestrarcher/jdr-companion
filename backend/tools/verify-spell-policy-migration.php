<?php
declare(strict_types=1);

// Read-only database verification; the snapshot is a local /tmp artifact only.
require __DIR__.'/../vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv(__DIR__.'/../.env');
$kernel = new App\Kernel('dev', true); $kernel->boot();
$db = $kernel->getContainer()->get('doctrine')->getConnection();
if ($kernel->getEnvironment() !== 'dev' || ($db->getParams()['host'] ?? '') !== 'database') throw new RuntimeException('Docker development only');
$snapshot = [];
foreach ($db->fetchFirstColumn("SELECT tablename FROM pg_tables WHERE schemaname='public' ORDER BY tablename") as $table) {
    $quoted=$db->quoteIdentifier($table);
    $row = $table==='spell_subclass_grant' ? "(to_jsonb(t) - 'quota_impact')::text" : 'to_jsonb(t)::text';
    $snapshot[$table]=$db->fetchAssociative("SELECT COUNT(*) AS count, md5(COALESCE(string_agg($row, '' ORDER BY $row), '')) AS hash FROM public.$quoted t");
}
$path='/tmp/jdr-spell-policy-before.json';
if (($argv[1]??'')==='before') {
    if (isset($snapshot['character_spell_acquisition']) || isset($snapshot['spellcasting_policy_definition'])) throw new RuntimeException('Migration already present');
    file_put_contents($path,json_encode($snapshot,JSON_THROW_ON_ERROR));
    echo count($snapshot)." public table fingerprints recorded.\n";
} elseif (($argv[1]??'')==='after') {
    $before=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
    foreach ($before as $table=>$fingerprint) if ($table!=='doctrine_migration_versions' && ($snapshot[$table]??null)!==$fingerprint) throw new RuntimeException('Existing data changed: '.$table);
    foreach (['character_spell_acquisition','spellcasting_policy_definition'] as $table) if (!isset($snapshot[$table]) || (int)$snapshot[$table]['count']!==0) throw new RuntimeException('Expected empty new table: '.$table);
    if ((int)$db->fetchOne("SELECT COUNT(*) FROM spell_subclass_grant WHERE quota_impact <> 'unspecified'")!==0) throw new RuntimeException('Existing grants inferred');
    if (count($snapshot)!==count($before)+2) throw new RuntimeException('Unexpected schema change');
    echo (count($before)-1)." existing application tables unchanged; two new tables empty; grants unspecified.\n";
} else throw new RuntimeException('Use before or after');
$kernel->shutdown();
