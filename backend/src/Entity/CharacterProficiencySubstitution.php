<?php
declare(strict_types=1);
namespace App\Entity;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\UniqueConstraint(columns: ['character_id', 'grant_rule_id', 'original_id'])]
class CharacterProficiencySubstitution
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne(inversedBy: 'proficiencySubstitutions'), ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Character $character,
        #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
        private ProficiencyGrantRule $grantRule,
        #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
        private ProficiencyDefinition $original,
        #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
        private ProficiencyDefinition $replacement,
    ) {

    }
    public function getId(): ?int { return $this->id; }
    public function getCharacter(): Character { return $this->character; }
    public function getGrantRule(): ProficiencyGrantRule { return $this->grantRule; }
    public function getOriginal(): ProficiencyDefinition { return $this->original; }
    public function getReplacement(): ProficiencyDefinition { return $this->replacement; }

}

