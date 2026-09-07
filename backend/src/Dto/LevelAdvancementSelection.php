<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\Feat;
use App\Enum\Ability;

final readonly class LevelAdvancementSelection
{
    /**
     * @param list<array{ability: Ability, value: int}> $abilityIncreases
     */
    private function __construct(
        private ?Feat $feat,
        private ?Ability $featAbility,
        private array $abilityIncreases,
    ) {
    }

    public static function increaseOneAbility(Ability $ability): self
    {
        return new self(
            feat: null,
            featAbility: null,
            abilityIncreases: [
                ['ability' => $ability, 'value' => 2],
            ],
        );
    }

    public static function increaseTwoAbilities(Ability $first, Ability $second): self
    {
        if ($first === $second) {
            throw new \InvalidArgumentException(
                'Choisis deux caractéristiques différentes, ou utilise une augmentation de +2.',
            );
        }

        return new self(
            feat: null,
            featAbility: null,
            abilityIncreases: [
                ['ability' => $first, 'value' => 1],
                ['ability' => $second, 'value' => 1],
            ],
        );
    }

    public static function feat(Feat $feat, ?Ability $chosenAbility = null): self
    {
        if ($feat->requiresAbilityChoice() && $chosenAbility === null) {
            throw new \InvalidArgumentException(sprintf(
                'Le don "%s" nécessite de choisir une caractéristique.',
                $feat->getName(),
            ));
        }

        if (!$feat->requiresAbilityChoice() && $chosenAbility !== null) {
            throw new \InvalidArgumentException(sprintf(
                'Le don "%s" ne permet pas de choisir une caractéristique.',
                $feat->getName(),
            ));
        }

        return new self(
            feat: $feat,
            featAbility: $chosenAbility,
            abilityIncreases: [],
        );
    }

    public function isFeat(): bool
    {
        return $this->feat !== null;
    }

    public function getFeat(): ?Feat
    {
        return $this->feat;
    }

    public function getFeatAbility(): ?Ability
    {
        return $this->featAbility;
    }

    /**
     * @return list<array{ability: Ability, value: int}>
     */
    public function getAbilityIncreases(): array
    {
        return $this->abilityIncreases;
    }
}
