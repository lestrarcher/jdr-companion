<?php

declare(strict_types=1);
namespace App\Tests\Integration;

use App\Entity\{Campaign, Character, CharacterClass, CharacterClassLevel, CharacterFeatureDefinition, CharacterFeatureRule, CharacterMagicItem, CharacterSessionState, GameSession, MagicItem, User};
use App\Service\{ItemInfusions, PlayerCharacterAccess};
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class ItemInfusionsTest extends TestCase
{
    private \App\Kernel $kernel;
    private \Doctrine\ORM\EntityManagerInterface $em;
    private \Doctrine\DBAL\Connection $db;
    private ItemInfusions $service;
    private Character $creator;
    private Character $recipient;
    private CharacterClass $artificer;
    private CharacterSessionState $actor;
    private array $keys = [];

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
        foreach (['Version20261108020000','Version20261108030000'] as $version) {
            require_once dirname(__DIR__,2).'/migrations/'.$version.'.php';
            $class = 'DoctrineMigrations\\'.$version;
            foreach ($class::statements() as $sql) $this->db->executeStatement(preg_replace('/^CREATE TABLE /', 'CREATE TEMP TABLE ', $sql));
        }
        foreach (['Version20261022220000','Version20261023001000'] as $version) {
            require_once dirname(__DIR__,2).'/migrations/'.$version.'.php';
            $class = 'DoctrineMigrations\\'.$version;
            $migration = new $class($this->db, new \Psr\Log\NullLogger());
            $migration->up(new \Doctrine\DBAL\Schema\Schema());
            foreach ($migration->getSql() as $query) $this->db->executeStatement(preg_replace('/^CREATE TABLE /','CREATE TEMP TABLE ', $query->getStatement()));
        }
        $this->db->executeStatement('ALTER TABLE feature_spell_grant ADD enabled BOOLEAN DEFAULT TRUE, ADD managed_resource_id INT, ADD resource_definition_id INT');
        // Exercise the actual additive migration, confined to temporary tables/functions.
        $this->db->executeStatement('DROP TABLE pg_temp.character_item_infusion');
        require_once dirname(__DIR__,2).'/migrations/Version20261114020000.php';
        $migration = new \DoctrineMigrations\Version20261114020000($this->db, new \Psr\Log\NullLogger());
        $migration->up(new \Doctrine\DBAL\Schema\Schema());
        foreach ($migration->getSql() as $query) {
            $sql = preg_replace('/^CREATE TABLE /', 'CREATE TEMP TABLE ', $query->getStatement());
            $this->db->executeStatement(str_replace('FUNCTION retire_item_infusions()', 'FUNCTION pg_temp.retire_item_infusions()', $sql));
        }
        require_once dirname(__DIR__,2).'/migrations/Version20261115020000.php';
        foreach (\DoctrineMigrations\Version20261115020000::guards() as $sql) {
            $this->db->executeStatement(str_replace('FUNCTION clear_ended_infusion_attunement()', 'FUNCTION pg_temp.clear_ended_infusion_attunement()', $sql));
        }
        self::assertSame('t', $this->db->fetchOne("SELECT relpersistence FROM pg_class WHERE oid=to_regclass('character_item_infusion')"));
        $owner = (new User())->setEmail('infusions@example.invalid')->setPassword('unused');
        $campaign = new Campaign($owner, 'infusions', 'Infusions');
        $this->creator = new Character($campaign, 'creator', 'Creator', Character::TYPE_PLAYER);
        $this->recipient = new Character($campaign, 'recipient', 'Recipient', Character::TYPE_PLAYER);
        $this->artificer = new CharacterClass('artificer', 'Artificer', 8, 3);
        $game = (new GameSession($campaign, 'live', 'Live'))->setStatus(GameSession::STATUS_LIVE);
        $this->actor = new CharacterSessionState($game, $this->creator);
        $source = new CharacterFeatureDefinition('infusions-known', 'Infusions');
        foreach ([$owner,$campaign,$this->creator,$this->recipient,$this->artificer,$game,$this->actor,$source] as $entity) $this->em->persist($entity);
        $this->levels($this->artificer, 2);
        $rule = CharacterFeatureRule::forClass($source, $this->artificer, 2);
        $this->em->persist($rule);
        $this->em->flush();
        $this->db->insert('feature_choice_group', ['key'=>'artificer-infusions','name'=>'Infusions']);
        $this->db->insert('feature_choice_grant', ['key'=>'infusions','group_key'=>'artificer-infusions','rule_id'=>$rule->getId(),'choice_count'=>6]);
        foreach (['enhanced-defense'=>2,'mind-sharpener'=>2,'armor-of-magical-strength'=>2,'replicate-bag-of-holding'=>2,'repulsion-shield'=>6,'resistant-armor'=>6] as $name=>$minimum) {
            $feature = new CharacterFeatureDefinition('artificer-infusion-'.$name, $name);
            $this->em->persist($feature); $this->em->flush();
            $this->db->insert('feature_choice_option', ['key'=>$name,'group_key'=>'artificer-infusions','feature_id'=>$feature->getId(),'prerequisites'=>json_encode(['classLevels'=>['artificer'=>$minimum]])]);
            $key = bin2hex(random_bytes(16));
            $this->keys[$name] = $key;
            $this->db->insert('character_feature_choice', ['key'=>$key,'character_id'=>$this->creator->getId(),'grant_key'=>'infusions','option_key'=>$name,'slot'=>count($this->keys),'acquired_position'=>2,'provenance'=>'{}']);
        }
        $this->service = $container->get(ItemInfusions::class);
        $this->rest();
    }

    private function rest(string $type = \App\Entity\RestRequest::TYPE_LONG_REST): void
    {
        $this->kernel->getContainer()->get('test.service_container')->get(\App\Service\CharacterRestService::class)->apply($this->actor, $type);
        $this->em->flush();
    }

    public function testPreparationRequiresLongRestAndClosesWithoutChanges(): void
    {
        $item = $this->item();
        $this->actor->setState([]); $this->em->flush();
        self::assertFalse($this->service->preparation($this->actor)['open']);
        $this->rejects(fn () => $this->apply('enhanced-defense', $item));
        $this->rest(\App\Entity\RestRequest::TYPE_SHORT_REST);
        self::assertFalse($this->service->preparation($this->actor)['open']);
        $this->rest();
        self::assertTrue($this->service->preparation($this->actor)['open']);
        $active = $this->apply('enhanced-defense', $item)['active'];
        $phase = $this->service->preparation($this->actor)['id'];
        $this->service->finishPreparation($this->actor, $phase);
        $this->service->finishPreparation($this->actor, $phase);
        self::assertSame($active, $this->service->view($this->creator)['active']);
        self::assertTrue($this->actor->getState()['spellPreparationAllowed']);
        $this->rejects(fn () => $this->apply('mind-sharpener', $this->item()));
        $this->rejects(fn () => $this->service->remove($this->actor, $active[0]['id']));
        $this->rest();
        $this->rejects(fn () => $this->service->finishPreparation($this->actor, $phase));
        self::assertTrue($this->service->preparation($this->actor)['open']);
        self::assertSame($active, $this->service->view($this->creator)['active']);
    }

    public function testEligibilityUsesAcquiredCapabilityAndArtificerLevels(): void
    {
        self::assertFalse($this->service->afterLongRest($this->recipient, [])['infusionPreparation']['open']);
        $fighter = new CharacterClass('fighter', 'Fighter', 10, 3);
        $this->em->persist($fighter); $this->levels($fighter, 3);
        $this->rest();
        self::assertTrue($this->service->preparation($this->actor)['open']);
        self::assertSame(2, $this->service->view($this->creator)['quota']);
    }

    public function testPreparationCannotBeForgedAndConfirmationUsesPlayerAccess(): void
    {
        $container = $this->kernel->getContainer()->get('test.service_container');
        $this->rejects(fn () => $container->get(\App\Service\PlayerCharacterStateUpdater::class)->merge($this->actor, ['infusionPreparation'=>['open'=>true]]), 422);
        $controller = $container->get(\App\Controller\PlayerItemInfusionController::class);
        $phase = $this->service->preparation($this->actor)['id'];
        $request = \Symfony\Component\HttpFoundation\Request::create('/', 'POST', server:['CONTENT_TYPE'=>'application/json'], content:json_encode(['phaseId'=>$phase]));
        $response = $controller->confirm($this->actor->getAccessToken(), $request);
        self::assertFalse(json_decode($response->getContent(), true)['preparation']['open']);
        $this->actor->setParticipating(false); $this->em->flush();
        $this->rejects(fn () => $controller->confirm($this->actor->getAccessToken(), $request), 404);
    }

    public function testReapplicationReplacesOnlyItsPreviousSupport(): void
    {
        $first = $this->item(); $second = $this->item();
        $before = $this->apply('enhanced-defense', $first)['active'];
        self::assertSame($before, $this->apply('enhanced-defense', $first)['active']);
        $after = $this->apply('enhanced-defense', $second)['active'];
        self::assertCount(1, $after);
        self::assertSame($second->getId(), $after[0]['itemId']);
        self::assertSame('formula_reapplied', $this->db->fetchOne('SELECT end_reason FROM character_item_infusion WHERE id=?', [$before[0]['id']]));
    }

    public function testRestPreservesSpellSelectionsAndIndependentPreparation(): void
    {
        $state = $this->actor->getState();
        $state['preparedSpells'] = ['101'=>[301,302], '102'=>[]];
        $state['spellPreparationAllowed'] = false;
        $state['characterActions'] = ['prepared'=>['test-action'], 'preparationPending'=>false];
        $this->actor->setState($state); $this->em->flush();
        $phase = $this->service->preparation($this->actor);
        $this->rest(\App\Entity\RestRequest::TYPE_SHORT_REST);
        self::assertSame($phase, $this->service->preparation($this->actor));
        self::assertFalse($this->actor->getState()['spellPreparationAllowed']);
        $this->rest();
        self::assertSame($state['preparedSpells'], $this->actor->getState()['preparedSpells']);
        self::assertTrue($this->actor->getState()['spellPreparationAllowed']);
        self::assertSame([], $this->actor->getState()['characterActions']['prepared']);
        self::assertTrue($this->actor->getState()['characterActions']['preparationPending']);
        $this->service->finishPreparation($this->actor, $this->service->preparation($this->actor)['id']);
        self::assertTrue($this->actor->getState()['spellPreparationAllowed']);
        self::assertSame($state['preparedSpells'], $this->actor->getState()['preparedSpells']);
    }

    protected function tearDown(): void
    {
        while ($this->db->isTransactionActive()) $this->db->rollBack();
        $this->em->clear(); $this->kernel->shutdown();
    }

    private function levels(CharacterClass $class, int $count): void
    {
        for ($i=0; $i<$count; ++$i) {
            $level = new CharacterClassLevel($this->creator, $class, $this->creator->getNextLevelPosition());
            $this->creator->addClassLevel($level); $this->em->persist($level);
        }
        $this->em->flush();
    }

    private function item(string $category = 'armor', ?Character $owner = null, bool $magical = false): CharacterMagicItem
    {
        $definition = new MagicItem(($owner ?? $this->creator)->getCampaign(), 'Support test', null);
        $definition->classify($category, $magical, true, $category === 'armor' ? 'armor' : 'hand', $category === 'armor' ? null : 'one');
        $item = new CharacterMagicItem($owner ?? $this->creator, $definition);
        $this->em->persist($definition); $this->em->persist($item); $this->em->flush();
        return $item;
    }

    private function apply(string $name, CharacterMagicItem $item): array
    {
        return $this->service->apply($this->actor, $this->keys[$name], $item->getCharacter()->getId(), $item->getId());
    }

    private function selection(string $name, CharacterMagicItem $item): array
    {
        return ['choiceKey'=>$this->keys[$name], 'recipientId'=>$item->getCharacter()->getId(), 'itemId'=>$item->getId()];
    }

    public function testWeaponPropertiesFollowCreatorEquipmentAndLifecycle(): void
    {
        $feature = new CharacterFeatureDefinition('artificer-infusion-enhanced-weapon', 'Arme améliorée');
        $this->em->persist($feature); $this->em->flush();
        $this->db->update('feature_choice_option', ['feature_id'=>$feature->getId()], ['key'=>'mind-sharpener']);
        $item = $this->item('weapon', $this->recipient);
        $profile = json_decode(file_get_contents(dirname(__DIR__,2).'/data/weapon-profiles-2014.json'),true)['phb2014-weapon-longsword'];
        $item->getMagicItem()->configureWeaponProfile($profile); $this->em->flush();
        $this->apply('mind-sharpener', $item);
        $resolver = new \App\Service\ItemInfusionArmorEffects($this->db);
        $properties = fn () => $resolver->itemProperties($this->recipient);
        self::assertSame([], $resolver->itemProperties($this->creator));
        self::assertSame(0, $properties()[$item->getId()]['weaponBonuses']['attack']);
        self::assertSame(1, $resolver->itemProperties($this->recipient, true)[$item->getId()]['weaponBonuses']['attack']);
        $item->setEquipped(true); $this->em->flush();
        $bonus = $properties()[$item->getId()]['weaponBonuses'];
        self::assertSame(['attack'=>1, 'damage'=>1, 'itemId'=>$item->getId(), 'requiresUse'=>true], $bonus);
        $this->recipient->addMagicItem($item);
        $recipientSession = new CharacterSessionState($this->actor->getGameSession(), $this->recipient);
        $this->em->persist($recipientSession); $this->em->flush();
        $container = $this->kernel->getContainer()->get('test.service_container');
        $response = $container->get(\App\Controller\PublicCharacterMagicItemController::class)->list(
            $recipientSession->getAccessToken(), $container->get(PlayerCharacterAccess::class),
            $container->get(\App\Service\CharacterAbilityCalculator::class),
        );
        $inventory = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($bonus, $inventory['ownedItems'][0]['infusion']['weaponBonuses']);
        self::assertSame($this->creator->getName(), $inventory['ownedItems'][0]['infusion']['creatorName']);
        $weaponCalculator = new \App\Service\CharacterWeaponCalculator(new \App\Service\CharacterAbilityCalculator());
        $total = fn () => $weaponCalculator->calculate($this->recipient, [], $resolver->itemProperties($this->recipient,true))[0];
        $baseAttack = $total()['attackBonus'];
        self::assertSame([], $resolver->resolve($this->recipient));
        $this->levels($this->artificer, 8);
        self::assertSame(2, $properties()[$item->getId()]['weaponBonuses']['damage']);
        self::assertSame($baseAttack + 1, $total()['attackBonus']);
        $item->setEquipped(false); $this->em->flush();
        self::assertSame(0, $properties()[$item->getId()]['weaponBonuses']['damage']);
        self::assertSame($baseAttack + 1, $total()['attackBonus']);
        $second = $this->item('weapon', $this->recipient);
        $this->apply('mind-sharpener', $second);
        self::assertArrayNotHasKey($item->getId(), $properties());
        self::assertSame($baseAttack - 1, $total()['attackBonus']);
        self::assertArrayHasKey($second->getId(), $properties());
        $this->db->executeStatement('UPDATE character_feature_choice SET active=FALSE WHERE key=?', [$this->keys['mind-sharpener']]);
        self::assertSame([], $properties());
    }

    public function testWeaponPropertiesDisappearWithFifoAndRejectMagicalSupports(): void
    {
        $feature = new CharacterFeatureDefinition('artificer-infusion-enhanced-weapon', 'Arme améliorée');
        $this->em->persist($feature); $this->em->flush();
        $this->db->update('feature_choice_option', ['feature_id'=>$feature->getId()], ['key'=>'mind-sharpener']);
        $this->rejects(fn () => $this->apply('mind-sharpener', $this->item('weapon', null, true)));
        $this->rejects(fn () => $this->apply('mind-sharpener', $this->item('shield')));
        $item = $this->item('weapon', $this->recipient);
        $this->apply('mind-sharpener', $item);
        $resolver = new \App\Service\ItemInfusionArmorEffects($this->db);
        self::assertArrayHasKey($item->getId(), $resolver->itemProperties($this->recipient));
        $this->apply('enhanced-defense', $this->item());
        $this->apply('armor-of-magical-strength', $this->item());
        self::assertSame([], $resolver->itemProperties($this->recipient));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('weaponInfusions')]
    public function testWeaponInfusionSupportsAndAttunement(string $slug, string $reference, bool $attunement): void
    {
        $feature = new CharacterFeatureDefinition('artificer-infusion-'.$slug, 'Weapon infusion');
        $this->em->persist($feature); $this->em->flush();
        $this->db->update('feature_choice_option', ['feature_id'=>$feature->getId()], ['key'=>'mind-sharpener']);
        $profiles = json_decode(file_get_contents(dirname(__DIR__,2).'/data/weapon-profiles-2014.json'),true);
        $bad = $this->item('weapon'); $bad->getMagicItem()->configureWeaponProfile($profiles['phb2014-weapon-longsword']);
        $this->em->flush(); $this->rejects(fn () => $this->apply('mind-sharpener',$bad));
        $item = $this->item('weapon',$this->recipient);
        $item->getMagicItem()->configureWeaponProfile($profiles['phb2014-weapon-'.$reference]);
        $this->recipient->addMagicItem($item); $this->em->flush();
        $this->apply('mind-sharpener',$item);
        $resolver = new \App\Service\ItemInfusionArmorEffects($this->db);
        $effects = fn () => $resolver->itemProperties($this->recipient,true)[$item->getId()];
        self::assertSame($attunement ? 0 : 1,$effects()['weaponBonuses']['attack']);
        if ($attunement) {
            $session = new CharacterSessionState($this->actor->getGameSession(), $this->recipient);
            $this->em->persist($session); $this->em->flush();
            $container = $this->kernel->getContainer()->get('test.service_container');
            $response = $container->get(\App\Controller\PublicCharacterMagicItemController::class)->update(
                $session->getAccessToken(), $item->getId(),
                \Symfony\Component\HttpFoundation\Request::create('/', 'PATCH', server:['CONTENT_TYPE'=>'application/json'], content:'{"attuned":true}'),
                $container->get(PlayerCharacterAccess::class), $this->em->getRepository(CharacterMagicItem::class),
                $container->get(\App\Service\CharacterAbilityCalculator::class), $this->em,
            );
            self::assertSame(200,$response->getStatusCode());
            $inventory = json_decode($response->getContent(),true);
            self::assertTrue($inventory['ownedItems'][0]['magicItem']['requiresAttunement']);
            self::assertSame(1,$this->recipient->getAttunedMagicItemCount());
            self::assertSame(1,$effects()['weaponBonuses']['attack']);
        }
        self::assertNotEmpty($effects()['weaponNote']);
        $calculator = new \App\Service\CharacterWeaponCalculator(new \App\Service\CharacterAbilityCalculator());
        $row = $calculator->calculate($this->recipient, [], $resolver->itemProperties($this->recipient,true))[0];
        if ($attunement) {
            self::assertNotContains('Chargement', $row['notes']);
            // A deletion must not UPDATE the same possession from the retirement trigger.
            $this->db->beginTransaction();
            $this->db->executeStatement('DELETE FROM character_magic_item WHERE id=?', [$item->getId()]);
            self::assertSame([], $resolver->itemProperties($this->recipient,true));
            $this->db->rollBack();
            self::assertSame(1,$effects()['weaponBonuses']['attack']);
        }
        $this->db->executeStatement('UPDATE character_feature_choice SET active=FALSE WHERE key=?',[$this->keys['mind-sharpener']]);
        self::assertSame([],$resolver->itemProperties($this->recipient,true));
        $this->em->refresh($item);
        self::assertFalse($item->isAttuned());
    }
    public static function weaponInfusions(): array { return [['returning-weapon','dagger',false],['repeating-shot','light-crossbow',true]]; }

    public function testWeaponEnrichmentIsNonDestructiveAndIdempotent(): void
    {
        $item = new MagicItem(null,'Reference test',null,null,\App\Enum\ReferenceOrigin::Official);
        $item->classify('weapon',false,true,'hand','one');
        $item->setImportMetadata('phb2014-weapon-dagger','PHB 2014');
        $this->em->persist($item); $this->em->flush();
        $command = new \Symfony\Component\Console\Tester\CommandTester(new \App\Command\EnrichWeaponProfilesCommand($this->db,dirname(__DIR__,2)));
        $before = $this->db->fetchAllAssociative('SELECT * FROM character_magic_item ORDER BY id');
        self::assertSame(0,$command->execute([]));
        self::assertNull($this->db->fetchOne('SELECT weapon_profile FROM magic_item WHERE id=?',[$item->getId()]));
        $command->execute(['--apply'=>true]);
        $configured = $this->db->fetchOne('SELECT weapon_profile FROM magic_item WHERE id=?',[$item->getId()]);
        self::assertNotNull($configured);
        $command->execute(['--apply'=>true]);
        self::assertStringContainsString('Appliqués : 0',$command->getDisplay());
        self::assertSame($before,$this->db->fetchAllAssociative('SELECT * FROM character_magic_item ORDER BY id'));
        $override = json_decode($configured,true); $override['dice']='1d6';
        $this->db->update('magic_item',['weapon_profile'=>json_encode($override)],['id'=>$item->getId()]);
        $command->execute(['--apply'=>true]);
        self::assertStringContainsString('Configuration existante préservée',$command->getDisplay());
        self::assertSame($override,json_decode($this->db->fetchOne('SELECT weapon_profile FROM magic_item WHERE id=?',[$item->getId()]),true));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('arcaneFocusSupports')]
    public function testEnhancedArcaneFocusSupports(string $reference, bool $magical, bool $official, bool $eligible): void
    {
        $feature = new CharacterFeatureDefinition('artificer-infusion-enhanced-arcane-focus', 'Focaliseur arcanique amélioré');
        $this->em->persist($feature); $this->em->flush();
        $this->db->update('feature_choice_option', ['feature_id'=>$feature->getId()], ['key'=>'mind-sharpener']);
        $definition = new MagicItem($official ? null : $this->creator->getCampaign(), 'Baguette personnalisée ou renommée', null,
            origin:$official ? \App\Enum\ReferenceOrigin::Official : \App\Enum\ReferenceOrigin::Custom);
        $definition->classify('gear', $magical, false);
        $definition->setReferenceDetails($reference, null, null);
        $item = new CharacterMagicItem($this->recipient, $definition);
        $this->em->persist($definition); $this->em->persist($item); $this->em->flush();
        $before = $this->db->fetchAllAssociative('SELECT * FROM character_magic_item ORDER BY id');
        $listed = $this->service->items($this->creator, $this->recipient->getId(), $this->keys['mind-sharpener']);
        self::assertSame($eligible, $listed[0]['eligible']);
        if ($eligible) {
            self::assertNull($listed[0]['reason']);
            $result = $this->confirm([$this->selection('mind-sharpener', $item)]);
            self::assertSame($item->getId(), $result['active'][0]['itemId']);
            self::assertFalse($result['preparation']['open']);
            $this->rejects(fn () => $this->service->apply($this->actor, $this->keys['mind-sharpener'], $this->recipient->getId(), $item->getId()));
        } else {
            self::assertNotEmpty($listed[0]['reason']);
            $this->rejects(fn () => $this->confirm([$this->selection('mind-sharpener', $item)]));
            self::assertSame([], $this->service->view($this->creator)['active']);
        }
        self::assertSame($before, $this->db->fetchAllAssociative('SELECT * FROM character_magic_item ORDER BY id'));
    }

    public static function arcaneFocusSupports(): array
    {
        return [
            'wand'=>['Wand',false,true,true], 'staff'=>['Staff',false,true,true], 'rod'=>['Rod',false,true,true],
            'magical wand'=>['Wand',true,true,false], 'magical staff'=>['Staff',true,true,false], 'magical rod'=>['Rod',true,true,false],
            'crystal'=>['Crystal',false,true,false], 'orb'=>['Orb',false,true,false],
            'unknown reference'=>['Unknown',false,true,false], 'custom named wand'=>['Wand',false,false,false],
        ];
    }

    private function confirm(array $selections): array
    {
        return $this->service->finishPreparation($this->actor, $this->service->preparation($this->actor)['id'], $selections);
    }

    public function testGlobalConfirmationPreservesRowsAndAddsWithoutInventoryChanges(): void
    {
        $first = $this->item(); $second = $this->item();
        $before = $this->apply('enhanced-defense', $first)['active'][0];
        $plan = [$this->selection('mind-sharpener', $second), $this->selection('enhanced-defense', $first)];
        self::assertCount(1, $this->service->view($this->creator)['active']); // Local draft has no effect.
        $result = $this->confirm($plan);
        self::assertCount(2, $result['active']);
        self::assertSame($before, $result['active'][0]);
        self::assertFalse($result['preparation']['open']);
        $replay = $this->confirm($plan);
        self::assertTrue($replay['replayed']);
        self::assertSame($result['active'], $replay['active']);
        self::assertSame(2, (int)$this->db->fetchOne('SELECT count(*) FROM character_item_infusion'));
        $this->rejects(fn () => $this->confirm([]));
    }

    public function testGlobalReapplicationAndReplacementAreAtomicAndUpdateArmorClass(): void
    {
        $shield = $this->equippedSupport('shield'); $armor = $this->item();
        $base = $this->armorClass($this->recipient)['value'];
        $first = $this->apply('enhanced-defense', $armor)['active'][0];
        $this->confirm([$this->selection('enhanced-defense', $shield), $this->selection('mind-sharpener', $armor)]);
        self::assertSame($base + 1, $this->armorClass($this->recipient)['value']);
        self::assertSame('formula_reapplied', $this->db->fetchOne('SELECT end_reason FROM character_item_infusion WHERE id=?', [$first['id']]));
        $this->rest();
        $this->confirm([$this->selection('armor-of-magical-strength', $armor)]);
        self::assertSame($base, $this->armorClass($this->recipient)['value']);
        self::assertSame(2, (int)$this->db->fetchOne("SELECT count(*) FROM character_item_infusion WHERE end_reason='preparation_removed'"));
    }

    public function testGlobalFifoKeepsRealAgeAndUsesNewListOrder(): void
    {
        $fighter = new CharacterClass('fighter', 'Fighter', 10, 3);
        $this->em->persist($fighter); $this->levels($fighter, 8);
        $first = $this->item(); $second = $this->item(); $third = $this->item();
        $old = $this->apply('enhanced-defense', $first)['active'][0];
        $young = $this->apply('mind-sharpener', $second)['active'][1];
        $plan = [$this->selection('armor-of-magical-strength', $third), $this->selection('mind-sharpener', $second), $this->selection('enhanced-defense', $first)];
        $result = $this->confirm($plan);
        self::assertSame(2, $result['quota']); // Ten total levels, only two artificer levels.
        self::assertCount(2, $result['active']);
        self::assertSame($young, $result['active'][0]);
        self::assertSame($old['id'], $result['evicted'][0]['id']);
        self::assertSame('quota_replaced', $this->db->fetchOne('SELECT end_reason FROM character_item_infusion WHERE id=?', [$old['id']]));
        $this->rest(); $this->confirm([]); $this->rest();
        $result = $this->confirm($plan);
        self::assertSame([$this->keys['mind-sharpener'], $this->keys['enhanced-defense']], array_column($result['active'], 'choiceKey'));
        self::assertSame($this->keys['armor-of-magical-strength'], $result['evicted'][0]['choiceKey']);
    }

    public function testInvalidGlobalPlanLeavesAllRowsAndPhaseUntouched(): void
    {
        $first = $this->item(); $second = $this->item(); $invalid = $this->item('armor', null, true);
        $this->apply('enhanced-defense', $first);
        $before = $this->db->fetchAllAssociative('SELECT * FROM character_item_infusion ORDER BY id');
        $phase = $this->actor->getState();
        $this->rejects(fn () => $this->confirm([$this->selection('enhanced-defense', $second), $this->selection('mind-sharpener', $invalid)]));
        self::assertSame($before, $this->db->fetchAllAssociative('SELECT * FROM character_item_infusion ORDER BY id'));
        self::assertSame($phase, $this->actor->getState());
        self::assertTrue($this->service->preparation($this->actor)['open']);
        $this->confirm([$this->selection('enhanced-defense', $second)]);
    }

    public function testGlobalDuplicatesAndUnknownChoicesAreRejected(): void
    {
        $one = $this->item(); $two = $this->item();
        $this->rejects(fn () => $this->confirm([$this->selection('enhanced-defense', $one), $this->selection('enhanced-defense', $two)]), 422);
        $this->rejects(fn () => $this->confirm([$this->selection('enhanced-defense', $one), $this->selection('mind-sharpener', $one)]), 422);
        $this->rejects(fn () => $this->confirm([$this->selection('repulsion-shield', $this->item('shield'))]));
        $this->rejects(fn () => $this->confirm([['choiceKey'=>str_repeat('f',32), 'recipientId'=>$this->creator->getId(), 'itemId'=>$one->getId()]]));
        $this->rejects(fn () => $this->confirm([['choiceKey'=>$this->keys['enhanced-defense']]]), 422);
        self::assertSame([], $this->service->view($this->creator)['active']);
    }

    public function testGlobalAuthorizationAndStalePhase(): void
    {
        $item = $this->item();
        $campaign = new Campaign($this->creator->getCampaign()->getOwner(), 'outside-c2', 'Outside');
        $outsider = new Character($campaign, 'outsider-c2', 'Outsider', Character::TYPE_PLAYER);
        $this->em->persist($campaign); $this->em->persist($outsider); $this->em->flush();
        $outsideItem = $this->item('armor', $outsider);
        $this->rejects(fn () => $this->confirm([$this->selection('enhanced-defense', $outsideItem)]), 404);
        $otherActor = new CharacterSessionState($this->actor->getGameSession(), $this->recipient);
        $otherActor->setState($this->service->afterLongRest($this->recipient, []));
        $this->em->persist($otherActor); $this->em->flush();
        $this->rejects(fn () => $this->service->finishPreparation($otherActor, $this->service->preparation($otherActor)['id'], []));
        $wrong = $this->selection('enhanced-defense', $item); $wrong['recipientId'] = $this->recipient->getId();
        $this->rejects(fn () => $this->confirm([$wrong]), 404);
        $oldPhase = $this->service->preparation($this->actor)['id'];
        $this->rest();
        $this->rejects(fn () => $this->service->finishPreparation($this->actor, $oldPhase, [$this->selection('enhanced-defense', $item)]));
        $this->actor->setParticipating(false); $this->em->flush();
        $this->rejects(fn () => $this->confirm([]), 404);
    }

    public function testUnitEndpointsCannotBypassGlobalConfirmation(): void
    {
        $controller = $this->kernel->getContainer()->get('test.service_container')->get(\App\Controller\PlayerItemInfusionController::class);
        foreach ([true,false] as $open) {
            if (!$open) $this->confirm([]);
            $this->rejects(fn () => $controller->apply($this->actor->getAccessToken(), \Symfony\Component\HttpFoundation\Request::create('/', 'POST')));
            $this->rejects(fn () => $controller->remove($this->actor->getAccessToken(), 123));
        }
        self::assertSame([], $this->service->view($this->creator)['active']);
    }

    public function testGlobalWriteFailureRollsBackRetirementsAndInsertions(): void
    {
        $first = $this->item(); $second = $this->item(); $third = $this->item();
        $this->apply('enhanced-defense', $first);
        $before = $this->db->fetchAllAssociative('SELECT * FROM character_item_infusion ORDER BY id');
        $state = $this->actor->getState();
        $persistedState = $this->db->fetchOne('SELECT state FROM character_session_state WHERE id=?', [$this->actor->getId()]);
        $this->db->executeStatement("CREATE FUNCTION pg_temp.reject_infusion_insert() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF NEW.item_id=".$third->getId()." THEN RAISE EXCEPTION 'test failure'; END IF; RETURN NEW; END $$");
        $this->db->executeStatement('CREATE TRIGGER test_reject_infusion BEFORE INSERT ON character_item_infusion FOR EACH ROW EXECUTE FUNCTION pg_temp.reject_infusion_insert()');
        try {
            $this->confirm([$this->selection('enhanced-defense', $second), $this->selection('mind-sharpener', $third)]);
            self::fail('Expected database failure');
        } catch (\Doctrine\DBAL\Exception $error) {
            self::assertStringContainsString('test failure', $error->getMessage());
        }
        self::assertSame($before, $this->db->fetchAllAssociative('SELECT * FROM character_item_infusion ORDER BY id'));
        self::assertSame($state, $this->actor->getState());
        self::assertSame($persistedState, $this->db->fetchOne('SELECT state FROM character_session_state WHERE id=?', [$this->actor->getId()]));
    }

    public function testGlobalRefreshRejectsStaleConfirmationAndForeignSupport(): void
    {
        $item = $this->item(); $second = $this->item();
        $staleState = $this->actor->getState();
        $plan = [$this->selection('enhanced-defense', $item)];
        $result = $this->confirm($plan);
        // Simulate a second request still carrying the pre-confirmation session snapshot.
        $this->actor->setState($staleState);
        $this->rejects(fn () => $this->confirm([$this->selection('enhanced-defense', $second)]));
        self::assertSame($result['active'], $this->service->view($this->creator)['active']);
        self::assertFalse($this->service->preparation($this->actor)['open']);
        $this->rest();
        $key = bin2hex(random_bytes(16));
        $this->db->insert('character_feature_choice', ['key'=>$key, 'character_id'=>$this->recipient->getId(), 'grant_key'=>'infusions', 'option_key'=>'mind-sharpener', 'slot'=>1, 'acquired_position'=>2, 'provenance'=>'{}']);
        $this->db->insert('character_item_infusion', ['creator_id'=>$this->recipient->getId(), 'item_id'=>$second->getId(), 'choice_key'=>$key, 'item_name'=>'Other infusion', 'created_at'=>'2026-01-01 00:00:00', 'active'=>true]);
        $this->rejects(fn () => $this->confirm([$this->selection('enhanced-defense', $second)]));
        self::assertCount(1, $this->service->view($this->creator)['active']);
        self::assertCount(1, $this->service->view($this->recipient)['active']);
        self::assertTrue($this->service->preparation($this->actor)['open']);
    }

    private function rejects(callable $operation, int $status = 409): void
    {
        try { $operation(); self::fail('Expected rejection'); }
        catch (HttpExceptionInterface $error) { self::assertSame($status, $error->getStatusCode()); }
    }

    private function armorClass(Character $wearer): array
    {
        $container = $this->kernel->getContainer()->get('test.service_container');
        return $container->get(\App\Service\CharacterProfileSerializer::class)->serialize($wearer)['armorClass'];
    }

    private function equippedSupport(string $category): CharacterMagicItem
    {
        $item = $this->item($category, $this->recipient);
        $item->getMagicItem()->configureArmor($category === 'shield' ? 'shield' : 'light', $category === 'shield' ? 2 : 11);
        $this->recipient->addMagicItem($item);
        (new \App\Service\CharacterEquipmentService())->equip($this->recipient, $item, true);
        $this->em->flush();
        return $item;
    }

    public function testEnhancedDefenseShieldUsesCurrentCreatorLevelAndEquipment(): void
    {
        $base = $this->armorClass($this->recipient)['value'];
        $shield = $this->equippedSupport('shield');
        self::assertSame($base + 2, $this->armorClass($this->recipient)['value']);
        $this->apply('enhanced-defense', $shield);
        $snapshot = $this->db->fetchAllAssociative('SELECT * FROM magic_item');
        $owned = $this->db->fetchAllAssociative('SELECT * FROM character_magic_item');
        self::assertSame($base + 3, $this->armorClass($this->recipient)['value']);
        self::assertSame($base + 3, $this->armorClass($this->recipient)['value']);
        $container = $this->kernel->getContainer()->get('test.service_container');
        $effects = $container->get(\App\Service\ItemInfusionArmorEffects::class)->resolve($this->recipient);
        self::assertSame($base + 3, $container->get(\App\Service\CharacterArmorClassCalculator::class)->calculate($this->recipient, [...$effects, ...$effects])['value']);
        $other = new CharacterClass('fighter', 'Fighter', 10, 3);
        $this->em->persist($other); $this->levels($other, 8);
        self::assertSame($base + 3, $this->armorClass($this->recipient)['value']);
        $this->levels($this->artificer, 8);
        $result = $this->armorClass($this->recipient);
        self::assertSame($base + 4, $result['value']);
        $bonus = array_values(array_filter($result['contributions'], static fn ($c) => $c['kind'] === 'bonus'));
        self::assertCount(1, $bonus);
        self::assertSame('Défense améliorée', $bonus[0]['label']);
        self::assertSame(2, $bonus[0]['value']);
        self::assertStringContainsString($this->creator->getName(), $bonus[0]['source']);
        self::assertSame($snapshot, $this->db->fetchAllAssociative('SELECT * FROM magic_item'));
        self::assertSame($owned, $this->db->fetchAllAssociative('SELECT * FROM character_magic_item'));
        self::assertSame($base, $this->armorClass($this->creator)['value']);
        $shield->setEquipped(false); $this->em->flush();
        self::assertSame($base, $this->armorClass($this->recipient)['value']);
        $shield->setEquipped(true); $this->em->flush();
        self::assertSame($base + 4, $this->armorClass($this->recipient)['value']);
        $this->service->remove($this->actor, $this->service->view($this->creator)['active'][0]['id']);
        self::assertSame($base + 2, $this->armorClass($this->recipient)['value']);
    }

    public function testEnhancedDefenseArmorAndLostChoice(): void
    {
        $armor = $this->equippedSupport('armor');
        $base = $this->armorClass($this->recipient)['value'];
        $this->apply('enhanced-defense', $armor);
        self::assertSame($base + 1, $this->armorClass($this->recipient)['value']);
        $this->levels($this->artificer, 8);
        self::assertSame($base + 2, $this->armorClass($this->recipient)['value']);
        $this->db->executeStatement('UPDATE character_feature_choice SET active=FALSE WHERE key=?', [$this->keys['enhanced-defense']]);
        self::assertSame($base, $this->armorClass($this->recipient)['value']);
        $this->apply('mind-sharpener', $armor);
        self::assertSame($base, $this->armorClass($this->recipient)['value']);
    }

    public function testEnhancedDefenseFollowsPersistedPossessionAndDeletion(): void
    {
        $shield = $this->equippedSupport('shield');
        $this->apply('enhanced-defense', $shield);
        $resolver = $this->kernel->getContainer()->get('test.service_container')->get(\App\Service\ItemInfusionArmorEffects::class);
        self::assertCount(1, $resolver->resolve($this->recipient));
        // No general transfer API for armor exists yet; simulate a persisted ownership change.
        $this->db->executeStatement('UPDATE character_magic_item SET character_id=? WHERE id=?', [$this->creator->getId(), $shield->getId()]);
        self::assertSame([], $resolver->resolve($this->recipient));
        self::assertCount(1, $resolver->resolve($this->creator));
        $this->db->executeStatement('DELETE FROM character_magic_item WHERE id=?', [$shield->getId()]);
        self::assertSame([], $resolver->resolve($this->creator));
        $recipientId = $this->recipient->getId();
        $creatorId = $this->creator->getId();
        $this->em->clear();
        $recipient = $this->em->find(Character::class, $recipientId);
        $creator = $this->em->find(Character::class, $creatorId);
        self::assertSame($this->armorClass($recipient)['value'], $this->armorClass($creator)['value']);
    }

    public function testOwnAndOtherInventoryRemainUnchanged(): void
    {
        $own = $this->item(); $other = $this->item('shield', $this->recipient);
        $snapshot = $this->db->fetchAllAssociative('SELECT * FROM character_magic_item ORDER BY id');
        $definitions = $this->db->fetchAllAssociative('SELECT * FROM magic_item ORDER BY id');
        $characters = $this->db->fetchAllAssociative('SELECT * FROM character ORDER BY id');
        $this->apply('mind-sharpener', $own);
        $controller = $this->kernel->getContainer()->get('test.service_container')->get(\App\Controller\PlayerItemInfusionController::class);
        $response = $controller->confirm($this->actor->getAccessToken(), \Symfony\Component\HttpFoundation\Request::create('/', 'POST', server: ['CONTENT_TYPE'=>'application/json'], content: json_encode(['phaseId'=>$this->service->preparation($this->actor)['id'], 'selections'=>[$this->selection('mind-sharpener', $own), $this->selection('enhanced-defense', $other)]])));
        self::assertSame(200, $response->getStatusCode());
        $view = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(2, $view['active']);
        self::assertSame($this->recipient->getId(), $view['active'][1]['recipientId']);
        self::assertSame($snapshot, $this->db->fetchAllAssociative('SELECT * FROM character_magic_item ORDER BY id'));
        self::assertSame($definitions, $this->db->fetchAllAssociative('SELECT * FROM magic_item ORDER BY id'));
        self::assertSame($characters, $this->db->fetchAllAssociative('SELECT * FROM character ORDER BY id'));
        self::assertCount(2, $this->service->recipients($this->creator));
        self::assertTrue($this->service->items($this->creator, $this->recipient->getId(), $this->keys['enhanced-defense'])[0]['eligible']);
        $this->kernel->getContainer()->get('test.service_container')->get(\App\Service\CharacterRestService::class)->apply($this->actor, \App\Entity\RestRequest::TYPE_LONG_REST);
        $this->em->flush();
        self::assertCount(2, $this->service->view($this->creator)['active']);
    }

    public function testUnknownPrerequisiteReplicaAndPhysicalRejections(): void
    {
        $armor = $this->item();
        $this->rejects(fn () => $this->service->apply($this->actor, str_repeat('f',32), $this->creator->getId(), $armor->getId()));
        $this->rejects(fn () => $this->apply('repulsion-shield', $this->item('shield')));
        $this->rejects(fn () => $this->apply('replicate-bag-of-holding', $armor));
        $this->rejects(fn () => $this->apply('enhanced-defense', $this->item('armor', null, true)));
        $this->rejects(fn () => $this->apply('mind-sharpener', $this->item('shield')));
        $custom = CharacterMagicItem::custom($this->creator, 'Unknown');
        $this->em->persist($custom); $this->em->flush();
        $this->rejects(fn () => $this->apply('enhanced-defense', $custom));
        $custom->setQuantity(2); $this->em->flush();
        $this->rejects(fn () => $this->apply('enhanced-defense', $custom));
        self::assertSame(0, (int)$this->db->fetchOne('SELECT COUNT(*) FROM character_item_infusion'));
    }

    public function testCrossCampaignAndWrongRecipientAreRejected(): void
    {
        $campaign = new Campaign($this->creator->getCampaign()->getOwner(), 'other', 'Other');
        $outsider = new Character($campaign, 'outsider', 'Outsider', Character::TYPE_PLAYER);
        $this->em->persist($campaign); $this->em->persist($outsider); $this->em->flush();
        $this->rejects(fn () => $this->apply('enhanced-defense', $this->item('armor', $outsider)), 404);
        $item = $this->item('armor', $this->recipient);
        $this->rejects(fn () => $this->service->apply($this->actor, $this->keys['enhanced-defense'], $this->creator->getId(), $item->getId()), 404);
        $this->rejects(fn () => $this->service->items($this->creator, $outsider->getId(), $this->keys['enhanced-defense']), 404);
    }

    public function testUniquenessAndOldestEviction(): void
    {
        $first = $this->item(); $second = $this->item(); $third = $this->item();
        $this->apply('enhanced-defense', $first);
        $this->rejects(fn () => $this->apply('mind-sharpener', $first));
        $this->apply('mind-sharpener', $second);
        $this->db->beginTransaction();
        $this->apply('armor-of-magical-strength', $third);
        $this->db->rollBack();
        self::assertSame([$first->getId(),$second->getId()], array_column($this->service->view($this->creator)['active'],'itemId'));
        $view = $this->apply('armor-of-magical-strength', $third);
        self::assertSame([$second->getId(),$third->getId()], array_column($view['active'],'itemId'));
        self::assertSame('quota_replaced', $this->db->fetchOne('SELECT end_reason FROM character_item_infusion WHERE item_id=?',[$first->getId()]));
    }

    public function testClassLevelQuotaAndMulticlassing(): void
    {
        $other = new CharacterClass('fighter', 'Fighter', 10, 3);
        $this->em->persist($other); $this->levels($other, 8);
        self::assertSame(2, $this->service->view($this->creator)['quota']);
        $this->levels($this->artificer, 4);
        self::assertSame(3, $this->service->view($this->creator)['quota']);
        self::assertCount(6, $this->service->known($this->creator));
        $this->apply('enhanced-defense', $this->item());
        $this->apply('mind-sharpener', $this->item());
        self::assertCount(3, $this->apply('repulsion-shield', $this->item('shield'))['active']);
        foreach ([0=>0,1=>0,2=>2,5=>2,6=>3,9=>3,10=>4,13=>4,14=>5,17=>5,18=>6,20=>6] as $level=>$quota) self::assertSame($quota, ItemInfusions::quota($level));
    }

    public function testRemovalAndLostChoicePreserveHistory(): void
    {
        $first = $this->apply('enhanced-defense', $this->item())['active'][0];
        self::assertSame([], $this->service->remove($this->actor, $first['id'])['active']);
        self::assertSame('voluntary', $this->db->fetchOne('SELECT end_reason FROM character_item_infusion WHERE id=?',[$first['id']]));
        $item = $this->item(); $this->apply('mind-sharpener', $item);
        $this->db->executeStatement('UPDATE character_feature_choice SET active=FALSE WHERE key=?',[$this->keys['mind-sharpener']]);
        self::assertSame([], $this->service->view($this->creator)['active']);
        self::assertSame('choice_lost', $this->db->fetchOne('SELECT end_reason FROM character_item_infusion WHERE item_id=?',[$item->getId()]));
    }

    public function testDeletingItemEndsInfusionAndRollbackRestoresBoth(): void
    {
        $item = $this->item(); $this->apply('enhanced-defense', $item);
        $this->db->beginTransaction();
        $this->db->executeStatement('DELETE FROM character_magic_item WHERE id=?',[$item->getId()]);
        $row = $this->db->fetchAssociative('SELECT * FROM character_item_infusion');
        self::assertFalse($row['active']); self::assertNull($row['item_id']); self::assertSame('item_removed', $row['end_reason']);
        $this->db->rollBack();
        self::assertCount(1, $this->service->view($this->creator)['active']);
        self::assertSame($item->getId(), (int)$this->db->fetchOne('SELECT id FROM character_magic_item WHERE id=?',[$item->getId()]));
    }

    public function testPlayerAccessAndCreatorOnlyRemoval(): void
    {
        $id = $this->apply('enhanced-defense', $this->item())['active'][0]['id'];
        $otherActor = new CharacterSessionState($this->actor->getGameSession(), $this->recipient);
        $this->em->persist($otherActor); $this->em->flush();
        $this->rejects(fn () => $this->service->remove($otherActor, $id), 404);
        $item = $this->item();
        $this->rejects(fn () => $this->service->apply($otherActor, $this->keys['mind-sharpener'], $this->creator->getId(), $item->getId()));
        $access = $this->kernel->getContainer()->get('test.service_container')->get(PlayerCharacterAccess::class);
        $this->rejects(fn () => $access->requireParticipating(str_repeat('0',64)), 404);
        $this->actor->setParticipating(false); $this->em->flush();
        $this->rejects(fn () => $this->service->remove($this->actor, $id), 404);
        $this->actor->setParticipating(true);
        $this->actor->getGameSession()->setStatus(GameSession::STATUS_CLOSED); $this->em->flush();
        $this->rejects(fn () => $this->service->remove($this->actor, $id));
    }
}
