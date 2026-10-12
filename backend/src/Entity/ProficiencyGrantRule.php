<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ReferenceOrigin;
use App\Enum\ProficiencyAcquisitionMode;
use App\Repository\ProficiencyGrantRuleRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProficiencyGrantRuleRepository::class)]
#[ORM\Table(name: 'proficiency_grant_rule')]
#[ORM\UniqueConstraint(name: 'uniq_proficiency_grant_rule_import_key', columns: ['import_key'])]
#[ORM\HasLifecycleCallbacks]
class ProficiencyGrantRule
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $importKey = null;

    public function getImportKey(): ?string
    {
        return $this->importKey;
    }

    public function setImportKey(string $key): static
    {
        if (strlen($key) > 120 || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $key)) {
            throw new \InvalidArgumentException('Clé d’import de maîtrise invalide.');
        }
        if ($this->importKey !== null && $this->importKey !== $key) {
            throw new \LogicException('La clé d’import est immuable.');
        }
        $this->importKey = $key;

        return $this;
    }

    #[ORM\Column(length: 120)]
    private string $label;

    #[ORM\ManyToOne(targetEntity: CharacterRace::class)]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    private ?CharacterRace $characterRace = null;

    #[ORM\ManyToOne(targetEntity: CharacterClass::class)]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    private ?CharacterClass $characterClass = null;

    #[ORM\ManyToOne(targetEntity: CharacterSubclass::class)]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    private ?CharacterSubclass $characterSubclass = null;

    #[ORM\ManyToOne(targetEntity: Feat::class)]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    private ?Feat $feat = null;

    #[ORM\ManyToOne(targetEntity: CharacterBackground::class)]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    private ?CharacterBackground $characterBackground = null;

    public function getCharacterBackground(): ?CharacterBackground
    {
        return $this->characterBackground;
    }

    public function setCharacterBackground(?CharacterBackground $background): static
    {
        $this->clearSources();
        $this->characterBackground = $background;

        return $this;
    }

    #[ORM\Column(options: ['default' => 1])]
    private int $unlockLevel = 1;

    #[ORM\Column(options: ['default' => 0])]
    private int $choiceCount = 0;

    #[ORM\Column(length: 20, enumType: ProficiencyAcquisitionMode::class, options: ['default' => 'ANY'])]
    private ProficiencyAcquisitionMode $acquisitionMode = ProficiencyAcquisitionMode::Any;

    public function getAcquisitionMode(): ProficiencyAcquisitionMode
    {
        return $this->acquisitionMode;
    }

    public function setAcquisitionMode(ProficiencyAcquisitionMode $mode): static
    {
        $this->acquisitionMode = $mode;

        return $this;
    }

    /**
     * @var Collection<int, ProficiencyDefinition>
     */
    #[ORM\ManyToMany(targetEntity: ProficiencyDefinition::class)]
    #[ORM\JoinTable(name: 'proficiency_grant_rule_option')]
    private Collection $options;

    #[ORM\Column(
        length: 8,
        enumType: ReferenceOrigin::class,
        options: ['default' => 'OFFICIAL'],
    )]
    private ReferenceOrigin $origin = ReferenceOrigin::Official;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?User $owner = null;

    public function __construct(string $label)
    {
        $this->setLabel($label);
        $this->options = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): static
    {
        $label = trim($label);

        if ($label === '') {
            throw new \InvalidArgumentException(
                'Le libellé de la règle est obligatoire.',
            );
        }

        $this->label = $label;

        return $this;
    }

    public function getCharacterRace(): ?CharacterRace
    {
        return $this->characterRace;
    }

    public function getCharacterClass(): ?CharacterClass
    {
        return $this->characterClass;
    }

    public function getCharacterSubclass(): ?CharacterSubclass
    {
        return $this->characterSubclass;
    }

    public function getFeat(): ?Feat
    {
        return $this->feat;
    }

    public function setCharacterRace(?CharacterRace $race): static
    {
        $this->clearSources();
        $this->characterRace = $race;

        return $this;
    }

    public function setCharacterClass(?CharacterClass $class): static
    {
        $this->clearSources();
        $this->characterClass = $class;

        return $this;
    }

    public function setCharacterSubclass(?CharacterSubclass $subclass): static
    {
        $this->clearSources();
        $this->characterSubclass = $subclass;

        return $this;
    }

    public function setFeat(?Feat $feat): static
    {
        $this->clearSources();
        $this->feat = $feat;

        return $this;
    }

    private function clearSources(): void
    {
        $this->characterRace = null;
        $this->characterClass = null;
        $this->characterSubclass = null;
        $this->feat = null;
        $this->characterBackground = null;
    }

    public function getUnlockLevel(): int
    {
        return $this->unlockLevel;
    }

    public function setUnlockLevel(int $level): static
    {
        if ($level < 1 || $level > 20) {
            throw new \InvalidArgumentException(
                'Le niveau de déblocage doit être compris entre 1 et 20.',
            );
        }

        $this->unlockLevel = $level;

        return $this;
    }

    public function getChoiceCount(): int
    {
        return $this->choiceCount;
    }

    public function setChoiceCount(int $count): static
    {
        if ($count < 0) {
            throw new \InvalidArgumentException(
                'Le nombre de choix ne peut pas être négatif.',
            );
        }

        $this->choiceCount = $count;

        return $this;
    }

    /**
     * @return Collection<int, ProficiencyDefinition>
     */
    public function getOptions(): Collection
    {
        return $this->options;
    }

    public function addOption(ProficiencyDefinition $option): static
    {
        if (!$this->options->contains($option)) {
            $this->options->add($option);
        }

        return $this;
    }

    public function removeOption(ProficiencyDefinition $option): static
    {
        $this->options->removeElement($option);

        return $this;
    }

    public function getOrigin(): ReferenceOrigin
    {
        return $this->origin;
    }

    public function getOwner(): ?User
    {
        return $this->owner;
    }

    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    #[ORM\PostLoad]
    public function validateRule(): void
    {
        if ($this->acquisitionMode !== ProficiencyAcquisitionMode::Any && $this->characterClass === null) {
            throw new \LogicException('Un mode d’acquisition spécifique exige une source classe.');
        }

        if (($this->origin === ReferenceOrigin::Official) !== ($this->owner === null)) {
            throw new \LogicException(
                'Invalid reference origin/owner pair.',
            );
        }

        $sources = [
            $this->characterRace,
            $this->characterClass,
            $this->characterSubclass,
            $this->feat,
            $this->characterBackground,
        ];

        $sourceCount = count(
            array_filter($sources, static fn ($source) => $source !== null),
        );

        if ($sourceCount !== 1) {
            throw new \LogicException(
                'Une règle de maîtrise doit posséder exactement une source.',
            );
        }

        if ($this->options->isEmpty()) {
            throw new \LogicException(
                'Une règle de maîtrise doit proposer au moins une option.',
            );
        }

        if ($this->choiceCount > $this->options->count()) {
            throw new \LogicException(
                'Le nombre de choix dépasse le nombre d’options disponibles.',
            );
        }
    }
}
