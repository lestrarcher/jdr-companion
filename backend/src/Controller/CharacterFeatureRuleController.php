<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\CharacterClass;
use App\Entity\CharacterFeatureDefinition;
use App\Entity\CharacterFeatureRule;
use App\Entity\CharacterRace;
use App\Entity\CharacterSubclass;
use App\Entity\Feat;
use App\Repository\CharacterFeatureRuleRepository;
use Doctrine\ORM\EntityManagerInterface;
use JsonException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/dnd/feature-rules')]
final class CharacterFeatureRuleController extends AbstractController
{
    #[Route('', name: 'api_dnd_feature_rule_list', methods: ['GET'])]
    public function list(CharacterFeatureRuleRepository $repository): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_USER');

        return $this->json([
            'rules' => array_map($this->serializeRule(...), $repository->findOrdered()),
        ]);
    }

    #[Route('', name: 'api_dnd_feature_rule_create', methods: ['POST'])]
    public function create(
        Request $request,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        $this->denyAccessUnlessGranted('ROLE_USER');

        $payload = $this->payload($request);

        if ($payload instanceof JsonResponse) {
            return $payload;
        }

        $feature = $this->findEntity(
            CharacterFeatureDefinition::class,
            $payload['featureDefinitionId'] ?? null,
            'La capacité sélectionnée est introuvable.',
            $entityManager,
        );

        if ($feature instanceof JsonResponse) {
            return $feature;
        }

        $sourceType = (string) ($payload['sourceType'] ?? '');
        $source = $this->resolveSource(
            $sourceType,
            $payload['sourceId'] ?? null,
            $entityManager,
        );

        if ($source instanceof JsonResponse) {
            return $source;
        }

        $unlockLevel = $this->integer($payload['unlockLevel'] ?? 1);
        $displayOrder = $this->integer($payload['displayOrder'] ?? 0);

        if ($unlockLevel === null || $unlockLevel < 1 || $unlockLevel > 20) {
            return $this->validationError(
                'Le niveau de déblocage doit être compris entre 1 et 20.',
            );
        }

        if ($displayOrder === null || $displayOrder < 0) {
            return $this->validationError(
                'L’ordre d’affichage doit être positif ou nul.',
            );
        }

        $sourceProperty = match ($sourceType) {
            'class' => 'characterClass',
            'subclass' => 'characterSubclass',
            'race' => 'characterRace',
            'feat' => 'feat',
            default => null,
        };

        if ($sourceProperty === null) {
            return $this->validationError('Le type de source est invalide.');
        }

        $existingRule = $entityManager
            ->getRepository(CharacterFeatureRule::class)
            ->findOneBy([
                'featureDefinition' => $feature,
                $sourceProperty => $source,
                'unlockLevel' => $unlockLevel,
            ]);

        if ($existingRule !== null) {
            return $this->json(
                ['message' => 'Cette capacité est déjà attribuée à cette source à ce niveau.'],
                Response::HTTP_CONFLICT,
            );
        }

        try {
            $rule = match ($sourceType) {
                'class' => CharacterFeatureRule::forClass(
                    $feature,
                    $source,
                    $unlockLevel,
                    $displayOrder,
                ),
                'subclass' => CharacterFeatureRule::forSubclass(
                    $feature,
                    $source,
                    $unlockLevel,
                    $displayOrder,
                ),
                'race' => CharacterFeatureRule::forRace(
                    $feature,
                    $source,
                    $unlockLevel,
                    $displayOrder,
                ),
                'feat' => CharacterFeatureRule::forFeat(
                    $feature,
                    $source,
                    $unlockLevel,
                    $displayOrder,
                ),
            };

            $entityManager->persist($rule);
            $entityManager->flush();
        } catch (\InvalidArgumentException|\LogicException $exception) {
            return $this->validationError($exception->getMessage());
        }

        return $this->json(
            ['rule' => $this->serializeRule($rule)],
            Response::HTTP_CREATED,
        );
    }

    #[Route(
        '/{ruleId}',
        name: 'api_dnd_feature_rule_update',
        requirements: ['ruleId' => '\d+'],
        methods: ['PATCH'],
    )]
    public function update(
        int $ruleId,
        Request $request,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        $this->denyAccessUnlessGranted('ROLE_USER');

        $rule = $entityManager
            ->getRepository(CharacterFeatureRule::class)
            ->find($ruleId);

        if (!$rule instanceof CharacterFeatureRule) {
            return $this->json(
                ['message' => 'Règle de capacité introuvable.'],
                Response::HTTP_NOT_FOUND,
            );
        }

        $payload = $this->payload($request);

        if ($payload instanceof JsonResponse) {
            return $payload;
        }

        try {
            if (array_key_exists('unlockLevel', $payload)) {
                $unlockLevel = $this->integer($payload['unlockLevel']);

                if ($unlockLevel === null) {
                    return $this->validationError('Le niveau de déblocage est invalide.');
                }

                $rule->setUnlockLevel($unlockLevel);
            }

            if (array_key_exists('displayOrder', $payload)) {
                $displayOrder = $this->integer($payload['displayOrder']);

                if ($displayOrder === null) {
                    return $this->validationError('L’ordre d’affichage est invalide.');
                }

                $rule->setDisplayOrder($displayOrder);
            }

            $entityManager->flush();
        } catch (\InvalidArgumentException|\LogicException $exception) {
            return $this->validationError($exception->getMessage());
        }

        return $this->json([
            'rule' => $this->serializeRule($rule),
        ]);
    }

    #[Route(
        '/{ruleId}',
        name: 'api_dnd_feature_rule_delete',
        requirements: ['ruleId' => '\d+'],
        methods: ['DELETE'],
    )]
    public function delete(
        int $ruleId,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        $this->denyAccessUnlessGranted('ROLE_USER');

        $rule = $entityManager
            ->getRepository(CharacterFeatureRule::class)
            ->find($ruleId);

        if (!$rule instanceof CharacterFeatureRule) {
            return $this->json(
                ['message' => 'Règle de capacité introuvable.'],
                Response::HTTP_NOT_FOUND,
            );
        }

        $entityManager->remove($rule);
        $entityManager->flush();

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    private function resolveSource(
        string $sourceType,
        mixed $sourceId,
        EntityManagerInterface $entityManager,
    ): CharacterClass|CharacterSubclass|CharacterRace|Feat|JsonResponse {
        return match ($sourceType) {
            'class' => $this->findEntity(
                CharacterClass::class,
                $sourceId,
                'La classe sélectionnée est introuvable.',
                $entityManager,
            ),
            'subclass' => $this->findEntity(
                CharacterSubclass::class,
                $sourceId,
                'La sous-classe sélectionnée est introuvable.',
                $entityManager,
            ),
            'race' => $this->findEntity(
                CharacterRace::class,
                $sourceId,
                'La race sélectionnée est introuvable.',
                $entityManager,
            ),
            'feat' => $this->findEntity(
                Feat::class,
                $sourceId,
                'Le don sélectionné est introuvable.',
                $entityManager,
            ),
            default => $this->validationError('Le type de source est invalide.'),
        };
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $entityClass
     *
     * @return T|JsonResponse
     */
    private function findEntity(
        string $entityClass,
        mixed $id,
        string $errorMessage,
        EntityManagerInterface $entityManager,
    ): object {
        $id = $this->integer($id);

        if ($id === null || $id <= 0) {
            return $this->validationError($errorMessage);
        }

        $entity = $entityManager->getRepository($entityClass)->find($id);

        return $entity ?? $this->validationError($errorMessage);
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
     * @return array<string, mixed>
     */
    private function serializeRule(CharacterFeatureRule $rule): array
    {
        $feature = $rule->getFeatureDefinition();

        return [
            'id' => $rule->getId(),
            'feature' => [
                'id' => $feature->getId(),
                'slug' => $feature->getSlug(),
                'name' => $feature->getName(),
                'activationType' => $feature->getActivationType()->value,
                'hasResource' => $feature->usesResource(),
            ],
            'sourceType' => $rule->sourceType(),
            'sourceId' => $rule->sourceId(),
            'sourceName' => $rule->sourceName(),
            'unlockLevel' => $rule->getUnlockLevel(),
            'displayOrder' => $rule->getDisplayOrder(),
        ];
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
