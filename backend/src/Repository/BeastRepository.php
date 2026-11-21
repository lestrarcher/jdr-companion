<?php
declare(strict_types=1);
namespace App\Repository;
use App\Entity\Beast;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Beast> */
class BeastRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, Beast::class); }
    /** @return list<Beast> */
    public function catalogue(): array
    {
        return $this->createQueryBuilder('b')
            ->leftJoin('b.habitats', 'h')->addSelect('h')
            ->leftJoin('b.naturalWeapons', 'w')->addSelect('w')
            ->leftJoin('b.features', 'bf')->addSelect('bf')
            ->leftJoin('bf.definition', 'f')->addSelect('f')
            ->orderBy('b.slug', 'ASC')->getQuery()->getResult();
    }
    public function isReferenced(int $id): bool
    {
        // Session state is intentionally JSON, not an implicit session lookup in a rules resolver.
        $db = $this->getEntityManager()->getConnection();
        return (bool) $db->fetchOne('SELECT 1 FROM character_seen_beast WHERE beast_id = ?', [$id])
            || (bool) $db->fetchOne("SELECT 1 FROM character_session_state WHERE state->'wildShape'->'active'->>'beastId' = ? LIMIT 1", [(string) $id]);
    }
}
