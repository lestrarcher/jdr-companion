<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\CharacterClass;
use App\Enum\SpellcastingProgressionType;
use App\Repository\CharacterClassRepository;
use Doctrine\ORM\EntityManagerInterface;
use JsonException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/dnd/classes')]
final class CharacterClassController extends AbstractController
{
    #[Route('', name: 'api_dnd_classes_list', methods: ['GET'])]
    public function list(
        CharacterClassRepository $repository,
    ): JsonResponse {
        $this->denyAccessUnlessGranted('ROLE_USER');

        return $this->json([
            'classes' => array_map(
                $this->serialize(...),
                $repository->findBy([], ['name' => 'ASC']),
            ),
            'hitDice' => [6, 8, 10, 12],
            'spellcastingProgressions' =>
                $this->serializeSpellcastingProgressions(),
        ]);
    }

    #[Route('', name: 'api_dnd_classes_create', methods: ['POST'])]
    public function create(
        Request $request,
        CharacterClassRepository $repository,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        $this->denyAccessUnlessGranted('ROLE_USER');

        $payload = $this->payload($request);

        if ($payload instanceof JsonResponse) {
            return $payload;
        }

        $slug = strtolower(trim((string) ($payload['slug'] ?? '')));
        $name = trim((string) ($payload['name'] ?? ''));
        $hitDie = $this->integer($payload['hitDie'] ?? null);
        $subclassSelectionLevel = $this->integer(
            $payload['subclassSelectionLevel'] ?? null,
        );

        $progression = SpellcastingProgressionType::tryFrom(
            (string) ($payload['spellcastingProgression'] ?? ''),
        );

        if ($slug === '' || $name === '') {
            return $this->validationError(
                'Le slug et le nom de la classe sont obligatoires.',
            );
        }

        if ($repository->findOneBy(['slug' => $slug]) !== null) {
            return $this->validationError(
                'Une classe utilise déjà ce slug.',
            );
        }

        if ($hitDie === null) {
            return $this->validationError(
                'Le dé de vie est obligatoire.',
            );
        }

        if ($subclassSelectionLevel === null) {
            return $this->validationError(
                'Le niveau de sélection de la sous-classe est obligatoire.',
            );
        }

        if ($progression === null) {
            return $this->validationError(
                'La progression magique est invalide.',
            );
        }

        try {
            $characterClass = new CharacterClass(
                slug: $slug,
                name: $name,
                hitDie: $hitDie,
                subclassSelectionLevel: $subclassSelectionLevel,
                spellcastingProgression: $progression,
            );

            $characterClass
                ->setDescription(
                    $this->nullableString($payload['description'] ?? null),
                )
                ->setCustom(
                    $this->boolean($payload['custom'] ?? false),
                );

            $entityManager->persist($characterClass);
            $entityManager->flush();
        } catch (\LogicException $exception) {
            return $this->validationError($exception->getMessage());
        }

        return $this->json(
            [
                'message' => sprintf(
                    'La classe "%s" a été créée.',
                    $characterClass->getName(),
                ),
                'class' => $this->serialize($characterClass),
            ],
            Response::HTTP_CREATED,
        );
    }

