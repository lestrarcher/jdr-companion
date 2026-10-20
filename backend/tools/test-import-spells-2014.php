<?php

declare(strict_types=1);

// Full corpus is only prepared in memory. Writes use synthetic fixtures in pg_temp.
use App\Command\ImportSpells2014Command;
use App\Entity\{SpellDefinition, User};
use App\Enum\SpellSchool;
use App\Service\Catalogue\{Spell2014Catalogue, Spell2014ImportPlan};
use Doctrine\DBAL\Schema\Schema;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Dotenv\Dotenv;

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/../migrations/Version20261016120000.php';
require __DIR__.'/../migrations/Version20261016130000.php';
(new Dotenv())->bootEnv(__DIR__.'/../.env');
$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void {
    if (!$ok) throw new RuntimeException($message);
    ++$checks;
};
$data = json_decode(file_get_contents(__DIR__.'/../data/jdr_companion_490_sorts_2014_durees.json'), true, 512, JSON_THROW_ON_ERROR);
$review = json_decode(file_get_contents(__DIR__.'/../data/spells-2014-material-review.json'), true, 512, JSON_THROW_ON_ERROR);
$mapper = new Spell2014Catalogue();
$result = $mapper->prepare($data, $review);
$check(count($result['prepared']) === 487 && count($result['excluded']) === 3 && $result['errors'] === [], 'Complete corpus valid without excluded spells');
$check(count($result['blockers']) === 1 && str_contains($result['blockers'][0], 'Droits'), 'Only unresolved rights still block writes');
$spells = [];
$schools = ['abjuration'=>'abjuration','invocation'=>'conjuration','divination'=>'divination','enchantement'=>'enchantment','évocation'=>'evocation','illusion'=>'illusion','nécromancie'=>'necromancy','transmutation'=>'transmutation'];
foreach ($result['prepared'] as $spell) $spells[$spell->getImportKey()] = $spell;
foreach ($data['spells'] as $input) {
    if (in_array($input['import_key'], Spell2014ImportPlan::EXCLUDED_KEYS, true)) {
        $check(!isset($spells[$input['import_key']]), 'Explicit exclusion');
        continue;
    }
    $spell = $spells[$input['import_key']];
    $check($spell->getSchool()->value === $schools[$input['school']] && $spell->getEdition() === '2014', 'School and edition');
    $check($spell->getDuration() === $input['duration'] && $spell->isConcentration() === $input['concentration'] && $spell->isRitual() === $input['ritual'], 'Duration and flags preserved');
    $check($spell->isMaterial() === in_array('M', $input['components'], true) && $spell->isVerbal() === in_array('V', $input['components'], true) && $spell->isSomatic() === in_array('S', $input['components'], true), 'Exact components');
    $check($spell->getHigherLevels() === null && $spell->getDescription() === trim($input['description_short_fr']), 'Only supplied short description, never full cached text');
    $check($spell->getSourceCode() !== null && $spell->getSourceReference() === $input['source_book'], 'Searchable source code and unchanged label');
    if ($spell->isMaterial()) {
        $detail = $input['components_detail'];
        $expected = substr($detail, strpos($detail, '(') + 1, strrpos($detail, ')') - strpos($detail, '(') - 1);
        $note = $review['entries'][$input['import_key']]['materialDescriptionNote'] ?? null;
        $check($spell->getMaterialDescription() === $expected.($note === null ? '' : "\n\n".$note), 'Full material text preserved with reviewed original notes');
    }
}
$bySlug = [];
foreach ($spells as $spell) $bySlug[$spell->getSlug()] = $spell;
foreach (['clone', 'legend-lore', 'imprisonment', 'create-undead', 'astral-projection', 'leomund-s-secret-chest', 'create-homunculus', 'gentle-repose'] as $slug) $check($bySlug[$slug]->getMaterialCostCp() === null, 'No approximate scalar for '.$slug);
foreach (['clone','legend-lore','create-homunculus','astral-projection'] as $slug) $check($bySlug[$slug]->isMaterialConsumed(), 'Partial consumption at casting '.$slug);
foreach (['forbiddance'=>'30 jours','summon-greater-demon'=>'cercle de protection optionnel','summon-lesser-demons'=>'cercle de protection optionnel','drawmij-s-instant-summons'=>'activation ultérieure','globe-of-invulnerability'=>'au terme du sort','magic-jar'=>'à la fin du sort'] as $slug=>$condition) {
    $check(!$bySlug[$slug]->isMaterialConsumed() && str_contains($bySlug[$slug]->getMaterialDescription(), $condition), 'Special consumption described without unconditional flag: '.$slug);
}
foreach (['warding-bond'=>10000, 'mordenkainen-s-magnificent-mansion'=>1500, 'steel-wind-strike'=>10, 'booming-blade'=>10, 'detect-thoughts'=>1, 'true-resurrection'=>2500000, 'infernal-calling'=>99900] as $slug=>$cost) $check($bySlug[$slug]->getMaterialCostCp() === $cost, 'Explicit copper conversion '.$slug);
$check(!$bySlug['forcecage']->isMaterialConsumed(), 'Cost does not imply consumption');
$check($bySlug['maelstrom']->getSourceUrl() === 'https://www.aidedd.org/dnd/sorts.php?vf=maelstrom', 'Reviewed URL alias without identity change');
$base = $data['spells'][0];
foreach ([['ruleset'=>'2024'],['origin'=>'CUSTOM'],['level'=>'1'],['ritual'=>'false'],['school'=>'unknown'],['description_short_fr'=>'','description'=>'Forbidden fallback'],['higher_level'=>'Forbidden full text'],['components'=>['S','S']],['duration'=>'concentration, 1 round'],['source_book'=>'Extra (AideDD)'],['aidedd_url'=>'https://example.invalid/'],['import_key'=>'foreign:identity']] as $patch) {
    $invalid = $mapper->prepare(['spells'=>[array_replace($base,$patch)]], $review);
    $check(count($invalid['errors']) === 1 && $invalid['prepared'] === [], 'Malformed row rejected: '.json_encode($patch));
}
$check(count($mapper->prepare(['spells'=>[$base,$base]],$review)['errors']) === 1, 'Duplicate input');
$material = $data['spells'][1];
$material['components_detail'] .= 'changed';
$check(count($mapper->prepare(['spells'=>[$material]],$review)['errors']) === 1, 'Changed material text rejected');
$missingReview = $review; unset($missingReview['entries'][$data['spells'][1]['import_key']]);
$check(count($mapper->prepare(['spells'=>[$data['spells'][1]]],$missingReview)['errors']) === 1, 'Missing review rejected');
foreach ([['materialDescriptionNote'=>''], ['materialDescriptionNote'=>false], ['materialDescriptionNote'=>'Condition originale', 'materialConsumed'=>true]] as $patch) {
    $badReview = $review;
    $key = $data['spells'][1]['import_key'];
    $badReview['entries'][$key] = array_replace($badReview['entries'][$key], $patch);
    $check(count($mapper->prepare(['spells'=>[$data['spells'][1]]], $badReview)['errors']) === 1, 'Invalid special consumption note rejected');
}
foreach ([['materialCostCp'=>-1], ['materialCostCp'=>1.5], ['materialConsumed'=>'false'], ['status'=>'inferred']] as $patch) {
    $badReview = $review;
    $key = $data['spells'][1]['import_key'];
    $badReview['entries'][$key] = array_replace($badReview['entries'][$key],$patch);
    $check(count($mapper->prepare(['spells'=>[$data['spells'][1]]],$badReview)['errors']) === 1, 'Invalid reviewed parameters rejected');
}

