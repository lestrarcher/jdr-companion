<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\{Campaign, Character, CharacterClass, CharacterClassLevel, CharacterClassLevelRule, CharacterFeatureAssignment, CharacterFeatureDefinition, CharacterFeatureRule, CharacterProgression, CharacterRace, CharacterSessionState, CharacterSubclass, GameSession, ProgressionDefinition, User};
use App\Enum\{Ability, FeatureActivationType, HitPointGainMethod, LevelAdvancementChoice};
use App\Service\{CharacterHitPointCalculator, CharacterHitPointStateService, CharacterLevelUpService, CharacterSessionStateFactory, CharacterSessionStateSynchronizer};
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;

/** Real resolver and level-up; application tables and sequences are shadowed in pg_temp. */
final class PermanentHitPointEffectsTest extends TestCase
{
    private \App\Kernel $kernel;
    private EntityManagerInterface $em;
    private Connection $db;
    private Character $character;
    private CharacterClass $sorcerer;
    private CharacterClass $warlock;
    private CharacterSubclass $draconic;
    private CharacterRace $hillDwarf;
    private CharacterFeatureDefinition $resilience;
    private CharacterFeatureDefinition $toughness;

    private function service(string $class): object
    {
        return $this->kernel->getContainer()->get('test.service_container')->get($class);
    }

    protected function setUp(): void
    {
        $this->kernel = new \App\Kernel('test', true);
        $this->kernel->boot();
        $this->em = $this->service('doctrine')->getManager();
        $this->db = $this->em->getConnection();
        $this->db->beginTransaction();
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        foreach ((new SchemaTool($this->em))->getCreateSchemaSql($metadata) as $sql) {
            $this->db->executeStatement(preg_replace(['/^CREATE TABLE /', '/^CREATE SEQUENCE /'], ['CREATE TEMP TABLE ', 'CREATE TEMP SEQUENCE '], $sql));
        }
        foreach (['Version20261108020000', 'Version20261108030000'] as $version) {
            require_once dirname(__DIR__, 2).'/migrations/'.$version.'.php';
            $class = 'DoctrineMigrations\\'.$version;
            foreach ($class::statements() as $sql) {
                $this->db->executeStatement(preg_replace('/^CREATE TABLE /', 'CREATE TEMP TABLE ', $sql));
            }
        }
        foreach (['Version20261022220000', 'Version20261023001000'] as $version) {
            require_once dirname(__DIR__, 2).'/migrations/'.$version.'.php';
            $class = 'DoctrineMigrations\\'.$version;
            $migration = new $class($this->db, new \Psr\Log\NullLogger());
            $migration->up(new \Doctrine\DBAL\Schema\Schema());
            foreach ($migration->getSql() as $query) {
                $this->db->executeStatement(preg_replace('/^CREATE TABLE /', 'CREATE TEMP TABLE ', $query->getStatement()));
            }
        }
        $this->db->executeStatement('ALTER TABLE feature_spell_grant ADD enabled BOOLEAN DEFAULT TRUE, ADD casting_ability VARCHAR(10), ADD casting_rules JSONB, ADD resource_definition_id INT, ADD managed_resource_id INT');
        foreach ([...array_map(static fn ($m) => $m->getTableName(), $metadata), 'feature_spell_grant', 'character_feature_spell', 'feature_choice_group', 'feature_choice_option', 'feature_choice_grant', 'character_feature_choice', 'feature_choice_rule_gate', 'feature_choice_legacy_rule'] as $table) {
            self::assertSame('t', $this->db->fetchOne('SELECT relpersistence FROM pg_class WHERE oid=to_regclass(?)', [$this->db->quoteIdentifier($table)]));
        }
        $owner = (new User())->setEmail('hp@example.invalid')->setPassword('unused');
        $campaign = new Campaign($owner, 'hp', 'HP');
        $this->character = new Character($campaign, 'hero', 'Hero', Character::TYPE_PLAYER);
        $this->character->getAbilityScore(Ability::Constitution)->setBaseValue(14);
        $this->character->getAbilityScore(Ability::Charisma)->setBaseValue(14);
        $this->sorcerer = new CharacterClass('sorcerer', 'Sorcerer', 6, 1);
        $this->warlock = new CharacterClass('warlock', 'Warlock', 8, 20);
        $this->draconic = new CharacterSubclass($this->sorcerer, 'draconic-bloodline', 'Draconic');
        $this->hillDwarf = new CharacterRace('dwarf-hill-phb', 'Hill dwarf');
        $this->resilience = (new CharacterFeatureDefinition('draconic-resilience', 'Resilience'))->configureHitPointEffects([
            ['key' => 'resilience', 'label' => 'Resilience', 'type' => 'per-class-level', 'value' => 1, 'classSlug' => 'sorcerer'],
        ]);
        $this->toughness = (new CharacterFeatureDefinition('racial-dwarf-hill-phb-dwarven-toughness-1', 'Toughness'))->configureHitPointEffects([
            ['key' => 'toughness', 'label' => 'Toughness', 'type' => 'per-total-level', 'value' => 1],
        ]);
        $this->persist($owner, $campaign, $this->character, $this->sorcerer, $this->warlock, $this->draconic, $this->hillDwarf,
            $this->resilience, $this->toughness, CharacterFeatureRule::forSubclass($this->resilience, $this->draconic, 1),
            CharacterFeatureRule::forRace($this->toughness, $this->hillDwarf));
    }

