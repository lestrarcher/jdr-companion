<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\TrackableResourceRule;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\ORM\QueryBuilder;
use App\Entity\User;
use App\Service\ReferenceVisibility;

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
        return $this->orderedQuery()->getQuery()->getResult();
    }

    /** Same ordering as findOrderedRules(), scoped before hydration. */
    public function findVisibleForOwner(User $owner): array
    {
        return ReferenceVisibility::scope($this->orderedQuery(), 'rule', $owner)->getQuery()->getResult();
    }

    private function orderedQuery(): QueryBuilder
    {
        return $this->createQueryBuilder('rule')
            ->addSelect('resource')
            ->join('rule.resourceDefinition', 'resource')
            ->orderBy('resource.id', 'ASC')
            ->addOrderBy('rule.unlockLevel', 'ASC');
    }
}
