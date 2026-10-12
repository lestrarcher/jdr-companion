<?php
declare(strict_types=1);
namespace App\Entity;

use App\Enum\ReferenceOrigin;
use Doctrine\Common\Collections\{ArrayCollection, Collection};
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
class LanguageGrantRule
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;
    #[ORM\Column(length: 120, unique: true)]
    private string $importKey;
    #[ORM\Column(length: 8, enumType: ReferenceOrigin::class)]
    private ReferenceOrigin $origin = ReferenceOrigin::Official;
    #[ORM\ManyToOne, ORM\JoinColumn(onDelete: 'RESTRICT')]
    private ?User $owner = null;
    #[ORM\ManyToOne, ORM\JoinColumn(onDelete: 'CASCADE')]
    private ?CharacterClass $characterClass = null;
    #[ORM\ManyToOne, ORM\JoinColumn(onDelete: 'CASCADE')]
    private ?CharacterBackground $characterBackground = null;
    #[ORM\ManyToMany(targetEntity: LanguageDefinition::class)]
    private Collection $options;
    public function __construct(
        string $key,
        #[ORM\Column(length: 120)] private string $label,
        #[ORM\Column] private int $unlockLevel = 1,
        #[ORM\Column] private int $choiceCount = 0,
    ) {
        if (strlen($key) > 120 || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $key)) throw new \DomainException('Clé invalide.');
        $this->importKey = $key;
        $this->options = new ArrayCollection();
    }
    public static function forOwner(User $owner, string $key, string $label, int $level = 1, int $count = 0): self
    {
        $rule = new self($key, $label, $level, $count);
        $rule->origin = ReferenceOrigin::Custom; $rule->owner = $owner;
        return $rule;
    }
    public function getId(): ?int { return $this->id; }
    public function getImportKey(): string { return $this->importKey; }
    public function getLabel(): string { return $this->label; }
    public function getUnlockLevel(): int { return $this->unlockLevel; }
    public function getChoiceCount(): int { return $this->choiceCount; }
    public function getOrigin(): ReferenceOrigin { return $this->origin; }
    public function getOwner(): ?User { return $this->owner; }
    public function getOptions(): Collection { return $this->options; }
    public function getCharacterClass(): ?CharacterClass { return $this->characterClass; }
    public function setCharacterClass(?CharacterClass $source): static { $this->clearSources(); $this->characterClass = $source; return $this; }
    public function getCharacterBackground(): ?CharacterBackground { return $this->characterBackground; }
    public function setCharacterBackground(?CharacterBackground $source): static { $this->clearSources(); $this->characterBackground = $source; return $this; }
    private function clearSources(): void { $this->characterClass = null; $this->characterBackground = null; }
    public function addOption(LanguageDefinition $option): static { if (!$this->options->contains($option)) $this->options->add($option); return $this; }
    public function setLabel(string $label): void {
        if (trim($label) === '' || mb_strlen($label) > 120) throw new \DomainException('Libellé invalide.');
        $this->label = $label;
    }
    #[ORM\PrePersist, ORM\PreUpdate, ORM\PostLoad]
    public function validate(): void
    {
        if (($this->origin === ReferenceOrigin::Official) !== ($this->owner === null)) throw new \LogicException('Invalid reference origin/owner pair.');
        if (count(array_filter([$this->characterClass, $this->characterBackground])) !== 1
            || $this->unlockLevel < 1 || $this->unlockLevel > 20 || $this->choiceCount < 0
            || $this->options->isEmpty() || $this->choiceCount > $this->options->count()
            || trim($this->label) === '' || mb_strlen($this->label) > 120) throw new \DomainException('Règle invalide.');

    }
}
