<?php

declare(strict_types=1);

use App\Kernel;
use Symfony\Component\Dotenv\Dotenv;

require __DIR__.'/../vendor/autoload.php';
(new Dotenv())->bootEnv(__DIR__.'/../.env');
$kernel = new Kernel('dev', true);
$kernel->boot();
$db = $kernel->getContainer()->get('doctrine')->getConnection();
$migration = (string) file_get_contents(__DIR__.'/../migrations/Version20260916213000.php');
preg_match_all("/<<<'SQL'\R(.*?)\RSQL/s", $migration, $matches);
$sql = $matches[1];
if (count($sql) !== 3) { throw new RuntimeException('Expected exactly three migration SQL blocks.'); }
$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void { if (!$ok) { throw new RuntimeException($message); } ++$checks; };
$tables = ['character_class_level', 'character_feature_rule', 'trackable_resource_rule', 'character_feature_definition',
    'trackable_resource_definition', 'character_subclass', 'character_class'];
$setup = static function () use ($db, $tables): void {
    foreach ($tables as $table) { $db->executeStatement("DROP TABLE IF EXISTS pg_temp.$table CASCADE"); }
    foreach (array_reverse($tables) as $table) { $db->executeStatement("CREATE TEMP TABLE $table (LIKE public.$table INCLUDING ALL)"); }
    $now = '2026-09-16 12:00:00';
    $db->insert('character_class', ['id'=>1,'slug'=>'fighter','name'=>'Fighter','hit_die'=>10,'subclass_selection_level'=>3,'spellcasting_progression'=>'none','custom'=>0,'created_at'=>$now,'updated_at'=>$now]);
    $db->insert('character_class', ['id'=>2,'slug'=>'warlock','name'=>'Warlock','hit_die'=>8,'subclass_selection_level'=>1,'spellcasting_progression'=>'pact','custom'=>0,'created_at'=>$now,'updated_at'=>$now]);
    $db->insert('character_subclass', ['id'=>10,'character_class_id'=>2,'slug'=>'genie-dao','name'=>'Genie','custom'=>0,'created_at'=>$now,'updated_at'=>$now]);
    $db->insert('character_subclass', ['id'=>20,'character_class_id'=>1,'slug'=>'chevalier-occulte','name'=>'Duplicate','custom'=>0,'created_at'=>$now,'updated_at'=>$now]);
    $db->insert('character_subclass', ['id'=>21,'character_class_id'=>1,'slug'=>'eldritch-knight','name'=>'Canonical','custom'=>0,'created_at'=>$now,'updated_at'=>$now]);
    $db->insert('character_feature_definition', ['id'=>30,'slug'=>'magie-de-guerre','name'=>'War Magic','activation_type'=>'passive','visible'=>1,'custom'=>0,'created_at'=>$now,'updated_at'=>$now]);
    $db->insert('character_feature_definition', ['id'=>31,'slug'=>'test-feature','name'=>'Test','activation_type'=>'passive','visible'=>1,'custom'=>0,'created_at'=>$now,'updated_at'=>$now]);
    $db->insert('character_feature_rule', ['id'=>40,'feature_definition_id'=>30,'character_subclass_id'=>21,'unlock_level'=>7,'display_order'=>7]);
    $db->insert('trackable_resource_definition', ['id'=>50,'slug'=>'test-resource','name'=>'Test','recharge_type'=>'long-rest','maximum_type'=>'fixed','base_maximum'=>1,'multiplier'=>1,'minimum_maximum'=>1,'custom'=>0,'created_at'=>$now,'updated_at'=>$now]);
};
$execute = static function () use ($db, $sql): void { foreach ($sql as $statement) { $db->executeStatement($statement); } };
$scenario = static function (callable $prepare, callable $assert, bool $mustFail = false) use ($db, $setup, $execute): void {
    $setup(); $prepare(); $db->createSavepoint('scenario');
    $failed = false;
    try { $execute(); } catch (Throwable) { $failed = true; $db->rollbackSavepoint('scenario'); }
    if ($mustFail !== $failed) { throw new RuntimeException($mustFail ? 'Scenario should fail' : 'Scenario unexpectedly failed'); }
    $assert();
};

