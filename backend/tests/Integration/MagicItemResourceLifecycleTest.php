<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controller\MagicItemController;
use App\Entity\Campaign;
use App\Entity\Character;
use App\Entity\CharacterMagicItem;
use App\Entity\CharacterMagicItemSpellUsage;
use App\Enum\MagicItemRechargeType;
use App\Service\MagicItemRechargeService;
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

final class MagicItemResourceLifecycleTest extends TestCase
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
        $owner = (new User())->setEmail('lifecycle@example.invalid')->setPassword('unused');
        $campaign = new Campaign($owner, 'lifecycle', 'Lifecycle');
        $character = new Character($campaign, 'hero', 'Hero', Character::TYPE_PLAYER);
        $item = new MagicItem($campaign, 'Baguette de test', MagicItemRarity::Rare);
        $item->setMaximumCharges(6)->setRechargeType(MagicItemRechargeType::Daily)->setRechargeFormula('2');
        $spell = SpellDefinition::forOwner($owner, 'spell-lifecycle', 'Sort test', 2, SpellSchool::cases()[0], '1 action', 'Contact', 'Instantanée', 'Test');
        $grant = new MagicItemSpellGrant($item, $spell, 'possession', 'limited', 1, 2, 'short-rest');
        $item->addSpellGrant($grant);
        $owned1 = new CharacterMagicItem($character, $item);
        $owned2 = new CharacterMagicItem($character, $item);
        $character->addMagicItem($owned1)->addMagicItem($owned2);
        foreach ([$owner, $campaign, $character, $item, $spell, $grant, $owned1, $owned2] as $entity) $this->em->persist($entity);
        $this->em->flush();
        return [$character, $owned1, $owned2, $grant];
    }

    public function testLimitedUsesAreIndependentPerPhysicalCopyAndPersist(): void
    {
        [, $first, $second, $grant] = $this->fixture();
        $a = new CharacterMagicItemSpellUsage($first, $grant);
        $b = new CharacterMagicItemSpellUsage($second, $grant);
        $a->spend();
        $a->spend();
        $this->em->persist($a);
        $this->em->persist($b);
        $this->em->flush();
        $firstId = $first->getId();
        $secondId = $second->getId();
        $this->em->clear();
        $firstUsage = $this->em->getRepository(CharacterMagicItemSpellUsage::class)->findOneBy(['ownedItem' => $firstId]);
        $secondUsage = $this->em->getRepository(CharacterMagicItemSpellUsage::class)->findOneBy(['ownedItem' => $secondId]);
        self::assertSame(0, $firstUsage->getRemaining());
        self::assertSame(2, $secondUsage->getRemaining());
        $this->expectException(\DomainException::class);
        $firstUsage->spend();
    }

    public function testShortRestRestoresLimitedUsesButNotDailyCharges(): void
    {
        [$character, $first, , $grant] = $this->fixture();
        $usage = new CharacterMagicItemSpellUsage($first, $grant);
        $usage->spend();
        $first->setCurrentCharges(1);
        $this->em->persist($usage);
        $this->em->flush();
        $service = new MagicItemRechargeService($this->em);
        $result = $service->apply($character, 'short-rest');
        $this->em->flush();
        self::assertSame(2, $usage->getRemaining());
        self::assertSame(1, $first->getCurrentCharges());
        self::assertSame(1, $result['grants']);
        self::assertSame(0, $result['items']);
    }

    public function testDawnRechargesOnlyDailyChargesAndCapsAtMaximum(): void
    {
        [$character, $first, $second, $grant] = $this->fixture();
        $usage = new CharacterMagicItemSpellUsage($first, $grant);
        $usage->spend();
        $this->em->persist($usage);
        $first->setCurrentCharges(5);
        $second->setCurrentCharges(1);
        $this->em->flush();
        $result = (new MagicItemRechargeService($this->em))->apply($character, 'dawn');
        $this->em->flush();
        self::assertSame(6, $first->getCurrentCharges());
        self::assertSame(3, $second->getCurrentCharges());
        self::assertSame(1, $usage->getRemaining());
        self::assertSame(2, $result['items']);
        self::assertSame(0, $result['grants']);
    }
}
