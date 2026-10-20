<?php
declare(strict_types=1);
namespace App\Entity;
use Doctrine\ORM\Mapping as ORM;

/** A GM decision replaces a current selection, never deletes its history. */
#[ORM\Entity, ORM\Table(name: 'character_spell_revision')]
#[ORM\Index(name: 'idx_revision_character', columns: ['character_id'])]
#[ORM\Index(name: 'idx_revision_source', columns: ['source_policy_id'])]
#[ORM\Index(name: 'idx_revision_target', columns: ['target_policy_id'])]
#[ORM\Index(name: 'idx_revision_actor', columns: ['decided_by_id'])]
class CharacterSpellRevision
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Character $character;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private SpellcastingPolicyDefinition $sourcePolicy;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private SpellcastingPolicyDefinition $targetPolicy;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private User $decidedBy;
    #[ORM\Column(type: 'text')]
    private string $reason;
    #[ORM\Column]
    private bool $overrideRules;
    #[ORM\Column]
    private \DateTimeImmutable $decidedAt;
    public function __construct(Character $character, SpellcastingPolicyDefinition $sourcePolicy, SpellcastingPolicyDefinition $targetPolicy, User $actor, string $reason, bool $overrideRules)
    {
        if ($character->getCampaign()->getOwner()->getId() !== $actor->getId() || $sourcePolicy->getCharacterClass() !== $targetPolicy->getCharacterClass() || trim($reason)==='' || mb_strlen($reason)>10000) throw new \InvalidArgumentException('Révision invalide.');
        $this->character=$character; $this->sourcePolicy=$sourcePolicy; $this->targetPolicy=$targetPolicy; $this->decidedBy=$actor;
        $this->reason=$reason; $this->overrideRules=$overrideRules; $this->decidedAt=new \DateTimeImmutable();
    }
    public function getId(): ?int { return $this->id; }
}