try {
    $db->beginTransaction();
    $scenario(static fn () => null, function () use ($db, $check): void {
        $check($db->fetchOne("SELECT slug FROM character_subclass WHERE id=10") === 'genie', 'Genie slug normalized with same ID');
        $check($db->fetchOne("SELECT slug FROM character_feature_definition WHERE id=30") === 'eldritch-knight-war-magic', 'War Magic slug normalized with same ID');
        $check($db->fetchOne("SELECT count(*) FROM character_subclass WHERE id=20") === 0, 'Empty duplicate removed');
    });
    $scenario(fn () => $db->insert('character_subclass', ['id'=>11,'character_class_id'=>2,'slug'=>'genie','name'=>'Concurrent','custom'=>0,'created_at'=>'2026-09-16','updated_at'=>'2026-09-16']), fn () => $check(true, 'Concurrent genie rejected'), true);
    $scenario(fn () => $db->insert('character_feature_definition', ['id'=>32,'slug'=>'eldritch-knight-war-magic','name'=>'Concurrent','activation_type'=>'passive','visible'=>1,'custom'=>0,'created_at'=>'2026-09-16','updated_at'=>'2026-09-16']), fn () => $check(true, 'Concurrent feature rejected'), true);
    $scenario(fn () => $db->insert('character_class_level', ['id'=>60,'character_id'=>1,'character_class_id'=>1,'subclass_id'=>20,'position'=>3,'acquired_at'=>'2026-09-16']), fn () => $check($db->fetchOne('SELECT subclass_id FROM character_class_level WHERE id=60') === 21, 'CharacterClassLevel moved'), false);
    $scenario(function () use ($db): void {
        $db->insert('character_feature_rule', ['id'=>41,'feature_definition_id'=>31,'character_subclass_id'=>20,'unlock_level'=>3,'display_order'=>3]);
        $db->insert('trackable_resource_rule', ['id'=>51,'resource_definition_id'=>50,'character_subclass_id'=>20,'unlock_level'=>3,'maximum_override'=>1,'maximum_bonus'=>0]);
    }, function () use ($db, $check): void {
        $check($db->fetchOne('SELECT character_subclass_id FROM character_feature_rule WHERE id=41') === 21, 'Feature rule transferred');
        $check($db->fetchOne('SELECT character_subclass_id FROM trackable_resource_rule WHERE id=51') === 21, 'Resource rule transferred');
    });
    $scenario(function () use ($db): void {
        $db->insert('character_feature_rule', ['id'=>41,'feature_definition_id'=>31,'character_subclass_id'=>20,'unlock_level'=>3,'display_order'=>3]);
        $db->insert('character_feature_rule', ['id'=>42,'feature_definition_id'=>31,'character_subclass_id'=>21,'unlock_level'=>3,'display_order'=>3]);
        $db->insert('trackable_resource_rule', ['id'=>51,'resource_definition_id'=>50,'character_subclass_id'=>20,'unlock_level'=>3,'maximum_override'=>1,'maximum_bonus'=>0]);
        $db->insert('trackable_resource_rule', ['id'=>52,'resource_definition_id'=>50,'character_subclass_id'=>21,'unlock_level'=>3,'maximum_override'=>1,'maximum_bonus'=>0]);
    }, fn () => $check($db->fetchOne('SELECT count(*) FROM character_feature_rule WHERE feature_definition_id=31') === 1 && $db->fetchOne('SELECT count(*) FROM trackable_resource_rule WHERE resource_definition_id=50') === 1, 'Equivalent collisions deduplicated only after equality check'));
    $scenario(function () use ($db): void {
        $db->insert('character_feature_rule', ['id'=>41,'feature_definition_id'=>31,'character_subclass_id'=>20,'unlock_level'=>3,'display_order'=>4]);
        $db->insert('character_feature_rule', ['id'=>42,'feature_definition_id'=>31,'character_subclass_id'=>21,'unlock_level'=>3,'display_order'=>3]);
    }, fn () => $check(true, 'Non-equivalent feature collision rejected'), true);
    $scenario(function () use ($db): void {
        $db->insert('trackable_resource_rule', ['id'=>51,'resource_definition_id'=>50,'character_subclass_id'=>20,'unlock_level'=>3,'maximum_override'=>2,'maximum_bonus'=>0]);
        $db->insert('trackable_resource_rule', ['id'=>52,'resource_definition_id'=>50,'character_subclass_id'=>21,'unlock_level'=>3,'maximum_override'=>1,'maximum_bonus'=>0]);
    }, fn () => $check(true, 'Non-equivalent resource collision rejected'), true);
    echo "OK: $checks migration assertions on temporary tables.\n";
} finally {
    while ($db->isTransactionActive()) { $db->rollBack(); }
    $kernel->shutdown();
}
