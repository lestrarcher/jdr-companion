<?php
declare(strict_types=1);
namespace App\Entity;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\UniqueConstraint(columns: ['character_id', 'grant_rule_id', 'proficiency_definition_id'])]
class CharacterExpertiseChoice
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne(inversedBy: 'expertiseChoices'), ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Character $character,
        #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
        private ExpertiseGrantRule $grantRule,
        #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
        private ProficiencyDefinition $proficiencyDefinition,
    ) {

    }
    public function getId(): ?int { return $this->id; }
    public function getCharacter(): Character { return $this->character; }
    public function getGrantRule(): ExpertiseGrantRule { return $this->grantRule; }
    public function getProficiencyDefinition(): ProficiencyDefinition { return $this->proficiencyDefinition; }

}

