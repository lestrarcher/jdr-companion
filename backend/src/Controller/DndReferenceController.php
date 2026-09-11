<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\CharacterClass;
use App\Entity\CharacterRace;
use App\Entity\CharacterSubclass;
use App\Entity\Feat;
use App\Entity\RaceAbilityModifier;
use App\Enum\Ability;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/dnd/reference')]
final class DndReferenceController extends AbstractController
{
    #[Route('', name: 'api_dnd_reference', methods: ['GET'])]
    public function show(EntityManagerInterface $entityManager): JsonResponse
    {
        // $this->denyAccessUnlessGranted('ROLE_USER');

        $races = $entityManager
            ->getRepository(CharacterRace::class)
            ->findBy([], ['name' => 'ASC']);

        $classes = $entityManager
            ->getRepository(CharacterClass::class)
            ->findBy([], ['name' => 'ASC']);

        $subclasses = $entityManager
            ->getRepository(CharacterSubclass::class)
            ->findBy([], ['name' => 'ASC']);

        $feats = $entityManager
            ->getRepository(Feat::class)
            ->findBy([], ['name' => 'ASC']);

        return $this->json([
            'abilities' => array_map(
                static fn (Ability $ability): array => [
                    'value' => $ability->value,
                    'label' => $ability->label(),
                    'abbreviation' => $ability->abbreviation(),
                ],
                Ability::cases(),
            ),
            'races' => array_map(
                fn (CharacterRace $race): array =>
                    $this->serializeRace($race),
                $races,
            ),
            'classes' => array_map(
                static fn (CharacterClass $class): array => [
                    'id' => $class->getId(),
                    'slug' => $class->getSlug(),
                    'name' => $class->getName(),
                    'hitDie' => $class->getHitDie(),
                    'subclassSelectionLevel' =>
                        $class->getSubclassSelectionLevel(),
                    'spellcastingProgression' =>
                        $class->getSpellcastingProgression()->value,
                ],
                $classes,
            ),
            'subclasses' => array_map(
                static fn (CharacterSubclass $subclass): array => [
                    'id' => $subclass->getId(),
                    'classId' =>
                        $subclass->getCharacterClass()->getId(),
                    'slug' => $subclass->getSlug(),
                    'name' => $subclass->getName(),
                    'spellcastingProgression' =>
                        $subclass
                            ->getSpellcastingProgression()
                            ?->value,
                ],
                $subclasses,
            ),
            'feats' => array_map(
                static fn (Feat $feat): array => [
                    'id' => $feat->getId(),
                    'slug' => $feat->getSlug(),
                    'name' => $feat->getName(),
                    'description' => $feat->getDescription(),
                    'repeatable' => $feat->isRepeatable(),
                    'requiresAbilityChoice' => $feat->requiresAbilityChoice(),
                    'chosenAbilityIncrease' => $feat->getChosenAbilityIncrease(),
                    'allowedAbilities' => array_map(
                        static fn (Ability $ability): string =>
                            $ability->value,
                        $feat->getAllowedAbilities(),
                    ),
                ],
                $feats,
            ),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeRace(CharacterRace $race): array
    {
        return [
            'id' => $race->getId(),
            'slug' => $race->getSlug(),
            'name' => $race->getName(),
            'parentRaceId' => $race->getParentRace()?->getId(),
            'featChoiceCount' => $race->getInheritedFeatChoiceCount(),
            'abilityModifiers' => array_map(
                static fn (RaceAbilityModifier $modifier): array => [
                    'id' => $modifier->getId(),
                    'sourceRaceId' =>
                        $modifier->getRace()->getId(),
                    'ability' =>
                        $modifier->getAbility()?->value,
                    'value' => $modifier->getValue(),
                    'choiceKey' => $modifier->getChoiceKey(),
                    'requiresChoice' =>
                        $modifier->requiresChoice(),
                ],
                $race->getInheritedAbilityModifiers(),
            ),
        ];
    }
}