$kernel = new App\Kernel('dev', true); $kernel->boot();
$em = $kernel->getContainer()->get('doctrine')->getManager(); $db = $em->getConnection();
$fingerprint = static function () use ($db): array {
    $snapshot = [];
    foreach ($db->fetchFirstColumn("SELECT tablename FROM pg_tables WHERE schemaname = 'public' ORDER BY tablename") as $table) {
        $quoted = $db->getDatabasePlatform()->quoteIdentifier($table);
        $snapshot[$table] = $db->fetchAssociative('SELECT COUNT(*) AS count, md5(COALESCE(string_agg(row_to_json(t)::text,\'\' ORDER BY row_to_json(t)::text),\'\')) AS hash FROM public.'.$quoted.' t');
    }
    return $snapshot;
};
$before = $fingerprint();
$make = static fn (string $slug, string $key) => (new SpellDefinition($slug, 'Fiction de test', 1, SpellSchool::Illusion, 'Test', 'Test', 'Test', 'Fiction, aucun texte officiel.'))->setImportKey($key);
try {
    $db->beginTransaction();
    $db->executeStatement('CREATE TEMP TABLE "user" (id INT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY)');
    $migration = new DoctrineMigrations\Version20261016120000($db, new Psr\Log\NullLogger());
    $migration->up(new Schema());
    foreach ($migration->getSql() as $query) $db->executeStatement(str_replace('CREATE TABLE spell_definition', 'CREATE TEMP TABLE spell_definition', $query->getStatement()));
    $db->executeStatement('CREATE TEMP TABLE character_class (id INT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY, slug TEXT NOT NULL, origin TEXT NOT NULL, owner_id INT)');
    $db->executeStatement('CREATE TEMP TABLE character_spell_acquisition (id INT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY, spell_id INT NOT NULL)');
    $relationsMigration = new DoctrineMigrations\Version20261016130000($db, new Psr\Log\NullLogger());
    $relationsMigration->up(new Schema());
    foreach ($relationsMigration->getSql() as $query) $db->executeStatement(str_replace('CREATE TABLE spell_class_list_entry', 'CREATE TEMP TABLE spell_class_list_entry', $query->getStatement()));
    foreach (App\Service\Catalogue\Spell2014ClassAssignments::CLASSES as $slug) $db->insert('character_class', ['slug'=>$slug,'origin'=>'OFFICIAL']);
    foreach (['user','spell_definition'] as $table) $check($db->fetchOne('SELECT relpersistence FROM pg_class WHERE oid = to_regclass(?)', [$table === 'user' ? '"user"' : $table]) === 't', 'Temporary fixture table');
    $check($db->fetchOne("SELECT relpersistence FROM pg_class WHERE oid = pg_get_serial_sequence('spell_definition','id')::regclass") === 't', 'Temporary identity sequence');
    $planner = new Spell2014ImportPlan($em);
    $one = $make('fixture-one','fixture:one'); $two = $make('fixture-two','fixture:two');
    $check(count($planner->plan([$one,$two])['create']) === 2 && (int)$db->fetchOne('SELECT COUNT(*) FROM spell_definition') === 0, 'Plan is read only');
    $check($planner->insertMissing([$one,$two]) === ['created'=>2,'kept'=>0], 'First synthetic import');
    $db->executeStatement("UPDATE spell_definition SET description = 'Correction éditoriale', material = true, material_description = 'Objet personnel de test', material_cost_cp = 123, material_consumed = true WHERE slug = 'fixture-one'");
    $snapshot = $db->fetchAllAssociative('SELECT * FROM spell_definition ORDER BY id');
    $check($planner->insertMissing([$one,$two]) === ['created'=>0,'kept'=>2], 'Second import idempotent');
    $check($snapshot === $db->fetchAllAssociative('SELECT * FROM spell_definition ORDER BY id'), 'Existing IDs, content and mechanics preserved exactly');
    $db->executeStatement('INSERT INTO "user" DEFAULT VALUES');
    $db->executeStatement("UPDATE spell_definition SET origin = 'CUSTOM', owner_id = 1, import_key = null WHERE slug = 'fixture-two'");
    $customBefore = $db->fetchAssociative("SELECT * FROM spell_definition WHERE slug = 'fixture-two'");
    foreach ([[$two], [$make('fixture-one','fixture:different')], [$make('different-slug','fixture:one')], [$one,$one], [SpellDefinition::forOwner(new User(), 'custom-input','Fiction',0,SpellSchool::Illusion,'Test','Test','Test','Test')], [$make('excluded',Spell2014ImportPlan::EXCLUDED_KEYS[0])]] as $batch) {
        $check(count($planner->plan($batch)['conflicts']) > 0, 'Identity/ownership conflict');
    }
    try { $planner->insertMissing([$make('fixture-new','fixture:new'),$two]); throw new RuntimeException('Conflict accepted'); }
    catch (RuntimeException $e) { $check(str_contains($e->getMessage(),'Collision'), 'Mixed batch rejected'); }
    $check((int)$db->fetchOne('SELECT COUNT(*) FROM spell_definition') === 2 && $customBefore === $db->fetchAssociative("SELECT * FROM spell_definition WHERE slug = 'fixture-two'"), 'No partial insertion or CUSTOM mutation');
    $invalid = $make('fixture-invalid','fixture:invalid');
    (new ReflectionProperty(SpellDefinition::class, 'level'))->setValue($invalid, 99);
    try { $planner->insertMissing([$make('fixture-rollback','fixture:rollback'),$invalid]); throw new RuntimeException('Invalid SQL accepted'); }
    catch (Doctrine\DBAL\Exception\DriverException) { $check((int)$db->fetchOne('SELECT COUNT(*) FROM spell_definition') === 2, 'SQL failure rolls back the whole batch'); }
    require __DIR__.'/test-spell-import-class-cases.php';
    $tester = new CommandTester(new ImportSpells2014Command($em, dirname(__DIR__)));
    $exit = $tester->execute([]); // Never execute --apply, even in this test.
    $check($exit === 1 && str_contains($tester->getDisplay(),'Préparés : 487 | Exclus : 3 | Erreurs : 0'), 'Complete command dry run reports validation plus blockers');
    $check(str_contains($tester->getDisplay(),'487 créations | 0 conservés | 0 conflits') && (int)$db->fetchOne('SELECT COUNT(*) FROM spell_definition') === 2, 'Complete dry run inserts no corpus');
    $dryOutput = $tester->getDisplay();
    $check(str_contains($dryOutput, '1235 à créer | 0 existantes | 1170 base | 65 TCoE'), 'Full association dry-run');
    $tester->execute(['--dry-run'=>true]);
    $check($tester->getDisplay() === $dryOutput, 'Explicit and default dry-run identical');
    $collision = $make($base['slug_en'],'fixture:occupied');
    $planner->insertMissing([$collision]);
    $tester->execute([]);
    $check(str_contains($tester->getDisplay(),'1 conflits') && $tester->getStatusCode() === 1, 'Dry-run detects live DB collision, not only input errors');
    $check((int)$db->fetchOne('SELECT COUNT(*) FROM spell_definition') === 3, 'Collision dry-run never writes');
    $db->rollBack();
    $check($before === $fingerprint(), 'Every public table unchanged');
    echo "OK: $checks spell import assertions; synthetic temporary fixtures only, no --apply.\n";
} finally {
    if ($db->isTransactionActive()) $db->rollBack();
    $em->clear(); $kernel->shutdown();
}
