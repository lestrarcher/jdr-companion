<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ProgressionDefinition;
use App\Entity\ProgressionStage;
use App\Entity\ProgressionAdjustmentRule;
use App\Enum\ProgressionAdjustmentDirection;
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
    #[Route('/{progressionId}/adjustment-rules', name: 'api_dnd_progression_rules_create', requirements: ['progressionId' => '\d+'], defaults: ['ruleId' => null], methods: ['POST'])]
    #[Route('/{progressionId}/adjustment-rules/{ruleId}', name: 'api_dnd_progression_rules_update', requirements: ['progressionId' => '\d+', 'ruleId' => '\d+'], methods: ['PATCH'])]
    #[Route('/{progressionId}/adjustment-rules/{ruleId}', name: 'api_dnd_progression_rules_delete', requirements: ['progressionId' => '\d+', 'ruleId' => '\d+'], methods: ['DELETE'])]
    public function mutateAdjustmentRule(int $progressionId, Request $request, EntityManagerInterface $entityManager, ?int $ruleId): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        $progression = $entityManager->getRepository(ProgressionDefinition::class)->find($progressionId);
        if (!$progression instanceof ProgressionDefinition) {
            return $this->json(['message' => 'Progression introuvable.'], Response::HTTP_NOT_FOUND);
        }
        $rule = $ruleId !== null ? $entityManager->getRepository(ProgressionAdjustmentRule::class)->findOneBy([
            'id' => $ruleId, 'progressionDefinition' => $progression,
        ]) : null;
        if ($ruleId !== null && !$rule instanceof ProgressionAdjustmentRule) {
            return $this->json(['message' => 'Règle introuvable.'], Response::HTTP_NOT_FOUND);
        }
        if ($request->isMethod('DELETE')) {
            $progression->removeAdjustmentRule($rule);
            $entityManager->remove($rule);
        } else {
            $payload = $this->payload($request);
            if ($payload instanceof JsonResponse) {
                return $payload;
            }
            try {
                // Validate a detached candidate before changing the managed entity.
                $directionValue = array_key_exists('direction', $payload) ? $payload['direction'] : $rule?->getDirection()->value;
                $direction = is_string($directionValue) ? ProgressionAdjustmentDirection::tryFrom($directionValue) : null;
                if ($direction === null) {
                    throw new \InvalidArgumentException('La direction doit être gain ou loss.');
                }
                foreach (['description', 'adjustmentLabel', 'triggerType'] as $field) {
                    if (array_key_exists($field, $payload) && !is_string($payload[$field]) && !($field === 'triggerType' && $payload[$field] === null)) {
                        throw new \InvalidArgumentException(sprintf('Le champ %s doit être textuel.', $field));
                    }
                }
                $order = array_key_exists('displayOrder', $payload) ? $this->integer($payload['displayOrder']) : ($rule?->getDisplayOrder() ?? 0);
                if ($order === null) {
                    throw new \InvalidArgumentException('L’ordre d’affichage doit être entier.');
                }
                $candidate = new ProgressionAdjustmentRule($progression, $direction,
                    $payload['description'] ?? $rule?->getDescription() ?? '',
                    $payload['adjustmentLabel'] ?? $rule?->getAdjustmentLabel() ?? '');
                $candidate->setTriggerType(array_key_exists('triggerType', $payload) ? $payload['triggerType'] : $rule?->getTriggerType());
                $candidate->setDisplayOrder($order);
                if ($rule === null) {
                    $rule = $candidate;
                    $progression->addAdjustmentRule($rule);
                    $entityManager->persist($rule);
                } else {
                    $rule->setDirection($candidate->getDirection())
                        ->setDescription($candidate->getDescription())
                        ->setAdjustmentLabel($candidate->getAdjustmentLabel())
                        ->setTriggerType($candidate->getTriggerType())
                        ->setDisplayOrder($candidate->getDisplayOrder());
                }
            } catch (\InvalidArgumentException|\LogicException $exception) {
                return $this->validationError($exception->getMessage());
            }
        }
        $entityManager->flush();
        return $this->json(['progression' => $this->serializeProgression($progression)],
            $request->isMethod('POST') ? Response::HTTP_CREATED : Response::HTTP_OK);
    }

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
        if (array_key_exists('description', $payload)) {
            if ($payload['description'] !== null && !is_string($payload['description'])) {
                throw new \InvalidArgumentException('La description du palier doit être textuelle.');
            }
            $stage->setDescription($payload['description']);
        }
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
            'adjustmentRules' => $this->serializeAdjustmentRules($progression),
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
            'description' => $stage->getDescription(),
            'minimumValue' => $stage->getMinimumValue(),
            'maximumValue' => $stage->getMaximumValue(),
            'iconUrl' => $stage->getIconUrl(),
            'displayOrder' => $stage->getDisplayOrder(),
        ];
    }

    /** GM/reference data only: deliberately not used by CharacterProfileSerializer. */
    private function serializeAdjustmentRules(ProgressionDefinition $progression): array
    {
        $rules = $progression->getAdjustmentRules()->toArray();
        usort($rules, static fn (ProgressionAdjustmentRule $a, ProgressionAdjustmentRule $b): int =>
            [$a->getDisplayOrder(), $a->getId()] <=> [$b->getDisplayOrder(), $b->getId()]);
        return array_map(static fn (ProgressionAdjustmentRule $rule): array => [
            'id' => $rule->getId(),
            'direction' => $rule->getDirection()->value,
            'triggerType' => $rule->getTriggerType(),
            'description' => $rule->getDescription(),
            'adjustmentLabel' => $rule->getAdjustmentLabel(),
            'displayOrder' => $rule->getDisplayOrder(),
        ], $rules);
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
