<?php
declare(strict_types=1);
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** One immutable confirmation of an acquisition's declared rule, not reconstructed history. */
#[ORM\Entity, ORM\Table(name: 'character_spell_qualification')]
#[ORM\Index(name: 'idx_spell_qualification_rule', columns: ['rule_id'])]
#[ORM\Index(name: 'idx_spell_qualification_actor', columns: ['decided_by_id'])]
class CharacterSpellQualification
{
    #[ORM\Id, ORM\OneToOne, ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private CharacterSpellAcquisition $acquisition;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private SpellPolicyChoiceRule $rule;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private User $decidedBy;
    #[ORM\Column(type: 'text')]
    private string $reason;
    #[ORM\Column]
    private \DateTimeImmutable $decidedAt;
}
