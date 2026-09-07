<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\TrackableResourceRule;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class TrackableResourceRuleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TrackableResourceRule::class);
    }

    /**
     * @return list<TrackableResourceRule>
     */
    public function findOrderedRules(): array
    {
        return $this->createQueryBuilder('rule')
            ->addSelect('resource')
            ->join('rule.resourceDefinition', 'resource')
            ->orderBy('resource.id', 'ASC')
            ->addOrderBy('rule.unlockLevel', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
