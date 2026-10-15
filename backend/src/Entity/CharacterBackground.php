<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ReferenceOrigin;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'character_background')]
#[ORM\UniqueConstraint(name: 'uniq_character_background_slug', columns: ['slug'])]
#[ORM\Index(name: 'idx_character_background_owner', columns: ['owner_id'])]
#[ORM\HasLifecycleCallbacks]
class CharacterBackground
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

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $equipment = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $featureName = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $featureDescription = null;

    /** @var list<string> */
    #[ORM\Column(type: 'json', options: ['default' => '[]'])]
    private array $personalityTraits = [];

    /** @var list<string> */
    #[ORM\Column(type: 'json', options: ['default' => '[]'])]
    private array $ideals = [];

    /** @var list<string> */
    #[ORM\Column(type: 'json', options: ['default' => '[]'])]
    private array $bonds = [];

    /** @var list<string> */
    #[ORM\Column(type: 'json', options: ['default' => '[]'])]
    private array $flaws = [];

    #[ORM\Column(length: 8, enumType: ReferenceOrigin::class, options: ['default' => 'OFFICIAL'])]
    private ReferenceOrigin $origin = ReferenceOrigin::Official;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?User $owner = null;

    public function __construct(string $slug, string $name)
    {
        $slug = strtolower(trim($slug));
        if (strlen($slug) > 80 || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
            throw new \InvalidArgumentException('Slug d’historique invalide.');
        }
        $this->slug = $slug;
        $this->setName($name);
    }

    public static function forOwner(User $owner, string $slug, string $name): self
    {
        $background = new self($slug, $name);
        $background->origin = ReferenceOrigin::Custom;
        $background->owner = $owner;

        return $background;
    }

    public function getId(): ?int { return $this->id; }
    public function getSlug(): string { return $this->slug; }
    public function getName(): string { return $this->name; }
    public function getDescription(): ?string { return $this->description; }
    public function getEquipment(): ?string { return $this->equipment; }
    public function getFeatureName(): ?string { return $this->featureName; }
    public function getFeatureDescription(): ?string { return $this->featureDescription; }
    public function getPersonalityTraits(): array { return $this->personalityTraits; }
    public function getIdeals(): array { return $this->ideals; }
    public function getBonds(): array { return $this->bonds; }
    public function getFlaws(): array { return $this->flaws; }
    public function getOrigin(): ReferenceOrigin { return $this->origin; }
    public function getOwner(): ?User { return $this->owner; }

    public function setName(string $name): static
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 120) throw new \InvalidArgumentException('Nom d’historique invalide.');
        $this->name = $name;

        return $this;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description === null || trim($description) === '' ? null : trim($description);

        return $this;
    }

    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    #[ORM\PostLoad]
    public function validateReferenceOwnership(): void
    {
        if (($this->origin === ReferenceOrigin::Official) !== ($this->owner === null)) {
            throw new \LogicException('Invalid reference origin/owner pair.');
        }
    }
}