    protected function tearDown(): void
    {
        while ($this->db->isTransactionActive()) $this->db->rollBack();
        $this->em->clear();
        $this->kernel->shutdown();
    }

    private function persist(object ...$entities): void
    {
        foreach ($entities as $entity) $this->em->persist($entity);
        $this->em->flush();
    }

    private function addLevel(CharacterClass $class, ?int $gain = null, bool $missing = false): CharacterClassLevel
    {
        $position = $this->character->getNextLevelPosition();
        $gain ??= $position === 1 ? $class->getHitDie() : intdiv($class->getHitDie(), 2) + 1;
        $level = new CharacterClassLevel($this->character, $class, $position,
            $class === $this->sorcerer ? $this->draconic : null, $missing ? null : $gain,
            $missing ? null : ($position === 1 ? HitPointGainMethod::FirstLevel : HitPointGainMethod::Rolled));
        $this->character->addClassLevel($level);
        $this->persist($level);
        return $level;
    }

    private function session(): CharacterSessionState
    {
        $game = new GameSession($this->character->getCampaign(), 'hp-session', 'HP session');
        $session = new CharacterSessionState($game, $this->character, [
            'hitPoints' => ['current' => 3, 'temporary' => 7, 'maximumAdjustment' => 9],
            'hitDice' => [['id' => 'd8', 'current' => 0]], 'resources' => [], 'progressions' => [],
        ]);
        $this->persist($game, $session);
        return $session;
    }

    public function testAcquisitionMulticlassingDuplicateRulesAndReloads(): void
    {
        $calc = $this->service(CharacterHitPointCalculator::class);
        $this->addLevel($this->warlock);
        self::assertSame(10, $calc->calculate($this->character)->maximumValue);
        $this->addLevel($this->sorcerer);
        $this->addLevel($this->sorcerer);
        $this->character->setRace($this->hillDwarf);
        $this->persist(CharacterFeatureRule::forSubclass($this->resilience, $this->draconic, 2),
            new CharacterFeatureAssignment($this->character, $this->resilience));
        $before = $this->db->fetchAllAssociative('SELECT * FROM character_class_level ORDER BY position');
        for ($i = 0; $i < 3; ++$i) {
            $result = $calc->calculate($this->character);
            self::assertSame(27, $result->maximumValue); // 8+4+4 + 3*2 CON + 2 sorcerer + 3 total.
            self::assertSame(16, $result->baseValue);
            self::assertSame(6, $result->constitutionBonus);
            self::assertSame(5, $result->featureBonus);
            self::assertCount(2, $result->featureContributions);
            $id = $this->character->getId();
            $this->em->flush(); $this->em->clear();
            $this->character = $this->em->find(Character::class, $id);
        }
        self::assertSame($before, $this->db->fetchAllAssociative('SELECT * FROM character_class_level ORDER BY position'));
    }

