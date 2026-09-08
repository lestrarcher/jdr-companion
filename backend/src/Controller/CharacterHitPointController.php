<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Campaign;
use App\Entity\Character;
use App\Entity\CharacterClassLevel;
use App\Enum\HitPointGainMethod;
use App\Repository\CharacterRepository;
use App\Security\Voter\CampaignVoter;
use App\Service\CharacterProfileSerializer;
use Doctrine\ORM\EntityManagerInterface;
use JsonException;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(
    '/campaigns/{campaignId}/characters/{characterId}/hit-points',
    requirements: [
        'campaignId' => '\d+',
        'characterId' => '\d+',
    ],
)]
final class CharacterHitPointController extends AbstractController
{
    #[Route('/history', name: 'api_character_hit_points_history', methods: ['PUT'])]
    public function updateHistory(
        #[MapEntity(id: 'campaignId')] Campaign $campaign,
        int $characterId,
        Request $request,
        CharacterRepository $characterRepository,
        EntityManagerInterface $entityManager,
        CharacterProfileSerializer $serializer,
    ): JsonResponse {
        $this->denyAccessUnlessGranted(CampaignVoter::MANAGE, $campaign);

        $character = $characterRepository->findOneBy([
            'id' => $characterId,
            'campaign' => $campaign,
        ]);

        if (!$character instanceof Character) {
            return $this->json(
                ['message' => 'Personnage introuvable dans cette campagne.'],
                Response::HTTP_NOT_FOUND,
            );
        }

        try {
            $payload = $request->toArray();
        } catch (JsonException) {
            return $this->json(
                ['message' => 'Le corps JSON est invalide.'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $levelsPayload = $payload['levels'] ?? null;

        if (!is_array($levelsPayload) || $levelsPayload === []) {
            return $this->validationError(
                'Au moins un niveau de points de vie doit être renseigné.',
            );
        }

        try {
            $updates = $this->resolveUpdates($character, $levelsPayload);

            $entityManager->wrapInTransaction(
                function () use ($updates, $entityManager): void {
                    foreach ($updates as $update) {
                        $update['level']->setHitPointGain(
                            $update['gain'],
                            $update['method'],
                        );
                    }

                    $entityManager->flush();
                },
            );
        } catch (\LogicException $exception) {
            return $this->validationError($exception->getMessage());
        }

        return $this->json([
            'message' => 'L’historique des points de vie a été mis à jour.',
            'character' => $serializer->serialize($character),
        ]);
    }

    /**
     * @param array<mixed> $levelsPayload
     *
     * @return list<array{
     *     level: CharacterClassLevel,
     *     gain: int,
     *     method: HitPointGainMethod
     * }>
     */
    private function resolveUpdates(
        Character $character,
        array $levelsPayload,
    ): array {
        $levelsByPosition = [];

        foreach ($character->getClassLevels() as $level) {
            $levelsByPosition[$level->getPosition()] = $level;
        }

        $updates = [];
        $receivedPositions = [];

        foreach ($levelsPayload as $levelPayload) {
            if (!is_array($levelPayload)) {
                throw new \DomainException(
                    'Une ligne de points de vie est invalide.',
                );
            }

            $position = $this->integer($levelPayload['position'] ?? null);

            if ($position === null || !isset($levelsByPosition[$position])) {
                throw new \DomainException(
                    'Un niveau sélectionné est introuvable pour ce personnage.',
                );
            }

            if (isset($receivedPositions[$position])) {
                throw new \DomainException(sprintf(
                    'Le niveau %d est renseigné plusieurs fois.',
                    $position,
                ));
            }

            $receivedPositions[$position] = true;
            $level = $levelsByPosition[$position];
            $method = HitPointGainMethod::tryFrom(
                (string) ($levelPayload['method'] ?? ''),
            );

            if ($method === null) {
                throw new \DomainException(sprintf(
                    'La méthode de gain de PV du niveau %d est invalide.',
                    $position,
                ));
            }

            [$gain, $method] = $this->resolveLevelGain(
                $level,
                $method,
                $levelPayload['gain'] ?? null,
            );

            $updates[] = [
                'level' => $level,
                'gain' => $gain,
                'method' => $method,
            ];
        }

        return $updates;
    }

    /**
     * @return array{int, HitPointGainMethod}
     */
    private function resolveLevelGain(
        CharacterClassLevel $level,
        HitPointGainMethod $method,
        mixed $gainValue,
    ): array {
        $position = $level->getPosition();
        $hitDie = $level->getCharacterClass()->getHitDie();

        if ($position === 1) {
            if ($method !== HitPointGainMethod::FirstLevel) {
                throw new \DomainException(
                    'Le niveau 1 doit utiliser le maximum du dé de vie.',
                );
            }

            return [$hitDie, HitPointGainMethod::FirstLevel];
        }

        if ($method === HitPointGainMethod::FirstLevel) {
            throw new \DomainException(sprintf(
                'La méthode du premier niveau ne peut pas être utilisée au niveau %d.',
                $position,
            ));
        }

        if ($method === HitPointGainMethod::Average) {
            return [
                intdiv($hitDie, 2) + 1,
                HitPointGainMethod::Average,
            ];
        }

        $gain = $this->integer($gainValue);

        if ($gain === null || $gain < 1 || $gain > $hitDie) {
            throw new \DomainException(sprintf(
                'Le gain brut du niveau %d doit être compris entre 1 et %d.',
                $position,
                $hitDie,
            ));
        }

        return [$gain, $method];
    }

    private function integer(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (!is_string($value) && !is_float($value)) {
            return null;
        }

        $result = filter_var($value, FILTER_VALIDATE_INT);

        return $result !== false ? $result : null;
    }

    private function validationError(string $message): JsonResponse
    {
        return $this->json(
            ['message' => $message],
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }
}
