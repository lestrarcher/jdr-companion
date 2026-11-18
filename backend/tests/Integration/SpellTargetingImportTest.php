<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Command\ImportSpellTargeting2014Command;
use App\Service\Catalogue\Spell2014TargetingImport;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class SpellTargetingImportTest extends TestCase
{
    private \App\Kernel $kernel;
    private Connection $db;
    private Spell2014TargetingImport $importer;

    protected function setUp(): void
    {
        $this->kernel = new \App\Kernel('test', true); $this->kernel->boot();
        $this->db = $this->kernel->getContainer()->get('doctrine')->getConnection();
        $this->db->executeStatement("CREATE TEMP TABLE spell_definition (id INT GENERATED ALWAYS AS IDENTITY, edition VARCHAR(4) DEFAULT '2014', import_key TEXT, origin TEXT DEFAULT 'OFFICIAL', owner_id INT, name TEXT, level INT DEFAULT 1, range TEXT DEFAULT '18 mètres', description TEXT DEFAULT 'Fixture creature', higher_levels TEXT, target_type VARCHAR(10), multiple_targets BOOLEAN NOT NULL DEFAULT FALSE, multiple_targets_progression VARCHAR(15), multiple_targets_threshold INT, unrelated TEXT DEFAULT 'preserved')");
        self::assertSame('t', $this->db->fetchOne("SELECT relpersistence FROM pg_class WHERE oid=to_regclass('spell_definition')"));
        $this->importer = new Spell2014TargetingImport($this->db);
    }

    protected function tearDown(): void
    {
        $this->db->close(); // Only temporary fixtures exist in this connection.
        $this->kernel->shutdown();
    }

    private function fixture(string $key = 'fixture', array $overrides = []): array
    {
        $row = array_replace(['import_key'=>'aidedd-2014:'.$key, 'name'=>'Fixture '.$key], $overrides);
        $this->db->insert('spell_definition', $row);
        $stored = $this->db->fetchAssociative('SELECT * FROM spell_definition ORDER BY id DESC LIMIT 1');
        return ['edition'=>'2014', 'import_key'=>$row['import_key'], 'name'=>$row['name'], 'source_sha256'=>Spell2014TargetingImport::fingerprint($stored),
            'status'=>'ready', 'reason'=>'Une créature.', 'target_type'=>'creature', 'multiple_targets'=>false, 'multiple_targets_progression'=>null, 'multiple_targets_threshold'=>null];
    }

    private function document(array $entries): array { return ['schemaVersion'=>1, 'count'=>count($entries), 'spells'=>$entries]; }
    private function snapshot(): array { return $this->db->fetchAllAssociative('SELECT * FROM spell_definition ORDER BY id'); }

    public function testDryRunApplyIdempotenceAndOnlyFourColumnsChange(): void
    {
        $entry = $this->fixture();
        $this->fixture('custom', ['origin'=>'CUSTOM', 'owner_id'=>99]);
        $this->fixture('other-edition', ['edition'=>'2024']);
        $document = $this->document([$entry]); $before = $this->snapshot();
        $dry = $this->importer->run($document);
        self::assertSame([], $dry['errors']); self::assertCount(1, $dry['changes']);
        self::assertSame(1, $dry['customIgnored']); self::assertSame($before, $this->snapshot());
        $applied = $this->importer->run($document, true);
        self::assertSame(1, $applied['applied']);
        $after = $this->snapshot();
        self::assertSame('creature', $after[0]['target_type']);
        unset($before[0]['target_type'], $after[0]['target_type']);
        self::assertSame($before, $after, 'No other column, custom spell or edition is changed');
        $again = $this->importer->run($document, true);
        self::assertSame(0, $again['applied']); self::assertCount(1, $again['unchanged']); self::assertSame([], $again['changes']);
    }

    public function testExistingAndPartialConfigurationsArePreservedAndConflictsBlockAllWrites(): void
    {
        $a = $this->fixture('new');
        $b = $this->fixture('validated', ['target_type'=>'object']);
        $c = $this->fixture('partial', ['multiple_targets_threshold'=>5]);
        $before = $this->snapshot();
        $report = $this->importer->run($this->document([$a,$b,$c]), true);
        self::assertCount(2, $report['errors']); self::assertSame(0, $report['applied']); self::assertSame($before, $this->snapshot());
    }

    public function testReviewNeverErasesAnExistingConfiguration(): void
    {
        $entry = $this->fixture('reviewed', ['target_type'=>'creature', 'multiple_targets_progression'=>'slot', 'multiple_targets_threshold'=>3]);
        $entry = array_replace($entry, array_fill_keys(Spell2014TargetingImport::FIELDS, null), ['status'=>'review', 'reason'=>'Effet ambigu.']);
        $before = $this->snapshot();
        $report = $this->importer->run($this->document([$entry]), true);
        self::assertSame([], $report['errors']); self::assertTrue($report['review'][0]['existingPreserved']); self::assertSame($before, $this->snapshot());
    }

    public function testMissingUnknownAndAmbiguousIdentitiesBlockWrites(): void
    {
        $entry = $this->fixture();
        $this->fixture('unrecognized');
        $missing = array_replace($entry, ['import_key'=>'aidedd-2014:absent']);
        $this->db->executeStatement("INSERT INTO spell_definition (import_key,name) VALUES ('aidedd-2014:fixture','Duplicate')");
        $before = $this->snapshot();
        $report = $this->importer->run($this->document([$entry,$missing]), true);
        self::assertCount(3, $report['errors']); self::assertSame(0, $report['applied']); self::assertSame($before, $this->snapshot());
    }

    public function testSourceDriftBlocksAllWrites(): void
    {
        $entry = $this->fixture(); $this->db->executeStatement("UPDATE spell_definition SET higher_levels='New targets'");
        $before = $this->snapshot();
        $report = $this->importer->run($this->document([$entry]), true);
        self::assertCount(1, $report['errors']); self::assertSame($before, $this->snapshot());
    }

    public function testWholeDocumentIsValidatedBeforeAnyWrite(): void
    {
        $a = $this->fixture('a'); $b = $this->fixture('b'); $before = $this->snapshot();
        foreach ([['target_type'=>'invented'], ['multiple_targets'=>null], ['multiple_targets'=>'false'],
            ['multiple_targets_threshold'=>3], ['multiple_targets_progression'=>'slot','multiple_targets_threshold'=>10],
            ['multiple_targets_progression'=>'character','multiple_targets_threshold'=>21], ['edition'=>'2024'],
            ['import_key'=>$a['import_key']], ['status'=>'review'], ['unexpected'=>'field']] as $invalid) {
            try { $this->importer->run($this->document([$a,array_replace($b,$invalid)]), true); self::fail('Invalid reference accepted'); }
            catch (\InvalidArgumentException) { self::assertSame($before, $this->snapshot()); }
        }
    }

    public function testDatabaseFailureRollsBackEarlierUpdates(): void
    {
        $a = $this->fixture('a'); $b = $this->fixture('b'); $before = $this->snapshot();
        $this->db->executeStatement("CREATE FUNCTION pg_temp.reject_targeting() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN IF NEW.import_key = ''aidedd-2014:b'' THEN RAISE EXCEPTION ''fixture failure''; END IF; RETURN NEW; END'");
        $this->db->executeStatement('CREATE TRIGGER reject_targeting BEFORE UPDATE ON pg_temp.spell_definition FOR EACH ROW EXECUTE FUNCTION pg_temp.reject_targeting()');
        try { $this->importer->run($this->document([$a,$b]), true); self::fail('Expected database failure'); }
        catch (\Doctrine\DBAL\Exception) { self::assertSame($before, $this->snapshot()); }
    }

    public function testCommandDefaultsToSimulationAndApplyIsExplicit(): void
    {
        $entry = $this->fixture(); $before = $this->snapshot();
        $dir = sys_get_temp_dir().'/targeting-'.bin2hex(random_bytes(6)); mkdir($dir); mkdir($dir.'/data');
        $file = $dir.'/data/spells-2014-targeting.json';
        try {
            file_put_contents($file, json_encode($this->document([$entry])));
            $tester = new CommandTester(new ImportSpellTargeting2014Command($this->importer, $dir));
            self::assertSame(0, $tester->execute(['--json'=>true]));
            self::assertSame('dry-run', json_decode($tester->getDisplay(), true)['mode']); self::assertSame($before, $this->snapshot());
            self::assertSame(0, $tester->execute(['--apply'=>true, '--json'=>true]));
            self::assertSame(1, json_decode($tester->getDisplay(), true)['applied']);
            file_put_contents($file, '{invalid');
            $after = $this->snapshot();
            self::assertSame(1, $tester->execute(['--apply'=>true, '--json'=>true])); self::assertSame($after, $this->snapshot());
        } finally { unlink($file); rmdir($dir.'/data'); rmdir($dir); }
    }

    public function testVersionedReferenceCoverageAndCriticalProfiles(): void
    {
        $document = json_decode(file_get_contents(dirname(__DIR__,2).'/data/spells-2014-targeting.json'), true, 512, JSON_THROW_ON_ERROR);
        $entries = $this->importer->validate($document);
        self::assertCount(487, $entries);
        $byKey = array_column($entries, null, 'import_key');
        foreach (['orbe-chromatique'=>null, 'immobilisation-de-personne'=>3, 'immobilisation-de-monstre'=>6, 'decharge-occulte'=>5,
            'invisibilite'=>3, 'vol'=>4, 'heroisme'=>2, 'charme-monstre'=>5] as $key=>$threshold) {
            self::assertSame($threshold, $byKey['aidedd-2014:'.$key]['multiple_targets_threshold']);
            self::assertSame('creature', $byKey['aidedd-2014:'.$key]['target_type']);
        }
        foreach (['malefice','marque-du-chasseur','bouclier-de-platine-de-fizban','eclair-de-chaos','projectile-magique'] as $key) self::assertTrue($byKey['aidedd-2014:'.$key]['multiple_targets']);
        self::assertSame('special', $byKey['aidedd-2014:trait-de-feu']['target_type']);
        foreach (['souffle-du-dragon','couteau-de-glace','serviteur-miniature','souhait','enervation'] as $key) self::assertSame('review', $byKey['aidedd-2014:'.$key]['status']);
    }

    public function testFullReferencePreservesTheFourteenValidatedProfiles(): void
    {
        $document = json_decode(file_get_contents(dirname(__DIR__,2).'/data/spells-2014-targeting.json'), true, 512, JSON_THROW_ON_ERROR);
        $validated = ['benediction','boule-de-feu','boule-de-feu-a-retardement','decharge-occulte','hate','immobilisation-de-monstre',
            'immobilisation-de-personne','invisibilite','invisibilite-superieure','metamorphose','orbe-chromatique','projectile-magique','rayon-ardent','rayon-de-givre'];
        foreach ($document['spells'] as &$entry) {
            $key = substr($entry['import_key'],12);
            $row = [];
            if (in_array($key,$validated,true)) {
                $row = array_intersect_key($entry,array_flip(Spell2014TargetingImport::FIELDS));
                $row['multiple_targets'] = $row['multiple_targets'] ? 'true' : 'false';
            }
            $fixture = $this->fixture($key,$row);
            $entry['source_sha256'] = $fixture['source_sha256'];
        }
        unset($entry);
        $before = $this->snapshot();
        $report = $this->importer->run($document,true);
        self::assertSame([], $report['errors']); self::assertCount(14,$report['unchanged']);
        self::assertSame(count(array_filter($document['spells'],fn($entry)=>$entry['status']==='ready'))-14,$report['applied']);
        foreach ($this->snapshot() as $i=>$row) {
            if (in_array(substr($row['import_key'],12),$validated,true)) self::assertSame($before[$i],$row);
            self::assertSame(array_diff_key($before[$i],array_flip(Spell2014TargetingImport::FIELDS)),array_diff_key($row,array_flip(Spell2014TargetingImport::FIELDS)));
        }
        $again = $this->importer->run($document);
        self::assertSame([], $again['errors']); self::assertSame([], $again['changes']);
    }
}
