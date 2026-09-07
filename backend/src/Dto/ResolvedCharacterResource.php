<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\TrackableResourceDefinition;
use App\Enum\ResourceRechargeType;

final readonly class ResolvedCharacterResource
{
    public function __construct(
        private TrackableResourceDefinition $definition,
        private int $maximum,
    ) {
        if ($maximum < 0) {
            throw new \InvalidArgumentException(
                'Le maximum résolu d’une ressource ne peut pas être négatif.',
            );
        }
    }

    public function getDefinition(): TrackableResourceDefinition
    {
        return $this->definition;
    }

    public function getSlug(): string
    {
        return $this->definition->getSlug();
    }

    public function getName(): string
    {
        return $this->definition->getName();
    }

    public function getMaximum(): int
    {
        return $this->maximum;
    }

    public function getRechargeType(): ResourceRechargeType
    {
        return $this->definition->getRechargeType();
    }
}
