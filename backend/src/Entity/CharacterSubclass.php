<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\SpellcastingProgressionType;
use App\Repository\CharacterSubclassRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(
    repositoryClass: CharacterSubclassRepository::class,
)]
#[ORM\Table(name: 'character_subclass')]
#[ORM\UniqueConstraint(
    name: 'uniq_subclass_class_slug',
    columns: ['character_class_id', 'slug'],
)]
class CharacterSubclass
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(
        targetEntity: CharacterClass::class,
    )]
    #[ORM\JoinColumn(
        nullable: false,
        onDelete: 'CASCADE',
    )]
    private CharacterClass $characterClass;

    #[ORM\Column(length: 80)]
    private string $slug;

    #[ORM\Column(length: 120)]
    private string $name;

    /**
     * Utilisé lorsqu’une sous-classe ajoute
     * une progression magique à une classe.
     *
     * Exemples :
     * - Chevalier occulte : Third
     * - Mystificateur profane : Third
     *
     * null signifie que la sous-classe utilise
     * simplement la progression de sa classe.
     */
    #[ORM\Column(
        enumType: SpellcastingProgressionType::class,
        nullable: true,
    )]
    private ?SpellcastingProgressionType
        $spellcastingProgression = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column]
    private bool $custom = false;

    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    #[ORM\Column]
    private DateTimeImmutable $updatedAt;

    public function __construct(
        CharacterClass $characterClass,
        string $slug,
        string $name,
    ) {
        $this->characterClass =
            $characterClass;

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

    public function getCharacterClass():
        CharacterClass
    {
        return $this->characterClass;
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
                'Le slug de la sous-classe est invalide.',
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
                'Le nom de la sous-classe est obligatoire.',
            );
        }

        $this->name = $name;
        $this->touch();

        return $this;
    }

    public function getSpellcastingProgression():
        ?SpellcastingProgressionType
    {
        return $this->spellcastingProgression;
    }

    public function setSpellcastingProgression(
        ?SpellcastingProgressionType $progression,
    ): static {
        $this->spellcastingProgression =
            $progression;

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
}
