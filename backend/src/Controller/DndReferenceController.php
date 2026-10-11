<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\CharacterClass;
use App\Entity\CharacterRace;
use App\Entity\CharacterSubclass;
use App\Entity\Feat;
use App\Entity\RaceAbilityModifier;
use App\Enum\Ability;
use App\Service\CharacterRaceMetadataResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class DndReferenceController extends AbstractController
{
    public function __construct(private readonly CharacterRaceMetadataResolver $raceMetadataResolver, private readonly string $projectDir)
    {
    }

    #[Route('/dnd/reference', name: 'api_dnd_reference', methods: ['GET'])]
    public function show(EntityManagerInterface $entityManager): JsonResponse
    {
        return $this->catalogue($entityManager, null);
    }

    #[Route('/campaigns/{campaignId}/dnd/reference', name: 'api_campaign_dnd_reference', requirements: ['campaignId' => '\d+'], methods: ['GET'])]
    public function campaign(#[\Symfony\Bridge\Doctrine\Attribute\MapEntity(id: 'campaignId')] \App\Entity\Campaign $campaign, EntityManagerInterface $entityManager): JsonResponse
    {
        $this->denyAccessUnlessGranted(\App\Security\Voter\CampaignVoter::VIEW, $campaign);
        return $this->catalogue($entityManager, $campaign->getOwner());
    }

    private function catalogue(EntityManagerInterface $entityManager, ?\App\Entity\User $owner): JsonResponse
    {
        $races = \App\Service\ReferenceVisibility::choices($entityManager, CharacterRace::class, $owner);
        $catalogue = json_decode((string) file_get_contents($this->projectDir.'/data/reference/dnd-2014-races.json'), false, 512, JSON_THROW_ON_ERROR);
        $playableSlugs = [];
        foreach ($catalogue->entries as $entry) if ($entry->selectable) $playableSlugs[$entry->slug] = true;

        $classes = \App\Service\ReferenceVisibility::choices($entityManager, CharacterClass::class, $owner);

        $subclasses = \App\Service\ReferenceVisibility::choices($entityManager, CharacterSubclass::class, $owner);

        $feats = \App\Service\ReferenceVisibility::choices($entityManager, Feat::class, $owner);

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
                    $this->serializeRace($race, $playableSlugs),
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
    private function serializeRace(CharacterRace $race, array $playableSlugs): array
    {
        return [
            'id' => $race->getId(),
            'slug' => $race->getSlug(),
            'name' => $race->getName(),
            'selectable' => $race->isSelectable() && ($race->isCustom() || isset($playableSlugs[$race->getSlug()])),
            'parentRaceId' => $race->getParentRace()?->getId(),
            'metadata' => [
                'sizeOptions' => $race->getSizeOptions(),
                'walkingSpeed' => $race->getWalkingSpeed(),
                'movementSpeeds' => $race->getMovementSpeeds(),
                'languages' => $race->getLanguages(),
                'languageChoiceCount' => $race->getLanguageChoiceCount(),
                'senses' => $race->getSenses(),
                'damageResistances' => $race->getDamageResistances(),
                'damageImmunities' => $race->getDamageImmunities(),
                'conditionImmunities' => $race->getConditionImmunities(),
            ],
            'effectiveMetadata' => $this->raceMetadataResolver->resolve($race),
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
