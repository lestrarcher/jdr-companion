<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Character;
use App\Repository\CharacterSessionStateRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

final readonly class CharacterInspirationService
{
    public function __construct(
        private EntityManagerInterface $em,
        private CharacterSessionStateRepository $states,
    ) {}

    /** Caller holds the character lock and has checked access and the request revision. */
    public function changeLocked(Character $character, bool $inspiration): void
    {
        if (!$this->em->getConnection()->isTransactionActive()) {
            throw new \LogicException('Inspiration requires a transaction.');
        }
        if ($character->hasInspiration() === $inspiration) return;

        $states = $this->states->findBy(['character' => $character], ['id' => 'ASC']);
        foreach ($states as $state) {
            $this->em->lock($state, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($state);
        }
        $character->setInspiration($inspiration);
        foreach ($states as $state) {
            // Touch every revision, including inactive sessions, without copying state between sessions.
            $state->setState($state->getState());
        }
    }
}