    #[Route(
        '/{classId}',
        name: 'api_dnd_classes_update',
        requirements: ['classId' => '\d+'],
        methods: ['PATCH'],
    )]
    public function update(
        int $classId,
        Request $request,
        CharacterClassRepository $repository,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        $this->denyAccessUnlessGranted('ROLE_USER');

        $characterClass = $repository->find($classId);

        if (!$characterClass instanceof CharacterClass) {
            return $this->json(
                ['message' => 'Classe introuvable.'],
                Response::HTTP_NOT_FOUND,
            );
        }

        $payload = $this->payload($request);

        if ($payload instanceof JsonResponse) {
            return $payload;
        }

        try {
            if (array_key_exists('slug', $payload)) {
                $slug = strtolower(trim((string) $payload['slug']));

                $existingClass = $repository->findOneBy([
                    'slug' => $slug,
                ]);

                if (
                    $existingClass instanceof CharacterClass
                    && $existingClass !== $characterClass
                ) {
                    return $this->validationError(
                        'Une classe utilise déjà ce slug.',
                    );
                }

                $characterClass->setSlug($slug);
            }

            if (array_key_exists('name', $payload)) {
                $characterClass->setName((string) $payload['name']);
            }

            if (array_key_exists('hitDie', $payload)) {
                $hitDie = $this->integer($payload['hitDie']);

                if ($hitDie === null) {
                    return $this->validationError(
                        'Le dé de vie est invalide.',
                    );
                }

                $characterClass->setHitDie($hitDie);
            }

            if (
                array_key_exists(
                    'subclassSelectionLevel',
                    $payload,
                )
            ) {
                $selectionLevel = $this->integer(
                    $payload['subclassSelectionLevel'],
                );

                if ($selectionLevel === null) {
                    return $this->validationError(
                        'Le niveau de sélection de la sous-classe est invalide.',
                    );
                }

                $characterClass->setSubclassSelectionLevel(
                    $selectionLevel,
                );
            }

            if (
                array_key_exists(
                    'spellcastingProgression',
                    $payload,
                )
            ) {
                $progression =
                    SpellcastingProgressionType::tryFrom(
                        (string) $payload[
                            'spellcastingProgression'
                        ],
                    );

                if ($progression === null) {
                    return $this->validationError(
                        'La progression magique est invalide.',
                    );
                }

                $characterClass->setSpellcastingProgression(
                    $progression,
                );
            }

            if (array_key_exists('description', $payload)) {
                $characterClass->setDescription(
                    $this->nullableString(
                        $payload['description'],
                    ),
                );
            }

            if (array_key_exists('custom', $payload)) {
                $characterClass->setCustom(
                    $this->boolean($payload['custom']),
                );
            }

            $entityManager->flush();
        } catch (\LogicException $exception) {
            return $this->validationError($exception->getMessage());
        }

        return $this->json([
            'message' => sprintf(
                'La classe "%s" a été mise à jour.',
                $characterClass->getName(),
            ),
            'class' => $this->serialize($characterClass),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(
        CharacterClass $characterClass,
    ): array {
        $progression =
            $characterClass->getSpellcastingProgression();

        return [
            'id' => $characterClass->getId(),
            'slug' => $characterClass->getSlug(),
            'name' => $characterClass->getName(),
            'hitDie' => $characterClass->getHitDie(),
            'subclassSelectionLevel' =>
                $characterClass
                    ->getSubclassSelectionLevel(),
            'spellcastingProgression' =>
                $progression->value,
            'spellcastingProgressionLabel' =>
                $this->spellcastingProgressionLabel(
                    $progression,
                ),
            'description' =>
                $characterClass->getDescription(),
            'custom' => $characterClass->isCustom(),
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function serializeSpellcastingProgressions(): array
    {
        return array_map(
            fn (
                SpellcastingProgressionType $progression,
            ): array => [
                'value' => $progression->value,
                'label' =>
                    $this->spellcastingProgressionLabel(
                        $progression,
                    ),
            ],
            SpellcastingProgressionType::cases(),
        );
    }

    private function spellcastingProgressionLabel(
        SpellcastingProgressionType $progression,
    ): string {
        return match ($progression->value) {
            'none' => 'Aucune',
            'full' => 'Lanceur de sorts complet',
            'half' => 'Demi-lanceur de sorts',
            'artificer' => 'Artificier',
            'third' => 'Tiers de lanceur de sorts',
            'pact' => 'Magie de pacte',
            default => ucfirst(
                str_replace(
                    ['-', '_'],
                    ' ',
                    $progression->value,
                ),
            ),
        };
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

    private function integer(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (!is_string($value) && !is_float($value)) {
            return null;
        }

        $result = filter_var(
            $value,
            FILTER_VALIDATE_INT,
        );

        return $result !== false ? $result : null;
    }

    private function boolean(mixed $value): bool
    {
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

        return $value !== '' ? $value : null;
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
