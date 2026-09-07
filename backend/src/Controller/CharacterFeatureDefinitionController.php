<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\CharacterFeatureDefinition;
use App\Entity\TrackableResourceDefinition;
use App\Enum\FeatureActivationType;
use Doctrine\ORM\EntityManagerInterface;
use JsonException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/dnd/features')]
final class CharacterFeatureDefinitionController extends AbstractController
{
    #[Route('', name: 'api_dnd_feature_list', methods: ['GET'])]
    public function list(EntityManagerInterface $entityManager): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_USER');

        $features = $entityManager
            ->getRepository(CharacterFeatureDefinition::class)
            ->findBy([], ['name' => 'ASC']);

        $resources = $entityManager
            ->getRepository(TrackableResourceDefinition::class)
            ->findBy([], ['name' => 'ASC']);

        return $this->json([
            'features' => array_map($this->serializeFeature(...), $features),
            'resources' => array_map($this->serializeResource(...), $resources),
            'activationTypes' => array_map(
                static fn (FeatureActivationType $type): array => [
                    'value' => $type->value,
                    'label' => $type->label(),
                ],
                FeatureActivationType::cases(),
            ),
        ]);
    }

    #[Route('', name: 'api_dnd_feature_create', methods: ['POST'])]
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

        if ($slug === '' || $name === '') {
            return $this->json(
                ['message' => 'Le slug et le nom de la capacité sont obligatoires.'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $existingFeature = $entityManager
            ->getRepository(CharacterFeatureDefinition::class)
            ->findOneBy(['slug' => $slug]);

        if ($existingFeature !== null) {
            return $this->json(
                ['message' => 'Une capacité utilise déjà ce slug.'],
                Response::HTTP_CONFLICT,
            );
        }

        $activationType = FeatureActivationType::tryFrom(
            (string) ($payload['activationType'] ?? FeatureActivationType::Passive->value),
        );

        if ($activationType === null) {
            return $this->json(
                ['message' => 'Le type d’activation est invalide.'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $resource = $this->resolveResource($payload, $entityManager);

        if ($resource instanceof JsonResponse) {
            return $resource;
        }

        try {
            $feature = new CharacterFeatureDefinition($slug, $name);
            $feature
                ->setDescription($this->nullableString($payload['description'] ?? null))
                ->setActivationType($activationType)
                ->setResourceDefinition($resource)
                ->setVisible((bool) ($payload['visible'] ?? true))
                ->setCustom((bool) ($payload['custom'] ?? true));

            $entityManager->persist($feature);
            $entityManager->flush();
        } catch (\InvalidArgumentException|\LogicException $exception) {
            return $this->json(
                ['message' => $exception->getMessage()],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        return $this->json(
            ['feature' => $this->serializeFeature($feature)],
            Response::HTTP_CREATED,
        );
    }

    #[Route(
        '/{featureId}',
        name: 'api_dnd_feature_update',
        requirements: ['featureId' => '\d+'],
        methods: ['PATCH'],
    )]
    public function update(
        int $featureId,
        Request $request,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        $this->denyAccessUnlessGranted('ROLE_USER');

        $feature = $entityManager
            ->getRepository(CharacterFeatureDefinition::class)
            ->find($featureId);

        if (!$feature instanceof CharacterFeatureDefinition) {
            return $this->json(
                ['message' => 'Capacité introuvable.'],
                Response::HTTP_NOT_FOUND,
            );
        }

        $payload = $this->payload($request);

        if ($payload instanceof JsonResponse) {
            return $payload;
        }

        if (array_key_exists('slug', $payload)) {
            $slug = strtolower(trim((string) $payload['slug']));

            $existingFeature = $entityManager
                ->getRepository(CharacterFeatureDefinition::class)
                ->findOneBy(['slug' => $slug]);

            if (
                $existingFeature instanceof CharacterFeatureDefinition
                && $existingFeature->getId() !== $feature->getId()
            ) {
                return $this->json(
                    ['message' => 'Une capacité utilise déjà ce slug.'],
                    Response::HTTP_CONFLICT,
                );
            }
        }

        $activationType = null;

        if (array_key_exists('activationType', $payload)) {
            $activationType = FeatureActivationType::tryFrom(
                (string) $payload['activationType'],
            );

            if ($activationType === null) {
                return $this->json(
                    ['message' => 'Le type d’activation est invalide.'],
                    Response::HTTP_UNPROCESSABLE_ENTITY,
                );
            }
        }

        $resource = null;

        if (array_key_exists('resourceDefinitionId', $payload)) {
            $resource = $this->resolveResource($payload, $entityManager);

            if ($resource instanceof JsonResponse) {
                return $resource;
            }
        }

        try {
            if (array_key_exists('slug', $payload)) {
                $feature->setSlug((string) $payload['slug']);
            }

            if (array_key_exists('name', $payload)) {
                $feature->setName((string) $payload['name']);
            }

            if (array_key_exists('description', $payload)) {
                $feature->setDescription($this->nullableString($payload['description']));
            }

            if ($activationType !== null) {
                $feature->setActivationType($activationType);
            }

            if (array_key_exists('resourceDefinitionId', $payload)) {
                $feature->setResourceDefinition($resource);
            }

            if (array_key_exists('visible', $payload)) {
                $feature->setVisible((bool) $payload['visible']);
            }

            if (array_key_exists('custom', $payload)) {
                $feature->setCustom((bool) $payload['custom']);
            }

            $entityManager->flush();
        } catch (\InvalidArgumentException|\LogicException $exception) {
            return $this->json(
                ['message' => $exception->getMessage()],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        return $this->json([
            'feature' => $this->serializeFeature($feature),
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

    /**
     * @param array<string, mixed> $payload
     */
    private function resolveResource(
        array $payload,
        EntityManagerInterface $entityManager,
    ): TrackableResourceDefinition|JsonResponse|null {
        $resourceId = $payload['resourceDefinitionId'] ?? null;

        if ($resourceId === null || $resourceId === '') {
            return null;
        }

        $resourceId = filter_var($resourceId, FILTER_VALIDATE_INT);

        if ($resourceId === false || $resourceId <= 0) {
            return $this->json(
                ['message' => 'La ressource sélectionnée est invalide.'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $resource = $entityManager
            ->getRepository(TrackableResourceDefinition::class)
            ->find($resourceId);

        if (!$resource instanceof TrackableResourceDefinition) {
            return $this->json(
                ['message' => 'La ressource sélectionnée est introuvable.'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        return $resource;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeFeature(CharacterFeatureDefinition $feature): array
    {
        $resource = $feature->getResourceDefinition();

        return [
            'id' => $feature->getId(),
            'slug' => $feature->getSlug(),
            'name' => $feature->getName(),
            'description' => $feature->getDescription(),
            'activationType' => $feature->getActivationType()->value,
            'activationLabel' => $feature->getActivationType()->label(),
            'visible' => $feature->isVisible(),
            'custom' => $feature->isCustom(),
            'resourceDefinition' => $resource !== null
                ? $this->serializeResource($resource)
                : null,
        ];
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

    private function nullableString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}
