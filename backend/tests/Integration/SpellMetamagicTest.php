<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\{Campaign, Character, CharacterClass, CharacterClassLevel, CharacterFeatureDefinition, CharacterFeatureRule, CharacterSessionState, CharacterSpellAcquisition, GameSession, SpellDefinition, SpellcastingPolicyDefinition, TrackableResourceDefinition, TrackableResourceRule, User};
use App\Enum\{Ability, ResourceRechargeType, SpellSchool, SpellcastingProgressionType};
use App\Service\{CharacterSpellCastExecutor, PlayerSpellDirectory, SpellCastReceipts};
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class SpellMetamagicTest extends TestCase
{
    private \App\Kernel $kernel;
    private \Doctrine\ORM\EntityManagerInterface $em;
    private \Doctrine\DBAL\Connection $db;
    private CharacterSpellCastExecutor $executor;
    private PlayerSpellDirectory $directory;
    private CharacterSessionState $session;
    private SpellDefinition $spell;
    private string $source;
    private string $cantripSource;
    private int $request = 0;
    private const COSTS = ['careful'=>1, 'distant'=>1, 'empowered'=>1, 'extended'=>1, 'heightened'=>3,
        'quickened'=>2, 'subtle'=>1, 'twinned'=>1, 'seeking'=>2, 'transmuted'=>1];

    protected function setUp(): void
    {
        $this->kernel = new \App\Kernel('test', true);
        $this->kernel->boot();
        $container = $this->kernel->getContainer()->get('test.service_container');
        $this->em = $container->get('doctrine')->getManager();
        $this->db = $this->em->getConnection();
        $this->db->beginTransaction();
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        foreach ((new SchemaTool($this->em))->getCreateSchemaSql($metadata) as $sql) {
            $this->db->executeStatement(preg_replace(['/^CREATE TABLE /', '/^CREATE SEQUENCE /'], ['CREATE TEMP TABLE ', 'CREATE TEMP SEQUENCE '], $sql));
        }
        foreach (['Version20261108020000', 'Version20261108030000'] as $version) {
            require_once dirname(__DIR__, 2).'/migrations/'.$version.'.php';
            $class = 'DoctrineMigrations\\'.$version;
            foreach ($class::statements() as $sql) $this->db->executeStatement(preg_replace('/^CREATE TABLE /', 'CREATE TEMP TABLE ', $sql));
        }
        foreach (['Version20261022220000', 'Version20261023001000'] as $version) {
            require_once dirname(__DIR__, 2).'/migrations/'.$version.'.php';
            $class = 'DoctrineMigrations\\'.$version;
            $migration = new $class($this->db, new \Psr\Log\NullLogger());
            $migration->up(new \Doctrine\DBAL\Schema\Schema());
            foreach ($migration->getSql() as $query) $this->db->executeStatement(preg_replace('/^CREATE TABLE /', 'CREATE TEMP TABLE ', $query->getStatement()));
        }
        $this->db->executeStatement('ALTER TABLE feature_spell_grant ADD enabled BOOLEAN DEFAULT TRUE, ADD casting_ability VARCHAR(10), ADD casting_rules JSONB, ADD resource_definition_id INT, ADD managed_resource_id INT');
        foreach ([...array_map(static fn($m) => $m->getTableName(), $metadata), 'feature_spell_grant', 'character_feature_spell', 'feature_choice_group', 'feature_choice_option', 'feature_choice_grant', 'character_feature_choice', 'feature_choice_rule_gate', 'feature_choice_legacy_rule'] as $table) {
            self::assertSame('t', $this->db->fetchOne('SELECT relpersistence FROM pg_class WHERE oid=to_regclass(?)', [$this->db->quoteIdentifier($table)]));
        }
        $owner = (new User())->setEmail('metamagic@example.invalid')->setPassword('unused');
        $campaign = new Campaign($owner, 'metamagic', 'Metamagic');
        $character = new Character($campaign, 'caster', 'Caster', Character::TYPE_PLAYER);
        $sorcerer = new CharacterClass('sorcerer', 'Sorcerer', 6, 1, SpellcastingProgressionType::Full);
        $warlock = new CharacterClass('warlock', 'Warlock', 8, 1, SpellcastingProgressionType::Pact);
        $bard = new CharacterClass('bard', 'Bard', 8, 3, SpellcastingProgressionType::Full);
        foreach ([$owner, $campaign, $character, $sorcerer, $warlock, $bard] as $entity) $this->em->persist($entity);
        foreach ([$sorcerer, $sorcerer, $sorcerer, $warlock, $warlock, $warlock, $bard] as $i=>$class) {
            $level = new CharacterClassLevel($character, $class, $i + 1);
            $character->addClassLevel($level); $this->em->persist($level);
        }
        // Learn the spells as a bard: metamagic is not restricted to sorcerer spells.
        $policy = new SpellcastingPolicyDefinition($bard, new \App\Dto\SpellcastingPolicy('fixture-bard', 1, Ability::Charisma, 'known',
            array_fill(0, 20, ['maxSpellLevel'=>1, 'cantrips'=>2, 'known'=>2, 'bookAdditions'=>0, 'replacements'=>0, 'preparationBase'=>null]), 'Fixture'));
        $this->spell = new SpellDefinition('fixture-spell', 'Fixture spell', 1, SpellSchool::Evocation, '1 action', '18 mètres', '1 minute', 'Synthetic spell');
        $this->spell->setComponents(true, true, false);
        $cantrip = new SpellDefinition('fixture-cantrip', 'Fixture cantrip', 0, SpellSchool::Evocation, '1 action', '60 feet', '1 minute', 'Synthetic cantrip');
        $cantrip->setComponents(true, true, false);
        $acquisition = new CharacterSpellAcquisition($character, $this->spell, $policy, $level, $owner, 'manual-reconciliation', 'known', 'Fixture');
        $cantripAcquisition = new CharacterSpellAcquisition($character, $cantrip, $policy, $level, $owner, 'manual-reconciliation', 'cantrips', 'Fixture');
        $points = (new TrackableResourceDefinition('sorcery-points', 'Points', ResourceRechargeType::LongRest))->setBaseMaximum(20);
        $pact = (new TrackableResourceDefinition('warlock-pact-slots', 'Pact slots', ResourceRechargeType::ShortRest))->setBaseMaximum(2);
        $feature = new CharacterFeatureDefinition('fixture-metamagic', 'Metamagic');
        $rule = CharacterFeatureRule::forClass($feature, $sorcerer, 3);
        foreach ([$policy, $this->spell, $cantrip, $acquisition, $cantripAcquisition, $points, $pact, $feature, $rule,
            TrackableResourceRule::forClass($points, $sorcerer, 2), TrackableResourceRule::forClass($pact, $warlock, 1)] as $entity) $this->em->persist($entity);
        $this->em->flush();
        $this->db->insert('feature_choice_group', ['key'=>'metamagic', 'name'=>'Metamagic']);
        $this->db->insert('feature_choice_grant', ['key'=>'fixture-grant', 'group_key'=>'metamagic', 'rule_id'=>$rule->getId(), 'choice_count'=>10]);
        foreach (array_keys(self::COSTS) as $i=>$short) {
            $key = 'metamagic-'.$short.'-spell';
            $option = new CharacterFeatureDefinition($key, $short);
            $this->em->persist($option); $this->em->flush();
            $this->db->insert('feature_choice_option', ['key'=>$key, 'group_key'=>'metamagic', 'feature_id'=>$option->getId()]);
            $this->db->insert('character_feature_choice', ['key'=>'choice-'.$short, 'character_id'=>$character->getId(), 'grant_key'=>'fixture-grant',
                'option_key'=>$key, 'slot'=>$i+1, 'acquired_position'=>3, 'acquired_class_level'=>3, 'provenance'=>'{}']);
        }
        $game = (new GameSession($campaign, 'metamagic', 'Metamagic'))->setStatus(GameSession::STATUS_LIVE);
        $this->session = new CharacterSessionState($game, $character, $this->initialState());
        $this->em->persist($game); $this->em->persist($this->session); $this->em->flush();
        $this->source = 'acquisition:'.$acquisition->getId();
        $this->cantripSource = 'acquisition:'.$cantripAcquisition->getId();
        $this->executor = $container->get(CharacterSpellCastExecutor::class);
        $this->directory = $container->get(PlayerSpellDirectory::class);
        // Executor owns its transaction. Only temporary tables survive this fixture commit.
        $this->db->commit();
    }

    protected function tearDown(): void
    {
        while ($this->db->isTransactionActive()) $this->db->rollBack();
        $this->em->clear();
        $this->db->close(); // Drops all temporary fixtures, including successful cast receipts.
        $this->kernel->shutdown();
    }

    private function initialState(): array
    {
        return ['resources'=>[
            ['id'=>'spell-slot-1', 'currentValue'=>4], ['id'=>'spell-slot-2', 'currentValue'=>3],
            ['id'=>'warlock-pact-slots', 'currentValue'=>2], ['id'=>'sorcery-points', 'currentValue'=>20],
        ]];
    }

    private function command(?string $short = null, array $overrides = []): array
    {
        return array_replace(['revision'=>$this->session->getRevision(),
            'requestId'=>sprintf('11111111-1111-4111-8111-%012d', ++$this->request),
            'sourceKey'=>$this->source, 'method'=>'standard-slot', 'castingLevel'=>1,
            ...($short === null ? [] : ['metamagic'=>'metamagic-'.$short.'-spell'])], $overrides);
    }

    private function reset(?array $state = null): void
    {
        $this->session->setState($state ?? $this->initialState()); $this->em->flush();
    }

    private function stored(): array
    {
        return $this->db->fetchAssociative('SELECT state, revision, updated_at FROM character_session_state WHERE id=?', [$this->session->getId()]);
    }

    private function reject(array $command, string $reason): void
    {
        $before = $this->stored();
        try { $this->executor->execute($this->session, $command); self::fail('Expected refusal'); }
        catch (HttpExceptionInterface $error) { self::assertSame($reason, $error->getMessage()); }
        self::assertSame($before, $this->stored(), 'No partial cost, receipt or revision');
    }

    public function testAllTenCostsAndDirectoryUseAcquiredChoices(): void
    {
        $entries = $this->directory->forCharacter($this->session->getCharacter(), $this->session->getState());
        self::assertCount(2, $entries);
        foreach (self::COSTS as $short=>$cost) {
            $this->reset();
            $result = $this->executor->execute($this->session, $this->command($short));
            self::assertSame($cost, $result['cast']['metamagic']['cost']);
            self::assertSame(20-$cost, $result['state']['resources'][3]['currentValue']);
            self::assertSame(3, $result['state']['resources'][0]['currentValue']);
            $this->reset();
            $cantrip = $this->executor->execute($this->session, $this->command($short, ['method'=>'cantrip', 'castingLevel'=>0, 'sourceKey'=>$this->cantripSource]));
            self::assertSame($cost, $cantrip['cast']['metamagic']['cost']);
            self::assertSame(4, $cantrip['state']['resources'][0]['currentValue']);
        }
        $this->db->executeStatement("UPDATE character_feature_choice SET active=FALSE WHERE option_key='metamagic-subtle-spell'");
        $this->reject($this->command('subtle'), 'metamagic_not_acquired');
        $this->reject($this->command('invented'), 'metamagic_not_acquired');
    }

    public function testCantripAndPactCostUseActualCastingLevelAcrossClasses(): void
    {
        $result = $this->executor->execute($this->session, $this->command('twinned', ['method'=>'cantrip', 'castingLevel'=>0, 'sourceKey'=>$this->cantripSource]));
        self::assertSame(1, $result['cast']['metamagic']['cost']);
        self::assertSame(4, $result['state']['resources'][0]['currentValue']);
        foreach (['pact-slot', 'standard-slot'] as $method) {
            $this->reset();
            $result = $this->executor->execute($this->session, $this->command('twinned', ['method'=>$method, 'castingLevel'=>2]));
            self::assertSame(2, $result['cast']['metamagic']['cost']);
            self::assertSame(18, $result['state']['resources'][3]['currentValue']);
            self::assertSame($method === 'pact-slot' ? 1 : 2, $result['state']['resources'][$method === 'pact-slot' ? 2 : 1]['currentValue']);
        }
        $this->reject($this->command('twinned', ['method'=>'pact-slot', 'castingLevel'=>1]), 'casting_level_mismatch');
    }

    public function testIncompatibilitiesAndDirectoryReasons(): void
    {
        $cases = [
            ['setRange', 'Self', 'twinned', 'metamagic_self_range'],
            ['setRange', 'Personnelle (cône de 4,50 mètres)', 'distant', 'metamagic_range_too_short'],
            ['setRange', '1 mètre', 'distant', 'metamagic_range_too_short'],
            ['setDuration', 'Instantanée', 'extended', 'metamagic_duration_too_short'],
            ['setDuration', '1 round', 'extended', 'metamagic_duration_too_short'],
            ['setCastingTime', '1 action bonus', 'quickened', 'metamagic_requires_one_action'],
            ['setCastingTime', '1 minute', 'quickened', 'metamagic_requires_one_action'],
        ];
        foreach ($cases as [$setter, $value, $short, $reason]) {
            $this->spell->setRange('Contact')->setDuration('1 minute')->setCastingTime('1 action');
            $this->spell->$setter($value); $this->em->flush();
            $this->reject($this->command($short), $reason);
            $entry = array_values(array_filter($this->directory->forCharacter($this->session->getCharacter(), $this->session->getState()), fn($e)=>$e['key'] === $this->source))[0];
            $options = array_column($entry['castingAvailability']['methods'][0]['metamagics'], null, 'key');
            self::assertSame($reason, $options['metamagic-'.$short.'-spell']['reason']);
        }
        $this->spell->setComponents(false, false, true, 'Fixture material'); $this->em->flush();
        $this->reject($this->command('subtle'), 'metamagic_no_verbal_or_somatic_components');
    }

    public function testResourceFailuresLeaveEverythingUntouched(): void
    {
        foreach ([0, 1, -1, '20', 21, null] as $points) {
            $state = $this->initialState(); $state['resources'][3]['currentValue'] = $points; $this->reset($state);
            $before = $this->stored();
            try { $this->executor->execute($this->session, $this->command('heightened')); self::fail('Expected resource refusal'); }
            catch (HttpExceptionInterface $error) { self::assertSame(422, $error->getStatusCode()); }
            self::assertSame($before, $this->stored());
        }
        $state = $this->initialState(); $state['resources'][] = $state['resources'][3]; $this->reset($state);
        $this->reject($this->command('subtle'), 'sorcery_points_duplicate_counter');
        $state = $this->initialState(); $state['resources'][0]['currentValue'] = 0; $this->reset($state);
        $before = $this->stored();
        try { $this->executor->execute($this->session, $this->command('subtle')); self::fail('Expected empty slot refusal'); }
        catch (HttpExceptionInterface $error) { self::assertSame(422, $error->getStatusCode()); }
        self::assertSame($before, $this->stored());
    }

    public function testReplayRetainsBothCostsAndRejectsChangedMetamagic(): void
    {
        $command = $this->command('subtle');
        $result = $this->executor->execute($this->session, $command);
        $before = $this->stored();
        $this->db->executeStatement('UPDATE character_feature_choice SET active=FALSE');
        $replay = $this->executor->execute($this->session, $command);
        self::assertTrue($replay['replayed']); self::assertSame($result['cast'], $replay['cast']);
        self::assertSame($before, $this->stored());
        $this->reject(array_replace($command, ['metamagic'=>'metamagic-twinned-spell']), 'request_id_conflict');
        unset($command['metamagic']); $this->reject($command, 'request_id_conflict');
    }

    public function testWithoutMetamagicPreservesOldContract(): void
    {
        $command = $this->command();
        $result = $this->executor->execute($this->session, $command);
        self::assertArrayNotHasKey('metamagic', $result['cast']);
        self::assertSame(20, $result['state']['resources'][3]['currentValue']);
        self::assertSame(1, $result['state'][SpellCastReceipts::STATE_KEY][$command['requestId']]['version']);
    }

    public function testFailedPersistenceRollsBackBothDebitsAndReceipt(): void
    {
        $before = $this->stored();
        $this->db->executeStatement("CREATE FUNCTION pg_temp.reject_metamagic() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN RAISE EXCEPTION ''test forced failure''; END'");
        $this->db->executeStatement('CREATE TRIGGER reject_metamagic BEFORE UPDATE ON pg_temp.character_session_state FOR EACH ROW EXECUTE FUNCTION pg_temp.reject_metamagic()');
        try { $this->executor->execute($this->session, $this->command('heightened')); self::fail('Expected failed flush'); }
        catch (\Doctrine\DBAL\Exception $error) { self::assertStringContainsString('test forced failure', $error->getMessage()); }
        self::assertSame($before, $this->stored());
        self::assertFalse($this->em->isOpen(), 'Failed mutable state cannot be reused');
    }

    public function testSharedSorceryPoolRequiresAndConsumesTheCombinedCost(): void
    {
        $featureId = $this->db->fetchOne("SELECT id FROM character_feature_definition WHERE slug='fixture-metamagic'");
        $resourceId = $this->db->fetchOne("SELECT id FROM trackable_resource_definition WHERE slug='sorcery-points'");
        $this->db->insert('feature_spell_grant', ['id'=>500, 'feature_id'=>$featureId, 'spell_id'=>$this->spell->getId(), 'mode'=>'fixed',
            'unlock_level'=>1, 'casting_ability'=>'CHA', 'resource_definition_id'=>$resourceId,
            'casting_rules'=>json_encode(['version'=>1, 'methods'=>[['kind'=>'resource', 'cost'=>2, 'castingLevel'=>1]]])]);
        $this->db->insert('character_feature_spell', ['id'=>500, 'character_id'=>$this->session->getCharacter()->getId(), 'grant_id'=>500, 'spell_id'=>$this->spell->getId()]);
        $state = $this->initialState(); $state['resources'][3]['currentValue'] = 2; $this->reset($state);
        $this->reject($this->command('subtle', ['sourceKey'=>'feature:500', 'method'=>'resource']), 'insufficient_sorcery_points');
        $state['resources'][3]['currentValue'] = 3; $this->reset($state);
        $result = $this->executor->execute($this->session, $this->command('subtle', ['sourceKey'=>'feature:500', 'method'=>'resource']));
        self::assertSame(0, $result['state']['resources'][3]['currentValue']);
        self::assertSame(4, $result['state']['resources'][0]['currentValue']);
    }

    public function testMissingAcquisitionOrInactivePoolCannotBeUsed(): void
    {
        $this->db->executeStatement('UPDATE feature_choice_grant SET enabled=FALSE');
        $this->reject($this->command('subtle'), 'metamagic_not_acquired');
        $this->db->executeStatement('UPDATE feature_choice_grant SET enabled=TRUE');
        $this->db->executeStatement("DELETE FROM trackable_resource_rule WHERE resource_definition_id=(SELECT id FROM trackable_resource_definition WHERE slug='sorcery-points')");
        $this->reject($this->command('subtle'), 'sorcery_points_inactive_pool');
    }

    public function testTwinnedCantripUsesGlobalLevelAndCanonicalIdentity(): void
    {
        $service = $this->kernel->getContainer()->get('test.service_container')->get(\App\Service\CharacterSpellMetamagic::class);
        $context = ['resources'=>[['slug'=>'sorcery-points', 'status'=>'available', 'remaining'=>20]]];
        $method = ['kind'=>'cantrip', 'castingLevel'=>0, 'poolSlug'=>null, 'cost'=>0, 'status'=>'available'];
        foreach ([4, 5, 11, 13, 17] as $level) {
            $options = $service->options(['metamagic-twinned-spell'=>'Twin'], ['importKey'=>'aidedd-2014:decharge-occulte'], $method, $context, $level);
            self::assertSame($level < 5 ? null : 'metamagic_multiple_possible_targets', $options[0]['reason']);
            self::assertSame(1, $options[0]['cost']);
        }
        $character = $this->session->getCharacter();
        // 13 total levels, but only 3 warlock levels: use total, not casting-class level.
        $class = $character->getClassLevels()->first()->getCharacterClass();
        while ($character->getTotalLevel() < 13) {
            $level = new CharacterClassLevel($character, $class, $character->getNextLevelPosition());
            $character->addClassLevel($level); $this->em->persist($level);
        }
        $cantrip = $this->em->getRepository(SpellDefinition::class)->findOneBy(['slug'=>'fixture-cantrip']);
        $cantrip->setImportKey('aidedd-2014:decharge-occulte'); $this->em->flush();
        $this->reject($this->command('twinned', ['sourceKey'=>$this->cantripSource, 'method'=>'cantrip', 'castingLevel'=>0]), 'metamagic_multiple_possible_targets');
        $result = $this->executor->execute($this->session, $this->command('subtle', ['sourceKey'=>$this->cantripSource, 'method'=>'cantrip', 'castingLevel'=>0]));
        self::assertSame(19, $result['state']['resources'][3]['currentValue']);
    }

    public function testHoldMonsterCostsFivePersistentlyAndRejectsUpcastTwinned(): void
    {
        $character = $this->session->getCharacter();
        $class = $character->getClassLevels()->first()->getCharacterClass();
        while ($character->getTotalLevel() < 14) {
            $level = new CharacterClassLevel($character, $class, $character->getNextLevelPosition());
            $character->addClassLevel($level); $this->em->persist($level);
        }
        $this->spell->setImportKey('aidedd-2014:immobilisation-de-monstre')->setLevel(5);
        $this->em->flush();
        $state = $this->initialState();
        $state['resources'][] = ['id'=>'spell-slot-5', 'currentValue'=>2];
        $state['resources'][] = ['id'=>'spell-slot-6', 'currentValue'=>1];
        $this->reset($state);
        $this->reject($this->command('twinned', ['castingLevel'=>6]), 'metamagic_multiple_possible_targets');
        $command = $this->command('twinned', ['castingLevel'=>5]);
        $result = $this->executor->execute($this->session, $command);
        self::assertSame(5, $result['cast']['metamagic']['cost']);
        self::assertSame(15, $result['state']['resources'][3]['currentValue']);
        self::assertSame(1, $result['state']['resources'][4]['currentValue']);
        $stored = json_decode($this->stored()['state'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($result['state']['resources'], $stored['resources']);
        self::assertSame($result['state'][SpellCastReceipts::STATE_KEY], $stored[SpellCastReceipts::STATE_KEY]);
        $this->em->refresh($this->session);
        self::assertSame(15, $this->session->getState()['resources'][3]['currentValue']);
        $before = $this->stored();
        self::assertTrue($this->executor->execute($this->session, $command)['replayed']);
        self::assertSame($before, $this->stored());
        $state['resources'][3]['currentValue'] = 4; $this->reset($state);
        $this->reject($this->command('twinned', ['castingLevel'=>5]), 'insufficient_sorcery_points');
    }
}
