<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\TrackableResourceDefinition;
use App\Enum\Ability;
use App\Enum\ResourceMaximumType;
use App\Enum\ResourceRechargeType;
use Doctrine\ORM\EntityManagerInterface;
use JsonException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/dnd/resources')]
final class TrackableResourceDefinitionController extends AbstractController
{
    #[Route('', name: 'api_dnd_resource_list', methods: ['GET'])]
    public function list(EntityManagerInterface $entityManager): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_USER');

        $resources = $entityManager
            ->getRepository(TrackableResourceDefinition::class)
            ->findBy([], ['name' => 'ASC']);

        return $this->json([
            'resources' => array_map($this->serializeResource(...), $resources),
            'rechargeTypes' => array_map(
                fn (ResourceRechargeType $type): array => $this->serializeEnum($type),
                ResourceRechargeType::cases(),
            ),
            'maximumTypes' => array_map(
                fn (ResourceMaximumType $type): array => $this->serializeEnum($type),
                ResourceMaximumType::cases(),
            ),
            'abilities' => array_map(
                static fn (Ability $ability): array => [
                    'value' => $ability->value,
                    'label' => $ability->label(),
                    'abbreviation' => $ability->abbreviation(),
                ],
                Ability::cases(),
            ),
        ]);
    }

    #[Route('', name: 'api_dnd_resource_create', methods: ['POST'])]
    public function create(
        Request $request,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        $this->denyAccessUnlessGranted('ROLE_USER');

        $payload = $this->payload($request);

        if ($payload instanceof JsonResponse) {
            return $payload;
        }

        $slug = strtolower(trim((string) ($payload['slug'] ?? '')));
        $name = trim((string) ($payload['name'] ?? ''));
        $rechargeType = ResourceRechargeType::tryFrom(
            (string) ($payload['rechargeType'] ?? ''),
        );
        $maximumType = ResourceMaximumType::tryFrom(
            (string) ($payload['maximumType'] ?? ResourceMaximumType::Fixed->value),
        );

        if ($slug === '' || $name === '') {
            return $this->validationError('Le slug et le nom de la ressource sont obligatoires.');
        }

        if ($rechargeType === null) {
            return $this->validationError('Le type de recharge est invalide.');
        }

        if ($maximumType === null) {
            return $this->validationError('Le type de maximum est invalide.');
        }

        if (
            $entityManager
                ->getRepository(TrackableResourceDefinition::class)
                ->findOneBy(['slug' => $slug]) !== null
        ) {
            return $this->json(
                ['message' => 'Une ressource utilise déjà ce slug.'],
                Response::HTTP_CONFLICT,
            );
        }

        $baseMaximum = $this->integer($payload['baseMaximum'] ?? 0);
        $multiplier = $this->integer($payload['multiplier'] ?? 1);
        $minimumMaximum = $this->integer($payload['minimumMaximum'] ?? 0);

        if ($baseMaximum === null || $multiplier === null || $minimumMaximum === null) {
            return $this->validationError('Les valeurs numériques de la ressource sont invalides.');
        }

        $scalingAbility = $this->resolveAbility($payload['scalingAbility'] ?? null);

        if ($scalingAbility instanceof JsonResponse) {
            return $scalingAbility;
        }

        if (
            $maximumType === ResourceMaximumType::AbilityModifier
            && !$scalingAbility instanceof Ability
        ) {
            return $this->validationError(
                'Une ressource basée sur un modificateur nécessite une caractéristique.',
            );
        }

        try {
            $resource = new TrackableResourceDefinition(
                slug: $slug,
                name: $name,
                rechargeType: $rechargeType,
                maximumType: $maximumType,
                baseMaximum: $baseMaximum,
            );

            $resource
                ->setDescription($this->nullableString($payload['description'] ?? null))
                ->setMultiplier($multiplier)
                ->setMinimumMaximum($minimumMaximum)
                ->setCustom((bool) ($payload['custom'] ?? true));

            if ($maximumType === ResourceMaximumType::AbilityModifier) {
                $resource->setScalingAbility($scalingAbility);
            }

            $entityManager->persist($resource);
            $entityManager->flush();
        } catch (\InvalidArgumentException|\LogicException $exception) {
            return $this->validationError($exception->getMessage());
        }

        return $this->json(
            ['resource' => $this->serializeResource($resource)],
            Response::HTTP_CREATED,
        );
    }

    #[Route(
        '/{resourceId}',
        name: 'api_dnd_resource_update',
        requirements: ['resourceId' => '\d+'],
        methods: ['PATCH'],
    )]
    public function update(
        int $resourceId,
        Request $request,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        $this->denyAccessUnlessGranted('ROLE_USER');

        $resource = $entityManager
            ->getRepository(TrackableResourceDefinition::class)
            ->find($resourceId);

        if (!$resource instanceof TrackableResourceDefinition) {
            return $this->json(
                ['message' => 'Ressource introuvable.'],
                Response::HTTP_NOT_FOUND,
            );
        }

        $payload = $this->payload($request);

        if ($payload instanceof JsonResponse) {
            return $payload;
        }

        if (array_key_exists('slug', $payload)) {
            $slug = strtolower(trim((string) $payload['slug']));

            $existingResource = $entityManager
                ->getRepository(TrackableResourceDefinition::class)
                ->findOneBy(['slug' => $slug]);

            if (
                $existingResource instanceof TrackableResourceDefinition
                && $existingResource->getId() !== $resource->getId()
            ) {
                return $this->json(
                    ['message' => 'Une ressource utilise déjà ce slug.'],
                    Response::HTTP_CONFLICT,
                );
            }
        }

        $rechargeType = $resource->getRechargeType();
        $maximumType = $resource->getMaximumType();
        $scalingAbility = $resource->getScalingAbility();

        if (array_key_exists('rechargeType', $payload)) {
            $rechargeType = ResourceRechargeType::tryFrom((string) $payload['rechargeType']);

            if ($rechargeType === null) {
                return $this->validationError('Le type de recharge est invalide.');
            }
        }

        if (array_key_exists('maximumType', $payload)) {
            $maximumType = ResourceMaximumType::tryFrom((string) $payload['maximumType']);

            if ($maximumType === null) {
                return $this->validationError('Le type de maximum est invalide.');
            }
        }

        if (array_key_exists('scalingAbility', $payload)) {
            $scalingAbility = $this->resolveAbility($payload['scalingAbility']);

            if ($scalingAbility instanceof JsonResponse) {
                return $scalingAbility;
            }
        }

        if (
            $maximumType === ResourceMaximumType::AbilityModifier
            && !$scalingAbility instanceof Ability
        ) {
            return $this->validationError(
                'Une ressource basée sur un modificateur nécessite une caractéristique.',
            );
        }

        try {
            if (array_key_exists('slug', $payload)) {
                $resource->setSlug((string) $payload['slug']);
            }

            if (array_key_exists('name', $payload)) {
                $resource->setName((string) $payload['name']);
            }

            if (array_key_exists('description', $payload)) {
                $resource->setDescription($this->nullableString($payload['description']));
            }

            if (array_key_exists('rechargeType', $payload)) {
                $resource->setRechargeType($rechargeType);
            }

            if (array_key_exists('maximumType', $payload)) {
                $resource->setMaximumType($maximumType);
            }

            if (array_key_exists('baseMaximum', $payload)) {
                $value = $this->integer($payload['baseMaximum']);

                if ($value === null) {
                    return $this->validationError('Le maximum de base est invalide.');
                }

                $resource->setBaseMaximum($value);
            }

            if (array_key_exists('multiplier', $payload)) {
                $value = $this->integer($payload['multiplier']);

                if ($value === null) {
                    return $this->validationError('Le multiplicateur est invalide.');
                }

                $resource->setMultiplier($value);
            }

            if (array_key_exists('minimumMaximum', $payload)) {
                $value = $this->integer($payload['minimumMaximum']);

                if ($value === null) {
                    return $this->validationError('Le minimum est invalide.');
                }

                $resource->setMinimumMaximum($value);
            }

            if ($maximumType === ResourceMaximumType::AbilityModifier) {
                $resource->setScalingAbility($scalingAbility);
            }

            if (array_key_exists('custom', $payload)) {
                $resource->setCustom((bool) $payload['custom']);
            }

            $entityManager->flush();
        } catch (\InvalidArgumentException|\LogicException $exception) {
            return $this->validationError($exception->getMessage());
        }

        return $this->json([
            'resource' => $this->serializeResource($resource),
        ]);
    }

    /**
     * @return array<string, mixed>|JsonResponse
     */
    private function payload(Request $request): array|JsonResponse
    {
        try {
            return $request->toArray();
        } catch (JsonException) {
            return $this->json(
                ['message' => 'Le corps JSON est invalide.'],
                Response::HTTP_BAD_REQUEST,
            );
        }
    }

    private function resolveAbility(mixed $value): Ability|JsonResponse|null
    {
        if ($value === null || $value === '') {
            return null;
        }

        $ability = Ability::tryFrom((string) $value);

        return $ability ?? $this->validationError('La caractéristique sélectionnée est invalide.');
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

    /**
     * @return array<string, mixed>
     */
    private function serializeResource(TrackableResourceDefinition $resource): array
    {
        return [
            'id' => $resource->getId(),
            'slug' => $resource->getSlug(),
            'name' => $resource->getName(),
            'description' => $resource->getDescription(),
            'rechargeType' => $resource->getRechargeType()->value,
            'maximumType' => $resource->getMaximumType()->value,
            'baseMaximum' => $resource->getBaseMaximum(),
            'multiplier' => $resource->getMultiplier(),
            'minimumMaximum' => $resource->getMinimumMaximum(),
            'scalingAbility' => $resource->getScalingAbility()?->value,
            'custom' => $resource->isCustom(),
        ];
    }

    /**
     * @param ResourceRechargeType|ResourceMaximumType $enum
     *
     * @return array{value: string, label: string}
     */
    private function serializeEnum(ResourceRechargeType|ResourceMaximumType $enum): array
    {
        return [
            'value' => $enum->value,
            'label' => ucfirst(str_replace('_', ' ', $enum->value)),
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    private function validationError(string $message): JsonResponse
    {
        return $this->json(
            ['message' => $message],
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }
}
