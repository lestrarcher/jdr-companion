<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\CharacterRace;
use App\Entity\RaceAbilityModifier;
use App\Enum\Ability;
use App\Repository\CharacterRaceRepository;
use Doctrine\ORM\EntityManagerInterface;
use JsonException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/dnd/races')]
final class CharacterRaceController extends AbstractController
{
    #[Route('', name: 'api_dnd_races_list', methods: ['GET'])]
    public function list(
        CharacterRaceRepository $repository,
    ): JsonResponse {
        $this->denyAccessUnlessGranted('ROLE_USER');

        return $this->json([
            'races' => array_map(
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

    #[Route('', name: 'api_dnd_races_create', methods: ['POST'])]
    public function create(
        Request $request,
        CharacterRaceRepository $repository,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        $this->denyAccessUnlessGranted('ROLE_USER');

        $payload = $this->payload($request);

        if ($payload instanceof JsonResponse) {
            return $payload;
        }

        $slug = strtolower(trim((string) ($payload['slug'] ?? '')));
        $name = trim((string) ($payload['name'] ?? ''));
        $featChoiceCount = $this->integer(
            $payload['featChoiceCount'] ?? 0,
        );

        if ($slug === '' || $name === '') {
            return $this->validationError(
                'Le slug et le nom de la race sont obligatoires.',
            );
        }

        if ($repository->findOneBy(['slug' => $slug]) !== null) {
            return $this->validationError(
                'Une race utilise déjà ce slug.',
            );
        }

        if ($featChoiceCount === null) {
            return $this->validationError(
                'Le nombre de dons raciaux est invalide.',
            );
        }

        $parentRace = $this->resolveParentRace(
            $payload['parentRaceId'] ?? null,
            $repository,
        );

        if ($parentRace instanceof JsonResponse) {
            return $parentRace;
        }

        $modifiers = $this->resolveModifiers(
            $payload['abilityModifiers'] ?? [],
        );

        if ($modifiers instanceof JsonResponse) {
            return $modifiers;
        }

        try {
            $race = (new CharacterRace($slug, $name))
                ->setParentRace($parentRace)
                ->setDescription(
                    $this->nullableString(
                        $payload['description'] ?? null,
                    ),
                )
                ->setFeatChoiceCount($featChoiceCount)
                ->setCustom(
                    $this->boolean($payload['custom'] ?? false),
                );

            foreach ($modifiers as $modifier) {
                $raceModifier = new RaceAbilityModifier(
                    race: $race,
                    value: $modifier['value'],
                    ability: $modifier['ability'],
                    choiceKey: $modifier['choiceKey'],
                );

                $race->addAbilityModifier($raceModifier);
            }

            $entityManager->persist($race);
            $entityManager->flush();
        } catch (\LogicException $exception) {
            return $this->validationError($exception->getMessage());
        }

        return $this->json(
            [
                'message' => sprintf(
                    'La race "%s" a été créée.',
                    $race->getName(),
                ),
                'race' => $this->serialize($race),
            ],
            Response::HTTP_CREATED,
        );
    }

    #[Route(
        '/{raceId}',
        name: 'api_dnd_races_update',
        requirements: ['raceId' => '\d+'],
        methods: ['PATCH'],
    )]
    public function update(
        int $raceId,
        Request $request,
        CharacterRaceRepository $repository,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        $this->denyAccessUnlessGranted('ROLE_USER');

        $race = $repository->find($raceId);

        if (!$race instanceof CharacterRace) {
            return $this->json(
                ['message' => 'Race introuvable.'],
                Response::HTTP_NOT_FOUND,
            );
        }

        $payload = $this->payload($request);

        if ($payload instanceof JsonResponse) {
            return $payload;
        }

        /*
         * Les bonus raciaux ne sont volontairement pas remplacés ici.
         * Certains personnages peuvent déjà référencer précisément
         * leurs modificateurs raciaux libres.
         */
        if (array_key_exists('abilityModifiers', $payload)) {
            return $this->validationError(
                'Les bonus d’une race existante doivent être gérés individuellement.',
            );
        }

        try {
            if (array_key_exists('slug', $payload)) {
                $slug = strtolower(trim((string) $payload['slug']));
                $existingRace = $repository->findOneBy(['slug' => $slug]);

                if (
                    $existingRace instanceof CharacterRace
                    && $existingRace !== $race
                ) {
                    return $this->validationError(
                        'Une race utilise déjà ce slug.',
                    );
                }

                $race->setSlug($slug);
            }

            if (array_key_exists('name', $payload)) {
                $race->setName((string) $payload['name']);
            }

            if (array_key_exists('description', $payload)) {
                $race->setDescription(
                    $this->nullableString(
                        $payload['description'],
                    ),
                );
            }

            if (array_key_exists('featChoiceCount', $payload)) {
                $featChoiceCount = $this->integer(
                    $payload['featChoiceCount'],
                );

                if ($featChoiceCount === null) {
                    return $this->validationError(
                        'Le nombre de dons raciaux est invalide.',
                    );
                }

                $race->setFeatChoiceCount($featChoiceCount);
            }

            if (array_key_exists('parentRaceId', $payload)) {
                $parentRace = $this->resolveParentRace(
                    $payload['parentRaceId'],
                    $repository,
                );

                if ($parentRace instanceof JsonResponse) {
                    return $parentRace;
                }

                if ($parentRace === $race) {
                    return $this->validationError(
                        'Une race ne peut pas être sa propre race parente.',
                    );
                }

                $race->setParentRace($parentRace);
            }

            if (array_key_exists('custom', $payload)) {
                $race->setCustom(
                    $this->boolean($payload['custom']),
                );
            }

            $entityManager->flush();
        } catch (\LogicException $exception) {
            return $this->validationError($exception->getMessage());
        }

        return $this->json([
            'message' => sprintf(
                'La race "%s" a été mise à jour.',
                $race->getName(),
            ),
            'race' => $this->serialize($race),
        ]);
    }

    #[Route(
        '/{raceId}/ability-modifiers',
        name: 'api_dnd_race_modifiers_create',
        requirements: ['raceId' => '\d+'],
        methods: ['POST'],
    )]
    public function addModifier(
        int $raceId,
        Request $request,
        CharacterRaceRepository $repository,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        $this->denyAccessUnlessGranted('ROLE_USER');

        $race = $repository->find($raceId);

        if (!$race instanceof CharacterRace) {
            return $this->json(
                ['message' => 'Race introuvable.'],
                Response::HTTP_NOT_FOUND,
            );
        }

        $payload = $this->payload($request);

        if ($payload instanceof JsonResponse) {
            return $payload;
        }

        $modifiers = $this->resolveModifiers([$payload]);

        if ($modifiers instanceof JsonResponse) {
            return $modifiers;
        }

        $modifierData = $modifiers[0];

        if ($this->modifierAlreadyExists(
            $race,
            $modifierData['ability'],
            $modifierData['choiceKey'],
        )) {
            return $this->validationError(
                'Un bonus racial équivalent existe déjà.',
            );
        }

        try {
            $modifier = new RaceAbilityModifier(
                race: $race,
                value: $modifierData['value'],
                ability: $modifierData['ability'],
                choiceKey: $modifierData['choiceKey'],
            );

            $race->addAbilityModifier($modifier);
            $entityManager->persist($modifier);
            $entityManager->flush();
        } catch (\LogicException $exception) {
            return $this->validationError($exception->getMessage());
        }

        return $this->json(
            [
                'message' => 'Le bonus racial a été ajouté.',
                'modifier' => $this->serializeModifier($modifier),
                'race' => $this->serialize($race),
            ],
            Response::HTTP_CREATED,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(CharacterRace $race): array
    {
        return [
            'id' => $race->getId(),
            'slug' => $race->getSlug(),
            'name' => $race->getName(),
            'description' => $race->getDescription(),
            'custom' => $race->isCustom(),
            'featChoiceCount' => $race->getFeatChoiceCount(),
            'inheritedFeatChoiceCount' =>
                $race->getInheritedFeatChoiceCount(),
            'parentRace' => $race->getParentRace() !== null
                ? [
                    'id' => $race->getParentRace()?->getId(),
                    'slug' => $race->getParentRace()?->getSlug(),
                    'name' => $race->getParentRace()?->getName(),
                ]
                : null,
            'abilityModifiers' => array_map(
                $this->serializeModifier(...),
                $race->getAbilityModifiers()->toArray(),
            ),
            'inheritedAbilityModifiers' => array_map(
                $this->serializeModifier(...),
                $race->getInheritedAbilityModifiers(),
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeModifier(
        RaceAbilityModifier $modifier,
    ): array {
        return [
            'id' => $modifier->getId(),
            'sourceRaceId' => $modifier->getRace()->getId(),
            'sourceRaceName' => $modifier->getRace()->getName(),
            'ability' => $modifier->getAbility()?->value,
            'abilityLabel' => $modifier->getAbility()?->label(),
            'abilityAbbreviation' =>
                $modifier->getAbility()?->abbreviation(),
            'value' => $modifier->getValue(),
            'choiceKey' => $modifier->getChoiceKey(),
            'requiresChoice' => $modifier->requiresChoice(),
        ];
    }

    /**
     * @param array<mixed> $payload
     *
     * @return list<array{
     *     value: int,
     *     ability: Ability|null,
     *     choiceKey: string|null
     * }>|JsonResponse
     */
    private function resolveModifiers(
        array $payload,
    ): array|JsonResponse {
        $modifiers = [];
        $fixedAbilities = [];
        $choiceKeys = [];

        foreach ($payload as $index => $modifierPayload) {
            if (!is_array($modifierPayload)) {
                return $this->validationError(sprintf(
                    'Le bonus racial numéro %d est invalide.',
                    $index + 1,
                ));
            }

            $value = $this->integer(
                $modifierPayload['value'] ?? null,
            );

            if ($value === null || $value === 0) {
                return $this->validationError(sprintf(
                    'La valeur du bonus racial numéro %d est invalide.',
                    $index + 1,
                ));
            }

            $abilityValue = $modifierPayload['ability'] ?? null;
            $ability = null;
            $choiceKey = null;

            if ($abilityValue !== null && $abilityValue !== '') {
                $ability = Ability::tryFrom(
                    (string) $abilityValue,
                );

                if ($ability === null) {
                    return $this->validationError(sprintf(
                        'La caractéristique du bonus numéro %d est invalide.',
                        $index + 1,
                    ));
                }

                if (isset($fixedAbilities[$ability->value])) {
                    return $this->validationError(sprintf(
                        'La caractéristique %s possède plusieurs bonus fixes.',
                        $ability->label(),
                    ));
                }

                $fixedAbilities[$ability->value] = true;
            } else {
                $choiceKey = trim(
                    (string) (
                        $modifierPayload['choiceKey']
                        ?? sprintf(
                            'ability-choice-%d',
                            $index + 1,
                        )
                    ),
                );

                if ($choiceKey === '') {
                    return $this->validationError(
                        'Un bonus libre doit posséder une clé de choix.',
                    );
                }

                if (isset($choiceKeys[$choiceKey])) {
                    return $this->validationError(
                        'Deux bonus libres utilisent la même clé de choix.',
                    );
                }

                $choiceKeys[$choiceKey] = true;
            }

            $modifiers[] = [
                'value' => $value,
                'ability' => $ability,
                'choiceKey' => $choiceKey,
            ];
        }

        return $modifiers;
    }

    private function modifierAlreadyExists(
        CharacterRace $race,
        ?Ability $ability,
        ?string $choiceKey,
    ): bool {
        foreach ($race->getAbilityModifiers() as $modifier) {
            if (
                $ability !== null
                && $modifier->getAbility() === $ability
            ) {
                return true;
            }

            if (
                $choiceKey !== null
                && $modifier->getChoiceKey() === $choiceKey
            ) {
                return true;
            }
        }

        return false;
    }

    private function resolveParentRace(
        mixed $parentRaceId,
        CharacterRaceRepository $repository,
    ): CharacterRace|JsonResponse|null {
        if ($parentRaceId === null || $parentRaceId === '') {
            return null;
        }

        $parentRaceId = $this->integer($parentRaceId);

        if ($parentRaceId === null || $parentRaceId <= 0) {
            return $this->validationError(
                'La race parente sélectionnée est invalide.',
            );
        }

        $parentRace = $repository->find($parentRaceId);

        return $parentRace instanceof CharacterRace
            ? $parentRace
            : $this->validationError(
                'La race parente sélectionnée est introuvable.',
            );
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
