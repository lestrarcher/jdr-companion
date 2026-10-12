<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ProficiencyGrantRule;
use App\Entity\User;
use App\Service\ReferenceVisibility;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ProficiencyGrantRule> */
class ProficiencyGrantRuleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProficiencyGrantRule::class);
    }

    /** @return list<ProficiencyGrantRule> */
    public function findVisibleForOwner(User $owner): array
    {
        return ReferenceVisibility::scope($this->createQueryBuilder('rule'), 'rule', $owner)
            ->addSelect('option')->leftJoin('rule.options', 'option')
            ->orderBy('rule.id', 'ASC')->addOrderBy('option.slug', 'ASC')
            ->getQuery()->getResult();
    }
}
