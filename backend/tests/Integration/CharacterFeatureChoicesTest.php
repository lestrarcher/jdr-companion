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
