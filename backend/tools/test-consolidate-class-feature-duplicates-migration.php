<?php

declare(strict_types=1);

use App\Kernel;
use Symfony\Component\Dotenv\Dotenv;

require __DIR__.'/../vendor/autoload.php';
(new Dotenv())->bootEnv(__DIR__.'/../.env');
$kernel = new Kernel('dev', true);
$kernel->boot();
$db = $kernel->getContainer()->get('doctrine')->getConnection();
$migration = (string) file_get_contents(__DIR__.'/../migrations/Version20260917120000.php');
preg_match_all("/<<<'SQL'\R(.*?)\RSQL/s", $migration, $matches);
$sql = $matches[1];
if (count($sql) !== 1) {
    throw new RuntimeException('Expected exactly one migration SQL block.');
}

$pairs = [
    ['lien-avec-une-arme', 'eldritch-knight-weapon-bond'],
    ['frappe-occulte', 'eldritch-knight-eldritch-strike'],
    ['charge-arcanique', 'eldritch-knight-arcane-charge'],
    ['magie-de-guerre-amelioree', 'eldritch-knight-improved-war-magic'],
    ['monster-slayer-hunter-s-sense', 'hunters-sense'],
    ['storm-sorcery-storm-s-fury', 'storms-fury'],
    ['divination-experte', 'divination-expert-divination'],
    ['troisieme-oeil', 'divination-the-third-eye'],
];
$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void {
    if (!$ok) {
        throw new RuntimeException($message);
    }
    ++$checks;
};

$setup = static function () use ($db, $pairs): void {
    foreach (['character_feature_rule', 'character_feature_definition', 'trackable_resource_definition'] as $table) {
        $db->executeStatement("DROP TABLE IF EXISTS pg_temp.$table CASCADE");
    }
    $db->executeStatement('CREATE TEMP TABLE trackable_resource_definition (LIKE public.trackable_resource_definition INCLUDING ALL)');
    $db->executeStatement('CREATE TEMP TABLE character_feature_definition (LIKE public.character_feature_definition INCLUDING ALL)');
    $db->executeStatement('CREATE TEMP TABLE character_feature_rule (LIKE public.character_feature_rule INCLUDING ALL)');

    $now = '2026-09-17 12:00:00';
    $db->insert('trackable_resource_definition', [
        'id' => 50, 'slug' => 'third-eye-use', 'name' => 'Third Eye', 'recharge_type' => 'long-rest',
        'maximum_type' => 'fixed', 'base_maximum' => 1, 'multiplier' => 1, 'minimum_maximum' => 1,
        'custom' => 0, 'created_at' => $now, 'updated_at' => $now,
    ]);
    $id = 100;
    foreach ($pairs as [$sourceSlug, $targetSlug]) {
        $db->insert('character_feature_definition', [
            'id' => $id++, 'slug' => $sourceSlug, 'name' => $sourceSlug, 'activation_type' => 'passive',
            'visible' => 1, 'custom' => 0, 'resource_definition_id' => $sourceSlug === 'troisieme-oeil' ? 50 : null,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $db->insert('character_feature_definition', [
            'id' => $id++, 'slug' => $targetSlug, 'name' => $targetSlug, 'activation_type' => 'passive',
            'visible' => 1, 'custom' => 0, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }
};
$featureId = static fn (string $slug): int => (int) $db->fetchOne('SELECT id FROM character_feature_definition WHERE slug = ?', [$slug]);
$execute = static fn (): int => $db->executeStatement($sql[0]);
$scenario = static function (callable $prepare, callable $assert, bool $mustFail = false) use ($db, $setup, $execute): void {
    $setup();
    $prepare();
    $db->createSavepoint('scenario');
    $failed = false;
    try {
        $execute();
    } catch (Throwable) {
        $failed = true;
        $db->rollbackSavepoint('scenario');
    }
    if ($failed !== $mustFail) {
        throw new RuntimeException($mustFail ? 'Scenario should fail' : 'Scenario unexpectedly failed');
    }
    $assert();
};

try {
    $db->beginTransaction();

    $scenario(
        function () use ($db, $featureId): void {
            $db->insert('character_feature_rule', [
                'id' => 200, 'feature_definition_id' => $featureId('lien-avec-une-arme'),
                'character_subclass_id' => 10, 'unlock_level' => 3, 'display_order' => 3,
            ]);
            $db->insert('character_feature_rule', [
                'id' => 201, 'feature_definition_id' => $featureId('frappe-occulte'),
                'character_subclass_id' => 10, 'unlock_level' => 10, 'display_order' => 10,
            ]);
            $db->insert('character_feature_rule', [
                'id' => 202, 'feature_definition_id' => $featureId('eldritch-knight-eldritch-strike'),
                'character_subclass_id' => 10, 'unlock_level' => 10, 'display_order' => 10,
            ]);
        },
        function () use ($db, $featureId, $check): void {
            $check((int) $db->fetchOne("SELECT count(*) FROM character_feature_definition WHERE slug IN ('lien-avec-une-arme','frappe-occulte','charge-arcanique','magie-de-guerre-amelioree','monster-slayer-hunter-s-sense','storm-sorcery-storm-s-fury','divination-experte','troisieme-oeil')") === 0, 'All eight historical definitions removed');
            $check((int) $db->fetchOne('SELECT count(*) FROM character_feature_definition') === 8, 'All eight canonical definitions preserved');
            $check((int) $db->fetchOne('SELECT count(*) FROM character_feature_rule WHERE feature_definition_id = ?', [$featureId('eldritch-knight-weapon-bond')]) === 1, 'Historical rule transferred to canonical definition');
            $check((int) $db->fetchOne('SELECT count(*) FROM character_feature_rule WHERE feature_definition_id = ?', [$featureId('eldritch-knight-eldritch-strike')]) === 1, 'Equivalent rule collision deduplicated');
            $check((int) $db->fetchOne('SELECT resource_definition_id FROM character_feature_definition WHERE slug = ?', ['divination-the-third-eye']) === 50, 'Third Eye resource transferred');
        },
    );

    $scenario(
        function () use ($db, $featureId): void {
            $db->insert('character_feature_rule', [
                'id' => 210, 'feature_definition_id' => $featureId('charge-arcanique'),
                'character_subclass_id' => 10, 'unlock_level' => 15, 'display_order' => 14,
            ]);
            $db->insert('character_feature_rule', [
                'id' => 211, 'feature_definition_id' => $featureId('eldritch-knight-arcane-charge'),
                'character_subclass_id' => 10, 'unlock_level' => 15, 'display_order' => 15,
            ]);
        },
        fn () => $check((int) $db->fetchOne("SELECT count(*) FROM character_feature_definition WHERE slug = 'charge-arcanique'") === 1, 'Non-equivalent rule collision rejected without deleting its source'),
        true,
    );

    echo "OK: $checks migration assertions on PostgreSQL temporary tables.\n";
} finally {
    while ($db->isTransactionActive()) {
        $db->rollBack();
    }
    $kernel->shutdown();
}
