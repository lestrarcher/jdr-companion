<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\Ability;
use App\Enum\ProficiencyType;
use App\Enum\ReferenceOrigin;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'proficiency_definition')]
#[ORM\UniqueConstraint(
    name: 'uniq_proficiency_definition_slug',
    columns: ['slug'],
)]
#[ORM\Index(
    name: 'idx_proficiency_definition_owner',
    columns: ['owner_id'],
)]
#[ORM\HasLifecycleCallbacks]
class ProficiencyDefinition
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 80)]
    private string $slug;

    #[ORM\Column(length: 120)]
    private string $name;

    #[ORM\Column(length: 20, enumType: ProficiencyType::class)]
    private ProficiencyType $type;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(
        length: 8,
        enumType: ReferenceOrigin::class,
        options: ['default' => 'OFFICIAL'],
    )]
    private ReferenceOrigin $origin = ReferenceOrigin::Official;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?User $owner = null;

    #[ORM\Column(
        length: 20,
        nullable: true,
        enumType: Ability::class,
    )]
    private ?Ability $ability = null;

    public function __construct(
        string $slug,
        string $name,
        ProficiencyType $type,
    ) {
        $this->setSlug($slug);
        $this->setName($name);
        $this->type = $type;
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

        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
            throw new \InvalidArgumentException(
                'Le slug de la maîtrise est invalide.',
            );
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

        if ($name === '') {
            throw new \InvalidArgumentException(
                'Le nom de la maîtrise est obligatoire.',
            );
        }

        $this->name = $name;

        return $this;
    }

    public function getType(): ProficiencyType
    {
        return $this->type;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $description = $description !== null
            ? trim($description)
            : null;

        $this->description = $description !== ''
            ? $description
            : null;

        return $this;
    }

    public function getOrigin(): ReferenceOrigin
    {
        return $this->origin;
    }

    public function getOwner(): ?User
    {
        return $this->owner;
    }

    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    #[ORM\PostLoad]
    public function validateReferenceOwnership(): void
    {
        if (($this->origin === ReferenceOrigin::Official) !== ($this->owner === null)) {
            throw new \LogicException(
                'Invalid reference origin/owner pair.',
            );
        }
    }

    public function getAbility(): ?Ability
    {
        return $this->ability;
    }

    public function setAbility(?Ability $ability): static
    {
        $this->ability = $ability;

        return $this;
    }
}
