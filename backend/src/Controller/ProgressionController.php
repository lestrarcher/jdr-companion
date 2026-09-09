<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ProgressionDefinition;
use App\Entity\ProgressionStage;
use Doctrine\ORM\EntityManagerInterface;
use JsonException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/dnd/progressions')]
final class ProgressionController extends AbstractController
{
    #[Route('', name: 'api_dnd_progressions_list', methods: ['GET'])]
    public function list(
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        $this->denyAccessUnlessGranted('ROLE_USER');

        $progressions = $entityManager
            ->getRepository(ProgressionDefinition::class)
            ->findBy([], ['name' => 'ASC']);

        return $this->json([
            'progressions' => array_map(
                $this->serializeProgression(...),
                $progressions,
            ),
        ]);
    }

    #[Route('', name: 'api_dnd_progressions_create', methods: ['POST'])]
    public function create(
        Request $request,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        $this->denyAccessUnlessGranted('ROLE_USER');

        $payload = $this->payload($request);

        if ($payload instanceof JsonResponse) {
            return $payload;
        }

        $slug = strtolower(trim(
            (string) ($payload['slug'] ?? ''),
        ));
        $name = trim(
            (string) ($payload['name'] ?? ''),
        );

        if ($slug === '' || $name === '') {
            return $this->validationError(
                'Le slug et le nom de la progression sont obligatoires.',
            );
        }

        $existingProgression = $entityManager
            ->getRepository(ProgressionDefinition::class)
            ->findOneBy(['slug' => $slug]);

        if ($existingProgression !== null) {
            return $this->validationError(
                'Une progression utilise déjà ce slug.',
            );
        }

        try {
            $progression = new ProgressionDefinition(
                $slug,
                $name,
            );

            $this->applyProgressionPayload(
                $progression,
                $payload,
            );

            $entityManager->persist($progression);
            $entityManager->flush();
        } catch (
            \InvalidArgumentException|\LogicException $exception
        ) {
            return $this->validationError(
                $exception->getMessage(),
            );
        }

        return $this->json(
            [
                'message' => sprintf(
                    'La progression "%s" a été créée.',
                    $progression->getName(),
                ),
                'progression' =>
                    $this->serializeProgression($progression),
            ],
            Response::HTTP_CREATED,
        );
    }

