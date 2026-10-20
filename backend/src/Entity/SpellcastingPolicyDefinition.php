<?php
declare(strict_types=1);
namespace App\Entity;

use App\Dto\SpellcastingPolicy;
use App\Enum\Ability;
use Doctrine\ORM\Mapping as ORM;

/** Immutable version attached explicitly to exactly one reference source. */
#[ORM\Entity, ORM\Table(name: 'spellcasting_policy_definition')]
#[ORM\UniqueConstraint(name: 'uniq_spell_policy_class_version', columns: ['character_class_id', 'version'])]
#[ORM\UniqueConstraint(name: 'uniq_spell_policy_subclass_version', columns: ['subclass_id', 'version'])]
#[ORM\Index(name:'idx_policy_base',columns:['base_policy_id'])]
#[ORM\HasLifecycleCallbacks]
class SpellcastingPolicyDefinition
{
    #[ORM\Column(options:['default'=>1])]
    private int $formatVersion = 1;
    #[ORM\ManyToOne(targetEntity:self::class), ORM\JoinColumn(nullable:true,onDelete:'RESTRICT')]
    private ?self $basePolicy = null;
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?CharacterClass $characterClass;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?CharacterSubclass $subclass;
    #[ORM\Column(length: 80)]
    private string $policyKey;
    #[ORM\Column]
    private int $version;
    #[ORM\Column(length: 16, enumType: Ability::class)]
    private Ability $ability;
    #[ORM\Column(length: 16)]
    private string $learningStyle;
    #[ORM\Column(type: 'json')]
    private array $levels;
    #[ORM\Column(type: 'text')]
    private string $provenance;
    #[ORM\Column(type: 'json')]
    private array $limitations;

    public function __construct(CharacterClass|CharacterSubclass $source, SpellcastingPolicy $policy, ?self $basePolicy=null, int $formatVersion=1)
    {
        $this->characterClass = $source instanceof CharacterClass ? $source : null;
        $this->subclass = $source instanceof CharacterSubclass ? $source : null;
        $this->policyKey = $policy->key; $this->version = $policy->version;
        $this->ability = $policy->ability; $this->learningStyle = $policy->learningStyle;
        $this->levels = $policy->levels; $this->provenance = $policy->provenance;
        $this->limitations = $policy->limitations;
        $this->formatVersion=$formatVersion; $this->basePolicy=$basePolicy;
        $this->validate();
    }

    public function getId(): ?int { return $this->id; }
    public function getFormatVersion(): int { return $this->formatVersion; }
    public function getBasePolicy(): ?self { return $this->basePolicy; }
    public function getSource(): CharacterClass|CharacterSubclass { return $this->characterClass ?? $this->subclass; }
    public function getCharacterClass(): CharacterClass { return $this->characterClass ?? $this->subclass->getCharacterClass(); }
    public function getSubclass(): ?CharacterSubclass { return $this->subclass; }
    public function policy(): SpellcastingPolicy
    {
        return new SpellcastingPolicy($this->policyKey, $this->version, $this->ability, $this->learningStyle, $this->levels, $this->provenance, $this->limitations);
    }
    #[ORM\PrePersist, ORM\PreUpdate, ORM\PostLoad]
    public function validate(): void
    {
        if (($this->characterClass === null) === ($this->subclass === null)) throw new \InvalidArgumentException('Une source de politique est requise.');
        if (!in_array($this->formatVersion,[1,2],true) || ($this->basePolicy!==null && ($this->formatVersion!==2 || $this->subclass===null
            || $this->basePolicy->getSubclass()!==null || $this->basePolicy->getFormatVersion()!==2
            || $this->basePolicy->getCharacterClass()!==$this->getCharacterClass()
            || !\App\Service\ReferenceVisibility::allows($this->basePolicy->getSource(),$this->getSource()->getOwner())
            || $this->basePolicy->policy()->levels!==$this->levels || $this->basePolicy->policy()->ability!==$this->ability
            || $this->basePolicy->policy()->learningStyle!==$this->learningStyle))) throw new \InvalidArgumentException('Composition de politique incompatible.');
        $this->policy();
    }
}
