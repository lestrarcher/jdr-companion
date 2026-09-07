<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\FeatureActivationType;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'character_feature_definition')]
#[ORM\UniqueConstraint(name: 'uniq_character_feature_slug', columns: ['slug'])]
class CharacterFeatureDefinition
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

    #[ORM\Column(enumType: FeatureActivationType::class)]
    private FeatureActivationType $activationType = FeatureActivationType::Passive;

    /**
     * Ressource consommable éventuellement utilisée par cette capacité.
     *
     * Exemples :
     * - Rage -> ressource "Rage"
     * - Présage -> ressource "Dés de présage"
     * - Vision dans le noir -> aucune ressource
     * - Attaque supplémentaire -> aucune ressource
     */
    #[ORM\ManyToOne(targetEntity: TrackableResourceDefinition::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?TrackableResourceDefinition $resourceDefinition = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $visible = true;

    #[ORM\Column]
    private bool $custom = false;

    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    #[ORM\Column]
    private DateTimeImmutable $updatedAt;

    public function __construct(string $slug, string $name)
    {
        $this->setSlug($slug);
        $this->setName($name);

        $now = new DateTimeImmutable();
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

    public function setSlug(string $slug): static
    {
        $slug = strtolower(trim($slug));

        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
            throw new \InvalidArgumentException('Le slug de la capacité est invalide.');
        }

        $this->slug = $slug;
        $this->touch();

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
            throw new \InvalidArgumentException('Le nom de la capacité est obligatoire.');
        }

        $this->name = $name;
        $this->touch();

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $description = $description !== null ? trim($description) : null;
        $this->description = $description !== '' ? $description : null;
        $this->touch();

        return $this;
    }

    public function getActivationType(): FeatureActivationType
    {
        return $this->activationType;
    }

    public function setActivationType(FeatureActivationType $activationType): static
    {
        $this->activationType = $activationType;
        $this->touch();

        return $this;
    }

    public function getResourceDefinition(): ?TrackableResourceDefinition
    {
        return $this->resourceDefinition;
    }

    public function setResourceDefinition(?TrackableResourceDefinition $resourceDefinition): static
    {
        $this->resourceDefinition = $resourceDefinition;
        $this->touch();

        return $this;
    }

    public function usesResource(): bool
    {
        return $this->resourceDefinition !== null;
    }

    public function isVisible(): bool
    {
        return $this->visible;
    }

    public function setVisible(bool $visible): static
    {
        $this->visible = $visible;
        $this->touch();

        return $this;
    }

    public function isCustom(): bool
    {
        return $this->custom;
    }

    public function setCustom(bool $custom): static
    {
        $this->custom = $custom;
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
        $this->updatedAt = new DateTimeImmutable();
    }
}
