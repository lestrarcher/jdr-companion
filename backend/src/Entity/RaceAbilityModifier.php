<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\Ability;
use App\Repository\RaceAbilityModifierRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: RaceAbilityModifierRepository::class)]
#[ORM\Table(name: 'race_ability_modifier')]
#[ORM\UniqueConstraint(
    name: 'uniq_race_ability_choice_key',
    columns: ['race_id', 'choice_key'],
)]
class RaceAbilityModifier
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: CharacterRace::class, inversedBy: 'abilityModifiers')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private CharacterRace $race;

    /**
     * null signifie que le joueur doit choisir
     * la caractéristique lors de la création.
     */
    #[ORM\Column(enumType: Ability::class, nullable: true)]
    private ?Ability $ability = null;

    #[ORM\Column]
    private int $value;

    /**
     * Identifie un emplacement de choix.
     *
     * Exemple pour un humain avec deux choix :
     * - ability-choice-1
     * - ability-choice-2
     */
    #[ORM\Column(length: 80, nullable: true)]
    private ?string $choiceKey = null;

    public function __construct(
        CharacterRace $race,
        int $value,
        ?Ability $ability = null,
        ?string $choiceKey = null,
    ) {
        if ($value < -5 || $value > 5 || $value === 0) {
            throw new \InvalidArgumentException(
                'Le modificateur racial doit être compris entre -5 et 5 et être différent de zéro.',
            );
        }

        if ($ability === null && ($choiceKey === null || trim($choiceKey) === '')) {
            throw new \InvalidArgumentException(
                'Un bonus racial libre doit posséder une clé de choix.',
            );
        }

        if ($ability !== null && $choiceKey !== null) {
            throw new \InvalidArgumentException(
                'Un bonus racial fixe ne doit pas posséder de clé de choix.',
            );
        }

        $this->race = $race;
        $this->value = $value;
        $this->ability = $ability;
        $this->choiceKey = $choiceKey !== null ? trim($choiceKey) : null;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRace(): CharacterRace
    {
        return $this->race;
    }

    public function getAbility(): ?Ability
    {
        return $this->ability;
    }

    public function getValue(): int
    {
        return $this->value;
    }

    public function getChoiceKey(): ?string
    {
        return $this->choiceKey;
    }

    public function requiresChoice(): bool
    {
        return $this->ability === null;
    }
}
