<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Feat;
use App\Enum\Ability;
use App\Repository\CharacterFeatRepository;
use App\Repository\FeatRepository;
use Doctrine\ORM\EntityManagerInterface;
use JsonException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/dnd/feats')]
final class FeatController extends AbstractController
{
    #[Route('', name: 'api_dnd_feats_list', methods: ['GET'])]
    public function list(
        FeatRepository $repository,
    ): JsonResponse {
        $this->denyAccessUnlessGranted('ROLE_USER');

        return $this->json([
            'feats' => array_map(
                $this->serialize(...),
                $repository->findBy([], ['name' => 'ASC']),
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

    #[Route('', name: 'api_dnd_feats_create', methods: ['POST'])]
    public function create(
        Request $request,
        FeatRepository $repository,
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
                'Le slug et le nom du don sont obligatoires.',
            );
        }

        if ($repository->findOneBy(['slug' => $slug]) !== null) {
            return $this->validationError(
                'Un don utilise déjà ce slug.',
            );
        }

        try {
            $feat = new Feat($slug, $name);

            $this->applyPayload(
                $feat,
                $payload,
            );

            $entityManager->persist($feat);
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
                    'Le don "%s" a été créé.',
                    $feat->getName(),
                ),
                'feat' => $this->serialize($feat),
            ],
            Response::HTTP_CREATED,
        );
    }

    #[Route(
        '/{featId}',
        name: 'api_dnd_feats_update',
        requirements: ['featId' => '\d+'],
        methods: ['PATCH'],
    )]
    public function update(
        int $featId,
        Request $request,
        FeatRepository $repository,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        $this->denyAccessUnlessGranted('ROLE_USER');

        $feat = $repository->find($featId);

        if (!$feat instanceof Feat) {
            return $this->json(
                ['message' => 'Don introuvable.'],
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

            $existingFeat = $repository->findOneBy([
                'slug' => $slug,
            ]);

            if (
                $existingFeat instanceof Feat
                && $existingFeat !== $feat
            ) {
                return $this->validationError(
                    'Un don utilise déjà ce slug.',
                );
            }
        }

        try {
            $this->applyPayload(
                $feat,
                $payload,
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
                'Le don "%s" a été mis à jour.',
                $feat->getName(),
            ),
            'feat' => $this->serialize($feat),
        ]);
    }

    #[Route(
        '/{featId}',
        name: 'api_dnd_feats_delete',
        requirements: ['featId' => '\d+'],
        methods: ['DELETE'],
    )]
    public function delete(
        int $featId,
        FeatRepository $repository,
        CharacterFeatRepository $characterFeatRepository,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        $this->denyAccessUnlessGranted('ROLE_USER');

        $feat = $repository->find($featId);

        if (!$feat instanceof Feat) {
            return $this->json(
                ['message' => 'Don introuvable.'],
                Response::HTTP_NOT_FOUND,
            );
        }

        if (
            $characterFeatRepository->count([
                'feat' => $feat,
            ]) > 0
        ) {
            return $this->validationError(
                'Ce don est déjà utilisé par au moins un personnage et ne peut pas être supprimé.',
            );
        }

        $name = $feat->getName();

        $entityManager->remove($feat);
        $entityManager->flush();

        return $this->json([
            'message' => sprintf(
                'Le don "%s" a été supprimé.',
                $name,
            ),
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function applyPayload(
        Feat $feat,
        array $payload,
    ): void {
        if (array_key_exists('slug', $payload)) {
            $feat->setSlug(
                (string) $payload['slug'],
            );
        }

        if (array_key_exists('name', $payload)) {
            $feat->setName(
                (string) $payload['name'],
            );
        }

        if (array_key_exists('description', $payload)) {
            $feat->setDescription(
                $this->nullableString(
                    $payload['description'],
                ),
            );
        }

        if (array_key_exists('repeatable', $payload)) {
            $feat->setRepeatable(
                $this->boolean(
                    $payload['repeatable'],
                ),
            );
        }

        if (
            array_key_exists(
                'requiresAbilityChoice',
                $payload,
            )
        ) {
            $feat->setRequiresAbilityChoice(
                $this->boolean(
                    $payload['requiresAbilityChoice'],
                ),
            );
        }

        if (array_key_exists('allowedAbilities', $payload)) {
            $abilities = $this->resolveAbilities(
                $payload['allowedAbilities'],
            );

            $feat->setAllowedAbilities(
                ...$abilities,
            );
        }

        if (
            array_key_exists(
                'chosenAbilityIncrease',
                $payload,
            )
        ) {
            $increase = $this->integer(
                $payload['chosenAbilityIncrease'],
            );

            if ($increase === null) {
                throw new \InvalidArgumentException(
                    'Le bonus de caractéristique du don est invalide.',
                );
            }

            $feat->setChosenAbilityIncrease(
                $increase,
            );
        }

        if (array_key_exists('custom', $payload)) {
            $feat->setCustom(
                $this->boolean(
                    $payload['custom'],
                ),
            );
        }
    }

    /**
     * @return list<Ability>
     */
    private function resolveAbilities(
        mixed $values,
    ): array {
        if (!is_array($values)) {
            throw new \InvalidArgumentException(
                'La liste des caractéristiques autorisées est invalide.',
            );
        }

        $abilities = [];

        foreach ($values as $value) {
            $ability = Ability::tryFrom(
                (string) $value,
            );

            if ($ability === null) {
                throw new \InvalidArgumentException(
                    sprintf(
                        'La caractéristique "%s" est invalide.',
                        (string) $value,
                    ),
                );
            }

            $abilities[$ability->value] =
                $ability;
        }

        return array_values($abilities);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(
        Feat $feat,
    ): array {
        return [
            'id' => $feat->getId(),
            'slug' => $feat->getSlug(),
            'name' => $feat->getName(),
            'description' => $feat->getDescription(),
            'repeatable' => $feat->isRepeatable(),
            'requiresAbilityChoice' =>
                $feat->requiresAbilityChoice(),
            'chosenAbilityIncrease' =>
                $feat->getChosenAbilityIncrease(),
            'allowedAbilities' => array_map(
                static fn (Ability $ability): string =>
                    $ability->value,
                $feat->getAllowedAbilities(),
            ),
            'custom' => $feat->isCustom(),
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
