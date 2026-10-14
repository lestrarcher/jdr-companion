<?php

declare(strict_types=1);

use App\Command\CheckDndReferenceCommand;
use App\Kernel;
use App\Service\DndReferenceReadiness;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Dotenv\Dotenv;

require __DIR__.'/../vendor/autoload.php';
(new Dotenv())->bootEnv(__DIR__.'/../.env');
$kernel = new Kernel('dev', true);
$kernel->boot();
$db = $kernel->getContainer()->get('doctrine')->getConnection();
$service = new DndReferenceReadiness($db, dirname(__DIR__));
$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks): void {
    if (!$ok) throw new RuntimeException($label);
    ++$checks;
};
$tester = new CommandTester(new CheckDndReferenceCommand($service));
$tables = array_keys($service->requirements());
$tables = [...$tables, 'character_feature_definition', 'trackable_resource_definition'];
$snapshot = static function () use ($db, $tables): array {
    $rows = [];
    foreach ($tables as $table) $rows[$table] = $db->fetchAllAssociative("SELECT * FROM pg_temp.$table ORDER BY id");
    return $rows;
};
try {
    $db->beginTransaction();
    // Minimal synthetic tables: no public rows, sequences, schema or users touched.
    foreach ($tables as $table) {
        $db->executeStatement("CREATE TEMP TABLE $table (id integer GENERATED ALWAYS AS IDENTITY,
            slug text, import_key text, origin text DEFAULT 'OFFICIAL', owner_id integer,
            custom boolean DEFAULT false, languages json, language_choice_count integer DEFAULT 0, resource_definition_id integer,
            recharge_type text, maximum_type text, scaling_ability text,
            base_maximum integer, multiplier integer, minimum_maximum integer) ON COMMIT DROP");
    }
    $check($tester->execute([]) === 1, 'Empty reference tables block release');
    $check(str_contains($tester->getDisplay(), 'absent common'), 'Missing common diagnosed');
    foreach ($service->requirements() as $table => $requirement) {
        foreach ($requirement['keys'] as $key) $db->insert($table, [$requirement['column'] => $key]);
    }
    $db->insert('trackable_resource_definition', ['slug' => 'magical-tinkering', 'recharge_type' => 'none',
        'maximum_type' => 'ability-modifier', 'scaling_ability' => 'intelligence',
        'base_maximum' => 0, 'multiplier' => 1, 'minimum_maximum' => 1]);
    $db->insert('character_feature_definition', ['slug' => 'magical-tinkering', 'resource_definition_id' => 1]);
    $db->executeStatement("UPDATE character_race SET languages='[\"common\"]'");
    $before = $snapshot();
    $check($tester->execute([]) === 0, 'Complete release manifest accepted');
    $check($tester->execute([]) === 0 && $snapshot() === $before, 'Repeated checks are strictly read-only');
    $db->executeStatement("UPDATE character_race SET languages=NULL WHERE slug='human-phb'");
    $check($tester->execute([]) === 1 && str_contains($tester->getDisplay(), 'Métadonnée raciale manquante'), 'Missing race metadata blocks release');
    foreach (["origin='CUSTOM',owner_id=7", 'custom=true', 'owner_id=7'] as $protected) {
        $db->executeStatement("UPDATE character_race SET $protected, language_choice_count=NULL WHERE slug='human-phb'");
        $before = $snapshot();
        $check($tester->execute([]) === 1
            && str_contains($tester->getDisplay(), 'référence protégée/incompatible human-phb')
            && !str_contains($tester->getDisplay(), 'Métadonnée raciale manquante')
            && $snapshot() === $before, 'Protected slug collision does not impose official metadata or mutate data');
        $db->executeStatement("UPDATE character_race SET origin='OFFICIAL',owner_id=NULL,custom=false WHERE slug='human-phb'");
    }
    $db->executeStatement("UPDATE character_race SET language_choice_count=0 WHERE slug='human-phb'");
    foreach (['', ' ', 'Common', 'common_language', '-common', 'common--language', "common\n", str_repeat('a', 81), 42] as $invalid) {
        $db->executeStatement("UPDATE character_race SET languages=? WHERE slug='human-phb'", [json_encode([$invalid], JSON_THROW_ON_ERROR)]);
        $before = $snapshot();
        $check($tester->execute([]) === 1
            && str_contains($tester->getDisplay(), 'Langue raciale invalide : human-phb')
            && $snapshot() === $before, 'Malformed language slug explicitly rejected without mutation');
    }
    $db->executeStatement("UPDATE character_race SET languages='[\"common\"]' WHERE slug='human-phb'");
    $db->executeStatement("DELETE FROM language_definition WHERE slug='common'");
    $check($tester->execute([]) === 1 && str_contains($tester->getDisplay(), 'inconnue ou invisible'), 'Incident common reproduced');
    $db->insert('language_definition', ['slug' => 'common', 'origin' => 'CUSTOM', 'owner_id' => 1]);
    $before = $snapshot();
    $check($tester->execute([]) === 1 && $snapshot() === $before, 'CUSTOM common cannot satisfy official baseline and stays intact');
    $db->executeStatement("UPDATE language_definition SET origin='OFFICIAL',owner_id=NULL WHERE slug='common'");
    $db->insert('character_race', ['slug' => 'private-race', 'origin' => 'CUSTOM', 'owner_id' => 7, 'languages' => '["private-language"]']);
    $db->insert('language_definition', ['slug' => 'private-language', 'origin' => 'CUSTOM', 'owner_id' => 7]);
    $check($tester->execute([]) === 0, 'Unrelated private content is allowed');
    $db->executeStatement("UPDATE language_definition SET owner_id=8 WHERE slug='private-language'");
    $check($tester->execute([]) === 1, 'Invisible private race language detected');
    $db->executeStatement("UPDATE language_definition SET owner_id=7 WHERE slug='private-language'");
    $db->executeStatement("UPDATE character_feature_definition SET resource_definition_id=NULL");
    $check($tester->execute([]) === 1, 'Missing tinkering link blocks release');
    $db->executeStatement("UPDATE character_feature_definition SET resource_definition_id=1");
    $db->executeStatement("DELETE FROM proficiency_grant_rule WHERE import_key='tce-2014-artificer-starting-saves'");
    $check($tester->execute([]) === 1 && str_contains($tester->getDisplay(), 'artificer-starting-saves'), 'Partial class import detected');
    // Schema failure is tested in a savepoint with a deliberately invalid column,
    // so the connection cannot fall through to an existing public table.
    $db->executeStatement('ALTER TABLE pg_temp.character_feature_definition DROP COLUMN resource_definition_id');
    $db->createSavepoint('missing_schema');
    $check($tester->execute([]) === 1 && str_contains($tester->getDisplay(), 'impossible'), 'Schema error fails closed');
    $db->rollbackSavepoint('missing_schema');
    echo "OK: $checks readiness assertions; temporary tables only.\n";
} finally {
    while ($db->isTransactionActive()) $db->rollBack();
    $kernel->shutdown();
}
