<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CharacterSessionState;
use App\Entity\GameSession;
use App\Entity\RestRequest;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RestRequest>
 */
final class RestRequestRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
    ) {
        parent::__construct(
            $registry,
            RestRequest::class,
        );
    }

    public function findPendingForCharacterState(
        CharacterSessionState $characterSessionState,
    ): ?RestRequest {
        return $this->findOneBy(
            [
                'characterSessionState' =>
                    $characterSessionState,

                'status' =>
                    RestRequest::STATUS_PENDING,
            ],
            [
                'requestedAt' => 'DESC',
            ],
        );
    }

    public function findLatestForCharacterState(
        CharacterSessionState $characterSessionState,
    ): ?RestRequest {
        return $this->findOneBy(
            [
                'characterSessionState' =>
                    $characterSessionState,
            ],
            [
                'requestedAt' => 'DESC',
            ],
        );
    }

    /**
     * @return list<RestRequest>
     */
    public function findPendingForSession(
        GameSession $gameSession,
    ): array {
        return $this->createQueryBuilder('restRequest')
            ->innerJoin(
                'restRequest.characterSessionState',
                'characterState',
            )
            ->andWhere(
                'characterState.gameSession = :gameSession',
            )
            ->andWhere(
                'restRequest.status = :status',
            )
            ->setParameter(
                'gameSession',
                $gameSession,
            )
            ->setParameter(
                'status',
                RestRequest::STATUS_PENDING,
            )
            ->orderBy(
                'restRequest.requestedAt',
                'ASC',
            )
            ->getQuery()
            ->getResult();
    }
}
