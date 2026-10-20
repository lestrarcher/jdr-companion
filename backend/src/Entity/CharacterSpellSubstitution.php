<?php
declare(strict_types=1);
namespace App\Entity;
use Doctrine\ORM\Mapping as ORM;

/** Append-only individual substitution; never an automatic acquisition. */
#[ORM\Entity, ORM\Table(name:'character_spell_substitution')]
#[ORM\UniqueConstraint(name:'uniq_spell_substitution_next',columns:['previous_id'])]
#[ORM\Index(name:'idx_substitution_character',columns:['character_id'])]
#[ORM\Index(name:'idx_substitution_rule',columns:['rule_id'])]
#[ORM\Index(name:'idx_substitution_spell',columns:['spell_id'])]
#[ORM\Index(name:'idx_substitution_level',columns:['class_level_id'])]
#[ORM\Index(name:'idx_substitution_actor',columns:['decided_by_id'])]
class CharacterSpellSubstitution
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] private ?int $id=null;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable:false,onDelete:'RESTRICT')] private Character $character;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable:false,onDelete:'RESTRICT')] private SpellGrantSubstitutionRule $rule;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable:false,onDelete:'RESTRICT')] private SpellDefinition $spell;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable:false,onDelete:'RESTRICT')] private CharacterClassLevel $classLevel;
    #[ORM\ManyToOne(targetEntity:self::class), ORM\JoinColumn(nullable:true,onDelete:'RESTRICT')] private ?self $previous;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable:false,onDelete:'RESTRICT')] private User $decidedBy;
    #[ORM\Column(type:'text')] private string $reason;
    #[ORM\Column(length:16,options:['default'=>'substitution'])] private string $decisionKind='substitution';
    #[ORM\Column(nullable:true)] private ?int $effectivePosition=null;
    #[ORM\Column] private \DateTimeImmutable $decidedAt;
}
