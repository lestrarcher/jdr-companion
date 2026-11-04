<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'character_feature_assignment')]
#[ORM\UniqueConstraint(name: 'uniq_character_feature_assignment', columns: ['character_id', 'feature_definition_id'])]
class CharacterFeatureAssignment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Character::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Character $character;

    #[ORM\ManyToOne(targetEntity: CharacterFeatureDefinition::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private CharacterFeatureDefinition $featureDefinition;

    public function __construct(Character $character, CharacterFeatureDefinition $featureDefinition)
    {
        $this->character = $character;
        $this->featureDefinition = $featureDefinition;
    }

    public function getId(): ?int { return $this->id; }
    public function getCharacter(): Character { return $this->character; }
    public function getFeatureDefinition(): CharacterFeatureDefinition { return $this->featureDefinition; }
}
