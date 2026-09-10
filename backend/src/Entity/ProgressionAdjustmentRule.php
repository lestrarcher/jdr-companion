<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ProgressionAdjustmentDirection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'progression_adjustment_rule')]
class ProgressionAdjustmentRule
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'adjustmentRules')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ProgressionDefinition $progressionDefinition;

    #[ORM\Column(length: 4, enumType: ProgressionAdjustmentDirection::class)]
    private ProgressionAdjustmentDirection $direction;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $triggerType = null;

    #[ORM\Column(type: 'text')]
    private string $description;

    #[ORM\Column(length: 255)]
    private string $adjustmentLabel;

    #[ORM\Column(options: ['default' => 0])]
    private int $displayOrder = 0;

    public function __construct(ProgressionDefinition $progressionDefinition, ProgressionAdjustmentDirection $direction, string $description, string $adjustmentLabel)
    {
        $this->progressionDefinition = $progressionDefinition;
        $this->direction = $direction;
        $this->setDescription($description);
        $this->setAdjustmentLabel($adjustmentLabel);
    }

    public function getId(): ?int { return $this->id; }
    public function getProgressionDefinition(): ProgressionDefinition { return $this->progressionDefinition; }
    public function getDirection(): ProgressionAdjustmentDirection { return $this->direction; }
    public function getTriggerType(): ?string { return $this->triggerType; }
    public function getDescription(): string { return $this->description; }
    public function getAdjustmentLabel(): string { return $this->adjustmentLabel; }
    public function getDisplayOrder(): int { return $this->displayOrder; }

    public function setDirection(ProgressionAdjustmentDirection $direction): self
    {
        $this->direction = $direction;
        return $this;
    }

    public function setTriggerType(?string $triggerType): self
    {
        $value = $triggerType !== null ? trim($triggerType) : '';
        if (mb_strlen($value) > 120) {
            throw new \DomainException('Le type de déclenchement est limité à 120 caractères.');
        }
        $this->triggerType = $value !== '' ? $value : null;
        return $this;
    }

    public function setDescription(string $description): self
    {
        if (trim($description) === '') {
            throw new \DomainException('La description de la règle est obligatoire.');
        }
        $this->description = trim($description);
        return $this;
    }

    public function setAdjustmentLabel(string $adjustmentLabel): self
    {
        $value = trim($adjustmentLabel);
        if ($value === '' || mb_strlen($value) > 255) {
            throw new \DomainException('La variation est obligatoire et limitée à 255 caractères.');
        }
        $this->adjustmentLabel = $value;
        return $this;
    }

    public function setDisplayOrder(int $displayOrder): self
    {
        if ($displayOrder < 0) {
            throw new \DomainException('L’ordre d’affichage ne peut pas être négatif.');
        }
        $this->displayOrder = $displayOrder;
        return $this;
    }
}
