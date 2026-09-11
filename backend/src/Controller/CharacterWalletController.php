<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\PlayerCharacterAccess;

use App\Entity\CharacterSessionState;
use App\Entity\CharacterWallet;
use App\Entity\GameSession;
use Doctrine\ORM\EntityManagerInterface;
use JsonException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class CharacterWalletController
    extends AbstractController
{
    private const ALLOWED_FIELDS = [
        'copperPieces',
        'silverPieces',
        'electrumPieces',
        'goldPieces',
        'platinumPieces',
    ];

    /**
     * Consulte la bourse permanente du personnage.
     *
     * La lecture reste possible lorsque la session
     * est en préparation ou terminée.
     */
    #[Route(
        '/public/characters/{accessToken}/wallet',
        name: 'api_public_character_wallet_show',
        requirements: [
            'accessToken' => '[a-f0-9]{64}',
        ],
        methods: ['GET'],
    )]
    public function show(
        string $accessToken,
        PlayerCharacterAccess $playerAccess,
    ): JsonResponse {
        $state = $playerAccess
            ->requireParticipating($accessToken);

        return $this->json([
            'wallet' => $this->serializeWallet(
                $state->getCharacter()->getWallet(),
            ),
        ]);
    }

    /**
     * Applique des variations aux différentes monnaies.
     *
     * Exemple :
     * {
     *   "goldPieces": -5,
     *   "silverPieces": 12
     * }
     */
    #[Route(
        '/public/characters/{accessToken}/wallet',
        name: 'api_public_character_wallet_update',
        requirements: [
            'accessToken' => '[a-f0-9]{64}',
        ],
        methods: ['PATCH'],
    )]
    public function update(
        string $accessToken,
        Request $request,
        PlayerCharacterAccess $playerAccess,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        $state = $playerAccess
            ->requireParticipating($accessToken);

        if (
            $state
                ->getGameSession()
                ->getStatus()
            !== GameSession::STATUS_LIVE
        ) {
            return $this->json(
                [
                    'message' =>
                        'Cette session n’est pas ouverte.',
                ],
                Response::HTTP_CONFLICT,
            );
        }

        return $entityManager->wrapInTransaction(function () use ($accessToken, $request, $playerAccess, $entityManager, $state): JsonResponse {
            PlayerCharacterAccess::lockForMutation($entityManager, $state);

            try {
                $payload = $request->toArray();
            } catch (JsonException) {
                return $this->json(
                    [
                        'message' =>
                            'Le corps JSON est invalide.',
                    ],
                    Response::HTTP_BAD_REQUEST,
                );
            }

            if ($payload === []) {
                return $this->json(
                    [
                        'message' =>
                            'Aucune modification n’a été envoyée.',
                    ],
                    Response::HTTP_BAD_REQUEST,
                );
            }

            foreach (
                array_keys($payload)
                as $field
            ) {
                if (
                    !in_array(
                        $field,
                        self::ALLOWED_FIELDS,
                        true,
                    )
                ) {
                    return $this->json(
                        [
                            'message' => sprintf(
                                'La monnaie "%s" est inconnue.',
                                $field,
                            ),
                        ],
                        Response::HTTP_BAD_REQUEST,
                    );
                }
            }

            $wallet = $state
                ->getCharacter()
                ->getWallet();
            $entityManager->refresh($wallet, \Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);

            $currentValues = [
                'copperPieces' =>
                    $wallet->getCopperPieces(),
                'silverPieces' =>
                    $wallet->getSilverPieces(),
                'electrumPieces' =>
                    $wallet->getElectrumPieces(),
                'goldPieces' =>
                    $wallet->getGoldPieces(),
                'platinumPieces' =>
                    $wallet->getPlatinumPieces(),
            ];

            $newValues = $currentValues;

            foreach ($payload as $field => $change) {
                if (!is_int($change)) {
                    return $this->json(
                        [
                            'message' => sprintf(
                                'La variation de "%s" doit être un nombre entier.',
                                $field,
                            ),
                        ],
                        Response::HTTP_UNPROCESSABLE_ENTITY,
                    );
                }

                $newAmount =
                    $currentValues[$field]
                    + $change;

                if ($newAmount < 0) {
                    return $this->json(
                        [
                            'message' => sprintf(
                                'Le solde de "%s" ne peut pas être négatif.',
                                $field,
                            ),
                        ],
                        Response::HTTP_UNPROCESSABLE_ENTITY,
                    );
                }

                $newValues[$field] =
                    $newAmount;
            }

            $wallet
                ->setCopperPieces(
                    $newValues['copperPieces'],
                )
                ->setSilverPieces(
                    $newValues['silverPieces'],
                )
                ->setElectrumPieces(
                    $newValues['electrumPieces'],
                )
                ->setGoldPieces(
                    $newValues['goldPieces'],
                )
                ->setPlatinumPieces(
                    $newValues['platinumPieces'],
                );

            $entityManager->flush();

            return $this->json([
                'wallet' =>
                    $this->serializeWallet(
                        $wallet,
                    ),
            ]);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeWallet(
        CharacterWallet $wallet,
    ): array {
        return [
            'id' => $wallet->getId(),
            'characterId' =>
                $wallet
                    ->getCharacter()
                    ->getId(),
            'copperPieces' =>
                $wallet->getCopperPieces(),
            'silverPieces' =>
                $wallet->getSilverPieces(),
            'electrumPieces' =>
                $wallet->getElectrumPieces(),
            'goldPieces' =>
                $wallet->getGoldPieces(),
            'platinumPieces' =>
                $wallet->getPlatinumPieces(),
            'updatedAt' =>
                $wallet
                    ->getUpdatedAt()
                    ->format(DATE_ATOM),
        ];
    }
}
