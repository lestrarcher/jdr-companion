<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CharacterSessionState;
use App\Repository\CharacterActiveEffectRepository;

final readonly class CharacterSessionStateSerializer
{
    public function __construct(
        private CharacterProfileSerializer $profileSerializer,
        private CharacterHitPointStateService $hitPointStateService,
        private CharacterActiveEffectRepository $activeEffectRepository,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function serialize(CharacterSessionState $state, bool $includeAccessToken = false): array
    {
        $character = $state->getCharacter();
        $gameSession = $state->getGameSession();
        $sessionState = $state->getState();

        $sessionState['hitPoints']['effectiveMaximum'] = $this->hitPointStateService->effectiveMaximum($character, $sessionState);

        $activeEffects = $this->activeEffectRepository->findBy(['targetCharacter' => $character]);

        $data = [
            'id' => $state->getId(),
            'revision' => $state->getRevision(),
            'campaign' => [
                'id' => $gameSession->getCampaign()->getId(),
                'configurationKey' => $gameSession->getCampaign()->getConfigurationKey(),
            ],
            'session' => [
                'id' => $gameSession->getId(),
                'name' => $gameSession->getName(),
                'status' => $gameSession->getStatus(),
            ],
            'character' => $this->profileSerializer->serialize($character, $this->extractProgressionValues($state->getState())),
            'activeEffects' => array_map(
                static fn ($effect): array => [
                    'id' => $effect->getId(),
                    'type' => $effect->getType(),
                    'amount' => $effect->getAmount(),
                    'sourceCharacter' => $effect->getSourceCharacter() !== null
                        ? [
                            'id' => $effect->getSourceCharacter()->getId(),
                            'name' => $effect->getSourceCharacter()->getName(),
                        ]
                        : null,
                ],
                $activeEffects,
            ),
            'participating' => $state->isParticipating(),
            'levelUpAllowed' => $state->isLevelUpAllowed(),
            'state' => $sessionState,
            'updatedAt' => $state->getUpdatedAt()->format(DATE_ATOM),
        ];

        if ($includeAccessToken) {
            $data['accessToken'] = $state->getAccessToken();
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $state
     *
     * @return array<string, int>
     */
    private function extractProgressionValues(array $state): array
    {
        $progressions = $state['progressions'] ?? [];

        if (!is_array($progressions)) {
            return [];
        }

        $values = [];

        foreach ($progressions as $progression) {
            if (!is_array($progression) || !is_string($progression['id'] ?? null) || $progression['id'] === '' || !is_int($progression['currentValue'] ?? null)) {
                continue;
            }

            $values[$progression['id']] = $progression['currentValue'];
        }

        return $values;
    }
}
