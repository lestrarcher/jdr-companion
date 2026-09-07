<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CharacterClass;
use App\Entity\CharacterClassLevelRule;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class CharacterClassLevelRuleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CharacterClassLevelRule::class);
    }

    public function findForClassLevel(
        CharacterClass $characterClass,
        int $level,
    ): ?CharacterClassLevelRule {
        return $this->findOneBy([
            'characterClass' => $characterClass,
            'level' => $level,
        ]);
    }
}
