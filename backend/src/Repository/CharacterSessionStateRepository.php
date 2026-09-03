<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CharacterSessionState;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CharacterSessionState>
 */
final class CharacterSessionStateRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
    ) {
        parent::__construct(
            $registry,
            CharacterSessionState::class,
        );
    }

    public function findOneByAccessToken(
        string $accessToken,
    ): ?CharacterSessionState {
        return $this->findOneBy([
            'accessToken' => $accessToken,
        ]);
    }
}
