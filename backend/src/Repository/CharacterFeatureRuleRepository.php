<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CharacterFeatureRule;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CharacterFeatureRule>
 */
final class CharacterFeatureRuleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CharacterFeatureRule::class);
    }

    /**
     * @return list<CharacterFeatureRule>
     */
    public function findOrdered(): array
    {
        return $this->createQueryBuilder('rule')
            ->addSelect('feature', 'class', 'subclass', 'race', 'feat')
            ->join('rule.featureDefinition', 'feature')
            ->leftJoin('rule.characterClass', 'class')
            ->leftJoin('rule.characterSubclass', 'subclass')
            ->leftJoin('rule.characterRace', 'race')
            ->leftJoin('rule.feat', 'feat')
            ->orderBy('rule.displayOrder', 'ASC')
            ->addOrderBy('feature.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
