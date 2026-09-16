<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\CharacterClass;
use App\Entity\CharacterRace;
use App\Entity\CharacterSubclass;
use App\Entity\Feat;
use App\Entity\TrackableResourceDefinition;
use App\Entity\TrackableResourceRule;
use App\Repository\TrackableResourceRuleRepository;
use Doctrine\ORM\EntityManagerInterface;
use JsonException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/dnd')]
final class TrackableResourceRuleController extends AbstractController
{
    #[Route('/resources/{resourceId}/rules', name: 'api_dnd_resource_rule_list', requirements: ['resourceId' => '\d+'], methods: ['GET'])]
    public function list(int $resourceId, EntityManagerInterface $entityManager, TrackableResourceRuleRepository $repository): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_USER');

        $resource = $entityManager->getRepository(TrackableResourceDefinition::class)->find($resourceId);

        if (!$resource instanceof TrackableResourceDefinition) {
            return $this->json(['message' => 'Ressource introuvable.'], Response::HTTP_NOT_FOUND);
        }

        $rules = array_filter($repository->findOrderedRules(), static fn (TrackableResourceRule $rule): bool => $rule->getResourceDefinition() === $resource);

        return $this->json(['rules' => array_values(array_map($this->serializeRule(...), $rules))]);
    }

    #[Route('/resources/{resourceId}/rules', name: 'api_dnd_resource_rule_create', requirements: ['resourceId' => '\d+'], methods: ['POST'])]
    public function create(int $resourceId, Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_USER');

        $resource = $entityManager->getRepository(TrackableResourceDefinition::class)->find($resourceId);

        if (!$resource instanceof TrackableResourceDefinition) {
            return $this->json(['message' => 'Ressource introuvable.'], Response::HTTP_NOT_FOUND);
        }

        $payload = $this->payload($request);

        if ($payload instanceof JsonResponse) {
            return $payload;
        }

        $sourceType = (string) ($payload['sourceType'] ?? '');
        $source = $this->resolveSource($sourceType, $payload['sourceId'] ?? null, $entityManager);

        if ($source instanceof JsonResponse) {
            return $source;
        }

        $unlockLevel = $this->integer($payload['unlockLevel'] ?? 1);

        if ($unlockLevel === null || $unlockLevel < 1 || $unlockLevel > 20) {
            return $this->validationError('Le niveau de déblocage doit être compris entre 1 et 20.');
        }

        $maximumOverride = $this->nullableInteger($payload['maximumOverride'] ?? null);
        $maximumBonus = $this->integer($payload['maximumBonus'] ?? 0);

        if ($maximumOverride === false || $maximumBonus === null || $maximumBonus < 0) {
            return $this->validationError('Les valeurs de maximum de la ressource sont invalides.');
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

        $existingRule = $entityManager->getRepository(TrackableResourceRule::class)->findOneBy([
            'resourceDefinition' => $resource,
            $sourceProperty => $source,
            'unlockLevel' => $unlockLevel,
        ]);

        if ($existingRule instanceof TrackableResourceRule) {
            return $this->json(['message' => 'Cette ressource possède déjà une règle pour cette source à ce niveau.'], Response::HTTP_CONFLICT);
        }

        try {
            $rule = match ($sourceType) {
                'class' => TrackableResourceRule::forClass($resource, $source, $unlockLevel, $maximumOverride),
                'subclass' => TrackableResourceRule::forSubclass($resource, $source, $unlockLevel, $maximumOverride),
                'race' => TrackableResourceRule::forRace($resource, $source, $unlockLevel, $maximumOverride),
                'feat' => TrackableResourceRule::forFeat($resource, $source, $unlockLevel, $maximumOverride),
            };

            $rule->setMaximumBonus($maximumBonus);

            $entityManager->persist($rule);
            $entityManager->flush();
        } catch (\InvalidArgumentException|\LogicException $exception) {
            return $this->validationError($exception->getMessage());
        }

        return $this->json(['rule' => $this->serializeRule($rule)], Response::HTTP_CREATED);
    }

    #[Route('/resource-rules/{ruleId}', name: 'api_dnd_resource_rule_update', requirements: ['ruleId' => '\d+'], methods: ['PATCH'])]
    public function update(int $ruleId, Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_USER');

        $rule = $entityManager->getRepository(TrackableResourceRule::class)->find($ruleId);

        if (!$rule instanceof TrackableResourceRule) {
            return $this->json(['message' => 'Règle de ressource introuvable.'], Response::HTTP_NOT_FOUND);
        }

        $payload = $this->payload($request);

        if ($payload instanceof JsonResponse) {
            return $payload;
        }

        try {
            if (array_key_exists('maximumOverride', $payload)) {
                $maximumOverride = $this->nullableInteger($payload['maximumOverride']);

                if ($maximumOverride === false) {
                    return $this->validationError('Le maximum imposé est invalide.');
                }

                $rule->setMaximumOverride($maximumOverride);
            }

            if (array_key_exists('maximumBonus', $payload)) {
                $maximumBonus = $this->integer($payload['maximumBonus']);

                if ($maximumBonus === null || $maximumBonus < 0) {
                    return $this->validationError('Le bonus au maximum est invalide.');
                }

                $rule->setMaximumBonus($maximumBonus);
            }

            $entityManager->flush();
        } catch (\InvalidArgumentException|\LogicException $exception) {
            return $this->validationError($exception->getMessage());
        }

        return $this->json(['rule' => $this->serializeRule($rule)]);
    }

    #[Route('/resource-rules/{ruleId}', name: 'api_dnd_resource_rule_delete', requirements: ['ruleId' => '\d+'], methods: ['DELETE'])]
    public function delete(int $ruleId, EntityManagerInterface $entityManager): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_USER');

        $rule = $entityManager->getRepository(TrackableResourceRule::class)->find($ruleId);

        if (!$rule instanceof TrackableResourceRule) {
            return $this->json(['message' => 'Règle de ressource introuvable.'], Response::HTTP_NOT_FOUND);
        }

        $entityManager->remove($rule);
        $entityManager->flush();

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    private function resolveSource(string $sourceType, mixed $sourceId, EntityManagerInterface $entityManager): CharacterClass|CharacterSubclass|CharacterRace|Feat|JsonResponse
    {
        return match ($sourceType) {
            'class' => $this->findEntity(CharacterClass::class, $sourceId, 'La classe sélectionnée est introuvable.', $entityManager),
            'subclass' => $this->findEntity(CharacterSubclass::class, $sourceId, 'La sous-classe sélectionnée est introuvable.', $entityManager),
            'race' => $this->findEntity(CharacterRace::class, $sourceId, 'La race sélectionnée est introuvable.', $entityManager),
            'feat' => $this->findEntity(Feat::class, $sourceId, 'Le don sélectionné est introuvable.', $entityManager),
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
    private function findEntity(string $entityClass, mixed $id, string $errorMessage, EntityManagerInterface $entityManager): object
    {
        $id = $this->integer($id);

        if ($id === null || $id <= 0) {
            return $this->validationError($errorMessage);
        }

        return $entityManager->getRepository($entityClass)->find($id) ?? $this->validationError($errorMessage);
    }

    /**
     * @return array<string, mixed>|JsonResponse
     */
    private function payload(Request $request): array|JsonResponse
    {
        try {
            return $request->toArray();
        } catch (JsonException) {
            return $this->json(['message' => 'Le corps JSON est invalide.'], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeRule(TrackableResourceRule $rule): array
    {
        $source = match (true) {
            $rule->getCharacterClass() !== null => ['type' => 'class', 'entity' => $rule->getCharacterClass()],
            $rule->getCharacterSubclass() !== null => ['type' => 'subclass', 'entity' => $rule->getCharacterSubclass()],
            $rule->getCharacterRace() !== null => ['type' => 'race', 'entity' => $rule->getCharacterRace()],
            $rule->getFeat() !== null => ['type' => 'feat', 'entity' => $rule->getFeat()],
            default => null,
        };

        if ($source === null) {
            throw new \LogicException('La règle de ressource ne possède aucune source.');
        }

        return [
            'id' => $rule->getId(),
            'sourceType' => $source['type'],
            'sourceId' => $source['entity']->getId(),
            'sourceName' => $source['entity']->getName(),
            'unlockLevel' => $rule->getUnlockLevel(),
            'maximumOverride' => $rule->getMaximumOverride(),
            'maximumBonus' => $rule->getMaximumBonus(),
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

    private function nullableInteger(mixed $value): int|false|null
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->integer($value) ?? false;
    }

    private function validationError(string $message): JsonResponse
    {
        return $this->json(['message' => $message], Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
