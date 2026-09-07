<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\Ability;
use App\Repository\CharacterRaceAbilityChoiceRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CharacterRaceAbilityChoiceRepository::class)]
#[ORM\Table(name: 'character_race_ability_choice')]
#[ORM\UniqueConstraint(
    name: 'uniq_character_race_modifier_choice',
    columns: ['character_id', 'modifier_id'],
)]
class CharacterRaceAbilityChoice
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Character::class, inversedBy: 'raceAbilityChoices')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Character $character;

    #[ORM\ManyToOne(targetEntity: RaceAbilityModifier::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private RaceAbilityModifier $modifier;

    #[ORM\Column(enumType: Ability::class)]
    private Ability $ability;

    public function __construct(
        Character $character,
        RaceAbilityModifier $modifier,
        Ability $ability,
    ) {
        if (!$modifier->requiresChoice()) {
            throw new \InvalidArgumentException('Ce modificateur racial ne demande aucun choix.');
        }

        $this->character = $character;
        $this->modifier = $modifier;
        $this->ability = $ability;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCharacter(): Character
    {
        return $this->character;
    }

    public function getModifier(): RaceAbilityModifier
    {
        return $this->modifier;
    }

    public function getAbility(): Ability
    {
        return $this->ability;
    }

    public function setAbility(Ability $ability): static
    {
        $this->ability = $ability;

        return $this;
    }

    public function getValue(): int
    {
        return $this->modifier->getValue();
    }
}
