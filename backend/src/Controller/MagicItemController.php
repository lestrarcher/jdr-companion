<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Campaign;
use App\Entity\MagicItem;
use App\Entity\MagicItemAbilityEffect;
use App\Enum\Ability;
use App\Enum\AbilityEffectOperation;
use App\Enum\MagicItemRarity;
use App\Enum\MagicItemRechargeType;
use App\Repository\CampaignRepository;
use App\Security\Voter\CampaignVoter;
use Doctrine\ORM\EntityManagerInterface;
use JsonException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class MagicItemController
    extends AbstractController
{
    #[Route(
        '/campaigns/{campaignId}/magic-items',
        name: 'api_campaign_magic_item_list',
        requirements: [
            'campaignId' => '\d+',
        ],
        methods: ['GET'],
    )]
    public function list(
        int $campaignId,
        CampaignRepository $campaignRepository,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        $campaign = $this->getOwnedCampaign(
            $campaignId,
            $campaignRepository,
        );

        $items = $entityManager
            ->getRepository(MagicItem::class)
            ->findBy(
                ['campaign' => $campaign],
                ['name' => 'ASC'],
            );

        return $this->json([
            'items' => array_map(
                fn (MagicItem $item): array =>
                    $this->serializeItem($item),
                $items,
            ),
        ]);
    }

    #[Route(
        '/campaigns/{campaignId}/magic-items',
        name: 'api_campaign_magic_item_create',
        requirements: [
            'campaignId' => '\d+',
        ],
        methods: ['POST'],
    )]
    public function create(
        int $campaignId,
        Request $request,
        CampaignRepository $campaignRepository,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        $campaign = $this->getOwnedCampaign(
            $campaignId,
            $campaignRepository,
        );

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

        $name = trim(
            (string) ($payload['name'] ?? ''),
        );

        $rarity = MagicItemRarity::tryFrom(
            (string) (
                $payload['rarity']
                ?? MagicItemRarity::Common->value
            ),
        );

        if ($name === '') {
            return $this->json(
                [
                    'message' =>
                        'Le nom de l’objet est obligatoire.',
                ],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        if (!$rarity) {
            return $this->json(
                [
                    'message' =>
                        'La rareté est invalide.',
                ],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $rechargeType =
            MagicItemRechargeType::tryFrom(
                (string) (
                    $payload['rechargeType']
                    ?? MagicItemRechargeType::None->value
                ),
            );

        if (!$rechargeType) {
            return $this->json(
                [
                    'message' =>
                        'Le type de recharge est invalide.',
                ],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $maximumCharges = null;

        if (
            array_key_exists(
                'maximumCharges',
                $payload,
            )
            && $payload['maximumCharges']
                !== null
            && $payload['maximumCharges']
                !== ''
        ) {
            $maximumCharges = filter_var(
                $payload['maximumCharges'],
                FILTER_VALIDATE_INT,
            );

            if (
                $maximumCharges === false
                || $maximumCharges <= 0
            ) {
                return $this->json(
                    [
                        'message' =>
                            'Le maximum de charges doit être un entier supérieur à zéro.',
                    ],
                    Response::HTTP_UNPROCESSABLE_ENTITY,
                );
            }
        }

        $effectsPayload =
            $payload['abilityEffects'] ?? [];

        if (!is_array($effectsPayload)) {
            return $this->json(
                [
                    'message' =>
                        'Les effets de caractéristiques doivent former une liste.',
                ],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        try {
            $item = new MagicItem(
                campaign: $campaign,
                name: $name,
                rarity: $rarity,
            );

            $item
                ->setDescription(
                    $this->nullableString(
                        $payload['description']
                        ?? null,
                    ),
                )
                ->setRequiresAttunement(
                    (bool) (
                        $payload[
                            'requiresAttunement'
                        ] ?? false
                    ),
                )
                ->setMaximumCharges(
                    $maximumCharges,
                )
                ->setRechargeType(
                    $rechargeType,
                )
                ->setRechargeFormula(
                    $this->nullableString(
                        $payload[
                            'rechargeFormula'
                        ] ?? null,
                    ),
                );

            foreach (
                $effectsPayload
                as $effectPayload
            ) {
                if (!is_array($effectPayload)) {
                    throw new \InvalidArgumentException(
                        'Un effet de caractéristique est invalide.',
                    );
                }

                $effect =
                    $this->createAbilityEffect(
                        $item,
                        $effectPayload,
                    );

                $item->addAbilityEffect(
                    $effect,
                );
            }
        } catch (\InvalidArgumentException $exception) {
            return $this->json(
                [
                    'message' =>
                        $exception->getMessage(),
                ],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $entityManager->persist($item);
        $entityManager->flush();

        return $this->json(
            [
                'item' =>
                    $this->serializeItem($item),
            ],
            Response::HTTP_CREATED,
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function createAbilityEffect(
        MagicItem $item,
        array $payload,
    ): MagicItemAbilityEffect {
        $ability = Ability::tryFrom(
            (string) (
                $payload['ability'] ?? ''
            ),
        );

        if (!$ability) {
            throw new \InvalidArgumentException(
                'La caractéristique ciblée est invalide.',
            );
        }

        $operation =
            AbilityEffectOperation::tryFrom(
                (string) (
                    $payload['operation'] ?? ''
                ),
            );

        if (!$operation) {
            throw new \InvalidArgumentException(
                'Le type d’effet est invalide.',
            );
        }

        $value = filter_var(
            $payload['value'] ?? null,
            FILTER_VALIDATE_INT,
        );

        if ($value === false) {
            throw new \InvalidArgumentException(
                'La valeur de l’effet doit être un entier.',
            );
        }

        $effect =
            new MagicItemAbilityEffect(
                magicItem: $item,
                ability: $ability,
                operation: $operation,
                value: $value,
            );

        if (
            array_key_exists(
                'scoreCap',
                $payload,
            )
            && $payload['scoreCap'] !== null
            && $payload['scoreCap'] !== ''
        ) {
            $scoreCap = filter_var(
                $payload['scoreCap'],
                FILTER_VALIDATE_INT,
            );

            if ($scoreCap === false) {
                throw new \InvalidArgumentException(
                    'La limite de caractéristique doit être un entier.',
                );
            }

            $effect->setScoreCap(
                $scoreCap,
            );
        }

        if (
            array_key_exists(
                'maximumIncrease',
                $payload,
            )
        ) {
            $maximumIncrease = filter_var(
                $payload['maximumIncrease'],
                FILTER_VALIDATE_INT,
            );

            if (
                $maximumIncrease === false
            ) {
                throw new \InvalidArgumentException(
                    'L’augmentation du maximum doit être un entier.',
                );
            }

            $effect->setMaximumIncrease(
                $maximumIncrease,
            );
        }

        return $effect;
    }

    private function getOwnedCampaign(
        int $campaignId,
        CampaignRepository $campaignRepository,
    ): Campaign {
        $campaign =
            $campaignRepository->find(
                $campaignId,
            );

        if (!$campaign instanceof Campaign) {
            throw $this
                ->createNotFoundException(
                    'Campagne introuvable.',
                );
        }

        $this->denyAccessUnlessGranted(
            CampaignVoter::MANAGE,
            $campaign,
        );

        return $campaign;
    }

    private function nullableString(
        mixed $value,
    ): ?string {
        if ($value === null) {
            return null;
        }

        $value = trim(
            (string) $value,
        );

        return $value !== ''
            ? $value
            : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeItem(
        MagicItem $item,
    ): array {
        return [
            'id' => $item->getId(),
            'campaignId' =>
                $item
                    ->getCampaign()
                    ->getId(),
            'name' => $item->getName(),
            'description' =>
                $item->getDescription(),
            'rarity' =>
                $item->getRarity()->value,
            'rarityLabel' =>
                $item->getRarity()->label(),
            'requiresAttunement' =>
                $item->requiresAttunement(),
            'maximumCharges' =>
                $item->getMaximumCharges(),
            'rechargeType' =>
                $item
                    ->getRechargeType()
                    ->value,
            'rechargeLabel' =>
                $item
                    ->getRechargeType()
                    ->label(),
            'rechargeFormula' =>
                $item->getRechargeFormula(),

            'abilityEffects' =>
                array_map(
                    static fn (
                        MagicItemAbilityEffect $effect,
                    ): array => [
                        'id' =>
                            $effect->getId(),
                        'ability' =>
                            $effect
                                ->getAbility()
                                ->value,
                        'abilityLabel' =>
                            $effect
                                ->getAbility()
                                ->label(),
                        'operation' =>
                            $effect
                                ->getOperation()
                                ->value,
                        'operationLabel' =>
                            $effect
                                ->getOperation()
                                ->label(),
                        'value' =>
                            $effect->getValue(),
                        'scoreCap' =>
                            $effect
                                ->getScoreCap(),
                        'maximumIncrease' =>
                            $effect
                                ->getMaximumIncrease(),
                    ],
                    $item
                        ->getAbilityEffects()
                        ->toArray(),
                ),

            'createdAt' =>
                $item
                    ->getCreatedAt()
                    ->format(DATE_ATOM),
            'updatedAt' =>
                $item
                    ->getUpdatedAt()
                    ->format(DATE_ATOM),
        ];
    }
}