    public function testFixedAcquiredEffectsAndRemovalDoNotChangeSessionOrRawGains(): void
    {
        $level = $this->addLevel($this->sorcerer);
        $this->character->setRace($this->hillDwarf);
        $fixed = (new CharacterFeatureDefinition('gift', 'Gift'))->configureHitPointEffects([
            ['key' => 'gift', 'label' => 'Gift', 'type' => 'fixed', 'value' => 5],
        ]);
        $this->persist($fixed);
        $calc = $this->service(CharacterHitPointCalculator::class);
        self::assertSame(10, $calc->calculate($this->character)->maximumValue);
        $assignment = new CharacterFeatureAssignment($this->character, $fixed);
        $this->persist($assignment);
        $session = $this->session(); $before = $session->getState();
        self::assertSame(15, $calc->calculate($this->character)->maximumValue);
        self::assertSame(24, $this->service(CharacterHitPointStateService::class)->effectiveMaximum($this->character, $before));
        $fixed->configureHitPointEffects([['key' => 'gift', 'label' => 'Replacement', 'type' => 'fixed', 'value' => 2]]);
        self::assertSame(12, $calc->calculate($this->character)->maximumValue);
        $this->em->remove($assignment); $this->em->flush();
        $level->setSubclass(null); $this->character->setRace(null);
        self::assertSame(8, $calc->calculate($this->character)->maximumValue);
        self::assertSame($before['hitPoints'], $this->service(CharacterSessionStateSynchronizer::class)->synchronize($this->character, $before)['hitPoints']);
        self::assertSame($before, $session->getState());
        self::assertSame(6, $level->getHitPointGain());
    }

    public function testConstitutionRetroactivityAndPerLevelMinimumRemainUnchanged(): void
    {
        $this->addLevel($this->sorcerer); $this->addLevel($this->sorcerer, 1);
        $calc = $this->service(CharacterHitPointCalculator::class);
        foreach ([14 => 13, 16 => 15, 6 => 7] as $constitution => $maximum) {
            $this->character->getAbilityScore(Ability::Constitution)->setBaseValue($constitution);
            $result = $calc->calculate($this->character);
            self::assertSame($maximum, $result->maximumValue);
            self::assertSame(7, $result->baseValue);
            self::assertSame(2, $result->featureBonus);
        }
    }

    public function testChosenBonusUsesExistingSelectionResolverAndAnyExplicitClass(): void
    {
        $this->addLevel($this->warlock);
        $source = new CharacterFeatureDefinition('choice-source', 'Choice source');
        $option = (new CharacterFeatureDefinition('hp-option', 'HP option'))->configureHitPointEffects([
            ['key' => 'warlock', 'label' => 'Warlock bonus', 'type' => 'per-class-level', 'value' => 2, 'classSlug' => 'warlock'],
            ['key' => 'sorcerer', 'label' => 'Sorcerer bonus', 'type' => 'per-class-level', 'value' => 3, 'classSlug' => 'sorcerer'],
        ]);
        $rule = CharacterFeatureRule::forClass($source, $this->warlock, 1);
        $this->persist($source, $option, $rule);
        $this->db->insert('feature_choice_group', ['key' => 'hp', 'name' => 'HP']);
        $this->db->insert('feature_choice_option', ['key' => 'hp-option', 'group_key' => 'hp', 'feature_id' => $option->getId()]);
        $this->db->insert('feature_choice_grant', ['key' => 'hp-grant', 'group_key' => 'hp', 'rule_id' => $rule->getId(), 'choice_count' => 1]);
        $calc = $this->service(CharacterHitPointCalculator::class);
        self::assertSame(10, $calc->calculate($this->character)->maximumValue);
        $this->db->insert('character_feature_choice', ['key' => 'hp-choice', 'character_id' => $this->character->getId(),
            'grant_key' => 'hp-grant', 'option_key' => 'hp-option', 'slot' => 1, 'acquired_position' => 1,
            'acquired_class_level' => 1, 'provenance' => '{}']);
        self::assertSame(12, $calc->calculate($this->character)->maximumValue);
        $this->persist(new CharacterFeatureAssignment($this->character, $option));
        self::assertSame(12, $calc->calculate($this->character)->maximumValue);
        $this->addLevel($this->sorcerer);
        self::assertSame(22, $calc->calculate($this->character)->maximumValue); // 16 base + 1 dragon + 2 warlock + 3 sorcerer.
    }

