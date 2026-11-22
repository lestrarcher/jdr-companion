<?php

declare(strict_types=1);
namespace App\Tests\Integration;

use App\Entity\{Campaign, Character, CharacterClass, CharacterClassLevel, CharacterFeatureDefinition, CharacterFeatureRule, CharacterFeatureAssignment, CharacterProgression, ProgressionDefinition, ProficiencyDefinition, ProficiencyGrantRule, CharacterSessionState, GameSession, User};
use App\Service\{CharacterFeatureSpellAbilityChoice, CharacterFeatureSpellCastingResolver, CharacterFeatureSpellService, CharacterProficiencyResolver, CharacterProfileSerializer, CharacterSessionStateSynchronizer, CharacterSpellCastExecutor, CharacterRestService};
use PHPUnit\Framework\TestCase;

/** Real services; every application table and sequence is isolated in pg_temp. */
final class CustomFeatureEffectsTest extends TestCase
{
    private \App\Kernel $kernel;
    private \Doctrine\ORM\EntityManagerInterface $em;
    private \Doctrine\DBAL\Connection $db;
    private Character $character;
    private CharacterClass $class;
    private ProgressionDefinition $progression;
    private CharacterProgression $assignment;
    private CharacterFeatureDefinition $feature;

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
        foreach ((new \Doctrine\ORM\Tools\SchemaTool($this->em))->getCreateSchemaSql($metadata) as $sql) {
            $this->db->executeStatement(preg_replace(['/^CREATE TABLE /', '/^CREATE SEQUENCE /'], ['CREATE TEMP TABLE ', 'CREATE TEMP SEQUENCE '], $sql));
        }
        foreach (['Version20261108020000', 'Version20261108030000'] as $version) {
            require_once dirname(__DIR__, 2).'/migrations/'.$version.'.php';
            $class = 'DoctrineMigrations\\'.$version;
            foreach ($class::statements() as $sql) $this->db->executeStatement(preg_replace('/^CREATE TABLE /', 'CREATE TEMP TABLE ', $sql));
        }
        foreach (['Version20261022220000', 'Version20261023001000', 'Version20261104020000'] as $version) {
            require_once dirname(__DIR__, 2).'/migrations/'.$version.'.php';
            $class = 'DoctrineMigrations\\'.$version;
            $migration = new $class($this->db, new \Psr\Log\NullLogger());
            $migration->up(new \Doctrine\DBAL\Schema\Schema());
            foreach ($migration->getSql() as $query) $this->db->executeStatement(preg_replace('/^CREATE TABLE /', 'CREATE TEMP TABLE ', $query->getStatement()));
        }
        $this->db->executeStatement('ALTER TABLE feature_spell_grant ADD enabled BOOLEAN DEFAULT TRUE, ADD casting_ability VARCHAR(10), ADD casting_rules JSONB, ADD resource_definition_id INT, ADD managed_resource_id INT');
        foreach ([...array_map(static fn($m) => $m->getTableName(), $metadata), 'feature_spell_grant', 'character_feature_spell', 'character_spell_grant_ability_choice', 'feature_choice_group', 'feature_choice_option', 'feature_choice_grant', 'character_feature_choice', 'feature_choice_rule_gate', 'feature_choice_legacy_rule'] as $table) {
            self::assertSame('t', $this->db->fetchOne('SELECT relpersistence FROM pg_class WHERE oid=to_regclass(?)', [$this->db->quoteIdentifier($table)]));
        }
        $owner = (new User())->setEmail('custom-effects@example.invalid')->setPassword('unused');
        $campaign = new Campaign($owner, 'effects', 'Effects');
        $this->character = new Character($campaign, 'hero', 'Hero', Character::TYPE_PLAYER);
        $this->class = new CharacterClass('fixture', 'Fixture', 8, 3);
        $level = new CharacterClassLevel($this->character, $this->class, 1);
        $level->setHitPointGain(8, \App\Enum\HitPointGainMethod::FirstLevel);
        $this->character->addClassLevel($level);
        $this->progression = new ProgressionDefinition('story', 'Story');
        $this->assignment = new CharacterProgression($this->character, $this->progression);
        $this->character->addProgression($this->assignment);
        $this->feature = (new CharacterFeatureDefinition('gift', 'Gift'))->setCustom(true);
        $rule = CharacterFeatureRule::forProgression($this->feature, $this->progression, 2);
        foreach ([$this->feature, $this->progression, $rule] as $reference) $this->custom($reference);
        foreach ([$owner, $campaign, $this->character, $this->class, $level, $this->progression, $this->assignment, $this->feature,
            $rule] as $entity) $this->em->persist($entity);
        $this->em->flush();
        $this->db->commit();
    }

    private function custom(object $reference): void
    {
        (new \ReflectionProperty($reference, 'origin'))->setValue($reference, \App\Enum\ReferenceOrigin::Custom);
        (new \ReflectionProperty($reference, 'owner'))->setValue($reference, $this->character->getCampaign()->getOwner());
    }

    public function testNaturalAttacksFollowProgressionAndPersistThroughFeatureEditor(): void
    {
        $editor = $this->service(\App\Service\CustomReferenceFeatureService::class);
        $effects = [['key'=>'claws', 'type'=>'natural-attack', 'name'=>'Griffes narratives', 'dice'=>'1d6', 'damageType'=>'slashing', 'attackBonus'=>2]];
        $saved = $editor->patch($this->feature->getId(), ['attackEffects'=>$effects], $this->character->getCampaign()->getOwner());
        self::assertSame($effects, $saved['attackEffects']);
        $editor->patch($this->feature->getId(), ['description'=>'Updated description'], $this->character->getCampaign()->getOwner());
        $this->em->refresh($this->feature);
        self::assertSame($effects, $this->feature->getAttackEffects());
        $serializer = $this->service(CharacterProfileSerializer::class);
        foreach ([[], ['story'=>1]] as $context) {
            self::assertCount(2, $serializer->serialize($this->character, $context)['weapons']);
        }
        foreach ([2, 3] as $value) {
            $rows = $serializer->serialize($this->character, ['story'=>$value])['weapons'];
            self::assertCount(3, $rows);
            self::assertSame('feature:gift:claws', $rows[2]['id']);
            self::assertSame(4, $rows[2]['attackBonus']);
            self::assertSame('1d6 tranchant', $rows[2]['damage']);
        }
        self::assertCount(2, $serializer->serialize($this->character, ['story'=>0])['weapons']);
        self::assertCount(0, $this->character->getMagicItems());
        $this->feature->setActivationType(\App\Enum\FeatureActivationType::Action);
        self::assertCount(2, $serializer->serialize($this->character, ['story'=>3])['weapons']);
    }

    public function testAttackMigrationSeedsOnlyOfficialDragonHideAndPreservesOtherFeatures(): void
    {
        $dragon = new CharacterFeatureDefinition('dragon-hide', 'Cuir du dragon');
        $this->em->persist($dragon); $this->em->flush();
        $before = $this->db->fetchAllAssociative('SELECT id, slug, description, armor_class_effects FROM character_feature_definition ORDER BY id');
        $this->db->executeStatement('ALTER TABLE character_feature_definition DROP COLUMN attack_effects');
        require_once dirname(__DIR__, 2).'/migrations/Version20261122030000.php';
        $migration = new \DoctrineMigrations\Version20261122030000($this->db, new \Psr\Log\NullLogger());
        $migration->up(new \Doctrine\DBAL\Schema\Schema());
        foreach ($migration->getSql() as $query) $this->db->executeStatement($query->getStatement());
        self::assertSame($before, $this->db->fetchAllAssociative('SELECT id, slug, description, armor_class_effects FROM character_feature_definition ORDER BY id'));
        $this->em->refresh($dragon);
        self::assertSame('1d4', $dragon->getAttackEffects()[0]['dice']);
        self::assertSame('slashing', $dragon->getAttackEffects()[0]['damageType']);
        self::assertTrue($dragon->getAttackEffects()[0]['unarmed']);
        self::assertNull($this->db->fetchOne('SELECT attack_effects FROM character_feature_definition WHERE id=?', [$this->feature->getId()]));
        $this->character->getAbilityScore(\App\Enum\Ability::Strength)->setBaseValue(16);
        $rows = $this->service(\App\Service\CharacterWeaponCalculator::class)->calculate($this->character, [], [], [CharacterFeatureRule::forCharacter($dragon, $this->character)]);
        self::assertSame(5, $rows[2]['attackBonus']);
        self::assertSame('1d4+3 tranchant', $rows[2]['damage']);
    }

    protected function tearDown(): void
    {
        while ($this->db->isTransactionActive()) $this->db->rollBack();
        $this->em->clear();
        $this->db->executeStatement('DISCARD TEMP');
        $this->kernel->shutdown();
    }

    private function spellGrant(?CharacterFeatureDefinition $feature = null, ?string $ability = 'CHOICE'): int
    {
        $spell = new \App\Entity\SpellDefinition('spell-'.($feature ?? $this->feature)->getId(), 'Granted spell', 2,
            \App\Enum\SpellSchool::Illusion, '1 action', 'Self', '1 minute', 'Fixture');
        $this->em->persist($spell);
        $pool = new \App\Entity\TrackableResourceDefinition('free-'.($feature ?? $this->feature)->getId(), 'Free use', \App\Enum\ResourceRechargeType::LongRest);
        $pool->setBaseMaximum(1);
        if (($feature ?? $this->feature)->getOrigin() === \App\Enum\ReferenceOrigin::Custom) $this->custom($pool);
        $this->em->persist($pool); $this->em->flush();
        $this->db->insert('feature_spell_grant', ['feature_id'=>($feature ?? $this->feature)->getId(), 'mode'=>'fixed', 'spell_id'=>$spell->getId(),
            'casting_ability'=>$ability, 'resource_definition_id'=>$pool->getId(), 'managed_resource_id'=>$pool->getId(),
            'casting_rules'=>json_encode(['version'=>1, 'methods'=>[['kind'=>'resource', 'cost'=>1, 'castingLevel'=>2]]])]);
        $grantId = (int)$this->db->lastInsertId();
        $this->service(CharacterFeatureSpellService::class)->synchronize($this->character, $grantId);
        return $grantId;
    }

    public function testProgressionChoicePersistsWithoutRecreatingAcquisitionAndEnforcesThreshold(): void
    {
        $grant = $this->spellGrant();
        $choices = $this->service(CharacterFeatureSpellAbilityChoice::class);
        $casting = $this->service(CharacterFeatureSpellCastingResolver::class);
        $before = $this->db->fetchAllAssociative('SELECT * FROM character_feature_spell');
        self::assertSame([], $choices->list($this->character));
        self::assertSame([], $choices->list($this->character, ['story'=>1]));
        self::assertSame('ability_choice_missing', $casting->resolve($this->character, $grant, ['story'=>2])['reason']);
        self::assertCount(1, $choices->list($this->character, ['story'=>2]));
        foreach (['charisma', 'intelligence'] as $ability) {
            $choices->choose($this->character, $grant, $ability, ['story'=>2]);
            self::assertSame($ability, $casting->resolve($this->character, $grant, ['story'=>3])['castingAbility']);
        }
        $this->em->clear();
        $this->character = $this->em->find(Character::class, $this->character->getId());
        self::assertSame('intelligence', $choices->list($this->character, ['story'=>2])[0]['chosenAbility']);
        self::assertSame($before, $this->db->fetchAllAssociative('SELECT * FROM character_feature_spell'));
        self::assertSame([], $choices->list($this->character, ['story'=>1]));
        $this->expectException(\Symfony\Component\HttpKernel\Exception\BadRequestHttpException::class);
        $choices->choose($this->character, $grant, 'wisdom', ['story'=>1]);
    }

    public function testLegacyDefaultsFixedAbilitiesAndFutureTierArePreserved(): void
    {
        $this->progression->setDefaultCastingAbility(\App\Enum\Ability::Wisdom);
        $future = new CharacterFeatureDefinition('future', 'Future');
        $rule = CharacterFeatureRule::forProgression($future, $this->progression, 3);
        $this->custom($future); $this->custom($rule);
        $this->em->persist($future);
        $this->em->persist($rule);
        $this->em->flush();
        $grant = $this->spellGrant();
        $later = $this->spellGrant($future);
        $choices = $this->service(CharacterFeatureSpellAbilityChoice::class);
        $casting = $this->service(CharacterFeatureSpellCastingResolver::class);
        self::assertSame('wisdom', $choices->list($this->character, ['story'=>2])[0]['chosenAbility']);
        self::assertCount(1, $choices->list($this->character, ['story'=>2]));
        self::assertCount(2, $choices->list($this->character, ['story'=>3]));
        $choices->choose($this->character, $later, 'charisma', ['story'=>3]);
        self::assertSame('resolved', $casting->resolve($this->character, $later, ['story'=>3])['status']);
        foreach ([[null, 'wisdom'], ['INT', 'intelligence']] as [$stored, $expected]) {
            $this->db->update('feature_spell_grant', ['casting_ability'=>$stored], ['id'=>$grant]);
            self::assertSame($expected, $casting->resolve($this->character, $grant, ['story'=>2])['castingAbility']);
            self::assertSame([], $choices->list($this->character, ['story'=>2]));
        }
    }

    public function testDirectAbilityChoiceUsesTheSameStorageAndDoesNotChooseASpell(): void
    {
        $direct = new CharacterFeatureDefinition('direct', 'Direct');
        $this->custom($direct);
        $this->em->persist($direct);
        $this->em->persist(new CharacterFeatureAssignment($this->character, $direct));
        $this->em->flush();
        $grant = $this->spellGrant($direct);
        $choices = $this->service(CharacterFeatureSpellAbilityChoice::class);
        self::assertCount(1, $choices->list($this->character));
        $choices->choose($this->character, $grant, 'charisma');
        self::assertSame('charisma', $this->service(CharacterFeatureSpellCastingResolver::class)->resolve($this->character, $grant)['castingAbility']);
        self::assertSame([], $this->service(CharacterFeatureSpellService::class)->status($this->character)['pending']);
    }

    public function testAutomaticProficienciesRecalculateWithoutDuplicatesOrDeletingOtherSources(): void
    {
        $perception = new ProficiencyDefinition('perception', 'Perception', \App\Enum\ProficiencyType::Skill);
        $stealth = new ProficiencyDefinition('stealth', 'Stealth', \App\Enum\ProficiencyType::Skill);
        $automatic = (new ProficiencyGrantRule('Automatic'))->setCharacterFeatureDefinition($this->feature)->addOption($perception)->addOption($stealth);
        $this->custom($automatic);
        $base = (new ProficiencyGrantRule('Base'))->setCharacterClass($this->class)->addOption($perception);
        foreach ([$perception, $stealth, $automatic, $base] as $entity) $this->em->persist($entity);
        $this->em->flush();
        $resolver = $this->service(CharacterProficiencyResolver::class);
        self::assertSame(['perception'], array_keys($resolver->resolve($this->character, ['story'=>1])));
        $result = $resolver->resolve($this->character, ['story'=>2]);
        self::assertSame(['perception','stealth'], array_keys($result));
        self::assertCount(2, $result['perception']['grants']);
        self::assertSame(0, (int)$this->db->fetchOne('SELECT count(*) FROM character_proficiency_choice'));
        $profile = $this->service(CharacterProfileSerializer::class)->serialize($this->character, ['story'=>2]);
        self::assertSame(['perception','stealth'], array_column($profile['training']['proficiencies'], 'slug'));
        $id = $this->character->getId(); $this->em->clear();
        $this->character = $this->em->find(Character::class, $id);
        self::assertSame(['perception','stealth'], array_keys($resolver->resolve($this->character, ['story'=>2])));
        self::assertSame(['perception'], array_keys($resolver->resolve($this->character, ['story'=>1])));
        $direct = new CharacterFeatureAssignment($this->character, $this->em->find(CharacterFeatureDefinition::class, $this->feature->getId()));
        $this->em->persist($direct); $this->em->flush();
        self::assertSame(['perception','stealth'], array_keys($resolver->resolve($this->character)));
        $this->em->remove($direct); $this->em->flush();
        self::assertSame(['perception'], array_keys($resolver->resolve($this->character)));
    }

    public function testFreeDailyCastConsumesOnlyItsPoolAndLongRestRechargesIt(): void
    {
        $grant = $this->spellGrant();
        $this->service(CharacterFeatureSpellAbilityChoice::class)->choose($this->character, $grant, 'wisdom', ['story'=>2]);
        $initial = ['progressions'=>[['id'=>'story','currentValue'=>2]], 'resources'=>[['id'=>'spell-slot-1','currentValue'=>2]]];
        $initial = $this->service(CharacterSessionStateSynchronizer::class)->synchronizeResources($this->character, $initial);
        $pool = 'free-'.$this->feature->getId();
        self::assertSame(1, array_column($initial['resources'], 'currentValue', 'id')[$pool]);
        $game = (new GameSession($this->character->getCampaign(), 'live', 'Live'))->setStatus(GameSession::STATUS_LIVE);
        $session = new CharacterSessionState($game, $this->character, $initial);
        $this->em->persist($game); $this->em->persist($session); $this->em->flush();
        $command = ['revision'=>$session->getRevision(), 'requestId'=>'c0000000-0000-4000-8000-000000000001', 'sourceKey'=>'feature:'.$grant, 'method'=>'resource', 'castingLevel'=>2];
        $validator = $this->service(\App\Service\CharacterSpellCastCommandValidator::class);
        self::assertTrue($validator->validate($this->character, $initial, $command)['valid']);
        self::assertFalse($validator->validate($this->character, $initial, array_replace($command, ['method'=>'standard-slot']))['valid']);
        $executor = $this->service(CharacterSpellCastExecutor::class);
        $result = $executor->execute($session, $command);
        $expected = $initial['resources'];
        foreach ($expected as &$counter) if ($counter['id'] === $pool) $counter['currentValue'] = 0;
        unset($counter);
        self::assertSame($expected, $result['state']['resources']);
        self::assertTrue($executor->execute($session, $command)['replayed']);
        $command['revision'] = $session->getRevision();
        $command['requestId'] = 'c0000000-0000-4000-8000-000000000002';
        try { $executor->execute($session, $command); self::fail('Exhausted cast accepted'); }
        catch (\Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException $error) { self::assertSame('exhausted', $error->getMessage()); }
        $rest = $this->service(CharacterRestService::class);
        $rest->apply($session, \App\Entity\RestRequest::TYPE_SHORT_REST);
        self::assertSame(0, array_column($session->getState()['resources'], 'currentValue', 'id')[$pool]);
        $rest->apply($session, \App\Entity\RestRequest::TYPE_LONG_REST);
        self::assertSame(1, array_column($session->getState()['resources'], 'currentValue', 'id')[$pool]);
        self::assertSame($initial['progressions'], $session->getState()['progressions']);
    }

    public function testPortalEndpointsPassTheirOwnSessionContext(): void
    {
        $grant = $this->spellGrant();
        $controller = $this->service(\App\Controller\PlayerSpellController::class);
        $choices = $this->service(CharacterFeatureSpellAbilityChoice::class);
        $game = (new GameSession($this->character->getCampaign(), 'portal', 'Portal'))->setStatus(GameSession::STATUS_LIVE);
        $session = new CharacterSessionState($game, $this->character, ['progressions'=>[['id'=>'story','currentValue'=>2]]]);
        $this->em->persist($game); $this->em->persist($session); $this->em->flush();
        $response = $controller->abilityChoices($session->getAccessToken(), $choices);
        self::assertCount(1, json_decode($response->getContent(), true)['choices']);
        $request = \Symfony\Component\HttpFoundation\Request::create('/', 'PUT', server:['CONTENT_TYPE'=>'application/json'], content:'{"ability":"charisma"}');
        $response = $controller->chooseAbility($session->getAccessToken(), $grant, $request, $choices);
        self::assertSame('charisma', json_decode($response->getContent(), true)['choices'][0]['chosenAbility']);
        $session->setState(['progressions'=>[['id'=>'story','currentValue'=>1]]]); $this->em->flush();
        self::assertSame([], json_decode($controller->abilityChoices($session->getAccessToken(), $choices)->getContent(), true)['choices']);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\BadRequestHttpException::class);
        $controller->chooseAbility($session->getAccessToken(), $grant, $request, $choices);
    }

    public function testIrreversibleUnlockAndProficiencyChoicesRetainTheirSemantics(): void
    {
        $grant = $this->spellGrant();
        $stealth = new ProficiencyDefinition('stealth', 'Stealth', \App\Enum\ProficiencyType::Skill);
        $rule = (new ProficiencyGrantRule('Choose'))->setCharacterFeatureDefinition($this->feature)->setChoiceCount(1)->addOption($stealth);
        $this->custom($rule);
        $this->em->persist($stealth); $this->em->persist($rule); $this->em->flush();
        $resolver = $this->service(CharacterProficiencyResolver::class);
        self::assertSame([], $resolver->resolve($this->character, ['story'=>2]));
        $this->service(\App\Service\CharacterProficiencyChoiceService::class)->replaceChoices($this->character, $rule, [$stealth]);
        $this->em->flush();
        self::assertSame(['stealth'], array_keys($resolver->resolve($this->character, ['story'=>2])));
        self::assertSame([], $resolver->resolve($this->character, ['story'=>1]));
        self::assertCount(1, $this->character->getProficiencyChoices());
        $this->progression->setIrreversible(true);
        self::assertSame([], $resolver->resolve($this->character, ['story'=>2]));
        $this->assignment->recordReachedValue(2); $this->em->flush();
        self::assertSame(['stealth'], array_keys($resolver->resolve($this->character, ['story'=>1])));
        $choices = $this->service(CharacterFeatureSpellAbilityChoice::class);
        self::assertCount(1, $choices->list($this->character, ['story'=>1]));
        $choices->choose($this->character, $grant, 'wisdom', ['story'=>1]);
        self::assertSame('wisdom', $choices->list($this->character)[0]['chosenAbility']);
    }

    public function testOfficialRaceAndFeatAbilitiesKeepTheirExistingChoiceMechanisms(): void
    {
        $race = new \App\Entity\CharacterRace('fixture-race', 'Race');
        $racial = new CharacterFeatureDefinition('racial', 'Racial gift');
        $feat = new \App\Entity\Feat('fixture-feat', 'Feat');
        $feat->setRequiresAbilityChoice(true);
        $featFeature = new CharacterFeatureDefinition('feat-gift', 'Feat gift');
        $assignment = (new \App\Entity\CharacterFeat($this->character, $feat))->setChosenAbility(\App\Enum\Ability::Intelligence);
        $this->character->setRace($race); $this->character->addFeat($assignment);
        foreach ([$race, $racial, $feat, $featFeature, $assignment, CharacterFeatureRule::forRace($racial, $race, 1), CharacterFeatureRule::forFeat($featFeature, $feat)] as $entity) $this->em->persist($entity);
        $this->em->flush();
        $racialGrant = $this->spellGrant($racial);
        $featGrant = $this->spellGrant($featFeature);
        $choices = $this->service(CharacterFeatureSpellAbilityChoice::class);
        self::assertSame([$racialGrant], array_column($choices->list($this->character), 'grantId'));
        $choices->choose($this->character, $racialGrant, 'charisma');
        $casting = $this->service(CharacterFeatureSpellCastingResolver::class);
        self::assertSame('charisma', $casting->resolve($this->character, $racialGrant)['castingAbility']);
        self::assertSame('intelligence', $casting->resolve($this->character, $featGrant)['castingAbility']);
    }
}
