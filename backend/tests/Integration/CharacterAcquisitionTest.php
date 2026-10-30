<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Enum\ReferenceOrigin;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use App\Controller\PublicCharacterMagicItemController;
use App\Entity\Campaign;
use App\Entity\Character;
use App\Entity\CharacterSessionState;
use App\Entity\GameSession;
use App\Entity\MagicItem;
use App\Entity\User;
use App\Enum\MagicItemRarity;
use App\Repository\CharacterSessionStateRepository;
use App\Service\CharacterAbilityCalculator;
use App\Service\CharacterInventory;
use App\Service\ItemCatalogue;
use App\Service\PlayerCharacterAccess;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class CharacterAcquisitionTest extends TestCase
{
    private $kernel;
    private EntityManagerInterface $em;
    private $db;
    private $registry;

    protected function setUp(): void
    {
        $this->kernel = new \App\Kernel('test', true);
        $this->kernel->boot();

        $this->registry = $this->kernel
            ->getContainer()
            ->get('doctrine');

        $this->em = $this->registry->getManager();
        $this->db = $this->em->getConnection();

        $this->db->beginTransaction();

        $metadata = $this->em->getMetadataFactory()->getAllMetadata();

        foreach ((new SchemaTool($this->em))->getCreateSchemaSql($metadata) as $sql) {
            $sql = preg_replace('/^CREATE TABLE /', 'CREATE TEMP TABLE ', $sql);
            $sql = preg_replace('/^CREATE SEQUENCE /', 'CREATE TEMP SEQUENCE ', $sql);

            $this->db->executeStatement($sql);
        }

        // Vérification indispensable : aucune table permanente ne doit être utilisée.
        foreach ($metadata as $class) {
            $table = $this->db->getDatabasePlatform()
                ->quoteIdentifier($class->getTableName());

            self::assertSame(
                't',
                $this->db->fetchOne(
                    'SELECT relpersistence FROM pg_class WHERE oid = to_regclass(?)',
                    [$table]
                )
            );
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
        $user = (new User())
            ->setEmail('inventory-test@example.invalid')
            ->setPassword('unused');

        $campaign = new Campaign($user, 'inventory-test', 'Inventory Test');

        $character = new Character(
            $campaign,
            'hero',
            'Hero',
            Character::TYPE_PLAYER
        );

        $session = (new GameSession(
            $campaign,
            'inventory-session',
            'Inventory Session'
        ))->setStatus(GameSession::STATUS_LIVE);

        $state = new CharacterSessionState($session, $character);

        $item = new MagicItem(
            $campaign,
            'Épée de test',
            MagicItemRarity::Rare
        );

        foreach ([$user, $campaign, $character, $session, $state, $item] as $entity) {
            $this->em->persist($entity);
        }

        $this->em->flush();

        return [$character, $session, $state, $item];
    }

    private function controller(): PublicCharacterMagicItemController
    {
        $controller = new PublicCharacterMagicItemController();
        $controller->setContainer(new Container());

        return $controller;
    }

    private function access(): PlayerCharacterAccess
    {
        return new PlayerCharacterAccess(
            new CharacterSessionStateRepository($this->registry)
        );
    }

    private function request(int $itemId, int $quantity = 1): Request
    {
        return Request::create(
            '/',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'magicItemId' => $itemId,
                'quantity' => $quantity,
            ], JSON_THROW_ON_ERROR)
        );
    }

    private function getHttpStatus(callable $operation): int
    {
        try {
            return $operation()->getStatusCode();
        } catch (HttpExceptionInterface $exception) {
            return $exception->getStatusCode();
        }
    }

    public function testGmCanAcquireAnIndividualItem(): void
    {
        [$character, , , $item] = $this->fixture();

        $inventory = new CharacterInventory(
            $this->em,
            new ItemCatalogue($this->em)
        );

        $owned = $inventory->acquire(
            $character,
            $item->getId(),
            2
        );

        self::assertCount(2, $owned);
        self::assertNotSame($owned[0]->getId(), $owned[1]->getId());
        self::assertSame(1, $owned[0]->getQuantity());
        self::assertSame(1, $owned[1]->getQuantity());

        self::assertSame(
            2,
            (int) $this->db->fetchOne(
                'SELECT COUNT(*) FROM character_magic_item WHERE character_id = ?',
                [$character->getId()]
            )
        );
    }

    public function testInvalidPlayerTokenIsRejected(): void
    {
        $this->fixture();

        $status = $this->getHttpStatus(fn () => $this->controller()->acquire(
            str_repeat('0', 64),
            $this->request(1),
            $this->access(),
            new CharacterInventory($this->em, new ItemCatalogue($this->em)),
            new CharacterAbilityCalculator(),
            $this->em
        ));

        self::assertSame(404, $status);
    }

    public function testNonParticipatingPlayerIsRejected(): void
    {
        [, , $state, $item] = $this->fixture();

        $token = $state->getAccessToken();
        $state->setParticipating(false);
        $this->em->flush();

        $status = $this->getHttpStatus(fn () => $this->controller()->acquire(
            $token,
            $this->request($item->getId()),
            $this->access(),
            new CharacterInventory($this->em, new ItemCatalogue($this->em)),
            new CharacterAbilityCalculator(),
            $this->em
        ));

        self::assertSame(404, $status);
    }

    public function testClosedSessionRejectsAcquisition(): void
    {
        [, $session, $state, $item] = $this->fixture();

        $token = $state->getAccessToken();
        $session->setStatus(GameSession::STATUS_CLOSED);
        $this->em->flush();

        $status = $this->getHttpStatus(fn () => $this->controller()->acquire(
            $token,
            $this->request($item->getId()),
            $this->access(),
            new CharacterInventory($this->em, new ItemCatalogue($this->em)),
            new CharacterAbilityCalculator(),
            $this->em
        ));

        self::assertSame(409, $status);
        self::assertSame(
            0,
            (int) $this->db->fetchOne('SELECT COUNT(*) FROM character_magic_item')
        );
    }


    private function acquireAsPlayer(
        CharacterSessionState $state,
        int $itemId,
        int $quantity = 1
    ): array {
        $response = $this->controller()->acquire(
            $state->getAccessToken(),
            $this->request($itemId, $quantity),
            $this->access(),
            new CharacterInventory($this->em, new ItemCatalogue($this->em)),
            new CharacterAbilityCalculator(),
            $this->em
        );

        self::assertSame(201, $response->getStatusCode());

        return json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

private function officialItem(string $name, string $category): MagicItem
{
    $item = new MagicItem(
        null,
        $name,
        MagicItemRarity::Common,
        null,
        ReferenceOrigin::Official
    );

    if ($category === 'weapon') {
        $item->classify('weapon', true, true, 'hand', 'one');
    } else {
        $item->classify($category, true, false);
    }

    $this->em->persist($item);
    $this->em->flush();

    return $item;
}

    public function testPlayerCanAcquireOfficialItem(): void
    {
        [$character, , $state] = $this->fixture();
        $item = $this->officialItem('Épée officielle', 'weapon');

        $result = $this->acquireAsPlayer($state, $item->getId());

        self::assertCount(1, $result['acquiredItems']);
        self::assertSame($item->getId(), $result['acquiredItems'][0]['magicItem']['id']);
        self::assertSame($character->getId(), $result['characterId']);
        self::assertCount(1, $result['ownedItems']);
    }

    public function testPotionsAreStacked(): void
    {
        [, , $state] = $this->fixture();
        $potion = $this->officialItem('Potion de test', 'potion');

        $first = $this->acquireAsPlayer($state, $potion->getId(), 3);

        self::assertCount(1, $first['acquiredItems']);
        self::assertSame(3, $first['acquiredItems'][0]['quantity']);

        $second = $this->acquireAsPlayer($state, $potion->getId(), 2);

        self::assertCount(1, $second['acquiredItems']);
        self::assertSame(5, $second['acquiredItems'][0]['quantity']);
        self::assertSame(
            $first['acquiredItems'][0]['id'],
            $second['acquiredItems'][0]['id']
        );

        self::assertSame(
            1,
            (int) $this->db->fetchOne(
                'SELECT COUNT(*) FROM character_magic_item WHERE magic_item_id = ?',
                [$potion->getId()]
            )
        );
    }

    public function testWeaponsRemainIndividual(): void
    {
        [, , $state] = $this->fixture();
        $weapon = $this->officialItem('Épée individuelle', 'weapon');

        $result = $this->acquireAsPlayer($state, $weapon->getId(), 2);

        self::assertCount(2, $result['acquiredItems']);
        self::assertCount(2, $result['ownedItems']);

        self::assertNotSame(
            $result['acquiredItems'][0]['id'],
            $result['acquiredItems'][1]['id']
        );

        foreach ($result['acquiredItems'] as $owned) {
            self::assertSame(1, $owned['quantity']);
        }
    }

    public function testUnpublishedCampaignItemIsRejected(): void
    {
        [, , $state, $item] = $this->fixture();

        // L'objet de fixture est privé à la campagne et non publié.
        $status = $this->getHttpStatus(fn () => $this->controller()->acquire(
            $state->getAccessToken(),
            $this->request($item->getId()),
            $this->access(),
            new CharacterInventory($this->em, new ItemCatalogue($this->em)),
            new CharacterAbilityCalculator(),
            $this->em
        ));

        self::assertSame(404, $status);
        self::assertSame(
            0,
            (int) $this->db->fetchOne('SELECT COUNT(*) FROM character_magic_item')
        );
    }

    #[DataProvider('invalidQuantities')]
    public function testInvalidQuantityIsRejected(int $quantity): void
    {
        [, , $state] = $this->fixture();
        $item = $this->officialItem('Potion invalide', 'potion');

        $status = $this->getHttpStatus(fn () => $this->controller()->acquire(
            $state->getAccessToken(),
            $this->request($item->getId(), $quantity),
            $this->access(),
            new CharacterInventory($this->em, new ItemCatalogue($this->em)),
            new CharacterAbilityCalculator(),
            $this->em
        ));

        self::assertSame(422, $status);
    }

    public static function invalidQuantities(): array
    {
        return [
            'quantité négative' => [-1],
            'quantité nulle' => [0],
            'quantité excessive' => [10000],
        ];
    }
}
