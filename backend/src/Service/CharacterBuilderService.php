<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Campaign;
use App\Entity\Character;
use App\Entity\CharacterClass;
use App\Entity\CharacterFeat;
use App\Entity\CharacterRace;
use App\Entity\CharacterRaceAbilityChoice;
use App\Entity\CharacterSubclass;
use App\Entity\Feat;
use App\Entity\RaceAbilityModifier;
use App\Enum\Ability;
use App\Repository\CharacterRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class CharacterBuilderService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private CharacterRepository $characterRepository,
        private CharacterLevelUpService $levelUpService,
    ) {
    }

    /**
     * @param array<string, int> $abilityScores
     * @param list<array{modifier: RaceAbilityModifier, ability: Ability}> $racialAbilityChoices
     * @param list<array{feat: Feat, ability: Ability|null}> $racialFeatChoices
     */
    public function create(
        Campaign $campaign,
        string $slug,
        string $name,
        ?string $playerName,
        string $type,
        CharacterRace $race,
        array $abilityScores,
        array $racialAbilityChoices,
        array $racialFeatChoices,
        CharacterClass $startingClass,
        ?CharacterSubclass $startingSubclass = null,
    ): Character {
        return $this->entityManager->wrapInTransaction(function () use (
            $campaign,
            $slug,
            $name,
            $playerName,
            $type,
            $race,
            $abilityScores,
            $racialAbilityChoices,
            $racialFeatChoices,
            $startingClass,
            $startingSubclass,
        ): Character {
            $slug = strtolower(trim($slug));
            $name = trim($name);
            $playerName = $playerName !== null ? trim($playerName) : null;

            $this->validateIdentity(
                $campaign,
                $slug,
                $name,
                $type,
            );

            $character = new Character(
                campaign: $campaign,
                slug: $slug,
                name: $name,
                type: $type,
                definition: [],
            );

            $character
                ->setPlayerName($playerName !== '' ? $playerName : null)
                ->setRace($race);

            $this->applyAbilityScores(
                $character,
                $abilityScores,
            );

            $this->applyRacialAbilityChoices(
                $character,
                $race,
                $racialAbilityChoices,
            );

            $this->applyRacialFeatChoices(
                $character,
                $race,
                $racialFeatChoices,
            );

            $this->entityManager->persist($character);

            $this->levelUpService->levelUp(
                character: $character,
                characterClass: $startingClass,
                subclass: $startingSubclass,
            );

            $this->entityManager->flush();

            return $character;
        });
    }

    private function validateIdentity(
        Campaign $campaign,
        string $slug,
        string $name,
        string $type,
    ): void {
        if (
            $slug === ''
            || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)
        ) {
            throw new \DomainException(
                'Le slug doit contenir uniquement des lettres minuscules, des chiffres et des tirets.',
            );
        }

        if ($name === '') {
            throw new \DomainException(
                'Le nom du personnage est obligatoire.',
            );
        }

        if (!in_array($type, [Character::TYPE_PLAYER, Character::TYPE_NPC], true)) {
            throw new \DomainException(
                'Le type doit être "player" ou "npc".',
            );
        }

        if (
            $this->characterRepository->findOneBy([
                'campaign' => $campaign,
                'slug' => $slug,
            ]) !== null
        ) {
            throw new \DomainException(
                'Ce slug de personnage existe déjà dans cette campagne.',
            );
        }
    }

    /**
     * @param array<string, int> $abilityScores
     */
    private function applyAbilityScores(
        Character $character,
        array $abilityScores,
    ): void {
        $expectedAbilities = array_map(
            static fn (Ability $ability): string => $ability->value,
            Ability::cases(),
        );

        $providedAbilities = array_keys($abilityScores);

        sort($expectedAbilities);
        sort($providedAbilities);

        if ($providedAbilities !== $expectedAbilities) {
            throw new \DomainException(
                'Les six caractéristiques initiales doivent être renseignées exactement une fois.',
            );
        }

        foreach (Ability::cases() as $ability) {
            $character
                ->getAbilityScore($ability)
                ->setBaseValue($abilityScores[$ability->value]);
        }
    }

    /**
     * @param list<array{modifier: RaceAbilityModifier, ability: Ability}> $choices
     */
    private function applyRacialAbilityChoices(
        Character $character,
        CharacterRace $race,
        array $choices,
    ): void {
        $requiredModifiers = array_values(array_filter(
            $race->getInheritedAbilityModifiers(),
            static fn (RaceAbilityModifier $modifier): bool =>
                $modifier->requiresChoice(),
        ));

        if (count($choices) !== count($requiredModifiers)) {
            throw new \DomainException(sprintf(
                'La race %s nécessite %d choix de caractéristiques.',
                $race->getName(),
                count($requiredModifiers),
            ));
        }

        foreach ($choices as $choice) {
            if (!in_array($choice['modifier'], $requiredModifiers, true)) {
                throw new \DomainException(
                    'Un choix de caractéristique ne correspond pas à la race sélectionnée.',
                );
            }

            $racialChoice = new CharacterRaceAbilityChoice(
                character: $character,
                modifier: $choice['modifier'],
                ability: $choice['ability'],
            );

            $character->addRaceAbilityChoice($racialChoice);
            $this->entityManager->persist($racialChoice);
        }

        if (!$character->hasCompletedRaceAbilityChoices()) {
            throw new \DomainException(
                'Les choix de caractéristiques raciales sont incomplets.',
            );
        }
    }

    /**
     * @param list<array{feat: Feat, ability: Ability|null}> $choices
     */
    private function applyRacialFeatChoices(
        Character $character,
        CharacterRace $race,
        array $choices,
    ): void {
        $requiredCount = $race->getInheritedFeatChoiceCount();

        if (count($choices) !== $requiredCount) {
            throw new \DomainException(sprintf(
                'La race %s nécessite %d choix de don.',
                $race->getName(),
                $requiredCount,
            ));
        }

        foreach ($choices as $choice) {
            $feat = $choice['feat'];
            $ability = $choice['ability'];

            if ($feat->requiresAbilityChoice() && $ability === null) {
                throw new \DomainException(sprintf(
                    'Le don "%s" nécessite de choisir une caractéristique.',
                    $feat->getName(),
                ));
            }

            if (!$feat->requiresAbilityChoice() && $ability !== null) {
                throw new \DomainException(sprintf(
                    'Le don "%s" ne permet pas de choisir une caractéristique.',
                    $feat->getName(),
                ));
            }

            $characterFeat = (new CharacterFeat($character, $feat))
                ->setChosenAbility($ability)
                ->setAcquiredAtLevel(1);

            $character->addFeat($characterFeat);
            $this->entityManager->persist($characterFeat);
        }
    }
}