    public function testBulkHistoryAndSequentialLevelUpsProduceTheSameMaximum(): void
    {
        for ($i = 0; $i < 5; ++$i) $this->addLevel($this->sorcerer);
        $calc = $this->service(CharacterHitPointCalculator::class);
        self::assertSame(37, $calc->calculate($this->character)->maximumValue);
        $other = new Character($this->character->getCampaign(), 'sequential', 'Sequential', Character::TYPE_PLAYER);
        $other->getAbilityScore(Ability::Constitution)->setBaseValue(14);
        $this->persist($other);
        for ($i = 0; $i < 5; ++$i) {
            $this->service(CharacterLevelUpService::class)->levelUp($other, $this->sorcerer, $this->draconic);
        }
        self::assertSame($calc->calculate($this->character)->toArray(), $calc->calculate($other)->toArray());
    }

    public function testExplicitProgressionContextAndPassiveEffectsOnly(): void
    {
        $this->addLevel($this->warlock);
        $progression = (new ProgressionDefinition('story', 'Story'))->setMinimumValue(3);
        $assignment = new CharacterProgression($this->character, $progression);
        $this->character->addProgression($assignment);
        $feature = (new CharacterFeatureDefinition('story-gift', 'Gift'))->configureHitPointEffects([
            ['key' => 'gift', 'label' => 'Gift', 'type' => 'fixed', 'value' => 5],
        ]);
        $this->persist($progression, $assignment, $feature, CharacterFeatureRule::forProgression($feature, $progression, 3));
        $calc = $this->service(CharacterHitPointCalculator::class);
        self::assertSame(10, $calc->calculate($this->character)->maximumValue);
        self::assertSame(10, $calc->calculate($this->character, ['story' => 2])->maximumValue);
        self::assertSame(15, $calc->calculate($this->character, ['story' => 3])->maximumValue);
        self::assertSame(15, $this->service(CharacterSessionStateFactory::class)->create($this->character)['hitPoints']['current']);
        $state = ['hitPoints' => ['current' => 3, 'maximumAdjustment' => 9], 'progressions' => [['id' => 'story', 'currentValue' => 3]]];
        self::assertSame(24, $this->service(CharacterHitPointStateService::class)->effectiveMaximum($this->character, $state));
        self::assertSame(15, $this->service(CharacterSessionStateSynchronizer::class)->snapshot($this->character, ['story' => 3])['hitPoints']);
        $feature->setActivationType(FeatureActivationType::Action);
        self::assertSame(10, $calc->calculate($this->character, ['story' => 3])->maximumValue);
    }

    public function testLevelUpAddsOnlyMaximumDeltaIncludingAcquisitionAndLaterLevels(): void
    {
        $this->addLevel($this->warlock);
        $this->character->setRace($this->hillDwarf);
        $session = $this->session();
        $up = $this->service(CharacterLevelUpService::class);
        $up->levelUp($this->character, $this->sorcerer, $this->draconic);
        self::assertSame(['current' => 11, 'temporary' => 7, 'maximumAdjustment' => 9], $session->getState()['hitPoints']);
        $up->levelUp($this->character, $this->sorcerer);
        self::assertSame(19, $session->getState()['hitPoints']['current']);
        $up->levelUp($this->character, $this->warlock);
        self::assertSame(27, $session->getState()['hitPoints']['current']); // 5+2 CON+1 dwarf, no dragon bonus.
        self::assertSame(35, $this->service(CharacterHitPointCalculator::class)->calculate($this->character)->maximumValue);
        self::assertSame(9, $session->getState()['hitPoints']['maximumAdjustment']);
    }

