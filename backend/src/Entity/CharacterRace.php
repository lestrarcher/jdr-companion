<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\CreatureSize;
use App\Enum\ConditionType;
use App\Enum\DamageType;
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
    private const MOVEMENT_SPEED_KEYS = ['swim', 'fly', 'climb'];
    private const SENSE_KEYS = ['darkvision', 'blindsight', 'tremorsense', 'truesight'];

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

    #[ORM\Column(options: ['default' => true])]
    private bool $selectable = true;

    /** @var list<string>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $sizeOptions = null;

    #[ORM\Column(nullable: true)]
    private ?float $walkingSpeed = null;

    /** @var array<string, float|null>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $movementSpeeds = null;

    /** @var list<string>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $languages = null;

    #[ORM\Column(nullable: true)]
    private ?int $languageChoiceCount = null;

    /** @var array<string, float|null>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $senses = null;

    /** @var list<string>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $damageResistances = null;

    /** @var list<string>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $damageImmunities = null;

    /** @var list<string>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $conditionImmunities = null;

    /**
     * Nombre de dons choisis lors de la création.
     *
     * Exemple : Humain variant = 1.
     */
    #[ORM\Column(options: ['default' => 0])]
    private int $featChoiceCount = 0;

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

    public function getFeatChoiceCount(): int
    {
        return $this->featChoiceCount;
    }

    public function setFeatChoiceCount(int $featChoiceCount): static
    {
        if ($featChoiceCount < 0 || $featChoiceCount > 3) {
            throw new \InvalidArgumentException(
                'Le nombre de dons raciaux doit être compris entre 0 et 3.',
            );
        }

        $this->featChoiceCount = $featChoiceCount;
        $this->touch();

        return $this;
    }

    public function getInheritedFeatChoiceCount(): int
    {
        return $this->featChoiceCount
            + ($this->parentRace?->getInheritedFeatChoiceCount() ?? 0);
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

    public function isSelectable(): bool
    {
        return $this->selectable;
    }

    public function setSelectable(bool $selectable): static
    {
        $this->selectable = $selectable;
        $this->touch();

        return $this;
    }

    /** @return list<string>|null */
    public function getSizeOptions(): ?array
    {
        return $this->sizeOptions;
    }

    /** @param list<string>|null $sizeOptions */
    public function setSizeOptions(?array $sizeOptions): static
    {
        if ($sizeOptions === [] || ($sizeOptions !== null && !array_is_list($sizeOptions))) {
            throw new \InvalidArgumentException('Une liste de tailles définie ne peut pas être vide.');
        }

        if ($sizeOptions !== null) {
            $normalized = [];

            foreach ($sizeOptions as $size) {
                if (!is_string($size) || CreatureSize::tryFrom($size) === null) {
                    throw new \InvalidArgumentException('La taille raciale est invalide.');
                }

                $normalized[] = $size;
            }

            $sizeOptions = array_values(array_unique($normalized));
        }

        $this->sizeOptions = $sizeOptions;
        $this->touch();

        return $this;
    }

    public function getWalkingSpeed(): ?float
    {
        return $this->walkingSpeed;
    }

    public function setWalkingSpeed(?float $walkingSpeed): static
    {
        if ($walkingSpeed !== null && (!is_finite($walkingSpeed) || $walkingSpeed <= 0)) {
            throw new \InvalidArgumentException('La vitesse terrestre doit être positive.');
        }

        $this->walkingSpeed = $walkingSpeed;
        $this->touch();

        return $this;
    }

    /** @return array<string, float|null>|null */
    public function getMovementSpeeds(): ?array
    {
        return $this->movementSpeeds;
    }

    /** @param array<string, float|int|null>|null $movementSpeeds */
    public function setMovementSpeeds(?array $movementSpeeds): static
    {
        if ($movementSpeeds !== null) {
            $normalized = [];

            foreach ($movementSpeeds as $type => $speed) {
                if (!is_string($type) || !in_array($type, self::MOVEMENT_SPEED_KEYS, true)) {
                    throw new \InvalidArgumentException('Le type de déplacement racial est invalide.');
                }

                if ($speed !== null && ((!is_int($speed) && !is_float($speed)) || !is_finite((float) $speed) || $speed <= 0)) {
                    throw new \InvalidArgumentException('Une vitesse raciale définie doit être positive.');
                }

                $normalized[$type] = $speed !== null ? (float) $speed : null;
            }

            $movementSpeeds = $normalized;
        }

        $this->movementSpeeds = $movementSpeeds;
        $this->touch();

        return $this;
    }

    /** @return list<string>|null */
    public function getLanguages(): ?array
    {
        return $this->languages;
    }

    /** @param list<string>|null $languages */
    public function setLanguages(?array $languages): static
    {
        $this->languages = $this->normalizeSlugList($languages, 'La langue raciale est invalide.');
        $this->touch();

        return $this;
    }

    public function getLanguageChoiceCount(): ?int
    {
        return $this->languageChoiceCount;
    }

    public function setLanguageChoiceCount(?int $languageChoiceCount): static
    {
        if ($languageChoiceCount !== null && $languageChoiceCount < 0) {
            throw new \InvalidArgumentException('Le nombre de langues supplémentaires doit être positif ou nul.');
        }

        $this->languageChoiceCount = $languageChoiceCount;
        $this->touch();

        return $this;
    }

    /** @return array<string, float|null>|null */
    public function getSenses(): ?array
    {
        return $this->senses;
    }

    /** @param array<string, float|int|null>|null $senses */
    public function setSenses(?array $senses): static
    {
        if ($senses !== null) {
            $normalized = [];

            foreach ($senses as $type => $distance) {
                if (!is_string($type) || !in_array($type, self::SENSE_KEYS, true)) {
                    throw new \InvalidArgumentException('Le type de sens racial est invalide.');
                }

                if ($distance !== null && ((!is_int($distance) && !is_float($distance)) || !is_finite((float) $distance) || $distance <= 0)) {
                    throw new \InvalidArgumentException('La portée d’un sens racial doit être positive.');
                }

                $normalized[$type] = $distance !== null ? (float) $distance : null;
            }

            $senses = $normalized;
        }

        $this->senses = $senses;
        $this->touch();

        return $this;
    }

    /** @return list<string>|null */
    public function getDamageResistances(): ?array
    {
        return $this->damageResistances;
    }

    /** @param list<string>|null $damageResistances */
    public function setDamageResistances(?array $damageResistances): static
    {
        $this->damageResistances = $this->normalizeEnumList($damageResistances, DamageType::class, 'La résistance aux dégâts est invalide.');
        $this->touch();

        return $this;
    }

    /** @return list<string>|null */
    public function getDamageImmunities(): ?array
    {
        return $this->damageImmunities;
    }

    /** @param list<string>|null $damageImmunities */
    public function setDamageImmunities(?array $damageImmunities): static
    {
        $this->damageImmunities = $this->normalizeEnumList($damageImmunities, DamageType::class, 'L’immunité aux dégâts est invalide.');
        $this->touch();

        return $this;
    }

    /** @return list<string>|null */
    public function getConditionImmunities(): ?array
    {
        return $this->conditionImmunities;
    }

    /** @param list<string>|null $conditionImmunities */
    public function setConditionImmunities(?array $conditionImmunities): static
    {
        $this->conditionImmunities = $this->normalizeEnumList($conditionImmunities, ConditionType::class, 'L’immunité à l’état est invalide.');
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

    /** @param list<string>|null $values
     *  @return list<string>|null
     */
    private function normalizeSlugList(?array $values, string $errorMessage): ?array
    {
        if ($values === null) {
            return null;
        }

        if (!array_is_list($values)) {
            throw new \InvalidArgumentException($errorMessage);
        }

        foreach ($values as $value) {
            if (!is_string($value) || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $value)) {
                throw new \InvalidArgumentException($errorMessage);
            }
        }

        return array_values(array_unique($values));
    }

    /** @template T of \BackedEnum
     *  @param list<string>|null $values
     *  @param class-string<T> $enumClass
     *  @return list<string>|null
     */
    private function normalizeEnumList(?array $values, string $enumClass, string $errorMessage): ?array
    {
        $values = $this->normalizeSlugList($values, $errorMessage);

        if ($values !== null) {
            foreach ($values as $value) {
                if ($enumClass::tryFrom($value) === null) {
                    throw new \InvalidArgumentException($errorMessage);
                }
            }
        }

        return $values;
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
