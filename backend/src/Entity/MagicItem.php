<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\MagicItemRarity;
use App\Enum\MagicItemRechargeType;
use App\Enum\ReferenceOrigin;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'magic_item')]
#[ORM\Index(name: 'idx_magic_item_owner', columns: ['owner_id'])]
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
        nullable: true,
        onDelete: 'CASCADE',
    )]
    private ?Campaign $campaign;

    // A non-null campaign preserves the scope of historical definitions.
    #[ORM\Column(length: 8, enumType: ReferenceOrigin::class, options: ['default' => 'CUSTOM'])]
    private ReferenceOrigin $origin = ReferenceOrigin::Custom;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?User $owner;

    #[ORM\Column(length: 24, options: ['default' => 'other'])]
    private string $category = 'other';

    #[ORM\Column(options: ['default' => true])]
    private bool $magical = true;

    #[ORM\Column(options: ['default' => true])]
    private bool $equippable = true;

    // Keep legacy quantity semantics until explicit classification; never infer from names.
    #[ORM\Column(length: 16, options: ['default' => 'legacy'])]
    private string $quantityMode = 'legacy';

    #[ORM\Column(length: 16, options: ['default' => 'free'])]
    private string $equipmentSlot = 'free';

    #[ORM\Column(length: 16, nullable: true)]
    private ?string $handUsage = null;

    #[ORM\Column(length: 12, nullable: true)]
    private ?string $armorType = null;

    #[ORM\Column(nullable: true)]
    private ?int $baseArmorClass = null;

    #[ORM\Column(nullable: true)]
    private ?int $armorStrengthRequirement = null;

    // null means unconfigured, false explicitly means no disadvantage.
    #[ORM\Column(nullable: true)]
    private ?bool $armorStealthDisadvantage = null;

    public function getArmorType(): ?string { return $this->armorType; }
    public function getBaseArmorClass(): ?int { return $this->baseArmorClass; }
    public function getArmorStrengthRequirement(): ?int { return $this->armorStrengthRequirement; }
    public function getArmorStealthDisadvantage(): ?bool { return $this->armorStealthDisadvantage; }
    public function getArmorDexterityMode(): ?string
    {
        return match ($this->armorType) {
            'light' => 'full', 'medium' => 'capped', 'heavy', 'shield' => 'none', default => null,
        };
    }
    public function getArmorDexterityCap(): ?int { return $this->armorType === 'medium' ? 2 : null; }

    public function getArmorProfile(): array
    {
        return ['type' => $this->armorType, 'baseArmorClass' => $this->baseArmorClass,
            'dexterityMode' => $this->getArmorDexterityMode(), 'dexterityCap' => $this->getArmorDexterityCap(),
            'strengthRequirement' => $this->armorStrengthRequirement, 'stealthDisadvantage' => $this->armorStealthDisadvantage];
    }

    public function configureArmorRestrictions(?int $strength, ?bool $stealth): void
    {
        if (($strength !== null && ($strength < 1 || $strength > 30 || $this->armorType !== 'heavy'))
            || ($stealth !== null && $this->armorType === null)
            || ($this->armorType === 'shield' && $stealth === true)) {
            throw new \InvalidArgumentException('Restrictions physiques d’armure invalides.');
        }
        $this->armorStrengthRequirement = $strength;
        $this->armorStealthDisadvantage = $stealth;
        $this->touch();
    }

    /** Physical armor only: magical AC bonuses are deliberately separate. */
    public function configureArmor(?string $type, ?int $base): void
    {
        if ($type === null && $base === null) {
            $this->armorType = null;
            $this->baseArmorClass = null;
            $this->armorStrengthRequirement = null;
            $this->armorStealthDisadvantage = null;
            $this->touch();
            return;
        }
        if (!in_array($type, ['light', 'medium', 'heavy', 'shield'], true)
            || $base === null || $base < 1 || $base > 30
            || ($type === 'shield' && ($this->category !== 'shield' || $base !== 2))
            || ($type !== 'shield' && $this->category !== 'armor')) {
            throw new \InvalidArgumentException('Profil d’armure invalide (bouclier ordinaire : +2).');
        }
        if ($this->armorType !== $type || $this->baseArmorClass !== $base) {
            $this->armorStrengthRequirement = null;
            $this->armorStealthDisadvantage = null;
        }
        $this->armorType = $type;
        $this->baseArmorClass = $base;
        $this->touch();
    }

    #[ORM\Column(length: 4, nullable: true)]
    private ?string $edition = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $sourceReference = null;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $importKey = null;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $archivedAt = null;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $englishName = null;

    // Integer copper pieces avoid rounding errors (1 po = 100 pc).
    #[ORM\Column(nullable: true)]
    private ?int $priceCopper = null;

    #[ORM\Column(nullable: true)]
    private ?int $weightGrams = null;

    #[ORM\Column(length: 150)]
    private string $name;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(
        type: 'string',
        length: 20,
        enumType: MagicItemRarity::class,
        nullable: true,
    )]
    private ?MagicItemRarity $rarity;

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

    /** @var Collection<int, MagicItemSpellGrant> */
    #[ORM\OneToMany(mappedBy: 'magicItem', targetEntity: MagicItemSpellGrant::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $spellGrants;

    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    #[ORM\Column]
    private DateTimeImmutable $updatedAt;

    public function __construct(
        ?Campaign $campaign,
        string $name,
        ?MagicItemRarity $rarity,
        ?User $owner = null,
        ReferenceOrigin $origin = ReferenceOrigin::Custom,
    ) {
        $name = trim($name);

        if ($name === '') {
            throw new \InvalidArgumentException(
                'Le nom de l’objet est obligatoire.',
            );
        }

        $owner ??= $campaign?->getOwner();
        if (($origin === ReferenceOrigin::Official && ($owner !== null || $campaign !== null))
            || ($origin === ReferenceOrigin::Custom && $owner === null)
            || ($campaign !== null && $campaign->getOwner() !== $owner)) {
            throw new \InvalidArgumentException('Origine ou propriétaire de définition incohérent.');
        }
        $this->campaign = $campaign;
        $this->owner = $owner;
        $this->origin = $origin;
        $this->edition = $origin === ReferenceOrigin::Official ? '2014' : null;
        $this->name = $name;
        $this->rarity = $rarity;
        $this->rechargeType =
            MagicItemRechargeType::None;

        $this->abilityEffects =
            new ArrayCollection();
        $this->spellGrants = new ArrayCollection();

        $now = new DateTimeImmutable();

        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCampaign(): ?Campaign
    {
        return $this->campaign;
    }

    public function getOrigin(): ReferenceOrigin { return $this->origin; }
    public function getOwner(): ?User { return $this->owner; }
    public function getCategory(): string { return $this->category; }
    public function isMagical(): bool { return $this->magical; }
    public function isEquippable(): bool { return $this->equippable; }
    public function getQuantityMode(): string { return $this->quantityMode; }
    public function getEquipmentSlot(): string { return $this->equipmentSlot; }
    public function getHandUsage(): ?string { return $this->handUsage; }
    public function getEdition(): ?string { return $this->edition; }
    public function getSourceReference(): ?string { return $this->sourceReference; }
    public function getImportKey(): ?string { return $this->importKey; }

    public function setImportMetadata(string $importKey, string $sourceReference): static
    {
        if ($this->origin !== ReferenceOrigin::Official || $this->owner !== null || $this->campaign !== null) {
            throw new \LogicException('Seules les définitions officielles globales peuvent être importées.');
        }
        $this->importKey = $importKey;
        $this->sourceReference = $sourceReference;
        $this->edition = '2014';
        $this->touch();
        return $this;
    }

    public function getArchivedAt(): ?DateTimeImmutable { return $this->archivedAt; }

    /** Classification for new catalogue definitions; legacy definitions are not reclassified. */
    public function classify(string $category, bool $magical, bool $equippable, string $slot = 'free', ?string $hands = null): void
    {
        if (!in_array($category, ['gear', 'weapon', 'armor', 'shield', 'potion', 'scroll', 'spellbook', 'other'], true)
            || !in_array($slot, ['free', 'hand', 'armor'], true)
            || !in_array($hands, [null, 'one', 'two', 'versatile'], true)
            || (!$equippable && ($slot !== 'free' || $hands !== null))
            || (($slot === 'hand') !== ($hands !== null))
            || ($category === 'spellbook' && ($equippable || $slot !== 'free' || $hands !== null))
            || ($category === 'weapon' && (!$equippable || $slot !== 'hand'))
            || ($category === 'armor' && (!$equippable || $slot !== 'armor'))
            || ($category === 'shield' && (!$equippable || $slot !== 'hand' || $hands !== 'one'))
            || (!$magical && ($this->hasCharges() || $this->requiresAttunement || !$this->abilityEffects->isEmpty()))) {
            throw new \InvalidArgumentException('Classification de l’objet incohérente.');
        }
        $this->category = $category;
        $this->magical = $magical;
        $this->equippable = $equippable;
        $this->equipmentSlot = $slot;
        $this->handUsage = $hands;
        $this->quantityMode = $category === 'spellbook' || $equippable || $this->hasCharges() || $this->requiresAttunement ? 'individual' : 'stackable';
    }

    public function getEnglishName(): ?string { return $this->englishName; }
    public function getPriceCopper(): ?int { return $this->priceCopper; }
    public function getWeightGrams(): ?int { return $this->weightGrams; }

    public function setReferenceDetails(?string $englishName, ?int $priceCopper, ?int $weightGrams): static
    {
        if ($priceCopper !== null && $priceCopper < 0 || $weightGrams !== null && $weightGrams < 0) {
            throw new \InvalidArgumentException('Prix ou poids invalide.');
        }
        $this->englishName = $englishName;
        $this->priceCopper = $priceCopper;
        $this->weightGrams = $weightGrams;
        $this->touch();
        return $this;
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
        ?MagicItemRarity {
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

    /** @return Collection<int, MagicItemSpellGrant> */
    public function getSpellGrants(): Collection { return $this->spellGrants; }

    public function addSpellGrant(MagicItemSpellGrant $grant): static
    {
        if ($grant->getMagicItem() !== $this) {
            throw new \InvalidArgumentException('Cet octroi appartient à un autre objet.');
        }
        if (!$this->spellGrants->contains($grant)) {
            $this->spellGrants->add($grant);
        }
        $this->touch();
        return $this;
    }

    public function removeSpellGrant(MagicItemSpellGrant $grant): static
    {
        $this->spellGrants->removeElement($grant);
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
