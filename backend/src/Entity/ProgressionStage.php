<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'progression_stage')]
class ProgressionStage
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(
        targetEntity: ProgressionDefinition::class,
        inversedBy: 'stages',
    )]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ProgressionDefinition $progressionDefinition;

    #[ORM\Column(length: 120)]
    private string $label;

    #[ORM\Column]
    private int $minimumValue;

    #[ORM\Column(nullable: true)]
    private ?int $maximumValue = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $iconUrl = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $displayOrder = 0;

    public function __construct(
        ProgressionDefinition $progressionDefinition,
        string $label,
        int $minimumValue,
    ) {
        $this->progressionDefinition = $progressionDefinition;
        $this->setLabel($label);
        $this->minimumValue = $minimumValue;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProgressionDefinition(): ProgressionDefinition
    {
        return $this->progressionDefinition;
    }

    public function setProgressionDefinition(
        ProgressionDefinition $progressionDefinition,
    ): self {
        $this->progressionDefinition = $progressionDefinition;

        return $this;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): self
    {
        $label = trim($label);

        if ($label === '') {
            throw new \DomainException(
                'Le nom du palier est obligatoire.',
            );
        }

        $this->label = $label;

        return $this;
    }

    public function getMinimumValue(): int
    {
        return $this->minimumValue;
    }

    public function setMinimumValue(int $minimumValue): self
    {
        if (
            $this->maximumValue !== null
            && $minimumValue > $this->maximumValue
        ) {
            throw new \DomainException(
                'La valeur minimale du palier ne peut pas dépasser sa valeur maximale.',
            );
        }

        $this->minimumValue = $minimumValue;

        return $this;
    }

    public function getMaximumValue(): ?int
    {
        return $this->maximumValue;
    }

    public function setMaximumValue(?int $maximumValue): self
    {
        if (
            $maximumValue !== null
            && $maximumValue < $this->minimumValue
        ) {
            throw new \DomainException(
                'La valeur maximale du palier ne peut pas être inférieure à sa valeur minimale.',
            );
        }

        $this->maximumValue = $maximumValue;

        return $this;
    }

    public function getIconUrl(): ?string
    {
        return $this->iconUrl;
    }

    public function setIconUrl(?string $iconUrl): self
    {
        $iconUrl = $iconUrl !== null
            ? trim($iconUrl)
            : null;

        $this->iconUrl = $iconUrl !== ''
            ? $iconUrl
            : null;

        return $this;
    }

    public function getDisplayOrder(): int
    {
        return $this->displayOrder;
    }

    public function setDisplayOrder(int $displayOrder): self
    {
        if ($displayOrder < 0) {
            throw new \DomainException(
                'L’ordre d’affichage ne peut pas être négatif.',
            );
        }

        $this->displayOrder = $displayOrder;

        return $this;
    }

    public function containsValue(int $value): bool
    {
        return
            $value >= $this->minimumValue
            && (
                $this->maximumValue === null
                || $value <= $this->maximumValue
            );
    }
}
