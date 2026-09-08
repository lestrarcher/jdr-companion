<?php

declare(strict_types=1);

namespace App\Dto;

final readonly class ResolvedCharacterHitPoints
{
    /**
     * @param list<int> $missingLevelPositions
     */
    public function __construct(
        public ?int $maximumValue,
        public int $baseValue,
        public int $constitutionModifier,
        public int $constitutionBonus,
        public array $missingLevelPositions,
    ) {
    }

    public function isComplete(): bool
    {
        return $this->missingLevelPositions === [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'maximumValue' => $this->maximumValue,
            'baseValue' => $this->baseValue,
            'constitutionModifier' => $this->constitutionModifier,
            'constitutionBonus' => $this->constitutionBonus,
            'complete' => $this->isComplete(),
            'missingLevelPositions' => $this->missingLevelPositions,
        ];
    }
}
