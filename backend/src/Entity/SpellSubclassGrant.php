<?php
declare(strict_types=1);
namespace App\Entity;

use App\Enum\{ReferenceOrigin, SpellGrantMode};
use App\Service\ReferenceVisibility;
use Doctrine\ORM\Mapping as ORM;

/** Reference configuration only. Activation is a level in the source subclass's parent class. */
#[ORM\Entity]
#[ORM\Table(name: 'spell_subclass_grant')]
#[ORM\UniqueConstraint(name: 'uniq_spell_subclass_grant', columns: ['subclass_id', 'spell_id', 'mode', 'unlock_level'])]
#[ORM\Index(name: 'idx_spell_grant_spell', columns: ['spell_id'])]
#[ORM\Index(name: 'idx_spell_grant_subclass', columns: ['subclass_id'])]
#[ORM\Index(name: 'idx_spell_grant_casting_class', columns: ['casting_class_id'])]
#[ORM\Index(name: 'idx_spell_grant_resource', columns: ['resource_id'])]
#[ORM\Index(name: 'idx_spell_grant_owner', columns: ['owner_id'])]
#[ORM\HasLifecycleCallbacks]
class SpellSubclassGrant
{
    #[ORM\Column(length: 24, enumType: \App\Enum\SpellQuotaImpact::class, options: ['default' => 'unspecified'])]
    private \App\Enum\SpellQuotaImpact $quotaImpact = \App\Enum\SpellQuotaImpact::Unspecified;

    public function getQuotaImpact(): \App\Enum\SpellQuotaImpact { return $this->quotaImpact; }
    public function setQuotaImpact(\App\Enum\SpellQuotaImpact $impact): void { $this->quotaImpact = $impact; }
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;
    #[ORM\ManyToOne(targetEntity: CharacterSubclass::class), ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private CharacterSubclass $subclass;
    #[ORM\ManyToOne(targetEntity: SpellDefinition::class), ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private SpellDefinition $spell;
    #[ORM\ManyToOne(targetEntity: CharacterClass::class), ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?CharacterClass $castingClass;
    #[ORM\ManyToOne(targetEntity: TrackableResourceDefinition::class), ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?TrackableResourceDefinition $resource;
    #[ORM\Column(length: 24, enumType: SpellGrantMode::class)]
    private SpellGrantMode $mode;
    #[ORM\Column]
    private int $unlockLevel;
    #[ORM\Column(length: 16, nullable: true)]
    private ?string $slotUsage;
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $restrictions;
    #[ORM\Column(nullable: true)]
    private ?int $resourceCost;
    #[ORM\Column(length: 8, enumType: ReferenceOrigin::class)]
    private ReferenceOrigin $origin;
    #[ORM\ManyToOne(targetEntity: User::class), ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?User $owner;

    public function __construct(CharacterSubclass $subclass, SpellDefinition $spell, SpellGrantMode $mode, int $unlockLevel, ?CharacterClass $castingClass = null, ?string $slotUsage = null, ?string $restrictions = null, ?TrackableResourceDefinition $resource = null, ?int $resourceCost = null)
    {
        $this->subclass = $subclass; $this->spell = $spell; $this->mode = $mode;
        $this->unlockLevel = $unlockLevel; $this->castingClass = $castingClass;
        $this->slotUsage = $slotUsage; $this->restrictions = $restrictions;
        $this->resource = $resource; $this->resourceCost = $resourceCost;
        $this->origin = $subclass->getOrigin(); $this->owner = $subclass->getOwner();
        $this->validate();
    }

    public static function validateValues(SpellGrantMode $mode, int $level, bool $hasClass, ?string $slots, ?string $restrictions, bool $hasResource, ?int $cost): void
    {
        if ($level < 1 || $level > 20) throw new \InvalidArgumentException('Niveau de classe requis entre 1 et 20.');
        if ($mode !== SpellGrantMode::Cast && (!$hasClass || $slots !== null || $restrictions !== null || $hasResource || $cost !== null)) throw new \InvalidArgumentException('Ce mode nécessite une classe de lancement et aucun paramètre de lancement particulier.');
        if ($mode === SpellGrantMode::Cast && !in_array($slots, ['required', 'not-required', 'either'], true)) throw new \InvalidArgumentException('Précisez l’usage des emplacements.');
        if ($hasResource !== ($cost !== null) || ($cost !== null && ($cost < 1 || $cost > 2147483647))) throw new \InvalidArgumentException('Ressource et coût positif doivent être renseignés ensemble.');
        if ($restrictions !== null && (str_contains($restrictions, "\0") || mb_strlen($restrictions) > 10000)) throw new \InvalidArgumentException('Restrictions invalides.');
    }

    #[ORM\PrePersist, ORM\PreUpdate, ORM\PostLoad]
    public function validate(): void
    {
        self::validateValues($this->mode, $this->unlockLevel, $this->castingClass !== null, $this->slotUsage, $this->restrictions, $this->resource !== null, $this->resourceCost);
        if ($this->origin !== $this->subclass->getOrigin() || $this->owner?->getId() !== $this->subclass->getOwner()?->getId()
            || (($this->origin === ReferenceOrigin::Official) !== ($this->owner === null))) throw new \InvalidArgumentException('Provenance incompatible avec la sous-classe.');
        foreach ([$this->subclass, $this->spell, $this->castingClass, $this->resource] as $dependency) {
            if ($dependency !== null && !ReferenceVisibility::allows($dependency, $this->owner)) throw new \InvalidArgumentException('Dépendance indisponible pour cette attribution.');
        }
    }

    public function getId(): ?int { return $this->id; }
    public function getSubclass(): CharacterSubclass { return $this->subclass; }
    public function getSpell(): SpellDefinition { return $this->spell; }
    public function getOrigin(): ReferenceOrigin { return $this->origin; }
    public function getOwner(): ?User { return $this->owner; }
    public function getMode(): SpellGrantMode { return $this->mode; }
    public function getUnlockLevel(): int { return $this->unlockLevel; }
    public function getCastingClass(): ?CharacterClass { return $this->castingClass; }
    public function getSlotUsage(): ?string { return $this->slotUsage; }
    public function getRestrictions(): ?string { return $this->restrictions; }
    public function getResource(): ?TrackableResourceDefinition { return $this->resource; }
    public function getResourceCost(): ?int { return $this->resourceCost; }
}
