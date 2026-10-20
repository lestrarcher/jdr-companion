<?php
declare(strict_types=1);
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Append-only decision history. Automatic grants and daily preparation are not acquisitions. */
#[ORM\Entity, ORM\Table(name: 'character_spell_acquisition'), ORM\HasLifecycleCallbacks]
#[ORM\UniqueConstraint(name: 'uniq_spell_acquisition_replacement', columns: ['replaces_id'])]
#[ORM\Index(name: 'idx_acquisition_revision', columns: ['revision_id'])]
#[ORM\Index(name: 'idx_acquisition_retirement', columns: ['retirement_id'])]
class CharacterSpellAcquisition
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Character $character;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private SpellDefinition $spell;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private SpellcastingPolicyDefinition $policyDefinition;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private CharacterClassLevel $classLevel;
    #[ORM\ManyToOne(targetEntity: self::class), ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?self $replaces;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private User $decidedBy;
    #[ORM\Column(length: 24)]
    private string $provenance;
    #[ORM\Column(length: 16)]
    private string $quotaBucket;
    #[ORM\Column]
    private bool $known;
    #[ORM\Column]
    private bool $inSpellbook;
    #[ORM\Column]
    private bool $historicalValidation;
    #[ORM\Column(type: 'text')]
    private string $decisionNote;
    #[ORM\Column]
    private \DateTimeImmutable $decidedAt;
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $copyDetails = null;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?CharacterSpellRevision $revision = null;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?CharacterSpellRevision $retirement = null;

    public function __construct(Character $character, SpellDefinition $spell, SpellcastingPolicyDefinition $policy, CharacterClassLevel $level, User $actor, string $provenance, string $bucket, string $note, ?self $replaces = null, ?array $copyDetails = null)
    {
        $this->character = $character; $this->spell = $spell; $this->policyDefinition = $policy;
        $this->classLevel = $level; $this->decidedBy = $actor; $this->provenance = $provenance;
        $this->quotaBucket = $bucket; $this->decisionNote = $note; $this->replaces = $replaces;
        $this->historicalValidation = $provenance === 'level-history';
        $this->known = in_array($bucket,['known','cantrips'],true); $this->inSpellbook = $bucket === 'spellbook';
        $this->decidedAt = new \DateTimeImmutable();
        $this->copyDetails = $copyDetails;
        $this->validate();
    }

    #[ORM\PrePersist, ORM\PreUpdate, ORM\PostLoad]
    public function validate(): void
    {
        $owner = $this->character->getCampaign()->getOwner();
        foreach ([$this->spell, $this->policyDefinition->getSource(), $this->classLevel->getCharacterClass()] as $reference) {
            if (!\App\Service\ReferenceVisibility::allows($reference, $owner)) throw new \InvalidArgumentException('Dépendance indisponible.');
        }
        if (!in_array($this->provenance, ['level-history', 'manual-reconciliation', 'spellbook-copy'], true)
            || !in_array($this->quotaBucket, ['known', 'cantrips', 'spellbook','cast'], true)
            || trim($this->decisionNote) === '' || mb_strlen($this->decisionNote) > 10000
            || $this->historicalValidation !== ($this->provenance === 'level-history')
            || $this->classLevel->getCharacter() !== $this->character
            || $this->classLevel->getCharacterClass() !== $this->policyDefinition->getCharacterClass()
            || $this->known !== in_array($this->quotaBucket,['known','cantrips'],true) || $this->inSpellbook !== ($this->quotaBucket === 'spellbook')
            || ($this->policyDefinition->getFormatVersion()===1 && $this->quotaBucket==='cast')
            || ($this->policyDefinition->getFormatVersion()===1 && ($this->spell->getLevel() === 0) !== ($this->quotaBucket === 'cantrips'))) {
            throw new \InvalidArgumentException('Acquisition incohérente.');
        }
        if ($this->provenance === 'spellbook-copy') {
            if ($this->quotaBucket !== 'spellbook' || $this->replaces !== null || $this->policyDefinition->policy()->learningStyle !== 'spellbook') throw new \InvalidArgumentException('Copie incohérente.');
            \App\Dto\SpellbookCopyDecision::validate($this->copyDetails, $this->spell->getLevel());
        } elseif ($this->copyDetails !== null) throw new \InvalidArgumentException('Métadonnées de copie inattendues.');
        if ($this->replaces !== null && (!in_array($this->quotaBucket,$this->policyDefinition->getFormatVersion()===1?['known']:['known','cantrips','cast'],true) || $this->replaces->character !== $this->character
            || $this->replaces->policyDefinition !== $this->policyDefinition || $this->replaces->quotaBucket !== $this->quotaBucket
            || $this->replaces->spell === $this->spell || $this->replaces->classLevel->getPosition() >= $this->classLevel->getPosition())) {
            throw new \InvalidArgumentException('Remplacement incohérent.');
        }
    }
    public function getId(): ?int { return $this->id; }
    public function getSpell(): SpellDefinition { return $this->spell; }
    public function getCharacter(): Character { return $this->character; }
    public function getPolicyDefinition(): SpellcastingPolicyDefinition { return $this->policyDefinition; }
    public function getClassLevel(): CharacterClassLevel { return $this->classLevel; }
    public function getReplaces(): ?self { return $this->replaces; }
    public function getQuotaBucket(): string { return $this->quotaBucket; }
    public function getProvenance(): string { return $this->provenance; }
    public function isHistoricallyValidated(): bool { return $this->historicalValidation; }
    public function isKnown(): bool { return $this->known; }
    public function isInSpellbook(): bool { return $this->inSpellbook; }
    public function isLearned(): bool { return true; }
}
