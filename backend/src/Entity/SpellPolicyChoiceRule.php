<?php
declare(strict_types=1);
namespace App\Entity;

use App\Dto\SpellChoiceParameters;
use App\Service\ReferenceVisibility;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity, ORM\Table(name:'spell_policy_choice_rule')]
#[ORM\UniqueConstraint(name:'uniq_policy_choice_key',columns:['policy_id','rule_key'])]
#[ORM\Index(name:'idx_choice_policy_id',columns:['policy_id'])]
#[ORM\Index(name:'idx_choice_list_class_id',columns:['list_class_id'])]
#[ORM\Index(name:'idx_choice_replacement_list_class_id',columns:['replacement_list_class_id'])]
#[ORM\Index(name:'idx_choice_casting_class_id',columns:['casting_class_id'])]
#[ORM\Index(name:'idx_choice_required_spell_id',columns:['required_spell_id'])]
#[ORM\HasLifecycleCallbacks]
class SpellPolicyChoiceRule
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] private ?int $id=null;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable:false,onDelete:'RESTRICT')] private SpellcastingPolicyDefinition $policy;
    #[ORM\Column(length:80)] private string $ruleKey;
    #[ORM\Column(type:'json')] private array $parameters;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable:true,onDelete:'RESTRICT')] private ?CharacterClass $listClass;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable:true,onDelete:'RESTRICT')] private ?CharacterClass $replacementListClass;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable:true,onDelete:'RESTRICT')] private ?CharacterClass $castingClass;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable:true,onDelete:'RESTRICT')] private ?SpellDefinition $requiredSpell;

    public function __construct(SpellcastingPolicyDefinition $policy,string $key,SpellChoiceParameters $parameters,?CharacterClass $listClass,?CharacterClass $replacementListClass,?CharacterClass $castingClass=null,?SpellDefinition $requiredSpell=null)
    {
        $this->policy=$policy; $this->ruleKey=$key; $this->parameters=$parameters->values;
        $this->listClass=$listClass; $this->replacementListClass=$replacementListClass; $this->castingClass=$castingClass; $this->requiredSpell=$requiredSpell; $this->validate();
    }
    #[ORM\PrePersist, ORM\PreUpdate, ORM\PostLoad]
    public function validate(): void
    {
        $p=new SpellChoiceParameters($this->parameters);
        if ($this->policy->getFormatVersion()!==2 || ($p->values['result']==='spellbook' && $this->policy->policy()->learningStyle!=='spellbook')) throw new \InvalidArgumentException('Règle incompatible avec le format ou le fonctionnement de la politique.');
        if (!preg_match('/^[a-z][a-z0-9-]{0,79}$/D',$this->ruleKey) || (!$p->values['allClassLists'] && $this->listClass===null)) throw new \InvalidArgumentException('Liste de choix explicite requise.');
        foreach ([$this->listClass,$this->replacementListClass,$this->castingClass,$this->requiredSpell] as $reference)
            if ($reference!==null && !ReferenceVisibility::allows($reference,$this->policy->getSource()->getOwner())) throw new \InvalidArgumentException('Dépendance de choix incompatible.');
    }
    public function getId(): ?int { return $this->id; }
    public function getPolicy(): SpellcastingPolicyDefinition { return $this->policy; }
    public function getKey(): string { return $this->ruleKey; }
    public function parameters(): SpellChoiceParameters { return new SpellChoiceParameters($this->parameters); }
    public function getListClass(): ?CharacterClass { return $this->listClass; }
    public function getReplacementListClass(): ?CharacterClass { return $this->replacementListClass; }
    public function getCastingClass(): ?CharacterClass { return $this->castingClass; }
    public function getRequiredSpell(): ?SpellDefinition { return $this->requiredSpell; }
}
