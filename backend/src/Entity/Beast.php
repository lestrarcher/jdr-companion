<?php
declare(strict_types=1);
namespace App\Entity;

use App\Repository\BeastRepository;
use App\Enum\ReferenceOrigin;
use Doctrine\Common\Collections\{ArrayCollection, Collection};
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: BeastRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'beast')]
#[ORM\Index(name: 'idx_beast_owner', columns: ['owner_id'])]
#[ORM\UniqueConstraint(name: 'uniq_beast_slug', columns: ['slug'])]
class Beast
{
    #[ORM\Id, ORM\GeneratedValue(strategy: 'IDENTITY'), ORM\Column]
    private ?int $id = null;
    #[ORM\Column(length: 120)]
    private string $slug;
    #[ORM\Column(length: 160, nullable: true)]
    private ?string $name = null;
    #[ORM\Column(length: 300, nullable: true)]
    private ?string $sourceUrl = null;
    #[ORM\Column(length: 12, nullable: true)]
    private ?string $challengeRating = null;
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $statBlock = null;
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $imageFilename = null;
    #[ORM\Column(options: ['default' => false])]
    private bool $customized = false;
    #[ORM\Column(length: 16, enumType: ReferenceOrigin::class, options: ['default' => 'OFFICIAL'])]
    private ReferenceOrigin $origin = ReferenceOrigin::Official;
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?User $owner = null;
    /** @var Collection<int, BeastNaturalWeapon> */
    #[ORM\OneToMany(mappedBy: 'beast', targetEntity: BeastNaturalWeapon::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['sortOrder' => 'ASC', 'id' => 'ASC'])]
    private Collection $naturalWeapons;
    /** @var Collection<int, BeastHabitat> */
    #[ORM\OneToMany(mappedBy: 'beast', targetEntity: BeastHabitat::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['habitat' => 'ASC'])]
    private Collection $habitats;
    /** @var Collection<int, BeastFeature> */
    #[ORM\OneToMany(mappedBy: 'beast', targetEntity: BeastFeature::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['displayOrder' => 'ASC'])]
    private Collection $features;

    public function __construct(string $slug)
    {
        $this->slug = $slug;
        $this->naturalWeapons = new ArrayCollection();
        $this->habitats = new ArrayCollection();
        $this->features = new ArrayCollection();
    }
    public function getId(): ?int { return $this->id; }
    public function getSlug(): string { return $this->slug; }
    public function getName(): ?string { return $this->name; }
    public function getSourceUrl(): ?string { return $this->sourceUrl; }
    public function getChallengeRating(): ?string { return $this->challengeRating; }
    public function getStatBlock(): ?array { return $this->statBlock; }
    public function getImageFilename(): ?string { return $this->imageFilename; }
    public function isCustomized(): bool { return $this->customized; }
    public function getOrigin(): ReferenceOrigin { return $this->origin; }
    public function getOwner(): ?User { return $this->owner; }
    public function markCustom(User $owner): void { $this->origin = ReferenceOrigin::Custom; $this->owner = $owner; }
    public function isAccessibleTo(?User $user): bool
    {
        return $this->origin === ReferenceOrigin::Official || ($user !== null && $this->owner !== null
            && ($this->owner === $user || ($user->getId() !== null && $this->owner->getId() === $user->getId())));
    }
    #[ORM\PrePersist, ORM\PreUpdate]
    public function validateReferenceOwnership(): void
    {
        if (($this->origin === ReferenceOrigin::Official) !== ($this->owner === null)) throw new \LogicException('Invalid beast origin/owner pair.');
    }
    public function markCustomized(): void { $this->customized = true; }
    public function getNaturalWeapons(): Collection { return $this->naturalWeapons; }
    public function getHabitats(): Collection { return $this->habitats; }
    public function getFeatures(): Collection { return $this->features; }
    public function edit(string $slug, string $name, ?string $cr, ?string $sourceUrl, ?array $statBlock): void
    {
        $this->slug = $slug;
        $this->name = $name;
        $this->challengeRating = $cr;
        $this->sourceUrl = $sourceUrl;
        $this->statBlock = $statBlock;
        $this->markCustomized();
    }
    public function setImageFilename(?string $filename): void { $this->imageFilename = $filename; }
    public function replaceHabitats(array $values): void
    {
        foreach ($this->habitats->toArray() as $link) {
            if (!in_array($link->getHabitat(), $values, true)) $this->habitats->removeElement($link);
        }
        $existing = array_map(fn (BeastHabitat $h) => $h->getHabitat(), $this->habitats->toArray());
        foreach ($values as $value) if (!in_array($value, $existing, true)) $this->habitats->add(new BeastHabitat($this, $value));
        $this->markCustomized();
    }
    /** @param list<CharacterFeatureDefinition> $definitions */
    public function replaceFeatures(array $definitions): void
    {
        foreach ($this->features->toArray() as $link) {
            if (!in_array($link->getDefinition(), $definitions, true)) $this->features->removeElement($link);
        }
        foreach ($definitions as $order => $definition) {
            $found = false;
            foreach ($this->features as $link) if ($link->getDefinition() === $definition) { $link->setDisplayOrder($order); $found = true; }
            if (!$found) $this->features->add(new BeastFeature($this, $definition, $order));
        }
        $this->markCustomized();
    }
    public function addNaturalWeapon(BeastNaturalWeapon $weapon): void { $this->naturalWeapons->add($weapon); $this->markCustomized(); }
    public function removeNaturalWeapon(BeastNaturalWeapon $weapon): void { $this->naturalWeapons->removeElement($weapon); $this->markCustomized(); }
}
