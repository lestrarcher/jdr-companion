<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'progression_definition')]
#[ORM\UniqueConstraint(
    name: 'uniq_progression_definition_slug',
    columns: ['slug'],
)]
class ProgressionDefinition
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 80)]
    private string $slug;

    #[ORM\Column(length: 120)]
    private string $name;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column]
    private int $minimumValue = 0;

    #[ORM\Column(nullable: true)]
    private ?int $maximumValue = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $accentColor = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $gainLabel = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $spendLabel = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $custom = false;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /**
     * @var Collection<int, ProgressionStage>
     */
    #[ORM\OneToMany(mappedBy: 'progressionDefinition', targetEntity: ProgressionStage::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['displayOrder' => 'ASC', 'minimumValue' => 'ASC'])]
    private Collection $stages;

    /** @var Collection<int, ProgressionAdjustmentRule> */
    #[ORM\OneToMany(mappedBy: 'progressionDefinition', targetEntity: ProgressionAdjustmentRule::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['displayOrder' => 'ASC', 'id' => 'ASC'])]
    private Collection $adjustmentRules;

    /** @return Collection<int, ProgressionAdjustmentRule> */
    public function getAdjustmentRules(): Collection
    {
        return $this->adjustmentRules;
    }

    public function addAdjustmentRule(ProgressionAdjustmentRule $rule): self
    {
        if ($rule->getProgressionDefinition() !== $this) {
            throw new \DomainException('Cette règle appartient à une autre progression.');
        }
        if (!$this->adjustmentRules->contains($rule)) {
            $this->adjustmentRules->add($rule);
            $this->touch();
        }
        return $this;
    }

    public function removeAdjustmentRule(ProgressionAdjustmentRule $rule): self
    {
        if ($this->adjustmentRules->removeElement($rule)) {
            $this->touch();
        }
        return $this;
    }

    #[ORM\Column(options: ['default' => false])]
    private bool $bulkAdjustmentEnabled = false;

    public function __construct(
        string $slug,
        string $name,
    ) {
        $this->setSlug($slug);
        $this->setName($name);

        $now = new \DateTimeImmutable();

        $this->createdAt = $now;
        $this->updatedAt = $now;
        $this->stages = new ArrayCollection();
        $this->adjustmentRules = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): self
    {
        $slug = strtolower(trim($slug));

        if (
            $slug === ''
            || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)
        ) {
            throw new \DomainException(
                'Le slug doit contenir uniquement des lettres minuscules, des chiffres et des tirets.',
            );
        }

        $this->slug = $slug;
        $this->touch();

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $name = trim($name);

        if ($name === '') {
            throw new \DomainException(
                'Le nom de la progression est obligatoire.',
            );
        }

        $this->name = $name;
        $this->touch();

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $description = $description !== null
            ? trim($description)
            : null;

        $this->description = $description !== ''
            ? $description
            : null;

        $this->touch();

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
                'La valeur minimale ne peut pas dépasser la valeur maximale.',
            );
        }

        $this->minimumValue = $minimumValue;
        $this->touch();

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
                'La valeur maximale ne peut pas être inférieure à la valeur minimale.',
            );
        }

        $this->maximumValue = $maximumValue;
        $this->touch();

        return $this;
    }

    public function getAccentColor(): ?string
    {
        return $this->accentColor;
    }

    public function setAccentColor(?string $accentColor): self
    {
        $accentColor = $accentColor !== null
            ? trim($accentColor)
            : null;

        $this->accentColor = $accentColor !== ''
            ? $accentColor
            : null;

        $this->touch();

        return $this;
    }

    public function getGainLabel(): ?string
    {
        return $this->gainLabel;
    }

    public function setGainLabel(?string $gainLabel): self
    {
        $gainLabel = $gainLabel !== null
            ? trim($gainLabel)
            : null;

        $this->gainLabel = $gainLabel !== ''
            ? $gainLabel
            : null;

        $this->touch();

        return $this;
    }

    public function getSpendLabel(): ?string
    {
        return $this->spendLabel;
    }

    public function setSpendLabel(?string $spendLabel): self
    {
        $spendLabel = $spendLabel !== null
            ? trim($spendLabel)
            : null;

        $this->spendLabel = $spendLabel !== ''
            ? $spendLabel
            : null;

        $this->touch();

        return $this;
    }

    public function isCustom(): bool
    {
        return $this->custom;
    }

    public function setCustom(bool $custom): self
    {
        $this->custom = $custom;
        $this->touch();

        return $this;
    }

    /**
     * @return Collection<int, ProgressionStage>
     */
    public function getStages(): Collection
    {
        return $this->stages;
    }

    public function addStage(ProgressionStage $stage): self
    {
        if (!$this->stages->contains($stage)) {
            $this->stages->add($stage);
            $stage->setProgressionDefinition($this);
            $this->touch();
        }

        return $this;
    }

    public function removeStage(ProgressionStage $stage): self
    {
        if ($this->stages->removeElement($stage)) {
            $this->touch();
        }

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function isBulkAdjustmentEnabled(): bool
    {
        return $this->bulkAdjustmentEnabled;
    }

    public function setBulkAdjustmentEnabled(
        bool $bulkAdjustmentEnabled,
    ): self {
        $this->bulkAdjustmentEnabled =
            $bulkAdjustmentEnabled;

        return $this;
    }
}
