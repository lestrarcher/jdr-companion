<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Campaign;
use App\Entity\Character;
use App\Repository\CharacterRepository;
use App\Security\Voter\CampaignVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route(
    '/campaigns/{campaignId}/characters',
    requirements: ['campaignId' => '\d+'],
)]
final class CharacterController extends AbstractController
{
    #[Route(
        '',
        name: 'api_character_list',
        methods: ['GET'],
    )]
    public function list(
        #[MapEntity(id: 'campaignId')]
        Campaign $campaign,
        CharacterRepository $characterRepository,
    ): JsonResponse {
        $this->denyAccessUnlessGranted(
            CampaignVoter::VIEW,
            $campaign,
        );

        $characters = $characterRepository->findBy(
            ['campaign' => $campaign],
            ['name' => 'ASC'],
        );

        return $this->json([
            'characters' => array_map(
                fn (Character $character): array =>
                    $this->serializeCharacter($character),
                $characters,
            ),
        ]);
    }

    #[Route(
        '',
        name: 'api_character_create',
        methods: ['POST'],
    )]
    public function create(
        #[MapEntity(id: 'campaignId')]
        Campaign $campaign,
        Request $request,
        CharacterRepository $characterRepository,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        $this->denyAccessUnlessGranted(
            CampaignVoter::MANAGE,
            $campaign,
        );

        $payload = $request->toArray();

        $slug = trim(
            (string) ($payload['slug'] ?? ''),
        );

        $name = trim(
            (string) ($payload['name'] ?? ''),
        );

        $playerName = trim(
            (string) ($payload['playerName'] ?? ''),
        );

        $type = trim(
            (string) ($payload['type'] ?? ''),
        );

        $definition =
            $payload['definition'] ?? [];

        if (
            $slug === '' ||
            !preg_match('/^[a-z0-9-]+$/', $slug)
        ) {
            return $this->json(
                [
                    'message' => implode(' ', [
                        'Le slug doit contenir uniquement',
                        'des lettres minuscules, des chiffres',
                        'et des tirets.',
                    ]),
                ],
                JsonResponse::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        if ($name === '') {
            return $this->json(
                [
                    'message' =>
                        'Le nom du personnage est obligatoire.',
                ],
                JsonResponse::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        if (
            !in_array(
                $type,
                [
                    Character::TYPE_PLAYER,
                    Character::TYPE_NPC,
                ],
                true,
            )
        ) {
            return $this->json(
                [
                    'message' =>
                        'Le type doit être "player" ou "npc".',
                ],
                JsonResponse::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        if (!is_array($definition)) {
            return $this->json(
                [
                    'message' =>
                        'La définition du personnage doit être un objet JSON.',
                ],
                JsonResponse::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $existingCharacter =
            $characterRepository->findOneBy([
                'campaign' => $campaign,
                'slug' => $slug,
            ]);

        if ($existingCharacter) {
            return $this->json(
                [
                    'message' =>
                        'Ce slug de personnage existe déjà dans cette campagne.',
                ],
                JsonResponse::HTTP_CONFLICT,
            );
        }

        $character = new Character(
            $campaign,
            $slug,
            $name,
            $type,
            $definition,
        );

        $character->setPlayerName(
            $playerName !== ''
                ? $playerName
                : null,
        );

        $entityManager->persist($character);
        $entityManager->flush();

        return $this->json(
            [
                'character' =>
                    $this->serializeCharacter($character),
            ],
            JsonResponse::HTTP_CREATED,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeCharacter(
        Character $character,
    ): array {
        return [
            'id' => $character->getId(),
            'campaignId' =>
                $character->getCampaign()->getId(),
            'slug' => $character->getSlug(),
            'name' => $character->getName(),
            'playerName' =>
                $character->getPlayerName(),
            'type' => $character->getType(),
            'race' => $character->getRace() !== null
                ? [
                    'id' => $character->getRace()->getId(),
                    'name' => $character->getRace()->getName(),
                ]
                : null,
            'totalLevel' => $character->getTotalLevel(),
            'proficiencyBonus' => $character->getProficiencyBonus(),
            'classLevels' => array_map(
                static fn ($level): array => [
                    'position' => $level->getPosition(),
                    'classId' => $level->getCharacterClass()->getId(),
                    'className' => $level->getCharacterClass()->getName(),
                    'subclassId' => $level->getSubclass()?->getId(),
                    'subclassName' => $level->getSubclass()?->getName(),
                ],
                $character->getClassLevels()->toArray(),
            ),
            'progressions' => array_map(
                static function ($characterProgression): array {
                    $definition =
                        $characterProgression->getProgressionDefinition();

                    return [
                        'id' => $characterProgression->getId(),
                        'definitionId' => $definition->getId(),
                        'slug' => $definition->getSlug(),
                        'name' => $definition->getName(),
                        'minimumValue' =>
                            $definition->getMinimumValue(),
                        'maximumValue' =>
                            $definition->getMaximumValue(),
                    ];
                },
                $character->getProgressions()->toArray(),
            ),
            'definition' =>
                $character->getDefinition(),
        ];
    }
}
