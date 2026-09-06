<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\MagicItemRarity;
use App\Enum\MagicItemRechargeType;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'magic_item')]
class MagicItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(
        targetEntity: Campaign::class,
    )]
    #[ORM\JoinColumn(
        nullable: false,
        onDelete: 'CASCADE',
    )]
    private Campaign $campaign;

    #[ORM\Column(length: 150)]
    private string $name;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(
        type: 'string',
        length: 20,
        enumType: MagicItemRarity::class,
    )]
    private MagicItemRarity $rarity;

    #[ORM\Column]
    private bool $requiresAttunement = false;

    #[ORM\Column(nullable: true)]
    private ?int $maximumCharges = null;

    #[ORM\Column(
        type: 'string',
        length: 20,
        enumType: MagicItemRechargeType::class,
    )]
    private MagicItemRechargeType $rechargeType;

    /**
     * Exemples : "full", "1d6+1", "1d4".
     *
     * Cette formule sera interprétée plus tard
     * lors des recharges automatiques.
     */
    #[ORM\Column(length: 50, nullable: true)]
    private ?string $rechargeFormula = null;

    /**
     * @var Collection<int, MagicItemAbilityEffect>
     */
    #[ORM\OneToMany(
        mappedBy: 'magicItem',
        targetEntity: MagicItemAbilityEffect::class,
        cascade: ['persist', 'remove'],
        orphanRemoval: true,
    )]
    private Collection $abilityEffects;

    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    #[ORM\Column]
    private DateTimeImmutable $updatedAt;

    public function __construct(
        Campaign $campaign,
        string $name,
        MagicItemRarity $rarity,
    ) {
        $name = trim($name);

        if ($name === '') {
            throw new \InvalidArgumentException(
                'Le nom de l’objet est obligatoire.',
            );
        }

        $this->campaign = $campaign;
        $this->name = $name;
        $this->rarity = $rarity;
        $this->rechargeType =
            MagicItemRechargeType::None;

        $this->abilityEffects =
            new ArrayCollection();

        $now = new DateTimeImmutable();

        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCampaign(): Campaign
    {
        return $this->campaign;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(
        string $name,
    ): static {
        $name = trim($name);

        if ($name === '') {
            throw new \InvalidArgumentException(
                'Le nom de l’objet est obligatoire.',
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

    public function setDescription(
        ?string $description,
    ): static {
        $description =
            $description !== null
                ? trim($description)
                : null;

        $this->description =
            $description !== ''
                ? $description
                : null;

        $this->touch();

        return $this;
    }

    public function getRarity():
        MagicItemRarity {
        return $this->rarity;
    }

    public function setRarity(
        MagicItemRarity $rarity,
    ): static {
        $this->rarity = $rarity;
        $this->touch();

        return $this;
    }

    public function requiresAttunement(): bool
    {
        return $this->requiresAttunement;
    }

    public function setRequiresAttunement(
        bool $requiresAttunement,
    ): static {
        $this->requiresAttunement =
            $requiresAttunement;

        $this->touch();

        return $this;
    }

    public function getMaximumCharges(): ?int
    {
        return $this->maximumCharges;
    }

    public function setMaximumCharges(
        ?int $maximumCharges,
    ): static {
        if (
            $maximumCharges !== null
            && $maximumCharges <= 0
        ) {
            throw new \InvalidArgumentException(
                'Le maximum de charges doit être supérieur à zéro.',
            );
        }

        $this->maximumCharges =
            $maximumCharges;

        if ($maximumCharges === null) {
            $this->rechargeType =
                MagicItemRechargeType::None;

            $this->rechargeFormula = null;
        }

        $this->touch();

        return $this;
    }

    public function hasCharges(): bool
    {
        return $this->maximumCharges !== null;
    }

    public function getRechargeType():
        MagicItemRechargeType {
        return $this->rechargeType;
    }

    public function setRechargeType(
        MagicItemRechargeType $rechargeType,
    ): static {
        if (
            $rechargeType
            !== MagicItemRechargeType::None
            && !$this->hasCharges()
        ) {
            throw new \InvalidArgumentException(
                'Un objet sans charges ne peut pas avoir de recharge.',
            );
        }

        $this->rechargeType =
            $rechargeType;

        $this->touch();

        return $this;
    }

    public function getRechargeFormula():
        ?string {
        return $this->rechargeFormula;
    }

    public function setRechargeFormula(
        ?string $rechargeFormula,
    ): static {
        $rechargeFormula =
            $rechargeFormula !== null
                ? trim($rechargeFormula)
                : null;

        $this->rechargeFormula =
            $rechargeFormula !== ''
                ? $rechargeFormula
                : null;

        $this->touch();

        return $this;
    }

    /**
     * @return Collection<int, MagicItemAbilityEffect>
     */
    public function getAbilityEffects(): Collection
    {
        return $this->abilityEffects;
    }

    public function addAbilityEffect(MagicItemAbilityEffect $effect): static {
        if (
            $effect->getMagicItem()
            !== $this
        ) {
            throw new \InvalidArgumentException(
                'Cet effet appartient à un autre objet.',
            );
        }

        if (
            !$this->abilityEffects
                ->contains($effect)
        ) {
            $this->abilityEffects->add(
                $effect,
            );
        }

        $this->touch();

        return $this;
    }

    public function removeAbilityEffect(
        MagicItemAbilityEffect $effect,
    ): static {
        $this->abilityEffects
            ->removeElement($effect);

        $this->touch();

        return $this;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private function touch(): void
    {
        $this->updatedAt =
            new DateTimeImmutable();
    }
}
