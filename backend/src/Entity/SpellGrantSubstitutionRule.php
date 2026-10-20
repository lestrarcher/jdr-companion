<?php
declare(strict_types=1);
namespace App\Entity;

use App\Enum\{SpellGrantMode,SpellSchool};
use App\Service\ReferenceVisibility;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\{Collection,ArrayCollection};

/** Immutable versioned permission; merely defining it never changes a character. */
#[ORM\Entity, ORM\Table(name:'spell_grant_substitution_rule'), ORM\HasLifecycleCallbacks]
#[ORM\UniqueConstraint(name:'uniq_grant_substitution_version',columns:['grant_id','version'])]
#[ORM\Index(name:'idx_substitution_grant',columns:['grant_id'])]
class SpellGrantSubstitutionRule
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] private ?int $id=null;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable:false,onDelete:'RESTRICT')] private SpellSubclassGrant $grant;
    #[ORM\Column] private int $version;
    #[ORM\Column(length:80)] private string $budgetKey;
    #[ORM\Column(type:'json')] private array $schools;
    #[ORM\Column(type:'json')] private array $opportunities;
    #[ORM\Column(type:'text')] private string $provenance;
    #[ORM\ManyToMany(targetEntity:CharacterClass::class)]
    #[ORM\JoinTable(name:'spell_grant_substitution_list')]
    #[ORM\JoinColumn(name:'rule_id',referencedColumnName:'id',onDelete:'RESTRICT')]
    #[ORM\InverseJoinColumn(name:'class_id',referencedColumnName:'id',onDelete:'RESTRICT')]
    private Collection $lists;

    public function __construct(SpellSubclassGrant $grant,int $version,string $budgetKey,array $schools,array $opportunities,array $lists,string $provenance)
    {
        $this->grant=$grant; $this->version=$version; $this->budgetKey=$budgetKey; $this->schools=$schools;
        $this->opportunities=$opportunities; $this->lists=new ArrayCollection($lists); $this->provenance=$provenance; $this->validate();
    }
    #[ORM\PrePersist, ORM\PreUpdate, ORM\PostLoad]
    public function validate(): void
    {
        $this->grant->validate();
        if (!in_array($this->grant->getMode(),[SpellGrantMode::Known,SpellGrantMode::AlwaysPrepared],true) || $this->version<1
            || !preg_match('/^[a-z][a-z0-9-]{0,79}$/D',$this->budgetKey) || trim($this->provenance)==='' || mb_strlen($this->provenance)>10000
            || !array_is_list($this->schools) || count(array_unique($this->schools,SORT_REGULAR))!==count($this->schools)
            || !array_is_list($this->opportunities) || count($this->opportunities)!==20 || $this->lists->isEmpty()) throw new \InvalidArgumentException('Règle de substitution invalide.');
        foreach ($this->schools as $school) if (!is_string($school) || SpellSchool::tryFrom($school)===null) throw new \InvalidArgumentException('École invalide.');
        foreach ($this->opportunities as $n) if (!is_int($n) || $n<0 || $n>20) throw new \InvalidArgumentException('Budget invalide.');
        $seen=[];
        foreach ($this->lists as $list) {
            if (!$list instanceof CharacterClass || !ReferenceVisibility::allows($list,$this->grant->getOwner()) || isset($seen[$list->getId()??spl_object_id($list)])) throw new \InvalidArgumentException('Liste incompatible ou dupliquée.');
            $seen[$list->getId()??spl_object_id($list)]=true;
        }
    }
    public function getId(): ?int { return $this->id; }
    public function getGrant(): SpellSubclassGrant { return $this->grant; }
    public function getVersion(): int { return $this->version; }
    public function getBudgetKey(): string { return $this->budgetKey; }
    public function getSchools(): array { return $this->schools; }
    public function getOpportunities(): array { return $this->opportunities; }
    public function getLists(): array { return $this->lists->toArray(); }
    public function getProvenance(): string { return $this->provenance; }
}
