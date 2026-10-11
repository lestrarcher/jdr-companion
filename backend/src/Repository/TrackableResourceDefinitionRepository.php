<?php

namespace App\Repository;

use App\Entity\TrackableResourceDefinition;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use App\Entity\User;
use App\Service\ReferenceVisibility;

/**
 * @extends ServiceEntityRepository<TrackableResourceDefinition>
 */
class TrackableResourceDefinitionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TrackableResourceDefinition::class);
    }

    /** @param list<string> $slugs
     *  @return list<TrackableResourceDefinition>
     */
    public function findVisibleBySlugs(array $slugs, User $owner): array
    {
        if ($slugs === []) return [];
        $query = $this->createQueryBuilder('resource')
            ->andWhere('resource.slug IN (:slugs)')->setParameter('slugs', $slugs);

        return ReferenceVisibility::scope($query, 'resource', $owner)->getQuery()->getResult();
    }

    //    /**
    //     * @return TrackableResourceDefinition[] Returns an array of TrackableResourceDefinition objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('t')
    //            ->andWhere('t.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('t.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?TrackableResourceDefinition
    //    {
    //        return $this->createQueryBuilder('t')
    //            ->andWhere('t.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
