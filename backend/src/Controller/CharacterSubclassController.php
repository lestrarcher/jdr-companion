<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\CharacterClass;
use App\Entity\CharacterSubclass;
use App\Enum\SpellcastingProgressionType;
use Doctrine\ORM\EntityManagerInterface;
use JsonException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/dnd/subclasses')]
final class CharacterSubclassController extends AbstractController
{
    #[Route('', name: 'api_dnd_subclass_list', methods: ['GET'])]
    public function list(EntityManagerInterface $entityManager): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_USER');

        $subclasses = $entityManager
            ->getRepository(CharacterSubclass::class)
            ->findBy([], ['name' => 'ASC']);

        return $this->json([
            'subclasses' => array_map($this->serializeSubclass(...), $subclasses),
            'spellcastingProgressions' => array_map(
                static fn (SpellcastingProgressionType $progression): array => [
                    'value' => $progression->value,
                    'label' => self::progressionLabel($progression),
                ],
                SpellcastingProgressionType::cases(),
            ),
        ]);
    }

    #[Route('', name: 'api_dnd_subclass_create', methods: ['POST'])]
    public function create(
        Request $request,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        $this->denyAccessUnlessGranted('ROLE_USER');

        $payload = $this->payload($request);

        if ($payload instanceof JsonResponse) {
            return $payload;
        }

        $classId = $this->integer($payload['classId'] ?? null);
        $slug = strtolower(trim((string) ($payload['slug'] ?? '')));
        $name = trim((string) ($payload['name'] ?? ''));

        if ($classId === null || $classId <= 0) {
            return $this->validationError('La classe parente est obligatoire.');
        }

        $characterClass = $entityManager
            ->getRepository(CharacterClass::class)
            ->find($classId);

        if (!$characterClass instanceof CharacterClass) {
            return $this->validationError('La classe parente est introuvable.');
        }

        if ($slug === '' || $name === '') {
            return $this->validationError('Le slug et le nom de la sous-classe sont obligatoires.');
        }

        $existingSubclass = $entityManager
            ->getRepository(CharacterSubclass::class)
            ->findOneBy([
                'characterClass' => $characterClass,
                'slug' => $slug,
            ]);

        if ($existingSubclass !== null) {
            return $this->json(
                ['message' => 'Cette classe possède déjà une sous-classe utilisant ce slug.'],
                Response::HTTP_CONFLICT,
            );
        }

        $progression = $this->resolveProgression($payload['spellcastingProgression'] ?? null);

        if ($progression instanceof JsonResponse) {
            return $progression;
        }

        try {
            $subclass = new CharacterSubclass($characterClass, $slug, $name);
            $subclass
                ->setDescription($this->nullableString($payload['description'] ?? null))
                ->setSpellcastingProgression($progression)
                ->setCustom((bool) ($payload['custom'] ?? true));

            $entityManager->persist($subclass);
            $entityManager->flush();
        } catch (\LogicException $exception) {
            return $this->validationError($exception->getMessage());
        }

        return $this->json(
            ['subclass' => $this->serializeSubclass($subclass)],
            Response::HTTP_CREATED,
        );
    }

    #[Route(
        '/{subclassId}',
        name: 'api_dnd_subclass_update',
        requirements: ['subclassId' => '\d+'],
        methods: ['PATCH'],
    )]
    public function update(
        int $subclassId,
        Request $request,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        $this->denyAccessUnlessGranted('ROLE_USER');

        $subclass = $entityManager
            ->getRepository(CharacterSubclass::class)
            ->find($subclassId);

        if (!$subclass instanceof CharacterSubclass) {
            return $this->json(
                ['message' => 'Sous-classe introuvable.'],
                Response::HTTP_NOT_FOUND,
            );
        }

        $payload = $this->payload($request);

        if ($payload instanceof JsonResponse) {
            return $payload;
        }

        if (array_key_exists('slug', $payload)) {
            $slug = strtolower(trim((string) $payload['slug']));

            $existingSubclass = $entityManager
                ->getRepository(CharacterSubclass::class)
                ->findOneBy([
                    'characterClass' => $subclass->getCharacterClass(),
                    'slug' => $slug,
                ]);

            if (
                $existingSubclass instanceof CharacterSubclass
                && $existingSubclass->getId() !== $subclass->getId()
            ) {
                return $this->json(
                    ['message' => 'Cette classe possède déjà une sous-classe utilisant ce slug.'],
                    Response::HTTP_CONFLICT,
                );
            }
        }

        $progression = $subclass->getSpellcastingProgression();

        if (array_key_exists('spellcastingProgression', $payload)) {
            $progression = $this->resolveProgression($payload['spellcastingProgression']);

            if ($progression instanceof JsonResponse) {
                return $progression;
            }
        }

        try {
            if (array_key_exists('slug', $payload)) {
                $subclass->setSlug((string) $payload['slug']);
            }

            if (array_key_exists('name', $payload)) {
                $subclass->setName((string) $payload['name']);
            }

            if (array_key_exists('description', $payload)) {
                $subclass->setDescription($this->nullableString($payload['description']));
            }

            if (array_key_exists('spellcastingProgression', $payload)) {
                $subclass->setSpellcastingProgression($progression);
            }

            if (array_key_exists('custom', $payload)) {
                $subclass->setCustom((bool) $payload['custom']);
            }

            $entityManager->flush();
        } catch (\LogicException $exception) {
            return $this->validationError($exception->getMessage());
        }

        return $this->json([
            'subclass' => $this->serializeSubclass($subclass),
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

    private function resolveProgression(
        mixed $value,
    ): SpellcastingProgressionType|JsonResponse|null {
        if ($value === null || $value === '') {
            return null;
        }

        $progression = SpellcastingProgressionType::tryFrom((string) $value);

        return $progression
            ?? $this->validationError('La progression magique est invalide.');
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeSubclass(CharacterSubclass $subclass): array
    {
        return [
            'id' => $subclass->getId(),
            'classId' => $subclass->getCharacterClass()->getId(),
            'className' => $subclass->getCharacterClass()->getName(),
            'slug' => $subclass->getSlug(),
            'name' => $subclass->getName(),
            'description' => $subclass->getDescription(),
            'spellcastingProgression' => $subclass->getSpellcastingProgression()?->value,
            'custom' => $subclass->isCustom(),
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

    private static function progressionLabel(
        SpellcastingProgressionType $progression,
    ): string {
        return match ($progression->value) {
            'none' => 'Aucune',
            'full' => 'Lanceur de sorts complet',
            'half' => 'Demi-lanceur de sorts',
            'artificer' => 'Artificier',
            'third' => 'Tiers-lanceur de sorts',
            'pact' => 'Magie de pacte',
            default => ucfirst($progression->value),
        };
    }
}
