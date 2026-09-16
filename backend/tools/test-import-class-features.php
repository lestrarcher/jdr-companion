<?php

declare(strict_types=1);

use App\Command\ImportClassFeaturesCommand;
use App\Kernel;
use App\Service\ClassFeatureCatalogueValidator;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Dotenv\Dotenv;

require __DIR__.'/../vendor/autoload.php';
(new Dotenv())->bootEnv(__DIR__.'/../.env');
$kernel = new Kernel('dev', true);
$kernel->boot();
$db = $kernel->getContainer()->get('doctrine')->getConnection();
$path = tempnam(sys_get_temp_dir(), 'class-feature-import-');
$source = json_decode((string) file_get_contents(__DIR__.'/../data/reference/dnd-2014-class-features.json'), false, 512, JSON_THROW_ON_ERROR);
$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void { if (!$ok) { throw new RuntimeException($message); } ++$checks; };
$run = static function (object $catalogue, array $options = []) use ($db, $path): array {
    file_put_contents($path, json_encode($catalogue, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    $tester = new CommandTester(new ImportClassFeaturesCommand($db, new ClassFeatureCatalogueValidator(), dirname(__DIR__)));
    $code = $tester->execute(['path' => $path, ...$options]);
    return [$code, $tester->getDisplay()];
};
$tables = ['character_class', 'character_subclass', 'trackable_resource_definition', 'character_feature_definition',
    'character_feature_rule', 'trackable_resource_rule', 'character_action_definition', 'character_action_class_rule'];
$snapshot = static function () use ($db, $tables): array {
    $result = [];
    foreach ($tables as $table) { $result[$table] = $db->fetchAllAssociative("SELECT * FROM $table ORDER BY id"); }
    return $result;
};

try {
    $db->beginTransaction();
    foreach ($tables as $table) {
        $db->executeStatement("CREATE TEMP TABLE $table (LIKE public.$table INCLUDING ALL) ON COMMIT DROP");
        $db->executeStatement("CREATE TEMP SEQUENCE {$table}_import_id");
        $db->executeStatement("ALTER TABLE pg_temp.$table ALTER COLUMN id DROP IDENTITY IF EXISTS");
        $db->executeStatement("ALTER TABLE pg_temp.$table ALTER COLUMN id SET DEFAULT nextval('pg_temp.{$table}_import_id')");
    }
    $publicBefore = [];
    foreach ($tables as $table) { $publicBefore[$table] = $db->fetchOne("SELECT count(*) FROM public.$table"); }

    [$code] = $run($source, ['--dry-run' => true]);
    $check($code === 0 && $snapshot() === array_fill_keys($tables, []), 'Dry-run must not mutate empty tables');
    [$code, $display] = $run($source);
    if ($code !== 0) { fwrite(STDERR, $display); }
    $check($code === 0, 'Initial import must succeed');
    $check((int) $db->fetchOne('SELECT count(*) FROM character_class') === 13, 'Create 13 classes');
    $check((int) $db->fetchOne('SELECT count(*) FROM character_subclass') === 103, 'Create 103 subclasses');
    $check((int) $db->fetchOne('SELECT count(*) FROM character_feature_definition') === 719, 'Create 719 feature definitions');
    $check((int) $db->fetchOne('SELECT count(*) FROM character_feature_rule') === 751, 'Create only 751 approved feature rules');
    $check((int) $db->fetchOne("SELECT count(*) FROM character_feature_rule r JOIN character_feature_definition f ON f.id=r.feature_definition_id WHERE f.slug LIKE 'hunter-%'") === 4, 'Never create the 11 pending Hunter choice rules');
    $before = $snapshot();
    [$code] = $run($source);
    $check($code === 0 && $snapshot() === $before, 'Replay must preserve all rows, IDs and timestamps');

    $db->executeStatement("UPDATE character_class SET name='Local', custom=true WHERE slug='wizard'");
    $custom = $snapshot();
    [$code, $display] = $run($source, ['--update-existing' => true]);
    $check($code === 0 && $snapshot() === $custom && str_contains($display, 'custom protégé'), 'Custom row must be protected');
    $db->executeStatement("UPDATE character_class SET custom=false WHERE slug='wizard'");
    $id = $db->fetchOne("SELECT id FROM character_class WHERE slug='wizard'");
    [$code] = $run($source);
    $check($code === 0 && $db->fetchOne("SELECT name FROM character_class WHERE slug='wizard'") === 'Local', 'Different row preserved by default');
    [$code] = $run($source, ['--update-existing' => true]);
    $check($code === 0 && $db->fetchOne("SELECT name FROM character_class WHERE slug='wizard'") !== 'Local', 'Explicit update applied');
    $check($db->fetchOne("SELECT id FROM character_class WHERE slug='wizard'") === $id, 'Existing class ID preserved');

    $db->executeStatement("UPDATE character_subclass SET slug='genie-dao' WHERE slug='genie'");
    $db->executeStatement("UPDATE character_feature_definition SET slug='magie-de-guerre' WHERE slug='eldritch-knight-war-magic'");
    $legacyBefore = $snapshot();
    [$code, $display] = $run($source, ['--dry-run' => true]);
    $check($code === 0 && $snapshot() === $legacyBefore && substr_count($display, 'legacy match') >= 2, 'Legacy slugs detected without rename or duplicate');

    $invalid = unserialize(serialize($source));
    $invalid->features[0]->unexpected = true;
    [$code] = $run($invalid);
    $check($code !== 0 && $snapshot() === $legacyBefore, 'Invalid catalogue rejected before writes');
    $missingDependency = unserialize(serialize($source));
    $missingDependency->featureRules[0]->featureSlug = 'missing-feature';
    [$code] = $run($missingDependency);
    $check($code !== 0 && $snapshot() === $legacyBefore, 'Missing dependency rejected before writes');
    $unknown = unserialize(serialize($source));
    $unknown->controlledActions[0]->handlerType = 'unknown-handler';
    [$code] = $run($unknown);
    $check($code !== 0 && $snapshot() === $legacyBefore, 'Unknown handler rejected');

    $rollbackCatalogue = unserialize(serialize($source));
    $rollbackCatalogue->classes[0]->name .= ' changed';
    $rollbackCatalogue->classes[1]->name .= ' changed';
    $db->executeStatement("CREATE FUNCTION pg_temp.reject_class_update() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN IF NEW.slug = ''barbarian'' THEN RAISE EXCEPTION ''test failure''; END IF; RETURN NEW; END'");
    $db->executeStatement('CREATE TRIGGER reject_class_update BEFORE UPDATE ON pg_temp.character_class FOR EACH ROW EXECUTE FUNCTION pg_temp.reject_class_update()');
    [$code] = $run($rollbackCatalogue, ['--update-existing' => true]);
    $check($code !== 0 && $snapshot() === $legacyBefore, 'SQL error rolls back every earlier write');

    foreach ($tables as $table) { $check($publicBefore[$table] === $db->fetchOne("SELECT count(*) FROM public.$table"), "Public $table untouched"); }
    echo "OK: $checks class-feature import assertions; only temporary tables used.\n";
} finally {
    while ($db->isTransactionActive()) { $db->rollBack(); }
    @unlink($path);
    $kernel->shutdown();
}
