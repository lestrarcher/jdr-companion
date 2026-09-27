<?php

declare(strict_types=1);

use App\Command\ImportRacesCommand;
use App\Kernel;
use App\Service\{DndReferenceInitializer, RaceCatalogueValidator, RaceImportPlanner};
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Dotenv\Dotenv;

require __DIR__.'/../vendor/autoload.php';
(new Dotenv())->bootEnv(__DIR__.'/../.env');
$kernel = new Kernel('dev', true);
$kernel->boot();
$em = $kernel->getContainer()->get('doctrine')->getManager();
$db = $em->getConnection();
$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks): void {
    if (!$ok) throw new RuntimeException($label);
    ++$checks;
};
$tables = ['character_race', 'character_feature_definition', 'character_feature_rule', 'race_ability_modifier',
    'character_class', 'character_subclass', 'feat', 'trackable_resource_definition', 'trackable_resource_rule', 'character_class_level_rule'];
try {
    $db->beginTransaction();
    foreach ($tables as $table) {
        $db->executeStatement("CREATE TEMP TABLE $table (LIKE public.$table INCLUDING ALL) ON COMMIT DROP");
        $db->executeStatement("ALTER TABLE pg_temp.$table ALTER COLUMN id DROP IDENTITY IF EXISTS");
        $db->executeStatement("CREATE TEMP SEQUENCE {$table}_owner_test_id START 100000");
        $db->executeStatement("ALTER TABLE pg_temp.$table ALTER COLUMN id SET DEFAULT nextval('pg_temp.{$table}_owner_test_id')");
        $db->executeStatement("INSERT INTO pg_temp.$table SELECT * FROM public.$table");
    }
    $snapshot = static function () use ($tables, $db): array {
        $rows = [];
        foreach ($tables as $table) $rows[$table] = $db->fetchAllAssociative("SELECT * FROM $table ORDER BY id");
        return $rows;
    };
    $race = (int) $db->fetchOne("SELECT id FROM character_race WHERE slug='dragonborn-gem-ftd'");
    $feature = (int) $db->fetchOne("SELECT feature_definition_id FROM character_feature_rule WHERE character_race_id=$race ORDER BY id LIMIT 1");
    $rule = (int) $db->fetchOne("SELECT id FROM character_feature_rule WHERE character_race_id=$race ORDER BY id LIMIT 1");
    $check($race > 0 && $feature > 0 && $rule > 0, 'Canonical racial fixture exists');
    foreach (['character_race' => $race, 'character_feature_definition' => $feature, 'character_feature_rule' => $rule] as $table => $id) {
        $db->createSavepoint('private_race');
        $db->executeStatement("UPDATE $table SET origin='CUSTOM',owner_id=1 WHERE id=$id");
        $before = $snapshot();
        $tester = new CommandTester(new ImportRacesCommand($db, new RaceCatalogueValidator(), new RaceImportPlanner(), dirname(__DIR__)));
        $code = $tester->execute(['--update-existing' => true]);
        $check($code !== 0 && str_contains($tester->getDisplay(), 'CUSTOM') && $before === $snapshot(), "$table collision rejects and rolls back");
        $db->rollbackSavepoint('private_race');
        $db->releaseSavepoint('private_race');
    }
    $em->clear();
    $initializer = new DndReferenceInitializer($em);
    $before = $snapshot();
    try {
        (new ReflectionMethod($initializer, 'resource'))->invoke($initializer, 'entite-symbiotique', 'Overwrite',
            App\Enum\ResourceRechargeType::LongRest, App\Enum\ResourceMaximumType::Fixed, 99);
        throw new RuntimeException('Initializer accepted CUSTOM legacy-false resource');
    } catch (LogicException) { ++$checks; }
    $db->executeStatement("UPDATE feat SET origin='CUSTOM',owner_id=1 WHERE slug='resilient'");
    $em->clear();
    try {
        (new ReflectionMethod($initializer, 'feat'))->invoke($initializer, 'resilient', 'Overwrite');
        throw new RuntimeException('Initializer accepted CUSTOM feat');
    } catch (LogicException) { ++$checks; }
    $db->executeStatement("UPDATE feat SET origin='OFFICIAL',owner_id=NULL WHERE slug='resilient'");
    $check($before === $snapshot(), 'Initializer preserves private rows');
    echo "OK: $checks import/initializer ownership assertions; temporary tables rolled back.\n";
} finally {
    $em->clear();
    while ($db->isTransactionActive()) $db->rollBack();
    $kernel->shutdown();
}
