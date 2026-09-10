<?php

declare(strict_types=1);

use App\Command\ImportFeatsCommand;
use App\Entity\Feat;
use App\Kernel;
use App\Service\FeatCatalogueValidator;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Dotenv\Dotenv;

require __DIR__ . '/../vendor/autoload.php';
(new Dotenv())->bootEnv(__DIR__ . '/../.env');
$kernel = new Kernel('dev', true);
$kernel->boot();
$em = $kernel->getContainer()->get('doctrine')->getManager();
$db = $em->getConnection();
$path = tempnam(sys_get_temp_dir(), 'feat-import-');
$catalogue = json_decode(file_get_contents(__DIR__ . '/../data/reference/dnd-2014-feats.json'), false, 512, JSON_THROW_ON_ERROR);
$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    ++$checks;
};
$run = static function (array $options = []) use ($em, $path): array {
    $em->clear();
    $tester = new CommandTester(new ImportFeatsCommand($em, new FeatCatalogueValidator(), dirname(__DIR__)));
    $code = $tester->execute(['path' => $path, ...$options]);

    return [$code, $tester->getDisplay()];
};
$snapshot = static fn () => $db->fetchAllAssociative('SELECT * FROM feat ORDER BY id');

try {
    $db->beginTransaction();
    // PostgreSQL resolves unqualified ORM table names to this temporary table first.
    // No public rows or public identity sequences are touched.
    $db->executeStatement('CREATE TEMP TABLE feat (LIKE public.feat INCLUDING ALL) ON COMMIT DROP');
    $db->executeStatement('CREATE TEMP SEQUENCE feat_import_test_id');
    $db->executeStatement('ALTER TABLE pg_temp.feat ALTER COLUMN id DROP IDENTITY IF EXISTS');
    $db->executeStatement("ALTER TABLE pg_temp.feat ALTER COLUMN id SET DEFAULT nextval('pg_temp.feat_import_test_id')");
    $check($db->fetchOne("SELECT 'feat'::regclass::oid = 'pg_temp.feat'::regclass::oid") === true, 'Temporary table must shadow public.feat');
    $publicBefore = $db->fetchAllAssociative('SELECT * FROM public.feat ORDER BY id');
    file_put_contents($path, json_encode($catalogue, JSON_THROW_ON_ERROR));

    [$code] = $run(['--dry-run' => true]);
    $check($code === 0 && $snapshot() === [], 'Dry-run must not create feats');
    [$code] = $run();
    $check($code === 0 && count($snapshot()) === 9, 'Create only the nine approved feats');
    $pending = array_values(array_filter($catalogue->feats, static fn ($f) => $f->reviewStatus === 'pending'))[0];
    $check($db->fetchOne('SELECT id FROM feat WHERE slug = ?', [$pending->slug]) === false, 'Pending feat ignored');
    $before = $snapshot();
    [$code, $output] = $run();
    $check($code === 0 && $snapshot() === $before && str_contains($output, 'Existants identiques'), 'Replay must be identical, including timestamps and IDs');

    $db->executeStatement("UPDATE feat SET name = 'Local name' WHERE slug = 'resilient'");
    $before = $snapshot();
    [$code, $output] = $run();
    $check($code === 0 && $snapshot() === $before && str_contains($output, 'resilient.name'), 'Report differences and preserve existing data by default');
    [$code] = $run(['--update-existing' => true, '--dry-run' => true]);
    $check($code === 0 && $snapshot() === $before, 'Update dry-run must preserve managed entities and timestamps');
    [$code] = $run(['--update-existing' => true]);
    $check($code === 0 && $db->fetchOne("SELECT name FROM feat WHERE slug = 'resilient'") !== 'Local name', 'Explicit update applied');
    $check(array_column($snapshot(), 'id') === array_column($before, 'id'), 'Updates preserve IDs');

    $extra = new Feat('local-only', 'Local only');
    $em->persist($extra);
    $em->flush();
    $before = $snapshot();
    [$code] = $run(['--update-existing' => true]);
    $check($code === 0 && $snapshot() === $before, 'No deletion of feats absent from the catalogue');

    // A valid new approval can be imported later; validation must not freeze the initial nine.
    $pending->reviewStatus = 'approved';
    $pending->repeatable = false;
    $pending->requiresAbilityChoice = false;
    $pending->chosenAbilityIncrease = 0;
    $pending->allowedAbilities = [];
    $invalid = unserialize(serialize($catalogue));
    $invalid->feats[82]->unexpected = true;
    file_put_contents($path, json_encode($invalid, JSON_THROW_ON_ERROR));
    [$code] = $run(['--update-existing' => true]);
    $check($code !== 0 && $snapshot() === $before, 'Invalid late entry blocks all writes, including earlier creations');
    file_put_contents($path, '{invalid json');
    [$code] = $run();
    $check($code !== 0 && $snapshot() === $before, 'Malformed JSON blocks all writes');
    file_put_contents($path, json_encode($catalogue, JSON_THROW_ON_ERROR));
    [$code] = $run();
    $check($code === 0 && count($snapshot()) === 11, 'Newly approved entry can be created');
    $before = $snapshot();
    [$code] = $run();
    $check($code === 0 && $snapshot() === $before, 'Replay after new approval creates no duplicate');
    $newEntries = array_slice(array_values(array_filter($catalogue->feats, static fn ($f) => $f->reviewStatus === 'pending')), 0, 2);
    foreach ($newEntries as $entry) {
        $entry->reviewStatus = 'approved';
        $entry->repeatable = false;
        $entry->requiresAbilityChoice = false;
        $entry->chosenAbilityIncrease = 0;
        $entry->allowedAbilities = [];
    }
    $rejectedSlug = $db->quote($newEntries[1]->slug);
    $db->executeStatement("CREATE FUNCTION pg_temp.reject_test_feat() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN IF NEW.slug = " . str_replace("'", "''", $rejectedSlug) . " THEN RAISE EXCEPTION ''test write failure''; END IF; RETURN NEW; END'");
    $db->executeStatement('CREATE TRIGGER reject_test_feat BEFORE INSERT ON pg_temp.feat FOR EACH ROW EXECUTE FUNCTION pg_temp.reject_test_feat()');
    file_put_contents($path, json_encode($catalogue, JSON_THROW_ON_ERROR));
    [$code] = $run();
    $check($code !== 0 && $snapshot() === $before, 'SQL failure rolls back all creations in the import transaction');
    $check($publicBefore === $db->fetchAllAssociative('SELECT * FROM public.feat ORDER BY id'), 'Public feats untouched');
    echo "OK: $checks import assertions; only temporary table used.\n";
} finally {
    while ($db->isTransactionActive()) {
        $db->rollBack();
    }
    unlink($path);
    $kernel->shutdown();
}
