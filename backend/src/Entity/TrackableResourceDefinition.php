<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\Ability;
use App\Enum\ResourceMaximumType;
use App\Enum\ResourceRechargeType;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'trackable_resource_definition')]
#[ORM\UniqueConstraint(
    name: 'uniq_trackable_resource_slug',
    columns: ['slug'],
)]
class TrackableResourceDefinition
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100, unique: true)]
    private string $slug;

    #[ORM\Column(length: 150)]
    private string $name;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(enumType: ResourceRechargeType::class)]
    private ResourceRechargeType $rechargeType;

    #[ORM\Column(enumType: ResourceMaximumType::class)]
    private ResourceMaximumType $maximumType;

    /**
     * Valeur fixe ou valeur ajoutée au calcul.
     *
     * Exemples :
     * - Présage : 2
     * - Cri draconique : 0 + bonus de maîtrise
     * - ressource basée sur SAG : 0 + modificateur de SAG
     */
    #[ORM\Column]
    private int $baseMaximum;

    #[ORM\Column]
    private int $multiplier = 1;

    #[ORM\Column]
    private int $minimumMaximum = 0;

    #[ORM\Column(enumType: Ability::class, nullable: true)]
    private ?Ability $scalingAbility = null;

    #[ORM\Column]
    private bool $custom = false;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        string $slug,
        string $name,
        ResourceRechargeType $rechargeType,
        ResourceMaximumType $maximumType = ResourceMaximumType::Fixed,
        int $baseMaximum = 0,
    ) {
        $this->setSlug($slug);
        $this->setName($name);
        $this->rechargeType = $rechargeType;
        $this->maximumType = $maximumType;
        $this->setBaseMaximum($baseMaximum);

        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
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

        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
            throw new \InvalidArgumentException('Le slug de la ressource est invalide.');
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
            throw new \InvalidArgumentException('Le nom de la ressource est obligatoire.');
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
        $description = $description !== null ? trim($description) : null;
        $this->description = $description !== '' ? $description : null;
        $this->touch();

        return $this;
    }

    public function getRechargeType(): ResourceRechargeType
    {
        return $this->rechargeType;
    }

    public function setRechargeType(ResourceRechargeType $rechargeType): self
    {
        $this->rechargeType = $rechargeType;
        $this->touch();

        return $this;
    }

    public function getMaximumType(): ResourceMaximumType
    {
        return $this->maximumType;
    }

    public function setMaximumType(ResourceMaximumType $maximumType): self
    {
        $this->maximumType = $maximumType;

        if ($maximumType !== ResourceMaximumType::AbilityModifier) {
            $this->scalingAbility = null;
        }

        $this->touch();

        return $this;
    }

    public function getBaseMaximum(): int
    {
        return $this->baseMaximum;
    }

    public function setBaseMaximum(int $baseMaximum): self
    {
        if ($baseMaximum < 0) {
            throw new \InvalidArgumentException('Le maximum de base ne peut pas être négatif.');
        }

        $this->baseMaximum = $baseMaximum;
        $this->touch();

        return $this;
    }

    public function getMultiplier(): int
    {
        return $this->multiplier;
    }

    public function setMultiplier(int $multiplier): self
    {
        if ($multiplier < 1) {
            throw new \InvalidArgumentException('Le multiplicateur doit être supérieur ou égal à 1.');
        }

        $this->multiplier = $multiplier;
        $this->touch();

        return $this;
    }

    public function getMinimumMaximum(): int
    {
        return $this->minimumMaximum;
    }

    public function setMinimumMaximum(int $minimumMaximum): self
    {
        if ($minimumMaximum < 0) {
            throw new \InvalidArgumentException('Le minimum ne peut pas être négatif.');
        }

        $this->minimumMaximum = $minimumMaximum;
        $this->touch();

        return $this;
    }

    public function getScalingAbility(): ?Ability
    {
        return $this->scalingAbility;
    }

    public function setScalingAbility(?Ability $ability): self
    {
        if ($ability !== null && $this->maximumType !== ResourceMaximumType::AbilityModifier) {
            throw new \LogicException(
                'Une caractéristique ne peut être définie que pour une ressource basée sur un modificateur.',
            );
        }

        if ($ability === null && $this->maximumType === ResourceMaximumType::AbilityModifier) {
            throw new \InvalidArgumentException(
                'Une ressource basée sur un modificateur nécessite une caractéristique.',
            );
        }

        $this->scalingAbility = $ability;
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
}
