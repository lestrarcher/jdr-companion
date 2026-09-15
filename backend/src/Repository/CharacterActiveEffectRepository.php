<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Character;
use App\Entity\CharacterActiveEffect;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class CharacterActiveEffectRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) {
        parent::__construct($registry, CharacterActiveEffect::class);
    }

    public function findEffectFor(Character $target, string $type): ?CharacterActiveEffect
    {
        return $this->findOneBy([
            'targetCharacter' => $target,
            'type' => $type,
        ]);
    }
}