    public function testLevelUpWithMissingHistoryStillIncludesKnownFeatureDelta(): void
    {
        $this->addLevel($this->warlock, missing: true);
        $this->character->setRace($this->hillDwarf);
        $session = $this->session();
        $this->service(CharacterLevelUpService::class)->levelUp($this->character, $this->sorcerer, $this->draconic);
        $result = $this->service(CharacterHitPointCalculator::class)->calculate($this->character);
        self::assertNull($result->maximumValue);
        self::assertSame([1], $result->missingLevelPositions);
        self::assertSame(3, $result->featureBonus);
        self::assertSame(['current' => 11, 'temporary' => 7, 'maximumAdjustment' => 9], $session->getState()['hitPoints']);
        self::assertNull($this->character->getClassLevels()->first()->getHitPointGain());
    }

    public function testAsiLevelUpCombinesConstitutionRetroactivityAndFeatureGrowthOnce(): void
    {
        for ($i = 0; $i < 3; ++$i) $this->addLevel($this->sorcerer);
        $this->persist(new CharacterClassLevelRule($this->sorcerer, 4, LevelAdvancementChoice::AbilityScoreImprovementOrFeat));
        $session = $this->session();
        $this->service(CharacterLevelUpService::class)->levelUp($this->character, $this->sorcerer,
            advancement: \App\Dto\LevelAdvancementSelection::increaseOneAbility(Ability::Constitution));
        self::assertSame(14, $session->getState()['hitPoints']['current']); // 3 + 7 new HP + 3 retroactive CON + 1 dragon.
        self::assertSame(34, $this->service(CharacterHitPointCalculator::class)->calculate($this->character)->maximumValue);
    }

    public function testMigrationOnlyConfiguresOfficialEffectsAndPreservesHistoryAndState(): void
    {
        $this->addLevel($this->sorcerer);
        $session = $this->session();
        $beforeLevels = $this->db->fetchAllAssociative('SELECT * FROM character_class_level');
        $beforeStates = $this->db->fetchAllAssociative('SELECT * FROM character_session_state');
        $this->db->executeStatement('ALTER TABLE character_feature_definition DROP COLUMN hit_point_effects');
        require_once dirname(__DIR__, 2).'/migrations/Version20261122040000.php';
        $migration = new \DoctrineMigrations\Version20261122040000($this->db, new \Psr\Log\NullLogger());
        $migration->up(new \Doctrine\DBAL\Schema\Schema());
        foreach ($migration->getSql() as $query) $this->db->executeStatement($query->getStatement());
        $this->em->refresh($this->resilience); $this->em->refresh($this->toughness);
        self::assertSame('sorcerer', $this->resilience->getHitPointEffects()[0]['classSlug']);
        self::assertSame('per-total-level', $this->toughness->getHitPointEffects()[0]['type']);
        self::assertSame(9, $this->service(CharacterHitPointCalculator::class)->calculate($this->character)->maximumValue);
        self::assertSame($beforeLevels, $this->db->fetchAllAssociative('SELECT * FROM character_class_level'));
        self::assertSame($beforeStates, $this->db->fetchAllAssociative('SELECT * FROM character_session_state'));
        self::assertSame(3, $session->getState()['hitPoints']['current']);
        // Replaying data statements neither overwrites custom configuration nor targets personal references.
        $this->db->executeStatement('UPDATE character_feature_definition SET hit_point_effects=NULL, origin=?, owner_id=? WHERE id=?',
            ['CUSTOM', $this->character->getCampaign()->getOwner()->getId(), $this->resilience->getId()]);
        $custom = '[{"key":"custom","label":"Custom","type":"fixed","value":9}]';
        $this->db->executeStatement('UPDATE character_feature_definition SET hit_point_effects=? WHERE id=?', [$custom, $this->toughness->getId()]);
        foreach (array_slice($migration->getSql(), 2) as $query) $this->db->executeStatement($query->getStatement());
        self::assertNull($this->db->fetchOne('SELECT hit_point_effects FROM character_feature_definition WHERE id=?', [$this->resilience->getId()]));
        self::assertSame($custom, $this->db->fetchOne('SELECT hit_point_effects FROM character_feature_definition WHERE id=?', [$this->toughness->getId()]));
    }
}
