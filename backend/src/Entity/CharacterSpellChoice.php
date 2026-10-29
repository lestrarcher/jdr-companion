<?php
declare(strict_types=1);
namespace App\Entity;
use Doctrine\ORM\Mapping as ORM;

/** Exact rule and original opportunity survive replacement chains. Qualification never invents history. */
#[ORM\Entity, ORM\Table(name:'character_spell_choice')]
#[ORM\Index(name:'idx_character_choice_rule_id',columns:['rule_id'])]
#[ORM\Index(name:'idx_character_choice_opportunity_level_id',columns:['opportunity_level_id'])]
#[ORM\Index(name:'idx_character_choice_decided_by_id',columns:['decided_by_id'])]
class CharacterSpellChoice
{
    #[ORM\Id, ORM\OneToOne, ORM\JoinColumn(nullable:false,onDelete:'RESTRICT')] private CharacterSpellAcquisition $acquisition;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable:false,onDelete:'RESTRICT')] private SpellPolicyChoiceRule $rule;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable:true,onDelete:'RESTRICT')] private ?CharacterClassLevel $opportunityLevel;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable:false,onDelete:'RESTRICT')] private User $decidedBy;
    #[ORM\Column(type:'text')] private string $reason;
    #[ORM\Column(options:['default'=>true])] private bool $originKnown = true;
    #[ORM\Column] private \DateTimeImmutable $decidedAt;
}
