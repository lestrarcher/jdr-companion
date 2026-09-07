<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CharacterRaceRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(
    repositoryClass: CharacterRaceRepository::class,
)]
#[ORM\Table(name: 'character_race')]
#[ORM\UniqueConstraint(
    name: 'uniq_character_race_slug',
    columns: ['slug'],
)]
class CharacterRace
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 80, unique: true)]
    private string $slug;

    #[ORM\Column(length: 120)]
    private string $name;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    /**
     * Permet de représenter :
     * Elfe -> Haut-elfe
     * Gnome -> Gnome des forêts.
     */
    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(
        name: 'parent_race_id',
        referencedColumnName: 'id',
        nullable: true,
        onDelete: 'SET NULL',
    )]
    private ?self $parentRace = null;

    #[ORM\Column]
    private bool $custom = false;

    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    #[ORM\Column]
    private DateTimeImmutable $updatedAt;

    /**
     * @var Collection<int, RaceAbilityModifier>
     */
    #[ORM\OneToMany(mappedBy: 'race', targetEntity: RaceAbilityModifier::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $abilityModifiers;

    public function __construct(
        string $slug,
        string $name,
    ) {
        $this->setSlug($slug);
        $this->setName($name);
        $this->abilityModifiers = new ArrayCollection();

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

    public function setSlug(
        string $slug,
    ): static {
        $slug = strtolower(
            trim($slug),
        );

        if (
            !preg_match(
                '/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                $slug,
            )
        ) {
            throw new \InvalidArgumentException(
                'Le slug de la race est invalide.',
            );
        }

        $this->slug = $slug;
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
                'Le nom de la race est obligatoire.',
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
        $description = $description !== null
            ? trim($description)
            : null;

        $this->description =
            $description !== ''
                ? $description
                : null;

        $this->touch();

        return $this;
    }

    public function getParentRace(): ?self
    {
        return $this->parentRace;
    }

    public function setParentRace(?self $parentRace): static
    {
        $ancestor = $parentRace;

        while ($ancestor !== null) {
            if ($ancestor === $this) {
                throw new \InvalidArgumentException(
                    'Une race ne peut pas être sa propre ancêtre.',
                );
            }

            $ancestor = $ancestor->getParentRace();
        }

        $this->parentRace = $parentRace;
        $this->touch();

        return $this;
    }

    public function isCustom(): bool
    {
        return $this->custom;
    }

    public function setCustom(
        bool $custom,
    ): static {
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
        $this->updatedAt =
            new DateTimeImmutable();
    }

    /**
     * @return Collection<int, RaceAbilityModifier>
     */
    public function getAbilityModifiers(): Collection
    {
        return $this->abilityModifiers;
    }

    public function addAbilityModifier(RaceAbilityModifier $modifier): static
    {
        if ($modifier->getRace() !== $this) {
            throw new \InvalidArgumentException(
                'Ce modificateur appartient à une autre race.',
            );
        }

        if (!$this->abilityModifiers->contains($modifier)) {
            $this->abilityModifiers->add($modifier);
        }

        return $this;
    }

    public function removeAbilityModifier(RaceAbilityModifier $modifier): static
    {
        $this->abilityModifiers->removeElement($modifier);

        return $this;
    }

    /**
     * Retourne les modificateurs de la race parente
     * puis ceux de la race actuelle.
     *
     * @return list<RaceAbilityModifier>
     */
    public function getInheritedAbilityModifiers(): array
    {
        $modifiers = $this->parentRace
            ? $this->parentRace->getInheritedAbilityModifiers()
            : [];

        return [
            ...$modifiers,
            ...$this->abilityModifiers->toArray(),
        ];
    }

    public function inheritsFrom(CharacterRace $race): bool
    {
        $currentRace = $this;

        while ($currentRace !== null) {
            if ($currentRace === $race) {
                return true;
            }

            $currentRace = $currentRace->getParentRace();
        }

        return false;
    }
}
