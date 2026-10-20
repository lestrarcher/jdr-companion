<?php
declare(strict_types=1);
// Read-only database checks; snapshot lives in the local Docker container.
require __DIR__.'/../vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv(__DIR__.'/../.env');
$kernel=new App\Kernel('dev',true); $kernel->boot();
$db=$kernel->getContainer()->get('doctrine')->getConnection();
$identity=$db->fetchAssociative('SELECT current_database() AS database,current_user AS username');
if (($_SERVER['APP_ENV']??getenv('APP_ENV'))!=='dev' || ($db->getParams()['host']??'')!=='database' || $identity!==['database'=>'jdr_companion','username'=>'jdr']) throw new RuntimeException('Unexpected connection');
$version='DoctrineMigrations\\Version20261020120000'; $mode=$argv[1]??'';
$path='/tmp/jdr-spell-choice-before.json'; $snapshot=[];
foreach ($db->fetchFirstColumn("SELECT tablename FROM pg_tables WHERE schemaname='public' ORDER BY tablename") as $table) {
    $q=$db->quoteIdentifier($table);
    $row=$table==='spellcasting_policy_definition'?"(to_jsonb(t)-'format_version'-'base_policy_id')::text":'to_jsonb(t)::text';
    $snapshot[$table]=$db->fetchAssociative("SELECT count(*) AS count,md5(COALESCE(string_agg($row,'' ORDER BY $row),'')) AS hash FROM public.$q t");
}
$versions=$db->fetchAllAssociative('SELECT * FROM doctrine_migration_versions ORDER BY version');
if (in_array($mode,['before','recheck'],true)) {
    if ($db->fetchOne('SELECT 1 FROM doctrine_migration_versions WHERE version=?',[$version]) || isset($snapshot['spell_policy_choice_rule']) || isset($snapshot['character_spell_choice'])) throw new RuntimeException('Migration already or partially present');
    foreach (['20261017120000','20261017130000','20261017140000'] as $dependency) if (!$db->fetchOne('SELECT 1 FROM doctrine_migration_versions WHERE version=?',['DoctrineMigrations\\Version'.$dependency])) throw new RuntimeException('Missing dependency');
    if ($db->fetchOne("SELECT count(*) FROM information_schema.columns WHERE table_schema='public' AND table_name='spellcasting_policy_definition' AND column_name IN ('format_version','base_policy_id')")!=0) throw new RuntimeException('Partial migration columns');
    if ($db->fetchOne("SELECT count(*) FROM pg_stat_activity WHERE datname=current_database() AND pid<>pg_backend_pid() AND (state IS DISTINCT FROM 'idle' OR xact_start IS NOT NULL)")!=0) throw new RuntimeException('Concurrent activity');
    $record=['tables'=>$snapshot,'migrations'=>$versions];
    if ($mode==='before') file_put_contents($path,json_encode($record,JSON_THROW_ON_ERROR));
    elseif ($record!==json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR)) throw new RuntimeException('Database changed since snapshot');
    echo json_encode(['connection'=>$identity,'tables'=>count($snapshot),'migrations'=>count($versions),'counts'=>array_map(static fn($r)=>$r['count'],$snapshot),'concurrentOperations'=>0],JSON_PRETTY_PRINT)."\n";
} elseif ($mode==='after') {
    $before=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
    if (array_values(array_diff(array_keys($snapshot),array_keys($before['tables'])))!==['character_spell_choice','spell_policy_choice_rule']) throw new RuntimeException('Unexpected new tables');
    foreach ($before['tables'] as $table=>$fingerprint) if ($table!=='doctrine_migration_versions' && ($snapshot[$table]??null)!==$fingerprint) throw new RuntimeException('Changed data: '.$table);
    $oldVersions=array_values(array_filter($versions,static fn($r)=>$r['version']!==$version));
    if ($oldVersions!==$before['migrations'] || count($versions)!==count($oldVersions)+1) throw new RuntimeException('Unexpected migration history');
    foreach (['character_spell_choice','spell_policy_choice_rule'] as $table) if ((int)$snapshot[$table]['count']!==0) throw new RuntimeException('New table not empty');
    if ($db->fetchOne('SELECT count(*) FROM spellcasting_policy_definition WHERE format_version<>1 OR base_policy_id IS NOT NULL')!=0) throw new RuntimeException('Changed legacy policy');
    echo (count($before['tables'])-1)." existing application tables unchanged; new tables empty; legacy policies preserved.\n";
    echo json_encode($db->fetchAllAssociative("SELECT conrelid::regclass::text AS table_name,contype,count(*) FROM pg_constraint WHERE conrelid IN ('spell_policy_choice_rule'::regclass,'character_spell_choice'::regclass,'spellcasting_policy_definition'::regclass) GROUP BY conrelid,contype ORDER BY conrelid,contype"),JSON_PRETTY_PRINT)."\n";
} else throw new RuntimeException('Use before, recheck or after');
$kernel->shutdown();
