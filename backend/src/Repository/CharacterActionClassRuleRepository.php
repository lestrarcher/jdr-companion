<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CharacterActionClassRule;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\ORM\QueryBuilder;
use App\Entity\User;
use App\Service\ReferenceVisibility;

/**
 * @extends ServiceEntityRepository<CharacterActionClassRule>
 */
final class CharacterActionClassRuleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CharacterActionClassRule::class);
    }

    /**
     * @return list<CharacterActionClassRule>
     */
    public function findActiveOrdered(): array
    {
        return $this->orderedQuery()->getQuery()->getResult();
    }

    /** Same ordering as findActiveOrdered(), scoped before hydration. */
    public function findVisibleForOwner(User $owner): array
    {
        return ReferenceVisibility::scope($this->orderedQuery(), 'rule', $owner)->getQuery()->getResult();
    }

    private function orderedQuery(): QueryBuilder
    {
        return $this->createQueryBuilder('rule')
            ->addSelect('actionDefinition', 'characterClass')
            ->join('rule.actionDefinition', 'actionDefinition')
            ->join('rule.characterClass', 'characterClass')
            ->andWhere('actionDefinition.active = :active')
            ->setParameter('active', true)
            ->orderBy('actionDefinition.name', 'ASC')
            ->addOrderBy('rule.unlockLevel', 'ASC');
    }
}
