<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\SpellcastingProgressionType;
use App\Repository\CharacterClassRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(
    repositoryClass: CharacterClassRepository::class,
)]
#[ORM\Table(name: 'character_class')]
#[ORM\UniqueConstraint(
    name: 'uniq_character_class_slug',
    columns: ['slug'],
)]
class CharacterClass
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 80, unique: true)]
    private string $slug;

    #[ORM\Column(length: 120)]
    private string $name;

    /**
     * Valeur du dé sans le "d" :
     * 6, 8, 10 ou 12.
     */
    #[ORM\Column]
    private int $hitDie;

    #[ORM\Column]
    private int $subclassSelectionLevel;

    #[ORM\Column(
        enumType: SpellcastingProgressionType::class,
    )]
    private SpellcastingProgressionType
        $spellcastingProgression;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column]
    private bool $custom = false;

    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    #[ORM\Column]
    private DateTimeImmutable $updatedAt;

    public function __construct(
        string $slug,
        string $name,
        int $hitDie,
        int $subclassSelectionLevel,
        SpellcastingProgressionType
            $spellcastingProgression =
                SpellcastingProgressionType::None,
    ) {
        $this->setSlug($slug);
        $this->setName($name);
        $this->setHitDie($hitDie);

        $this->setSubclassSelectionLevel(
            $subclassSelectionLevel,
        );

        $this->spellcastingProgression =
            $spellcastingProgression;

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
                'Le slug de la classe est invalide.',
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
                'Le nom de la classe est obligatoire.',
            );
        }

        $this->name = $name;
        $this->touch();

        return $this;
    }

    public function getHitDie(): int
    {
        return $this->hitDie;
    }

    public function setHitDie(
        int $hitDie,
    ): static {
        if (
            !in_array(
                $hitDie,
                [6, 8, 10, 12],
                true,
            )
        ) {
            throw new \InvalidArgumentException(
                'Le dé de vie doit être un d6, d8, d10 ou d12.',
            );
        }

        $this->hitDie = $hitDie;
        $this->touch();

        return $this;
    }

    public function getSubclassSelectionLevel(): int
    {
        return $this->subclassSelectionLevel;
    }

    public function setSubclassSelectionLevel(
        int $level,
    ): static {
        if ($level < 1 || $level > 20) {
            throw new \InvalidArgumentException(
                'Le niveau de sélection de la sous-classe doit être compris entre 1 et 20.',
            );
        }

        $this->subclassSelectionLevel = $level;
        $this->touch();

        return $this;
    }

    public function getSpellcastingProgression():
        SpellcastingProgressionType
    {
        return $this->spellcastingProgression;
    }

    public function setSpellcastingProgression(
        SpellcastingProgressionType $progression,
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
