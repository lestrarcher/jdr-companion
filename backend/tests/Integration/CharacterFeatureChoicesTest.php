<?php

declare(strict_types=1);
namespace App\Tests\Integration;

use App\Entity\{Campaign, Character, CharacterClass, CharacterClassLevel, CharacterFeatureDefinition, CharacterFeatureRule, CharacterFeatureAssignment, User};
use App\Service\{CharacterFeatureChoices, CharacterFeatureResolver, FeatureChoiceReader};
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class CharacterFeatureChoicesTest extends TestCase
{
    private \App\Kernel $kernel;
    private \Doctrine\ORM\EntityManagerInterface $em;
    private \Doctrine\DBAL\Connection $db;
    private CharacterFeatureChoices $service;
    private CharacterFeatureResolver $resolver;
    private Character $character;
    private CharacterClass $class;
    private User $owner;
    private array $features = [];

    protected function setUp(): void
    {
        $this->kernel = new \App\Kernel('test', true);
        $this->kernel->boot();
        $container = $this->kernel->getContainer()->get('test.service_container');
        $this->em = $container->get('doctrine')->getManager();
        $this->db = $this->em->getConnection();
        $this->db->beginTransaction();
        foreach ((new SchemaTool($this->em))->getCreateSchemaSql($this->em->getMetadataFactory()->getAllMetadata()) as $sql) {
            $this->db->executeStatement(preg_replace(['/^CREATE TABLE /','/^CREATE SEQUENCE /'], ['CREATE TEMP TABLE ','CREATE TEMP SEQUENCE '], $sql));
        }
        require_once dirname(__DIR__,2).'/migrations/Version20261108020000.php';
        foreach (\DoctrineMigrations\Version20261108020000::statements() as $sql) {
            $this->db->executeStatement(preg_replace('/^CREATE TABLE /','CREATE TEMP TABLE ', $sql));
        }
        require_once dirname(__DIR__,2).'/migrations/Version20261108030000.php';
        foreach (\DoctrineMigrations\Version20261108030000::statements() as $sql) {
            $this->db->executeStatement(preg_replace('/^CREATE TABLE /','CREATE TEMP TABLE ', $sql));
        }
        foreach (['Version20261022220000','Version20261023001000'] as $version) {
            require_once dirname(__DIR__,2).'/migrations/'.$version.'.php';
            $class = 'DoctrineMigrations\\'.$version;
            $migration = new $class($this->db,new \Psr\Log\NullLogger());
            $migration->up(new \Doctrine\DBAL\Schema\Schema());
            foreach ($migration->getSql() as $query) $this->db->executeStatement(preg_replace('/^CREATE TABLE /','CREATE TEMP TABLE ',$query->getStatement()));
        }
        $this->db->executeStatement('ALTER TABLE feature_spell_grant ADD enabled BOOLEAN DEFAULT TRUE, ADD casting_ability VARCHAR(10), ADD casting_rules JSONB, ADD resource_definition_id INT, ADD managed_resource_id INT');
        foreach (['character','character_feature_definition','character_feature_rule','character_feature_choice','feature_choice_group','feature_choice_option','feature_choice_grant','feature_spell_grant','character_feature_spell'] as $table) {
            self::assertSame('t',$this->db->fetchOne('SELECT relpersistence FROM pg_class WHERE oid=to_regclass(?)',[$table]));
        }
        $this->service = $container->get(CharacterFeatureChoices::class);
        $this->resolver = $container->get(CharacterFeatureResolver::class);
        $this->owner = (new User())->setEmail('choices@example.invalid')->setPassword('unused');
        $campaign = new Campaign($this->owner,'choices','Choices');
        $this->character = new Character($campaign,'hero','Hero',Character::TYPE_PLAYER);
        $this->class = new CharacterClass('fixture-class','Fixture',8,3);
        foreach ([$this->owner,$campaign,$this->character,$this->class] as $entity) $this->em->persist($entity);
        $this->addLevel();
        foreach (['source','a','b','c'] as $slug) {
            $this->features[$slug] = new CharacterFeatureDefinition($slug,strtoupper($slug));
            $this->em->persist($this->features[$slug]);
        }
        $this->em->flush();
        $this->db->insert('feature_choice_group',['key'=>'family','name'=>'Family']);
        foreach (['a','b','c'] as $key) $this->db->insert('feature_choice_option',['key'=>$key,'group_key'=>'family','feature_id'=>$this->features[$key]->getId()]);
        $this->grant('first',1,1);
    }
    protected function tearDown(): void
    {
        while ($this->db->isTransactionActive()) $this->db->rollBack();
        $this->em->clear(); $this->kernel->shutdown();
    }
    private function addLevel(?CharacterClass $class = null): CharacterClassLevel
    {
        $level = new CharacterClassLevel($this->character,$class ?? $this->class,$this->character->getNextLevelPosition());
        $this->character->addClassLevel($level); $this->em->persist($level); $this->em->flush();
        return $level;
    }
    private function grant(string $key,int $level,int $count): void
    {
        $rule = CharacterFeatureRule::forClass($this->features['source'],$this->class,$level);
        $this->em->persist($rule); $this->em->flush();
        $this->db->insert('feature_choice_grant',['key'=>$key,'group_key'=>'family','rule_id'=>$rule->getId(),'choice_count'=>$count]);
    }
    private function save(array $rows): array
    {
        return $this->service->regularize($this->character,$this->owner,$this->service->preview($this->character)['fingerprint'],$rows,'Test repair');
    }
    private function selection(string $option='a',string $grant='first',int $slot=1): array
    {
        return ['grantKey'=>$grant,'optionKey'=>$option,'slot'=>$slot];
    }
    private function catalogue(bool $apply = true): \App\Service\Catalogue\FeatureChoiceCatalogue
    {
        $catalogue = new \App\Service\Catalogue\FeatureChoiceCatalogue($this->db, dirname(__DIR__, 2));
        $this->em->persist(new \App\Entity\TrackableResourceDefinition('sorcery-points','Sorcery points',\App\Enum\ResourceRechargeType::LongRest,\App\Enum\ResourceMaximumType::Fixed,20));
        $this->em->flush();
        foreach ($catalogue->load()['grants'] as $grant) {
            $class = $this->em->getRepository(CharacterClass::class)->findOneBy(['slug'=>$grant['class']]);
            if (!$class) { $class = new CharacterClass($grant['class'], $grant['class'], 8, 3); $this->em->persist($class); $this->em->flush(); }
            if ($grant['subclass'] && !$this->em->getRepository(\App\Entity\CharacterSubclass::class)->findOneBy(['slug'=>$grant['subclass']])) {
                $this->em->persist(new \App\Entity\CharacterSubclass($class, $grant['subclass'], $grant['subclass']));
            }
            if (!$this->em->getRepository(CharacterFeatureDefinition::class)->findOneBy(['slug'=>$grant['feature']])) {
                $this->em->persist(new CharacterFeatureDefinition($grant['feature'], $grant['feature']));
            }
            $this->em->flush();
        }
        // Existing level-3 identities, as in the real class catalogue; no automatic option rules by default.
        foreach (['bear','eagle','wolf','elk','tiger'] as $animal) {
            $this->em->persist(new CharacterFeatureDefinition('totem-warrior-'.$animal, $animal));
        }
        $this->em->flush();
        if ($apply) $catalogue->run(true);
        return $catalogue;
    }

    private function levels(string $slug, int $count, ?string $subclass = null): void
    {
        $class = $this->em->getRepository(CharacterClass::class)->findOneBy(['slug'=>$slug]);
        $sub = $subclass ? $this->em->getRepository(\App\Entity\CharacterSubclass::class)->findOneBy(['slug'=>$subclass]) : null;
        for ($i=0; $i<$count; ++$i) {
            $level = new CharacterClassLevel($this->character, $class, $this->character->getNextLevelPosition(), $sub);
            $this->character->addClassLevel($level); $this->em->persist($level);
        }
        $this->em->flush();
    }

    private function pick(string $group, int $level, string $feature, int $slot = 1): array
    {
        return ['grantKey'=>$group.'-'.$level, 'optionKey'=>$group.'-'.$feature, 'slot'=>$slot];
    }

    public function testConcreteCatalogueIsReadOnlyInPreviewAndIdempotentAfterInstallation(): void
    {
        $catalogue = $this->catalogue(false);
        $before = $this->db->fetchOne('SELECT count(*) FROM character_feature_definition');
        self::assertNotEmpty($catalogue->run()['created']);
        self::assertSame($before, $this->db->fetchOne('SELECT count(*) FROM character_feature_definition'));
        $catalogue->run(true);
        self::assertSame([], $catalogue->run()['created']);
        self::assertSame([], $catalogue->run(true)['created']);
        self::assertSame(54, (int)$this->db->fetchOne("SELECT count(*) FROM feature_choice_option WHERE group_key='eldritch-invocations'"));
        self::assertSame(10, (int)$this->db->fetchOne("SELECT count(*) FROM feature_choice_option WHERE group_key='metamagic'"));
        self::assertSame(0, (int)$this->db->fetchOne('SELECT count(*) FROM character_feature_choice'));
    }

    public function testCatalogueConflictDoesNotOverwritePersonalConfiguration(): void
    {
        $catalogue = $this->catalogue();
        $this->db->executeStatement("UPDATE feature_choice_group SET replacement='class-level' WHERE key='metamagic'");
        $this->expectException(\DomainException::class);
        $catalogue->run(true);
    }

    public function testConcreteStylesExcludeOtherClassOptionsAndDeduplicateManualDefense(): void
    {
        $this->catalogue(); $this->levels('paladin', 2);
        $feature = $this->em->getRepository(CharacterFeatureDefinition::class)->findOneBy(['slug'=>'fighting-style-defense']);
        $this->em->persist(new CharacterFeatureAssignment($this->character, $feature)); $this->em->flush();
        $this->save([$this->pick('fighting-style-paladin', 2, 'fighting-style-defense')]);
        $resolved = $this->resolver->resolve($this->character);
        self::assertArrayHasKey('fighting-style-defense', $resolved);
        self::assertCount(1, array_filter($resolved, fn ($r) => $r->getFeatureDefinition() === $feature));
        self::assertSame(1, (int)$this->db->fetchOne('SELECT count(*) FROM character_feature_assignment'));
        $grants = array_column($this->service->preview($this->character)['grants'], null, 'key');
        self::assertNotContains('fighting-style-archery', array_column($grants['fighting-style-paladin-2']['options'], 'featureKey'));
        self::assertSame(['armor'], $feature->getArmorClassEffects()[0]['conditions']);
        $profile = $this->kernel->getContainer()->get('test.service_container')->get(\App\Service\CharacterProfileSerializer::class);
        self::assertSame(10, $profile->serialize($this->character)['armorClass']['value']);
        $armor = new \App\Entity\MagicItem(null, 'Fixture armor', null, $this->owner);
        $armor->classify('armor', true, true, 'armor', null);
        $armor->configureArmor('light',11);
        $owned = new \App\Entity\CharacterMagicItem($this->character, $armor);
        $this->character->addMagicItem($owned); $owned->setEquipped(true);
        $this->em->persist($armor); $this->em->persist($owned); $this->em->flush();
        self::assertSame(12, $profile->serialize($this->character)['armorClass']['value']);
        $owned->setEquipped(false);
        self::assertSame(10, $profile->serialize($this->character)['armorClass']['value']);
    }

    public function testInvocationCantripPrerequisiteUsesActiveFeatureSpellAcquisition(): void
    {
        $this->catalogue(); $this->levels('warlock',2);
        $spell = new \App\Entity\SpellDefinition('eldritch-blast','Fixture blast',0,\App\Enum\SpellSchool::cases()[0],'1 action','36 m','Instant','Fixture');
        $this->em->persist($spell); $this->em->flush();
        $this->db->insert('feature_spell_grant',['feature_id'=>$this->features['source']->getId(),'spell_id'=>$spell->getId(),'mode'=>'fixed','casting_ability'=>'CHA']);
        $this->kernel->getContainer()->get('test.service_container')->get(\App\Service\CharacterFeatureSpellService::class)->synchronize($this->character);
        $this->save([$this->pick('eldritch-invocations',2,'eldritch-invocation-agonizing-blast'),$this->pick('eldritch-invocations',2,'eldritch-invocation-repelling-blast',2)]);
        self::assertArrayHasKey('eldritch-invocation-agonizing-blast',$this->resolver->resolve($this->character));
        $this->db->executeStatement('UPDATE feature_spell_grant SET enabled=false');
        self::assertArrayNotHasKey('eldritch-invocation-agonizing-blast',$this->resolver->resolve($this->character));
        self::assertCount(2,$this->service->preview($this->character)['issues']);
        self::assertSame(2,(int)$this->db->fetchOne('SELECT count(*) FROM character_feature_choice'));
    }

    public function testInvocationReplacementAtNewWarlockLevelPreservesItsHistory(): void
    {
        $this->catalogue(); $this->levels('warlock',2);
        $result = $this->save([$this->pick('eldritch-invocations',2,'eldritch-invocation-armor-of-shadows'),$this->pick('eldritch-invocations',2,'eldritch-invocation-devils-sight',2)]);
        $old = array_values(array_filter($result['history'], fn ($r) => $r['slot']===1))[0];
        $before = $this->service->preview($this->character);
        $this->levels('warlock',1);
        $level = $this->character->getClassLevels()->last();
        $this->service->applyLevel($this->character,$level,$before,[
            $this->pick('pact-boon',3,'pact-of-the-chain'),
            $this->pick('eldritch-invocations',2,'eldritch-invocation-voice-of-the-chain-master')+['replacesKey'=>$old['key']],
        ]);
        self::assertArrayNotHasKey('eldritch-invocation-armor-of-shadows',$this->resolver->resolve($this->character));
        self::assertArrayHasKey('eldritch-invocation-voice-of-the-chain-master',$this->resolver->resolve($this->character));
        self::assertFalse($this->db->fetchOne('SELECT active FROM character_feature_choice WHERE key=?',[$old['key']]));
        self::assertSame(4,(int)$this->db->fetchOne('SELECT count(*) FROM character_feature_choice'));
    }

    public function testMetamagicQuotasAndOptionalReplacementWindow(): void
    {
        $this->catalogue(); $this->levels('sorcerer', 3);
        $saved = $this->save([$this->pick('metamagic', 3, 'metamagic-subtle-spell'), $this->pick('metamagic', 3, 'metamagic-quickened-spell', 2)]);
        $reader = new FeatureChoiceReader($this->em);
        $this->levels('sorcerer', 1);
        $g = array_column($this->service->preview($this->character)['grants'], null, 'key')['metamagic-3'];
        self::assertFalse($reader->replacementAllowed($this->character, $g, 'sorcerer', [], [$g]));
        $flag = $this->em->getRepository(CharacterFeatureDefinition::class)->findOneBy(['slug'=>'sorcerous-versatility']);
        $this->em->persist(new CharacterFeatureAssignment($this->character, $flag)); $this->em->flush();
        $g = array_column($this->service->preview($this->character)['grants'], null, 'key')['metamagic-3'];
        self::assertTrue($reader->replacementAllowed($this->character, $g, 'sorcerer', [], [$g]));
        $this->levels('sorcerer', 6);
        $grants = array_filter($this->service->preview($this->character)['grants'], fn ($r) => $r['group_key']==='metamagic');
        self::assertSame(3, array_sum(array_column($grants, 'choice_count')));
        $this->levels('sorcerer', 7);
        $grants = array_filter($this->service->preview($this->character)['grants'], fn ($r) => $r['group_key']==='metamagic');
        self::assertSame(4, array_sum(array_column($grants, 'choice_count')));
    }

    public function testInvocationPactDependenciesAndClassLevelQuotas(): void
    {
        $this->catalogue(); $this->levels('warlock', 5);
        $this->save([$this->pick('pact-boon',3,'pact-of-the-blade'),
            $this->pick('eldritch-invocations',2,'eldritch-invocation-thirsting-blade'),
            $this->pick('eldritch-invocations',2,'eldritch-invocation-armor-of-shadows',2),
            $this->pick('eldritch-invocations',5,'eldritch-invocation-improved-pact-weapon')]);
        self::assertArrayHasKey('eldritch-invocation-thirsting-blade',$this->resolver->resolve($this->character));
        $g = array_column($this->service->preview($this->character)['grants'], null, 'key')['eldritch-invocations-2'];
        $opts = array_column($g['options'], null, 'featureKey');
        self::assertContains('classLevels:warlock', $opts['eldritch-invocation-lifedrinker']['reasons']);
        self::assertContains('spellsKnown:eldritch-blast', $opts['eldritch-invocation-agonizing-blast']['reasons']);
        self::assertContains('features:pact-of-the-chain', $opts['eldritch-invocation-voice-of-the-chain-master']['reasons']);
        $this->levels('warlock',13);
        $grants = array_filter($this->service->preview($this->character)['grants'], fn ($r) => $r['group_key']==='eldritch-invocations');
        self::assertSame(8, array_sum(array_column($grants,'choice_count')));
    }

    public function testTotemChoicesAreIndependentAtEachTier(): void
    {
        $this->catalogue(); $this->levels('barbarian',14,'totem-warrior');
        $this->save([$this->pick('totem-3',3,'totem-warrior-bear'), $this->pick('totem-6',6,'totem-warrior-eagle-6'), $this->pick('totem-14',14,'totem-warrior-wolf-14')]);
        $resolved = $this->resolver->resolve($this->character);
        foreach (['totem-warrior-bear','totem-warrior-eagle-6','totem-warrior-wolf-14'] as $key) self::assertArrayHasKey($key,$resolved);
        self::assertArrayNotHasKey('totem-warrior-tiger',$resolved);
        foreach ($this->service->preview($this->character)['grants'] as $g) if (str_starts_with($g['key'],'totem-')) {
            self::assertCount(5,$g['options']); self::assertSame('none',$g['replacement']);
        }
    }

    public function testChampionAndFighterShareOneOptionalReplacementPerLevel(): void
    {
        $this->catalogue(); $this->levels('fighter',11,'champion');
        $saved = $this->save([$this->pick('fighting-style-fighter',1,'fighting-style-defense'),$this->pick('fighting-style-champion',10,'fighting-style-dueling')]);
        $flag = $this->em->getRepository(CharacterFeatureDefinition::class)->findOneBy(['slug'=>'martial-versatility-fighter']);
        $this->em->persist(new CharacterFeatureAssignment($this->character,$flag)); $this->em->flush();
        $before = $this->service->preview($this->character);
        $this->levels('fighter',1,'champion');
        $old = array_column($saved['history'], null, 'grant_key');
        try {
            $this->service->applyLevel($this->character,$this->character->getClassLevels()->last(),$before,[
                $this->pick('fighting-style-fighter',1,'fighting-style-archery')+['replacesKey'=>$old['fighting-style-fighter-1']['key']],
                $this->pick('fighting-style-champion',10,'fighting-style-great-weapon-fighting')+['replacesKey'=>$old['fighting-style-champion-10']['key']],
            ]);
            self::fail('Two style replacements accepted');
        } catch (\DomainException $error) {
            self::assertStringContainsString('déjà utilisé',$error->getMessage());
            self::assertSame(2,(int)$this->db->fetchOne('SELECT count(*) FROM character_feature_choice WHERE active'));
            self::assertSame(2,(int)$this->db->fetchOne('SELECT count(*) FROM character_feature_choice'));
        }
    }

    public function testLegacyAutomaticTotemsArePreservedUntilExplicitRegularization(): void
    {
        $catalogue = $this->catalogue(false); $this->levels('barbarian',3,'totem-warrior');
        $sub = $this->em->getRepository(\App\Entity\CharacterSubclass::class)->findOneBy(['slug'=>'totem-warrior']);
        foreach (['bear','wolf'] as $animal) {
            $feature = $this->em->getRepository(CharacterFeatureDefinition::class)->findOneBy(['slug'=>'totem-warrior-'.$animal]);
            $this->em->persist(CharacterFeatureRule::forSubclass($feature,$sub,3));
        }
        $this->em->flush(); $catalogue->run(true);
        self::assertSame(2,(int)$this->db->fetchOne('SELECT count(*) FROM feature_choice_legacy_rule'));
        self::assertArrayHasKey('totem-warrior-wolf',$this->resolver->resolve($this->character));
        $this->save([$this->pick('totem-3',3,'totem-warrior-bear')]);
        self::assertArrayNotHasKey('totem-warrior-wolf',$this->resolver->resolve($this->character));
        self::assertArrayHasKey('totem-warrior-bear',$this->resolver->resolve($this->character));
        self::assertSame([], $catalogue->run()['created']);
        self::assertSame(2,(int)$this->db->fetchOne('SELECT count(*) FROM feature_choice_legacy_rule'));
    }

    public function testExistingCharacterPreviewDoesNotWriteAndSingleChoiceFlowsToBothResolvers(): void
    {
        self::assertSame(['first'],$this->service->preview($this->character)['missing']);
        self::assertSame(0,(int)$this->db->fetchOne('SELECT count(*) FROM character_feature_choice'));
        $result=$this->save([$this->selection()]);
        self::assertSame([],$result['missing']);
        self::assertArrayHasKey('a',$this->resolver->resolve($this->character));
        self::assertCount(2,$this->resolver->acquisitionRules($this->character));
        self::assertNotNull($this->resolver->resolve($this->character)['a']->getChoiceProvenance());
        self::assertSame(0,(int)$this->db->fetchOne('SELECT count(*) FROM character_feature_assignment'));
    }
    public function testCumulativeRightsUseClassLevelNotGlobalLevel(): void
    {
        $this->grant('second',2,2);
        $other = new CharacterClass('other','Other',8,3); $this->em->persist($other);
        $this->addLevel($other);
        self::assertCount(1,$this->service->preview($this->character)['grants']);
        $this->addLevel();
        self::assertCount(2,$this->service->preview($this->character)['grants']);
        $this->save([$this->selection(),$this->selection('b','second',1),$this->selection('c','second',2)]);
        self::assertCount(4,$this->resolver->resolve($this->character));
    }
    public function testDuplicateOptionIsRejectedAtomically(): void
    {
        $this->db->update('feature_choice_grant',['choice_count'=>2],['key'=>'first']);
        try { $this->save([$this->selection(),$this->selection('a','first',2)]); self::fail(); }
        catch (\DomainException) { self::assertSame(0,(int)$this->db->fetchOne('SELECT count(*) FROM character_feature_choice')); }
    }
    public function testStaleDoubleSubmissionIsRejected(): void
    {
        $fingerprint=$this->service->preview($this->character)['fingerprint'];
        $this->save([$this->selection()]);
        $this->expectException(ConflictHttpException::class);
        $this->service->regularize($this->character,$this->owner,$fingerprint,[$this->selection()],'duplicate');
    }
    public function testPrerequisitesPermitSameBatchDependenciesButRejectCycles(): void
    {
        $this->db->update('feature_choice_grant',['choice_count'=>2],['key'=>'first']);
        $this->db->update('feature_choice_option',['prerequisites'=>'{"features":["a"],"classLevels":{"fixture-class":1}}'],['key'=>'b']);
        $this->save([$this->selection('b','first',1),$this->selection('a','first',2)]);
        self::assertArrayHasKey('b',$this->resolver->resolve($this->character));
        $this->db->update('feature_choice_option',['prerequisites'=>'{"features":["b"]}'],['key'=>'a']);
        self::assertArrayNotHasKey('a',$this->resolver->resolve($this->character));
        self::assertArrayNotHasKey('b',$this->resolver->resolve($this->character));
        self::assertCount(2,$this->service->preview($this->character)['issues']);
    }
    public function testSourceRetirementPreservesManualProvenanceAndHistory(): void
    {
        $this->save([$this->selection()]);
        $this->em->persist(new CharacterFeatureAssignment($this->character,$this->features['a']));
        $this->em->flush();
        self::assertCount(2,$this->resolver->resolve($this->character));
        $this->db->update('feature_choice_grant',['enabled'=>false],['key'=>'first'],['enabled'=>'boolean']);
        self::assertArrayHasKey('a',$this->resolver->resolve($this->character));
        self::assertCount(1,$this->service->preview($this->character)['history']);
        self::assertCount(1,$this->service->preview($this->character)['issues']);
    }
    public function testReplacementRetainsHistoryAndConsumesOnlyOneLevelEvent(): void
    {
        $old=$this->save([$this->selection()])['history'][0]['key'];
        $this->db->update('feature_choice_group',['replacement'=>'class-level'],['key'=>'family']);
        $before=$this->service->preview($this->character);
        $level=$this->addLevel();
        $this->service->applyLevel($this->character,$level,$before,[...[$this->selection('b')+['replacesKey'=>$old]]]);
        self::assertArrayNotHasKey('a',$this->resolver->resolve($this->character));
        self::assertArrayHasKey('b',$this->resolver->resolve($this->character));
        self::assertCount(2,$this->service->preview($this->character)['history']);
    }
    public function testNewLevelCannotLeaveNewRightsIncomplete(): void
    {
        $this->grant('second',2,1);
        $before=$this->service->preview($this->character);
        $level=$this->addLevel();
        $this->expectException(\DomainException::class);
        $this->service->applyLevel($this->character,$level,$before,[]);
    }

    public function testProjectedRightsAreReadOnlyAndShowReplacementPolicy(): void
    {
        $this->grant('second',2,2);
        $this->db->update('feature_choice_group',['replacement'=>'class-level'],['key'=>'family']);
        $preview=$this->service->previewLevel($this->character,'fixture-class',null);
        self::assertCount(2,$preview['grants']);
        self::assertTrue($preview['grants'][0]['replacementAvailable']);
        self::assertSame(1,$this->character->getTotalLevel());
        self::assertSame(0,(int)$this->db->fetchOne('SELECT count(*) FROM character_feature_choice'));
    }

    public function testExistingCharacterWithoutAnyGrantCanLevelUp(): void
    {
        $this->db->executeStatement('DELETE FROM feature_choice_grant');
        $service = $this->kernel->getContainer()->get('test.service_container')->get(\App\Service\CharacterLevelUpService::class);
        $service->levelUp($this->character, $this->class);
        self::assertSame(2, $this->character->getTotalLevel());
        self::assertSame([], $this->service->preview($this->character)['missing']);
        self::assertSame(0, (int) $this->db->fetchOne('SELECT count(*) FROM character_feature_choice'));
    }

    public function testFullLevelUpRollsBackWhenMandatoryChoiceIsMissing(): void
    {
        $this->grant('second',2,1);
        $id=$this->character->getId();
        $service=$this->kernel->getContainer()->get('test.service_container')->get(\App\Service\CharacterLevelUpService::class);
        try { $service->levelUp($this->character,$this->class); self::fail('Missing choice accepted'); }
        catch (\DomainException $error) {
            self::assertStringContainsString('obligatoire',$error->getMessage());
            self::assertSame(1,(int)$this->db->fetchOne('SELECT count(*) FROM character_class_level WHERE character_id=?',[$id]));
            self::assertSame(0,(int)$this->db->fetchOne('SELECT count(*) FROM character_feature_choice'));
        }
    }

    public function testFullLevelUpPersistsOnlyNewChoicesAndLeavesHistoricalMissingRights(): void
    {
        $this->grant('second',2,1);
        $service=$this->kernel->getContainer()->get('test.service_container')->get(\App\Service\CharacterLevelUpService::class);
        $service->levelUp($this->character,$this->class,featureChoices:[$this->selection('b','second')]);
        self::assertSame(2,$this->character->getTotalLevel());
        self::assertSame(['first'],$this->service->preview($this->character)['missing']);
        self::assertArrayHasKey('b',$this->resolver->resolve($this->character));
    }

    public function testMultipleChoiceProvenancesAreNotDuplicatedAndSurviveOneSourceRetirement(): void
    {
        $this->save([$this->selection()]);
        $this->db->insert('feature_choice_group',['key'=>'other-family','name'=>'Other']);
        $this->db->insert('feature_choice_option',['key'=>'other-a','group_key'=>'other-family','feature_id'=>$this->features['a']->getId()]);
        $rule=CharacterFeatureRule::forClass($this->features['b'],$this->class,1);
        $this->em->persist($rule); $this->em->flush();
        $this->db->insert('feature_choice_grant',['key'=>'other-grant','group_key'=>'other-family','rule_id'=>$rule->getId(),'choice_count'=>1]);
        $this->save([$this->selection('other-a','other-grant')]);
        self::assertCount(4,$this->resolver->applicableRules($this->character));
        self::assertCount(3,$this->resolver->resolve($this->character));
        $this->db->update('feature_choice_grant',['enabled'=>false],['key'=>'first'],['enabled'=>'boolean']);
        self::assertArrayHasKey('a',$this->resolver->resolve($this->character));
    }

    public function testUnknownPrerequisitesFailClosedAndDoNotWrite(): void
    {
        $this->db->update('feature_choice_option',['prerequisites'=>'{"unknown":true}'],['key'=>'a']);
        self::assertContains('unsupported_prerequisite',$this->service->preview($this->character)['grants'][0]['options'][0]['reasons']);
        $this->expectException(\DomainException::class);
        $this->save([$this->selection()]);
    }

    public function testAnotherOwnerCannotRegularize(): void
    {
        $other=(new User())->setEmail('other@example.invalid')->setPassword('unused');
        $this->em->persist($other); $this->em->flush();
        $this->expectException(\Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException::class);
        $this->service->regularize($this->character,$other,$this->service->preview($this->character)['fingerprint'],[$this->selection()],'forged');
    }

    public function testResourcesAndProgressionsArePreservedAndArmorEffectsUseExistingEngine(): void
    {
        $resource=new \App\Entity\TrackableResourceDefinition('fixture-resource','Resource',\App\Enum\ResourceRechargeType::LongRest,\App\Enum\ResourceMaximumType::Fixed,3);
        $this->features['a']->setResourceDefinition($resource)->configureArmorClassEffects([
            ['key'=>'fixture-bonus','label'=>'Fixture','type'=>'bonus','value'=>1],
        ]);
        $session=new \App\Entity\GameSession($this->character->getCampaign(),'fixture-session','Session');
        $state=new \App\Entity\CharacterSessionState($session,$this->character);
        $state->setState(['resources'=>[['id'=>'fixture-resource','currentValue'=>1],['id'=>'unrelated','currentValue'=>0]],
            'progressions'=>[['id'=>'unrelated','currentValue'=>7]], 'hitPoints'=>['current'=>9]]);
        foreach ([$resource,$session,$state] as $entity) $this->em->persist($entity);
        $this->em->flush();
        $this->save([$this->selection()]);
        self::assertSame(1,$state->getState()['resources'][0]['currentValue']);
        self::assertSame(0,$state->getState()['resources'][1]['currentValue']);
        self::assertSame(7,$state->getState()['progressions'][0]['currentValue']);
        self::assertSame(['current'=>9],$state->getState()['hitPoints']);
        $profile=$this->kernel->getContainer()->get('test.service_container')->get(\App\Service\CharacterProfileSerializer::class)->serialize($this->character);
        self::assertSame(11,$profile['armorClass']['value']);
        self::assertContains('A',array_column($profile['armorClass']['contributions'],'source'));
    }

    public function testFeatureGrantedSpellAcquisitionUsesChoiceProvenance(): void
    {
        $spell=new \App\Entity\SpellDefinition('fixture-spell','Spell',0,\App\Enum\SpellSchool::cases()[0],'1 action','Contact','Instant','Test');
        $this->em->persist($spell); $this->em->flush();
        $this->db->insert('feature_spell_grant',['feature_id'=>$this->features['a']->getId(),'spell_id'=>$spell->getId(),'mode'=>'fixed','casting_ability'=>'INT']);
        $result=$this->save([$this->selection()]);
        self::assertSame(1,(int)$this->db->fetchOne('SELECT count(*) FROM character_feature_spell WHERE active'));
        $status=$this->kernel->getContainer()->get('test.service_container')->get(\App\Service\CharacterFeatureSpellService::class)->status($this->character);
        self::assertSame([$result['history'][0]['key']],$status['known'][0]['castingRulesResolution']['provenance']['choiceKeys']);
        $this->save([$this->selection('b')+['replacesKey'=>$result['history'][0]['key']]]);
        self::assertSame(0,(int)$this->db->fetchOne('SELECT count(*) FROM character_feature_spell WHERE active'));
        self::assertSame(1,(int)$this->db->fetchOne('SELECT count(*) FROM character_feature_spell'));
    }

    public function testSubclassRestrictionIsEnforcedBySourceRule(): void
    {
        $subclass=new \App\Entity\CharacterSubclass($this->class,'fixture-subclass','Subclass');
        $this->em->persist($subclass); $this->em->flush();
        $rule=CharacterFeatureRule::forSubclass($this->features['source'],$subclass,1);
        $this->em->persist($rule); $this->em->flush();
        $this->db->insert('feature_choice_grant',['key'=>'subclass-grant','group_key'=>'family','rule_id'=>$rule->getId(),'choice_count'=>1]);
        self::assertCount(1,$this->service->preview($this->character)['grants']);
    }

    public function testConsumedReplacementCannotBeUsedTwiceInTheSameLevel(): void
    {
        $old=$this->save([$this->selection()])['history'][0]['key'];
        $this->db->update('feature_choice_group',['replacement'=>'class-level'],['key'=>'family']);
        $before=$this->service->preview($this->character);
        $level=$this->addLevel();
        $this->service->applyLevel($this->character,$level,$before,[$this->selection('b')+['replacesKey'=>$old]]);
        $active=array_values(array_filter($this->service->preview($this->character)['history'],fn ($r)=>$r['active']))[0];
        $this->expectException(\DomainException::class);
        $this->service->applyLevel($this->character,$level,$before,[$this->selection('c')+['replacesKey'=>$active['key']]]);
    }
}
