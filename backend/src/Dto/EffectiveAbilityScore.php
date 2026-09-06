<?php

declare(strict_types=1);

namespace App\Dto;

use App\Enum\Ability;

final readonly class EffectiveAbilityScore
{
    public function __construct(
        public Ability $ability,
        public int $baseValue,
        public int $effectiveValue,
        public int $maximumValue,
    ) {
    }

    public function modifier(): int
    {
        return (int) floor(
            ($this->effectiveValue - 10) / 2,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'ability' =>
                $this->ability->value,
            'label' =>
                $this->ability->label(),
            'abbreviation' =>
                $this->ability
                    ->abbreviation(),
            'baseValue' =>
                $this->baseValue,
            'effectiveValue' =>
                $this->effectiveValue,
            'maximumValue' =>
                $this->maximumValue,
            'modifier' =>
                $this->modifier(),
        ];
    }
}
