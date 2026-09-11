<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CharacterSessionState;
use App\Entity\GameSession;
use App\Repository\CharacterSessionStateRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class PlayerCharacterAccess
{
    public function __construct(private CharacterSessionStateRepository $repository)
    {
    }

    public function requireParticipating(string $accessToken): CharacterSessionState
    {
        $state = $this->repository->findOneByAccessToken($accessToken);
        if ($state === null || !$state->isParticipating()) {
            throw new NotFoundHttpException('Lien joueur invalide.');
        }

        return $state;
    }

    /** Inside a transaction: serialize character-wide actions, then recheck access.
     * Lock order everywhere: character, game session, character state, action row.
     */
    public static function lockForMutation(EntityManagerInterface $em, CharacterSessionState $state): void
    {
        // Lock the row before refreshing: refresh-with-lock may join nullable
        // associations, which PostgreSQL does not permit with FOR UPDATE.
        $em->lock($state->getCharacter(), LockMode::PESSIMISTIC_WRITE);
        $em->refresh($state->getCharacter());
        $em->lock($state->getGameSession(), LockMode::PESSIMISTIC_READ);
        $em->refresh($state->getGameSession());
        $em->lock($state, LockMode::PESSIMISTIC_WRITE);
        $em->refresh($state);
        if (!$state->isParticipating()) {
            throw new NotFoundHttpException('Lien joueur invalide.');
        }
        if ($state->getGameSession()->getStatus() !== GameSession::STATUS_LIVE) {
            throw new ConflictHttpException('Cette session n’est pas ouverte.');
        }
    }
}
