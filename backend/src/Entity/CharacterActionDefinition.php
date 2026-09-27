<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ReferenceOrigin;
use App\Enum\CharacterActionHandlerType;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'character_action_definition')]
#[ORM\UniqueConstraint(
    name: 'uniq_character_action_slug',
    columns: ['slug'],
)]
#[ORM\Index(name: 'idx_character_action_definition_owner', columns: ['owner_id'])]
#[ORM\HasLifecycleCallbacks]
class CharacterActionDefinition
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

    #[ORM\Column(length: 100, unique: true)]
    private string $slug;

    #[ORM\Column(length: 150)]
    private string $name;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(
        enumType: CharacterActionHandlerType::class,
    )]
    private CharacterActionHandlerType $handlerType;

    #[ORM\Column]
    private bool $requiresPreparation = false;

    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    #[ORM\Column]
    private bool $custom = false;

    public function __construct(
        string $slug,
        string $name,
        CharacterActionHandlerType $handlerType,
    ) {
        $this->setSlug($slug);
        $this->setName($name);
        $this->handlerType = $handlerType;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): static
    {
        $slug = strtolower(trim($slug));

        if (
            !preg_match(
                '/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                $slug,
            )
        ) {
            throw new \InvalidArgumentException('Le slug de l’action est invalide.');
        }

        $this->slug = $slug;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $name = trim($name);

        if ('' === $name) {
            throw new \InvalidArgumentException('Le nom de l’action est obligatoire.');
        }

        $this->name = $name;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $description = null !== $description
            ? trim($description)
            : null;

        $this->description =
            '' !== $description ? $description : null;

        return $this;
    }

    public function getHandlerType(): CharacterActionHandlerType
    {
        return $this->handlerType;
    }

    public function setHandlerType(
        CharacterActionHandlerType $handlerType,
    ): static {
        $this->handlerType = $handlerType;

        return $this;
    }

    public function requiresPreparation(): bool
    {
        return $this->requiresPreparation;
    }

    public function setRequiresPreparation(
        bool $requiresPreparation,
    ): static {
        $this->requiresPreparation = $requiresPreparation;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;

        return $this;
    }

    public function isCustom(): bool
    {
        return $this->custom;
    }

    public function setCustom(bool $custom): static
    {
        $this->custom = $custom;

        return $this;
    }
}
