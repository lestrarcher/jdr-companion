<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controller\MagicItemController;
use App\Entity\Campaign;
use App\Entity\MagicItem;
use App\Entity\MagicItemSpellGrant;
use App\Entity\SpellDefinition;
use App\Entity\User;
use App\Enum\MagicItemRarity;
use App\Enum\SpellSchool;
use App\Service\SpellCatalogue;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class MagicItemSpellGrantPersistenceTest extends TestCase
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
        $owner = (new User())->setEmail('grant-owner@example.invalid')->setPassword('unused');
        $other = (new User())->setEmail('grant-other@example.invalid')->setPassword('unused');
        $campaign = new Campaign($owner, 'grant-campaign', 'Grant campaign');
        $item = new MagicItem($campaign, 'Fragment de test', MagicItemRarity::Rare);
        $item->setRequiresAttunement(true);
        $spell = SpellDefinition::forOwner(
            $owner, 'grant-test-spell', 'Sort de test', 5, SpellSchool::cases()[0],
            '1 action', 'Contact', 'Instantanée', 'Description de test',
        );
        $foreignSpell = SpellDefinition::forOwner(
            $other, 'foreign-grant-spell', 'Sort étranger', 1, SpellSchool::cases()[0],
            '1 action', 'Contact', 'Instantanée', 'Description de test',
        );
        foreach ([$owner, $other, $campaign, $item, $spell, $foreignSpell] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        return [$owner, $item, $spell, $foreignSpell];
    }

    public function testGrantPersistsAndAppearsInControllerSerialization(): void
    {
        [, $item, $spell] = $this->fixture();
        $item->addSpellGrant(new MagicItemSpellGrant($item, $spell, 'attuned', 'limited', 1, 1, 'dawn'));
        $this->em->flush();
        $itemId = $item->getId();
        $this->em->clear();

        $reloaded = $this->em->find(MagicItem::class, $itemId);
        self::assertNotNull($reloaded);
        self::assertCount(1, $reloaded->getSpellGrants());

        // Test the real controller serializer without bypassing a route's access checks.
        $method = new \ReflectionMethod(MagicItemController::class, 'serializeItem');
        $data = $method->invoke(new MagicItemController(), $reloaded);
        self::assertCount(1, $data['spellGrants']);
        self::assertSame($spell->getId(), $data['spellGrants'][0]['spellId']);
        self::assertSame('Sort de test', $data['spellGrants'][0]['spellName']);
        self::assertSame('attuned', $data['spellGrants'][0]['activation']);
        self::assertSame('limited', $data['spellGrants'][0]['costMode']);
        self::assertSame(1, $data['spellGrants'][0]['usesPerReset']);
        self::assertSame('dawn', $data['spellGrants'][0]['resetTrigger']);
    }

    public function testCatalogueRejectsAnotherOwnersCustomSpell(): void
    {
        [$owner, , , $foreignSpell] = $this->fixture();
        $catalogue = new SpellCatalogue($this->db);
        $this->expectException(NotFoundHttpException::class);
        $catalogue->get($foreignSpell->getId(), $owner);
    }

    public function testDuplicateGrantIsRejectedByDatabase(): void
    {
        [, $item, $spell] = $this->fixture();
        $item->addSpellGrant(new MagicItemSpellGrant($item, $spell, 'attuned', 'limited', 1, 1, 'dawn'));
        $this->em->flush();
        $this->expectException(\Doctrine\DBAL\Exception\UniqueConstraintViolationException::class);
        $item->addSpellGrant(new MagicItemSpellGrant($item, $spell, 'attuned', 'limited', 1, 1, 'dawn'));
        $this->em->flush();
    }
}