    #[Route(
        '/{progressionId}',
        name: 'api_dnd_progressions_update',
        requirements: ['progressionId' => '\d+'],
        methods: ['PATCH'],
    )]
    public function update(
        int $progressionId,
        Request $request,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        $this->denyAccessUnlessGranted('ROLE_USER');

        $progression = $entityManager
            ->getRepository(ProgressionDefinition::class)
            ->find($progressionId);

        if (!$progression instanceof ProgressionDefinition) {
            return $this->json(
                ['message' => 'Progression introuvable.'],
                Response::HTTP_NOT_FOUND,
            );
        }

        $payload = $this->payload($request);

        if ($payload instanceof JsonResponse) {
            return $payload;
        }

        if (array_key_exists('slug', $payload)) {
            $slug = strtolower(trim(
                (string) $payload['slug'],
            ));

            $existingProgression = $entityManager
                ->getRepository(ProgressionDefinition::class)
                ->findOneBy(['slug' => $slug]);

            if (
                $existingProgression instanceof ProgressionDefinition
                && $existingProgression !== $progression
            ) {
                return $this->validationError(
                    'Une progression utilise déjà ce slug.',
                );
            }
        }

        try {
            $this->applyProgressionPayload(
                $progression,
                $payload,
            );

            $this->validateStagesAgainstDefinition(
                $progression,
            );

            $entityManager->flush();
        } catch (
            \InvalidArgumentException|\LogicException $exception
        ) {
            return $this->validationError(
                $exception->getMessage(),
            );
        }

        return $this->json([
            'message' => sprintf(
                'La progression "%s" a été mise à jour.',
                $progression->getName(),
            ),
            'progression' =>
                $this->serializeProgression($progression),
        ]);
    }

    #[Route(
        '/{progressionId}',
        name: 'api_dnd_progressions_delete',
        requirements: ['progressionId' => '\d+'],
        methods: ['DELETE'],
    )]
    public function delete(
        int $progressionId,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        $this->denyAccessUnlessGranted('ROLE_USER');

        $progression = $entityManager
            ->getRepository(ProgressionDefinition::class)
            ->find($progressionId);

        if (!$progression instanceof ProgressionDefinition) {
            return $this->json(
                ['message' => 'Progression introuvable.'],
                Response::HTTP_NOT_FOUND,
            );
        }

        $name = $progression->getName();

        $entityManager->remove($progression);
        $entityManager->flush();

        return $this->json([
            'message' => sprintf(
                'La progression "%s" a été supprimée.',
                $name,
            ),
        ]);
    }

    #[Route(
        '/{progressionId}/stages',
        name: 'api_dnd_progression_stages_create',
        requirements: ['progressionId' => '\d+'],
        methods: ['POST'],
    )]
    public function createStage(
        int $progressionId,
        Request $request,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        $this->denyAccessUnlessGranted('ROLE_USER');

        $progression = $entityManager
            ->getRepository(ProgressionDefinition::class)
            ->find($progressionId);

        if (!$progression instanceof ProgressionDefinition) {
            return $this->json(
                ['message' => 'Progression introuvable.'],
                Response::HTTP_NOT_FOUND,
            );
        }

        $payload = $this->payload($request);

        if ($payload instanceof JsonResponse) {
            return $payload;
        }

        $label = trim(
            (string) ($payload['label'] ?? ''),
        );
        $minimumValue = $this->integer(
            $payload['minimumValue'] ?? null,
        );

        if ($label === '') {
            return $this->validationError(
                'Le nom du palier est obligatoire.',
            );
        }

        if ($minimumValue === null) {
            return $this->validationError(
                'La valeur minimale du palier est invalide.',
            );
        }

        try {
            $stage = new ProgressionStage(
                $progression,
                $label,
                $minimumValue,
            );

            $this->applyStagePayload(
                $stage,
                $payload,
            );

            $this->validateStage(
                $progression,
                $stage,
            );

            $progression->addStage($stage);
            $entityManager->persist($stage);
            $entityManager->flush();
        } catch (
            \InvalidArgumentException|\LogicException $exception
        ) {
            return $this->validationError(
                $exception->getMessage(),
            );
        }

        return $this->json(
            [
                'message' => 'Le palier a été créé.',
                'stage' => $this->serializeStage($stage),
                'progression' =>
                    $this->serializeProgression($progression),
            ],
            Response::HTTP_CREATED,
        );
    }

    #[Route(
        '/{progressionId}/stages/{stageId}',
        name: 'api_dnd_progression_stages_update',
        requirements: [
            'progressionId' => '\d+',
            'stageId' => '\d+',
        ],
        methods: ['PATCH'],
    )]
    public function updateStage(
        int $progressionId,
        int $stageId,
        Request $request,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        $this->denyAccessUnlessGranted('ROLE_USER');

        $progression = $entityManager
            ->getRepository(ProgressionDefinition::class)
            ->find($progressionId);

        if (!$progression instanceof ProgressionDefinition) {
            return $this->json(
                ['message' => 'Progression introuvable.'],
                Response::HTTP_NOT_FOUND,
            );
        }

        $stage = $entityManager
            ->getRepository(ProgressionStage::class)
            ->findOneBy([
                'id' => $stageId,
                'progressionDefinition' => $progression,
            ]);

        if (!$stage instanceof ProgressionStage) {
            return $this->json(
                ['message' => 'Palier introuvable.'],
                Response::HTTP_NOT_FOUND,
            );
        }

        $payload = $this->payload($request);

        if ($payload instanceof JsonResponse) {
            return $payload;
        }

        try {
            $this->applyStagePayload(
                $stage,
                $payload,
            );

            $this->validateStage(
                $progression,
                $stage,
            );

            $entityManager->flush();
        } catch (
            \InvalidArgumentException|\LogicException $exception
        ) {
            return $this->validationError(
                $exception->getMessage(),
            );
        }

        return $this->json([
            'message' => 'Le palier a été mis à jour.',
            'stage' => $this->serializeStage($stage),
            'progression' =>
                $this->serializeProgression($progression),
        ]);
    }

    #[Route(
        '/{progressionId}/stages/{stageId}',
        name: 'api_dnd_progression_stages_delete',
        requirements: [
            'progressionId' => '\d+',
            'stageId' => '\d+',
        ],
        methods: ['DELETE'],
    )]
    public function deleteStage(
        int $progressionId,
        int $stageId,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        $this->denyAccessUnlessGranted('ROLE_USER');

        $progression = $entityManager
            ->getRepository(ProgressionDefinition::class)
            ->find($progressionId);

        if (!$progression instanceof ProgressionDefinition) {
            return $this->json(
                ['message' => 'Progression introuvable.'],
                Response::HTTP_NOT_FOUND,
            );
        }

        $stage = $entityManager
            ->getRepository(ProgressionStage::class)
            ->findOneBy([
                'id' => $stageId,
                'progressionDefinition' => $progression,
            ]);

        if (!$stage instanceof ProgressionStage) {
            return $this->json(
                ['message' => 'Palier introuvable.'],
                Response::HTTP_NOT_FOUND,
            );
        }

        $progression->removeStage($stage);
        $entityManager->remove($stage);
        $entityManager->flush();

        return $this->json([
            'message' => 'Le palier a été supprimé.',
            'progression' =>
                $this->serializeProgression($progression),
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function applyProgressionPayload(
        ProgressionDefinition $progression,
        array $payload,
    ): void {
        if (array_key_exists('slug', $payload)) {
            $progression->setSlug(
                (string) $payload['slug'],
            );
        }

        if (array_key_exists('name', $payload)) {
            $progression->setName(
                (string) $payload['name'],
            );
        }

        if (array_key_exists('description', $payload)) {
            $progression->setDescription(
                $this->nullableString(
                    $payload['description'],
                ),
            );
        }

        if (array_key_exists('minimumValue', $payload)) {
            $minimumValue = $this->integer(
                $payload['minimumValue'],
            );

            if ($minimumValue === null) {
                throw new \InvalidArgumentException(
                    'La valeur minimale de la progression est invalide.',
                );
            }

            $progression->setMinimumValue(
                $minimumValue,
            );
        }

        if (array_key_exists('maximumValue', $payload)) {
            $maximumValue = $this->nullableInteger(
                $payload['maximumValue'],
            );

            if ($maximumValue === false) {
                throw new \InvalidArgumentException(
                    'La valeur maximale de la progression est invalide.',
                );
            }

            $progression->setMaximumValue(
                $maximumValue,
            );
        }

        if (array_key_exists('accentColor', $payload)) {
            $progression->setAccentColor(
                $this->nullableString(
                    $payload['accentColor'],
                ),
            );
        }

        if (array_key_exists('gainLabel', $payload)) {
            $progression->setGainLabel(
                $this->nullableString(
                    $payload['gainLabel'],
                ),
            );
        }

        if (array_key_exists('spendLabel', $payload)) {
            $progression->setSpendLabel(
                $this->nullableString(
                    $payload['spendLabel'],
                ),
            );
        }

        if (array_key_exists('bulkAdjustmentEnabled', $payload)) {
            $progression->setBulkAdjustmentEnabled(
                (bool) $payload['bulkAdjustmentEnabled'],
            );
        }

        if (array_key_exists('custom', $payload)) {
            $progression->setCustom(
                $this->boolean(
                    $payload['custom'],
                ),
            );
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function applyStagePayload(
        ProgressionStage $stage,
        array $payload,
    ): void {
        if (array_key_exists('label', $payload)) {
            $stage->setLabel(
                (string) $payload['label'],
            );
        }

        if (array_key_exists('minimumValue', $payload)) {
            $minimumValue = $this->integer(
                $payload['minimumValue'],
            );

            if ($minimumValue === null) {
                throw new \InvalidArgumentException(
                    'La valeur minimale du palier est invalide.',
                );
            }

            $stage->setMinimumValue(
                $minimumValue,
            );
        }

        if (array_key_exists('maximumValue', $payload)) {
            $maximumValue = $this->nullableInteger(
                $payload['maximumValue'],
            );

            if ($maximumValue === false) {
                throw new \InvalidArgumentException(
                    'La valeur maximale du palier est invalide.',
                );
            }

            $stage->setMaximumValue(
                $maximumValue,
            );
        }

        if (array_key_exists('iconUrl', $payload)) {
            $stage->setIconUrl(
                $this->nullableString(
                    $payload['iconUrl'],
                ),
            );
        }

        if (array_key_exists('displayOrder', $payload)) {
            $displayOrder = $this->integer(
                $payload['displayOrder'],
            );

            if ($displayOrder === null) {
                throw new \InvalidArgumentException(
                    'L’ordre d’affichage du palier est invalide.',
                );
            }

            $stage->setDisplayOrder(
                $displayOrder,
            );
        }
    }

    private function validateStage(
        ProgressionDefinition $progression,
        ProgressionStage $stage,
    ): void {
        if (
            $stage->getMinimumValue()
            < $progression->getMinimumValue()
        ) {
            throw new \InvalidArgumentException(
                'Le palier ne peut pas commencer avant la valeur minimale de la progression.',
            );
        }

        if (
            $progression->getMaximumValue() !== null
            && (
                $stage->getMaximumValue() === null
                || $stage->getMaximumValue()
                    > $progression->getMaximumValue()
            )
        ) {
            throw new \InvalidArgumentException(
                'Le palier dépasse la valeur maximale de la progression.',
            );
        }

        foreach ($progression->getStages() as $otherStage) {
            if ($otherStage === $stage) {
                continue;
            }

            if ($this->stagesOverlap(
                $stage,
                $otherStage,
            )) {
                throw new \InvalidArgumentException(
                    sprintf(
                        'Le palier chevauche "%s".',
                        $otherStage->getLabel(),
                    ),
                );
            }
        }
    }

    private function validateStagesAgainstDefinition(
        ProgressionDefinition $progression,
    ): void {
        foreach ($progression->getStages() as $stage) {
            $this->validateStage(
                $progression,
                $stage,
            );
        }
    }

    private function stagesOverlap(
        ProgressionStage $first,
        ProgressionStage $second,
    ): bool {
        $firstMaximum =
            $first->getMaximumValue() ?? PHP_INT_MAX;
        $secondMaximum =
            $second->getMaximumValue() ?? PHP_INT_MAX;

        return
            $first->getMinimumValue() <= $secondMaximum
            && $second->getMinimumValue() <= $firstMaximum;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeProgression(
        ProgressionDefinition $progression,
    ): array {
        return [
            'id' => $progression->getId(),
            'slug' => $progression->getSlug(),
            'name' => $progression->getName(),
            'description' => $progression->getDescription(),
            'minimumValue' => $progression->getMinimumValue(),
            'maximumValue' => $progression->getMaximumValue(),
            'accentColor' => $progression->getAccentColor(),
            'gainLabel' => $progression->getGainLabel(),
            'spendLabel' => $progression->getSpendLabel(),
            'bulkAdjustmentEnabled' => $progression->isBulkAdjustmentEnabled(),
            'custom' => $progression->isCustom(),
            'stages' => array_map(
                $this->serializeStage(...),
                $progression->getStages()->toArray(),
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeStage(
        ProgressionStage $stage,
    ): array {
        return [
            'id' => $stage->getId(),
            'label' => $stage->getLabel(),
            'minimumValue' => $stage->getMinimumValue(),
            'maximumValue' => $stage->getMaximumValue(),
            'iconUrl' => $stage->getIconUrl(),
            'displayOrder' => $stage->getDisplayOrder(),
        ];
    }

    /**
     * @return array<string, mixed>|JsonResponse
     */
    private function payload(
        Request $request,
    ): array|JsonResponse {
        try {
            return $request->toArray();
        } catch (JsonException) {
            return $this->json(
                ['message' => 'Le corps JSON est invalide.'],
                Response::HTTP_BAD_REQUEST,
            );
        }
    }

    private function integer(
        mixed $value,
    ): ?int {
        if (is_int($value)) {
            return $value;
        }

        if (
            !is_string($value)
            && !is_float($value)
        ) {
            return null;
        }

        $result = filter_var(
            $value,
            FILTER_VALIDATE_INT,
        );

        return $result !== false
            ? $result
            : null;
    }

    private function nullableInteger(
        mixed $value,
    ): int|null|false {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->integer($value) ?? false;
    }

    private function boolean(
        mixed $value,
    ): bool {
        if (is_bool($value)) {
            return $value;
        }

        return filter_var(
            $value,
            FILTER_VALIDATE_BOOL,
        );
    }

    private function nullableString(
        mixed $value,
    ): ?string {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== ''
            ? $value
            : null;
    }

    private function validationError(
        string $message,
    ): JsonResponse {
        return $this->json(
            ['message' => $message],
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }
}
