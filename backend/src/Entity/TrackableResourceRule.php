<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ReferenceOrigin;
use App\Repository\TrackableResourceRuleRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TrackableResourceRuleRepository::class)]
#[ORM\Table(name: 'trackable_resource_rule')]
#[ORM\UniqueConstraint(
    name: 'uniq_class_resource_level_off',
    columns: ['character_class_id', 'resource_definition_id', 'unlock_level'],
    options: ['where' => "(((origin)::text = 'OFFICIAL'::text) AND (character_class_id IS NOT NULL))"],
)]
#[ORM\UniqueConstraint(
    name: 'uniq_class_resource_level_own',
    columns: ['owner_id', 'character_class_id', 'resource_definition_id', 'unlock_level'],
    options: ['where' => "(((origin)::text = 'CUSTOM'::text) AND (character_class_id IS NOT NULL))"],
)]
#[ORM\UniqueConstraint(
    name: 'uniq_subclass_resource_level_off',
    columns: ['character_subclass_id', 'resource_definition_id', 'unlock_level'],
    options: ['where' => "(((origin)::text = 'OFFICIAL'::text) AND (character_subclass_id IS NOT NULL))"],
)]
#[ORM\UniqueConstraint(
    name: 'uniq_subclass_resource_level_own',
    columns: ['owner_id', 'character_subclass_id', 'resource_definition_id', 'unlock_level'],
    options: ['where' => "(((origin)::text = 'CUSTOM'::text) AND (character_subclass_id IS NOT NULL))"],
)]
#[ORM\UniqueConstraint(
    name: 'uniq_race_resource_level_off',
    columns: ['character_race_id', 'resource_definition_id', 'unlock_level'],
    options: ['where' => "(((origin)::text = 'OFFICIAL'::text) AND (character_race_id IS NOT NULL))"],
)]
#[ORM\UniqueConstraint(
    name: 'uniq_race_resource_level_own',
    columns: ['owner_id', 'character_race_id', 'resource_definition_id', 'unlock_level'],
    options: ['where' => "(((origin)::text = 'CUSTOM'::text) AND (character_race_id IS NOT NULL))"],
)]
#[ORM\UniqueConstraint(
    name: 'uniq_feat_resource_level_off',
    columns: ['feat_id', 'resource_definition_id', 'unlock_level'],
    options: ['where' => "(((origin)::text = 'OFFICIAL'::text) AND (feat_id IS NOT NULL))"],
)]
#[ORM\UniqueConstraint(
    name: 'uniq_feat_resource_level_own',
    columns: ['owner_id', 'feat_id', 'resource_definition_id', 'unlock_level'],
    options: ['where' => "(((origin)::text = 'CUSTOM'::text) AND (feat_id IS NOT NULL))"],
)]
#[ORM\Index(name: 'idx_trackable_resource_rule_owner', columns: ['owner_id'])]
#[ORM\HasLifecycleCallbacks]
class TrackableResourceRule
{
    // Existing constructors and factories create official references only.
    #[ORM\Column(length: 8, enumType: ReferenceOrigin::class, options: ['default' => 'OFFICIAL'])]
    private ReferenceOrigin $origin = ReferenceOrigin::Official;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?User $owner = null;

    public function getOrigin(): ReferenceOrigin
    {
        return $this->origin;
    }

    public function getOwner(): ?User
    {
        return $this->owner;
    }

    // No independent setters: custom creation is deliberately not exposed yet.
    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    #[ORM\PostLoad]
    public function validateReferenceOwnership(): void
    {
        if (($this->origin === ReferenceOrigin::Official) !== ($this->owner === null)) {
            throw new \LogicException('Invalid reference origin/owner pair.');
        }
    }

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private TrackableResourceDefinition $resourceDefinition;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?CharacterClass $characterClass = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?CharacterSubclass $characterSubclass = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?CharacterRace $characterRace = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Feat $feat = null;

