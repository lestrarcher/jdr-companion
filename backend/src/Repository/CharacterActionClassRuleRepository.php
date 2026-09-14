<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CharacterActionClassRule;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

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
        return $this->createQueryBuilder('rule')
            ->addSelect('actionDefinition', 'characterClass')
            ->join('rule.actionDefinition', 'actionDefinition')
            ->join('rule.characterClass', 'characterClass')
            ->andWhere('actionDefinition.active = :active')
            ->setParameter('active', true)
            ->orderBy('actionDefinition.name', 'ASC')
            ->addOrderBy('rule.unlockLevel', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
