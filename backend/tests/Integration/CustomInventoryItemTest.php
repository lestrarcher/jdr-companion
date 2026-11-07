<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controller\PublicCharacterMagicItemController;
use App\Entity\{Campaign, Character, CharacterMagicItem, CharacterSessionState, GameSession, MagicItem, User};
use App\Enum\MagicItemRarity;
use App\Service\{CharacterAbilityCalculator, CharacterInventory, ItemCatalogue, MagicItemRechargeService, PactTomeLibrary, PlayerCharacterAccess, SpellbookLibrary};
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class CustomInventoryItemTest extends TestCase
{
    private \App\Kernel $kernel;
    private EntityManagerInterface $em;
    private \Doctrine\DBAL\Connection $db;

    protected function setUp(): void
    {
        $this->kernel = new \App\Kernel('test', true);
        $this->kernel->boot();
        $this->em = $this->kernel->getContainer()->get('doctrine')->getManager();
        $this->db = $this->em->getConnection();
        $this->db->beginTransaction();

        foreach ((new SchemaTool($this->em))->getCreateSchemaSql($this->em->getMetadataFactory()->getAllMetadata()) as $sql) {
            $sql = preg_replace('/^CREATE TABLE /', 'CREATE TEMP TABLE ', $sql);
            $sql = preg_replace('/^CREATE SEQUENCE /', 'CREATE TEMP SEQUENCE ', $sql);
            $this->db->executeStatement($sql);
        }
        foreach ($this->em->getMetadataFactory()->getAllMetadata() as $metadata) {
            self::assertSame('t', $this->db->fetchOne(
                'SELECT relpersistence FROM pg_class WHERE oid = to_regclass(?)',
                [$this->db->getDatabasePlatform()->quoteIdentifier($metadata->getTableName())],
            ));
        }
    }

    protected function tearDown(): void
    {
        try {
            while ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
        } finally {
            $this->em->clear();
            $this->kernel->shutdown();
        }
    }

    private function fixture(): array
    {
        $owner = (new User())->setEmail('custom-inventory@example.invalid')->setPassword('unused');
        $campaign = new Campaign($owner, 'custom-inventory', 'Inventory');
        $character = new Character($campaign, 'hero', 'Hero', Character::TYPE_PLAYER);
        $other = new Character($campaign, 'other', 'Other', Character::TYPE_PLAYER);
        $session = (new GameSession($campaign, 'live', 'Live'))->setStatus(GameSession::STATUS_LIVE);
        $state = new CharacterSessionState($session, $character);
        $otherState = new CharacterSessionState($session, $other);
        $definition = (new MagicItem($campaign, 'Baguette', MagicItemRarity::Rare))->setMaximumCharges(5);
        $wand = (new CharacterMagicItem($character, $definition))->setCurrentCharges(2)->setEquipped(true);
        $character->addMagicItem($wand);
        foreach ([$owner, $campaign, $character, $other, $session, $state, $otherState, $definition, $wand] as $entity) $this->em->persist($entity);
        $this->em->flush();
        return [$state, $otherState, $wand];
    }

    private function controller(): PublicCharacterMagicItemController
    {
        $controller = new PublicCharacterMagicItemController(new SpellbookLibrary($this->em), new PactTomeLibrary($this->em));
        $controller->setContainer(new Container());
        return $controller;
    }

    private function access(): PlayerCharacterAccess
    {
        return new PlayerCharacterAccess($this->em->getRepository(CharacterSessionState::class));
    }

    private function create(string $token, array $payload): \Symfony\Component\HttpFoundation\JsonResponse
    {
        return $this->controller()->createCustom($token,
            Request::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload, JSON_THROW_ON_ERROR)),
            $this->access(), new CharacterInventory($this->em, new ItemCatalogue($this->em)), new CharacterAbilityCalculator(), $this->em);
    }

    private function update(string $token, int $id, array $payload): \Symfony\Component\HttpFoundation\JsonResponse
    {
        return $this->controller()->update($token, $id,
            Request::create('/', 'PATCH', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload, JSON_THROW_ON_ERROR)),
            $this->access(), $this->em->getRepository(CharacterMagicItem::class), new CharacterAbilityCalculator(), $this->em);
    }

    public function testCreateReloadListAndDeleteWithoutCatalogueEntry(): void
    {
        [$state, $otherState, $wand] = $this->fixture();
        $token = $state->getAccessToken();
        $otherToken = $otherState->getAccessToken();
        $wandId = $wand->getId();
        $response = $this->create($token, ['name' => '  Lettre scellée  ', 'description' => 'Une lettre personnelle.', 'quantity' => 3]);
        self::assertSame(201, $response->getStatusCode());
        $items = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR)['ownedItems'];
        $custom = array_values(array_filter($items, fn ($item) => $item['custom']))[0];
        self::assertNull($custom['magicItem']['id']);
        self::assertSame('Lettre scellée', $custom['magicItem']['name']);
        self::assertSame('Une lettre personnelle.', $custom['magicItem']['description']);
        self::assertFalse($custom['magicItem']['equippable']);
        self::assertFalse($custom['effectActive']);
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM magic_item'));
        $id = $custom['id'];
        $this->em->clear();
        $owned = $this->em->find(CharacterMagicItem::class, $id);
        self::assertNull($owned->getMagicItem());
        self::assertSame(3, $owned->getQuantity());
        self::assertSame('Une lettre personnelle.', $owned->getCustomDescription());
        $list = $this->controller()->list($token, $this->access(), new CharacterAbilityCalculator());
        self::assertCount(2, json_decode($list->getContent(), true)['ownedItems']);
        self::assertSame(['items' => 0, 'grants' => 0], (new MagicItemRechargeService($this->em))->apply($owned->getCharacter(), 'long-rest'));
        self::assertSame(404, $this->update($otherToken, $id, ['discardQuantity' => 3])->getStatusCode());
        self::assertSame(200, $this->update($token, $id, ['discardQuantity' => 1])->getStatusCode());
        self::assertSame(2, (int) $this->db->fetchOne('SELECT quantity FROM character_magic_item WHERE id = ?', [$id]));
        self::assertSame(200, $this->update($token, $id, ['discardQuantity' => 2])->getStatusCode());
        self::assertFalse($this->db->fetchOne('SELECT id FROM character_magic_item WHERE id = ?', [$id]));
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM magic_item'));
        $this->em->clear();
        $wand = $this->em->find(CharacterMagicItem::class, $wandId);
        self::assertSame(2, $wand->getCurrentCharges());
        self::assertTrue($wand->isEquipped());
    }

    public function testDefaultQuantityAndOptionalDescription(): void
    {
        [$state] = $this->fixture();
        self::assertSame(201, $this->create($state->getAccessToken(), ['name' => '0'])->getStatusCode());
        $owned = $this->em->getRepository(CharacterMagicItem::class)->findOneBy(['magicItem' => null]);
        self::assertSame('0', $owned->getDisplayName());
        self::assertSame(1, $owned->getQuantity());
        self::assertNull($owned->getCustomDescription());
    }

    public function testMigrationPreservesExistingPossessionsAndAllowsCustomOnes(): void
    {
        [$state] = $this->fixture();
        $this->db->executeStatement('ALTER TABLE character_magic_item DROP COLUMN custom_description');
        $this->db->executeStatement('ALTER TABLE character_magic_item ALTER magic_item_id SET NOT NULL');
        $before = $this->db->fetchAllAssociative('SELECT * FROM character_magic_item ORDER BY id');
        require_once __DIR__.'/../../migrations/Version20261106010000.php';
        $migration = new \DoctrineMigrations\Version20261106010000($this->db, new \Psr\Log\NullLogger());
        $migration->up(new \Doctrine\DBAL\Schema\Schema());
        foreach ($migration->getSql() as $query) {
            $this->db->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
        $after = $this->db->fetchAllAssociative('SELECT * FROM character_magic_item ORDER BY id');
        foreach ($after as &$row) unset($row['custom_description']);
        unset($row);
        self::assertSame($before, $after);
        self::assertSame(201, $this->create($state->getAccessToken(), ['name' => 'Objet après migration'])->getStatusCode());
    }

    #[DataProvider('forbiddenMutations')]
    public function testCustomItemCannotAcquireMagicCapabilities(array $payload): void
    {
        [$state] = $this->fixture();
        $response = $this->create($state->getAccessToken(), ['name' => 'Souvenir']);
        $items = json_decode($response->getContent(), true)['ownedItems'];
        $custom = array_values(array_filter($items, fn ($item) => $item['custom']))[0];
        try {
            $status = $this->update($state->getAccessToken(), $custom['id'], $payload)->getStatusCode();
        } catch (HttpExceptionInterface $exception) {
            $status = $exception->getStatusCode();
        }
        self::assertSame(422, $status);
    }

    public static function forbiddenMutations(): array
    {
        return [[['equipped' => true]], [['attuned' => true]], [['chargeChange' => 1]], [['consume' => true]]];
    }

    #[DataProvider('invalidPayloads')]
    public function testInvalidPayloadIsRejected(array $payload): void
    {
        [$state] = $this->fixture();
        try {
            $status = $this->create($state->getAccessToken(), $payload)->getStatusCode();
        } catch (HttpExceptionInterface $exception) {
            $status = $exception->getStatusCode();
        }
        self::assertSame(422, $status);
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM character_magic_item'));
    }

    public static function invalidPayloads(): array
    {
        return [
            [[]], [['name' => '   ']], [['name' => str_repeat('é', 201)]],
            [['name' => 'Objet', 'description' => []]], [['name' => 'Objet', 'description' => str_repeat('a', 10001)]],
            [['name' => 'Objet', 'quantity' => 0]], [['name' => 'Objet', 'quantity' => 10000]],
            [['name' => 'Objet', 'quantity' => 1.5]], [['name' => 'Objet', 'quantity' => '2']],
            [['name' => 'Objet', 'quantity' => null]],
            [['name' => 'Objet', 'magicItemId' => 1]], [['name' => 'Objet', 'equipped' => true]],
        ];
    }

    #[DataProvider('deniedAccess')]
    public function testExistingAccessRulesAreEnforced(string $mode, int $expectedStatus): void
    {
        [$state] = $this->fixture();
        if ($mode === 'not-participating') $state->setParticipating(false);
        if ($mode === 'closed') $state->getGameSession()->setStatus(GameSession::STATUS_CLOSED);
        $this->em->flush();
        try {
            $this->create($mode === 'invalid-token' ? str_repeat('0', 64) : $state->getAccessToken(), ['name' => 'Objet']);
            self::fail('Access should be denied.');
        } catch (HttpExceptionInterface $exception) {
            self::assertSame($expectedStatus, $exception->getStatusCode());
        }
    }

    public static function deniedAccess(): array
    {
        return [['invalid-token', 404], ['not-participating', 404], ['closed', 409]];
    }

    public function testQuantityChangesPersistAndZeroDeletes(): void
    {
        [$state, $other, $wand] = $this->fixture();
        $token = $state->getAccessToken();
        $this->create($token, ['name' => 'Flèche', 'quantity' => 2]);
        $id = (int) $this->db->fetchOne('SELECT id FROM character_magic_item WHERE magic_item_id IS NULL');
        self::assertSame(404, $this->update($other->getAccessToken(), $id, ['quantityChange' => 1])->getStatusCode());
        self::assertSame(422, $this->update($token, $wand->getId(), ['quantityChange' => 1])->getStatusCode());
        foreach ([[3, 5], [-2, 3]] as [$change, $expected]) {
            $response = $this->update($token, $id, ['quantityChange' => $change]);
            self::assertSame(200, $response->getStatusCode());
            self::assertSame($expected, (int) $this->db->fetchOne('SELECT quantity FROM character_magic_item WHERE id = ?', [$id]));
            $this->em->clear();
            self::assertSame($expected, $this->em->find(CharacterMagicItem::class, $id)->getQuantity());
        }
        foreach ([0, -4, 9999, 1.5, '1', null, PHP_INT_MAX] as $change) {
            self::assertSame(422, $this->update($token, $id, ['quantityChange' => $change])->getStatusCode());
        }
        self::assertSame(422, $this->update($token, $id, ['quantityChange' => 1, 'equipped' => true])->getStatusCode());
        self::assertSame(3, (int) $this->db->fetchOne('SELECT quantity FROM character_magic_item WHERE id = ?', [$id]));
        self::assertSame(200, $this->update($token, $id, ['quantityChange' => -3])->getStatusCode());
        self::assertFalse($this->db->fetchOne('SELECT id FROM character_magic_item WHERE id = ?', [$id]));
    }

    public function testCompatibleNamesMergeButDescriptionsAndCharactersStaySeparate(): void
    {
        [$state, $other] = $this->fixture();
        $token = $state->getAccessToken();
        $this->create($token, ['name' => 'Éclat de verre', 'description' => 'Bleu', 'quantity' => 2]);
        $id = (int) $this->db->fetchOne('SELECT id FROM character_magic_item WHERE magic_item_id IS NULL');
        $this->em->clear();
        self::assertSame(201, $this->create($token, ['name' => "  ÉCLAT\t de\u{00A0}  VERRE  ", 'description' => 'Bleu', 'quantity' => 3])->getStatusCode());
        self::assertSame(5, (int) $this->db->fetchOne('SELECT quantity FROM character_magic_item WHERE id = ?', [$id]));
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM character_magic_item WHERE magic_item_id IS NULL'));
        $this->create($token, ['name' => 'éclat de verre', 'description' => 'Rouge']);
        $this->create($other->getAccessToken(), ['name' => 'Éclat de verre', 'description' => 'Bleu']);
        self::assertSame(3, (int) $this->db->fetchOne('SELECT COUNT(*) FROM character_magic_item WHERE magic_item_id IS NULL'));
        $this->em->clear();
        self::assertSame(5, $this->em->find(CharacterMagicItem::class, $id)->getQuantity());
    }

    public function testAbsentDescriptionsMergeAndIndividualItemsDoNot(): void
    {
        [$state, , $wand] = $this->fixture();
        $token = $state->getAccessToken();
        $this->create($token, ['name' => 'Souvenir']);
        $this->create($token, ['name' => 'SOUVENIR', 'description' => '   ']);
        $owned = $this->em->getRepository(CharacterMagicItem::class)->findOneBy(['magicItem' => null]);
        self::assertSame(2, $owned->getQuantity());
        $owned->setNotes('Exemplaire signé');
        $this->em->flush();
        $this->create($token, ['name' => 'Souvenir']);
        $this->create($token, ['name' => 'Baguette']);
        self::assertSame(2, (int) $this->db->fetchOne('SELECT quantity FROM character_magic_item WHERE id = ?', [$owned->getId()]));
        self::assertSame(1, $wand->getQuantity());
        self::assertSame(2, $wand->getCurrentCharges());
        self::assertSame(4, (int) $this->db->fetchOne('SELECT COUNT(*) FROM character_magic_item'));
    }

    public function testMergeOverflowDoesNotCreateOrChangeAPossession(): void
    {
        [$state] = $this->fixture();
        $token = $state->getAccessToken();
        $this->create($token, ['name' => 'Flèche', 'quantity' => 9999]);
        try {
            $this->create($token, ['name' => 'FLÈCHE']);
            self::fail('Overflow must be rejected.');
        } catch (HttpExceptionInterface $exception) {
            self::assertSame(422, $exception->getStatusCode());
        }
        self::assertSame(9999, (int) $this->db->fetchOne('SELECT quantity FROM character_magic_item WHERE magic_item_id IS NULL'));
        self::assertSame(2, (int) $this->db->fetchOne('SELECT COUNT(*) FROM character_magic_item'));
    }
}