    /**
     * Niveau dans la classe ou la sous-classe concernée.
     *
     * Pour une race ou un don, cette valeur vaut généralement 1.
     */
    #[ORM\Column]
    private int $unlockLevel;

    /**
     * Permet de remplacer le maximum fixe à certains paliers.
     *
     * Exemple pour Rage :
     * niveau 1 => 2
     * niveau 3 => 3
     * niveau 6 => 4
     */
    #[ORM\Column(nullable: true)]
    private ?int $maximumOverride = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $maximumBonus = 0;

    private function __construct(
        TrackableResourceDefinition $resourceDefinition,
        int $unlockLevel,
        ?int $maximumOverride,
    ) {
        if ($unlockLevel < 1 || $unlockLevel > 20) {
            throw new \InvalidArgumentException(
                'Le niveau de déblocage doit être compris entre 1 et 20.',
            );
        }

        if ($maximumOverride !== null && $maximumOverride < 0) {
            throw new \InvalidArgumentException(
                'Le maximum d’une ressource ne peut pas être négatif.',
            );
        }

        $this->resourceDefinition = $resourceDefinition;
        $this->unlockLevel = $unlockLevel;
        $this->maximumOverride = $maximumOverride;
    }

    public static function forClass(
        TrackableResourceDefinition $resourceDefinition,
        CharacterClass $characterClass,
        int $unlockLevel,
        ?int $maximumOverride = null,
    ): self {
        $rule = new self($resourceDefinition, $unlockLevel, $maximumOverride);
        $rule->characterClass = $characterClass;

        return $rule;
    }

    public static function forSubclass(
        TrackableResourceDefinition $resourceDefinition,
        CharacterSubclass $characterSubclass,
        int $unlockLevel,
        ?int $maximumOverride = null,
    ): self {
        $rule = new self($resourceDefinition, $unlockLevel, $maximumOverride);
        $rule->characterSubclass = $characterSubclass;

        return $rule;
    }

    public static function forRace(
        TrackableResourceDefinition $resourceDefinition,
        CharacterRace $characterRace,
        int $unlockLevel = 1,
        ?int $maximumOverride = null,
    ): self {
        $rule = new self($resourceDefinition, $unlockLevel, $maximumOverride);
        $rule->characterRace = $characterRace;

        return $rule;
    }

    public static function forFeat(
        TrackableResourceDefinition $resourceDefinition,
        Feat $feat,
        int $unlockLevel = 1,
        ?int $maximumOverride = null,
    ): self {
        $rule = new self($resourceDefinition, $unlockLevel, $maximumOverride);
        $rule->feat = $feat;

        return $rule;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getResourceDefinition(): TrackableResourceDefinition
    {
        return $this->resourceDefinition;
    }

    public function getCharacterClass(): ?CharacterClass
    {
        return $this->characterClass;
    }

    public function getCharacterSubclass(): ?CharacterSubclass
    {
        return $this->characterSubclass;
    }

    public function getCharacterRace(): ?CharacterRace
    {
        return $this->characterRace;
    }

    public function getFeat(): ?Feat
    {
        return $this->feat;
    }

    public function getUnlockLevel(): int
    {
        return $this->unlockLevel;
    }

    public function getMaximumOverride(): ?int
    {
        return $this->maximumOverride;
    }

    public function setMaximumOverride(?int $maximumOverride): self
    {
        if ($maximumOverride !== null && $maximumOverride < 0) {
            throw new \InvalidArgumentException(
                'Le maximum d’une ressource ne peut pas être négatif.',
            );
        }

        $this->maximumOverride = $maximumOverride;

        return $this;
    }

    public function getMaximumBonus(): int
    {
        return $this->maximumBonus;
    }

    public function setMaximumBonus(int $maximumBonus): self
    {
        if ($maximumBonus < 0) {
            throw new \InvalidArgumentException('Le bonus au maximum d’une ressource ne peut pas être négatif.');
        }

        $this->maximumBonus = $maximumBonus;

        return $this;
    }
}
