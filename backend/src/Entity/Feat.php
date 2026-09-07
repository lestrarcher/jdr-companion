<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\Ability;
use App\Repository\FeatRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(
    repositoryClass: FeatRepository::class,
)]
#[ORM\Table(name: 'feat')]
#[ORM\UniqueConstraint(
    name: 'uniq_feat_slug',
    columns: ['slug'],
)]
class Feat
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
     * Certains dons peuvent être sélectionnés
     * plusieurs fois avec des choix différents.
     */
    #[ORM\Column]
    private bool $repeatable = false;

    /**
     * Exemple : Résilient demande de choisir
     * Force, Dextérité, Constitution, etc.
     */
    #[ORM\Column]
    private bool $requiresAbilityChoice = false;

    /**
     * Bonus appliqué à la caractéristique choisie.
     *
     * Résilient utilise la valeur 1.
     * Mage de guerre utilise la valeur 0.
     */
    #[ORM\Column]
    private int $chosenAbilityIncrease = 0;

    #[ORM\Column]
    private bool $custom = false;

    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    #[ORM\Column]
    private DateTimeImmutable $updatedAt;

    /**
     * @var list<string>
     */
    #[ORM\Column(
        type: 'json',
        options: ['default' => '[]'],
    )]
    private array $allowedAbilities = [];

    public function __construct(
        string $slug,
        string $name,
    ) {
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
                'Le slug du don est invalide.',
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
                'Le nom du don est obligatoire.',
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

    public function isRepeatable(): bool
    {
        return $this->repeatable;
    }

    public function setRepeatable(
        bool $repeatable,
    ): static {
        $this->repeatable = $repeatable;
        $this->touch();

        return $this;
    }

    public function requiresAbilityChoice(): bool
    {
        return $this->requiresAbilityChoice;
    }

    public function setRequiresAbilityChoice(
        bool $requiresAbilityChoice,
    ): static {
        $this->requiresAbilityChoice =
            $requiresAbilityChoice;

        if (!$requiresAbilityChoice) {
            $this->chosenAbilityIncrease = 0;
            $this->allowedAbilities = [];
        }

        $this->touch();

        return $this;
    }

    public function getChosenAbilityIncrease(): int
    {
        return $this->chosenAbilityIncrease;
    }

    public function setChosenAbilityIncrease(
        int $increase,
    ): static {
        if ($increase < 0 || $increase > 2) {
            throw new \InvalidArgumentException(
                'Le bonus de caractéristique du don doit être compris entre 0 et 2.',
            );
        }

        if (
            $increase > 0
            && !$this->requiresAbilityChoice
        ) {
            throw new \InvalidArgumentException(
                'Ce don doit demander une caractéristique avant de pouvoir lui appliquer un bonus.',
            );
        }

        $this->chosenAbilityIncrease =
            $increase;

        $this->touch();

        return $this;
    }

    /**
     * @return list<Ability>
     */
    public function getAllowedAbilities(): array
    {
        if (!$this->requiresAbilityChoice) {
            return [];
        }

        /*
        * Une liste vide signifie que toutes les
        * caractéristiques sont autorisées.
        */
        if ($this->allowedAbilities === []) {
            return Ability::cases();
        }

        return array_map(
            static fn (string $ability): Ability =>
                Ability::from($ability),
            $this->allowedAbilities,
        );
    }

    public function setAllowedAbilities(Ability ...$abilities): static
    {
        if (
            $abilities !== []
            && !$this->requiresAbilityChoice
        ) {
            throw new \LogicException(
                'Le don doit demander une caractéristique avant de limiter les choix autorisés.',
            );
        }

        $this->allowedAbilities = array_values(array_unique(
            array_map(
                static fn (Ability $ability): string =>
                    $ability->value,
                $abilities,
            ),
        ));

        $this->touch();

        return $this;
    }

    public function allowsAbility(Ability $ability): bool
    {
        return in_array(
            $ability,
            $this->getAllowedAbilities(),
            true,
        );
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
