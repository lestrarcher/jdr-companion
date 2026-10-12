<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Campaign;
use App\Entity\CharacterBackground;
use App\Entity\CharacterClassLevel;
use App\Entity\CharacterFeat;
use App\Service\CharacterTrainingService;
use App\Service\CharacterTrainingPresenter;
use App\Service\ReferenceVisibility;
use App\Entity\Character;
use App\Entity\CharacterClass;
use App\Entity\CharacterRace;
use App\Entity\CharacterSubclass;
use App\Entity\Feat;
use App\Entity\RaceAbilityModifier;
use App\Enum\Ability;
use App\Security\Voter\CampaignVoter;
use App\Service\CharacterAbilityCalculator;
use App\Service\CharacterBuilderService;
use App\Service\CharacterResourceResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route(
    '/campaigns/{campaignId}/characters/build',
    requirements: ['campaignId' => '\d+'],
)]
final class CharacterBuilderController extends AbstractController
{
    #[Route('', name: 'api_character_build', methods: ['POST'])]
    public function create(
        #[MapEntity(id: 'campaignId')]
        Campaign $campaign,
        Request $request,
        EntityManagerInterface $entityManager,
        CharacterBuilderService $builder,
        CharacterAbilityCalculator $abilityCalculator,
        CharacterResourceResolver $resourceResolver,
        CharacterTrainingPresenter $trainingPresenter,
    ): JsonResponse {
        $this->denyAccessUnlessGranted(
            CampaignVoter::MANAGE,
            $campaign,
        );

        try {
            $payload = $request->toArray();
            if (isset($payload['level']) && $payload['level'] !== 1) throw new \DomainException('Le POST du créateur démarre au niveau 1.');
            foreach (['slug', 'name', 'type'] as $field) if (!is_string($payload[$field] ?? null)) throw new \DomainException('Chaîne attendue : '.$field);
            if (isset($payload['playerName']) && !is_string($payload['playerName'])) throw new \DomainException('Chaîne attendue : playerName');

            $race = $entityManager->find(
                CharacterRace::class,
                $this->requiredId($payload, 'raceId'),
            );

            if (!$race instanceof CharacterRace) {
                throw new \DomainException(
                    'La race sélectionnée est introuvable.',
                );
            }

            $startingClass = $entityManager->find(
                CharacterClass::class,
                $this->requiredId($payload, 'classId'),
            );

            if (!$startingClass instanceof CharacterClass) {
                throw new \DomainException(
                    'La classe sélectionnée est introuvable.',
                );
            }

            $startingSubclass = $this->resolveSubclass(
                $payload,
                $entityManager,
            );

            $character = $builder->create(
                campaign: $campaign,
                slug: (string) ($payload['slug'] ?? ''),
                name: (string) ($payload['name'] ?? ''),
                playerName: isset($payload['playerName'])
                    ? (string) $payload['playerName']
                    : null,
                type: (string) ($payload['type'] ?? ''),
                race: $race,
                abilityScores: $this->parseAbilityScores($payload),
                racialAbilityChoices: $this->parseRacialAbilityChoices(
                    $payload,
                    $entityManager,
                ),
                racialFeatChoices: $this->parseRacialFeatChoices(
                    $payload,
                    $entityManager,
                ),
                startingClass: $startingClass,
                startingSubclass: $startingSubclass,
                background: $this->resolveBackground($payload, $entityManager),
                training: $this->parseTraining($payload),
            );

            return $this->json(
                [
                    'character' => $this->serializeCharacter(
                        $character,
                        $abilityCalculator,
                        $resourceResolver,
                    ) + ['background' => $character->getBackground() === null ? null : ['id' => $character->getBackground()->getId(), 'slug' => $character->getBackground()->getSlug(), 'name' => $character->getBackground()->getName()], 'training' => $trainingPresenter->resolved($character)],
                ],
                JsonResponse::HTTP_CREATED,
            );
        } catch (\DomainException|\InvalidArgumentException $exception) {
            return $this->json(
                ['message' => $exception->getMessage()],
                JsonResponse::HTTP_UNPROCESSABLE_ENTITY,
            );
        }
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, int>
     */
    /** Read-only preview; selection is JSON in the query string, never persisted. */
    #[Route('/options', name: 'api_character_build_options', methods: ['GET'])]
    public function options(
        #[MapEntity(id: 'campaignId')] Campaign $campaign,
        Request $request,
        EntityManagerInterface $entityManager,
        CharacterTrainingService $trainingService,
        CharacterTrainingPresenter $presenter,
    ): JsonResponse {
        $this->denyAccessUnlessGranted(CampaignVoter::MANAGE, $campaign);
        try {
            $payload = json_decode($request->query->getString('selection', '{}'), true, 128, JSON_THROW_ON_ERROR);
            if (!is_array($payload)) throw new \DomainException('Objet selection attendu.');
            $race = $entityManager->find(CharacterRace::class, $this->requiredId($payload, 'raceId'));
            $class = $entityManager->find(CharacterClass::class, $this->requiredId($payload, 'classId'));
            if (!$race instanceof CharacterRace || !$class instanceof CharacterClass || !$race->isSelectable()) throw new \DomainException('Race ou classe indisponible.');
            $background = $this->resolveBackground($payload, $entityManager);
            $subclass = $this->resolveSubclass($payload, $entityManager);
            foreach (array_filter([$race, $class, $background, $subclass]) as $reference) ReferenceVisibility::requireVisible($reference, $campaign->getOwner());
            $level = $payload['level'] ?? 1;
            if (!is_int($level) || $level < 1 || $level > 20) throw new \DomainException('Niveau invalide.');
            if ($subclass && ($subclass->getCharacterClass() !== $class || $level < $class->getSubclassSelectionLevel())) throw new \DomainException('Sous-classe incompatible.');
            $character = (new Character($campaign, 'preview', 'Preview', Character::TYPE_PLAYER))->setRace($race)->setBackground($background);
            for ($position = 1; $position <= $level; ++$position) $character->addClassLevel(new CharacterClassLevel($character, $class, $position, $subclass));
            $feats = $this->parseRacialFeatChoices($payload, $entityManager);
            if (count($feats) > $race->getInheritedFeatChoiceCount()) throw new \DomainException('Trop de dons raciaux.');
            foreach ($feats as $choice) {
                ReferenceVisibility::requireVisible($choice['feat'], $campaign->getOwner());
                $character->addFeat((new CharacterFeat($character, $choice['feat']))->setChosenAbility($choice['ability'])->setAcquiredAtLevel(1));
            }
            $trainingService->apply($character, $this->parseTraining($payload), $campaign->getOwner(), false);
            return $this->json($presenter->options($character));
        } catch (\DomainException|\InvalidArgumentException|\JsonException $exception) {
            return $this->json(['message' => $exception->getMessage()], 422);
        }
    }

    private function resolveBackground(array $payload, EntityManagerInterface $em): ?CharacterBackground
    {
        if (!isset($payload['backgroundId'])) return null;
        $background = $em->find(CharacterBackground::class, $this->requiredId($payload, 'backgroundId'));
        if (!$background instanceof CharacterBackground) throw new \DomainException('Historique introuvable.');
        return $background;
    }

    private function parseTraining(array $payload): array
    {
        $training = $payload['training'] ?? [];
        if (!is_array($training) || ($training !== [] && array_is_list($training))) throw new \DomainException('Objet training attendu.');
        return $training;
    }


    private function parseAbilityScores(array $payload): array
    {
        $values = $payload['abilities'] ?? null;

        if (!is_array($values)) {
            throw new \DomainException(
                'Les caractéristiques doivent être fournies dans "abilities".',
            );
        }

        $abilities = [];

        foreach ($values as $ability => $value) {
            if (!is_string($ability) || !is_int($value)) {
                throw new \DomainException(
                    'Chaque caractéristique doit posséder une valeur entière.',
                );
            }

            if (Ability::tryFrom($ability) === null) {
                throw new \DomainException(sprintf(
                    'La caractéristique "%s" est inconnue.',
                    $ability,
                ));
            }

            $abilities[$ability] = $value;
        }

        return $abilities;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<array{
     *     modifier: RaceAbilityModifier,
     *     ability: Ability
     * }>
     */
    private function parseRacialAbilityChoices(
        array $payload,
        EntityManagerInterface $entityManager,
    ): array {
        $values = $payload['racialAbilityChoices'] ?? [];

        if (!is_array($values) || !array_is_list($values)) {
            throw new \DomainException(
                '"racialAbilityChoices" doit être une liste.',
            );
        }

        $choices = [];

        foreach ($values as $value) {
            if (!is_array($value)) {
                throw new \DomainException(
                    'Un choix racial est invalide.',
                );
            }

            $modifierId = $this->requiredId(
                $value,
                'modifierId',
            );

            $modifier = $entityManager->find(
                RaceAbilityModifier::class,
                $modifierId,
            );

            if (!$modifier instanceof RaceAbilityModifier) {
                throw new \DomainException(sprintf(
                    'Le modificateur racial %d est introuvable.',
                    $modifierId,
                ));
            }

            $ability = Ability::tryFrom(
                (string) ($value['ability'] ?? ''),
            );

            if ($ability === null) {
                throw new \DomainException(
                    'La caractéristique choisie pour le bonus racial est invalide.',
                );
            }

            $choices[] = [
                'modifier' => $modifier,
                'ability' => $ability,
            ];
        }

        return $choices;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<array{
     *     feat: Feat,
     *     ability: Ability|null
     * }>
     */
    private function parseRacialFeatChoices(
        array $payload,
        EntityManagerInterface $entityManager,
    ): array {
        $values = $payload['racialFeatChoices'] ?? [];

        if (!is_array($values) || !array_is_list($values)) {
            throw new \DomainException(
                '"racialFeatChoices" doit être une liste.',
            );
        }

        $choices = [];

        foreach ($values as $value) {
            if (!is_array($value)) {
                throw new \DomainException(
                    'Un choix de don racial est invalide.',
                );
            }

            $featId = $this->requiredId($value, 'featId');
            $feat = $entityManager->find(Feat::class, $featId);

            if (!$feat instanceof Feat) {
                throw new \DomainException(sprintf(
                    'Le don %d est introuvable.',
                    $featId,
                ));
            }

            $abilityValue = $value['ability'] ?? null;
            $ability = null;

            if ($abilityValue !== null) {
                $ability = Ability::tryFrom(
                    (string) $abilityValue,
                );

                if ($ability === null) {
                    throw new \DomainException(
                        'La caractéristique choisie pour le don est invalide.',
                    );
                }
            }

            $choices[] = [
                'feat' => $feat,
                'ability' => $ability,
            ];
        }

        return $choices;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolveSubclass(
        array $payload,
        EntityManagerInterface $entityManager,
    ): ?CharacterSubclass {
        if (
            !isset($payload['subclassId'])
            || $payload['subclassId'] === null
        ) {
            return null;
        }

        $subclass = $entityManager->find(
            CharacterSubclass::class,
            $this->requiredId($payload, 'subclassId'),
        );

        if (!$subclass instanceof CharacterSubclass) {
            throw new \DomainException(
                'La sous-classe sélectionnée est introuvable.',
            );
        }

        return $subclass;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function requiredId(
        array $payload,
        string $field,
    ): int {
        $value = $payload[$field] ?? null;

        if (!is_int($value) || $value < 1) {
            throw new \DomainException(sprintf(
                'Le champ "%s" doit contenir un identifiant valide.',
                $field,
            ));
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeCharacter(
        Character $character,
        CharacterAbilityCalculator $abilityCalculator,
        CharacterResourceResolver $resourceResolver,
    ): array {
        $classLevels = [];

        foreach ($character->getClassLevels() as $level) {
            $classLevels[] = [
                'position' => $level->getPosition(),
                'classId' =>
                    $level->getCharacterClass()->getId(),
                'className' =>
                    $level->getCharacterClass()->getName(),
                'subclassId' =>
                    $level->getSubclass()?->getId(),
                'subclassName' =>
                    $level->getSubclass()?->getName(),
            ];
        }

        return [
            'id' => $character->getId(),
            'campaignId' =>
                $character->getCampaign()->getId(),
            'slug' => $character->getSlug(),
            'name' => $character->getName(),
            'playerName' =>
                $character->getPlayerName(),
            'type' => $character->getType(),
            'race' => [
                'id' => $character->getRace()?->getId(),
                'name' => $character->getRace()?->getName(),
            ],
            'totalLevel' =>
                $character->getTotalLevel(),
            'proficiencyBonus' =>
                $character->getProficiencyBonus(),
            'classLevels' => $classLevels,
            'abilities' => array_values(array_map(
                static fn ($ability): array => $ability->toArray(),
                $abilityCalculator->calculateAll($character),
            )),
            'resources' => array_values(array_map(
                static fn ($resource): array => [
                    'slug' => $resource->getSlug(),
                    'name' => $resource->getName(),
                    'maximum' => $resource->getMaximum(),
                    'rechargeType' => $resource->getRechargeType()->value,
                ],
                $resourceResolver->resolve($character),
            )),
        ];
    }
}
