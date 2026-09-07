<?php

declare(strict_types=1);

namespace App\Entity;
use App\Enum\Ability;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use App\Repository\CharacterRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CharacterRepository::class)]
#[ORM\Table(name: 'character')]
#[ORM\UniqueConstraint(
    name: 'uniq_character_campaign_slug',
    columns: ['campaign_id', 'slug'],
)]
class Character
{
    public const TYPE_PLAYER = 'player';
    public const TYPE_NPC = 'npc';

    private const ALLOWED_TYPES = [
        self::TYPE_PLAYER,
        self::TYPE_NPC,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Campaign::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Campaign $campaign;

    #[ORM\ManyToOne(targetEntity: CharacterRace::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?CharacterRace $race = null;

    #[ORM\Column(length: 80)]
    private string $slug;

    #[ORM\Column(length: 100)]
    private string $name;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $playerName = null;

    #[ORM\Column(length: 20)]
    private string $type;

    /**
     * Données relativement stables :
     * classe, niveau, maximum de PV,
     * définitions des ressources, progressions, etc.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: 'json')]
    private array $definition = [];

    #[ORM\OneToOne(
        mappedBy: 'character',
        targetEntity: CharacterWallet::class,
        cascade: ['persist', 'remove'],
        orphanRemoval: true,
    )]
    private ?CharacterWallet $wallet = null;

    /**
     * @var Collection<int, CharacterAbilityScore>
     */
    #[ORM\OneToMany(
        mappedBy: 'character',
        targetEntity: CharacterAbilityScore::class,
        cascade: ['persist', 'remove'],
        orphanRemoval: true,
    )]
    #[ORM\OrderBy(['ability' => 'ASC'])]
    private Collection $abilityScores;

    /**
     * @var Collection<int, CharacterMagicItem>
     */
    #[ORM\OneToMany(
        mappedBy: 'character',
        targetEntity: CharacterMagicItem::class,
        cascade: ['persist', 'remove'],
        orphanRemoval: true,
    )]
    private Collection $magicItems;

    /**
     * @var Collection<int, CharacterClassLevel>
     */
    #[ORM\OneToMany(mappedBy: 'character', targetEntity: CharacterClassLevel::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $classLevels;

    /**
     * @var Collection<int, CharacterFeat>
     */
    #[ORM\OneToMany(mappedBy: 'character', targetEntity: CharacterFeat::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $feats;

    /**
     * @var Collection<int, CharacterRaceAbilityChoice>
     */
    #[ORM\OneToMany(mappedBy: 'character', targetEntity: CharacterRaceAbilityChoice::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $raceAbilityChoices;

    /**
     * @var Collection<int, CharacterAbilityAdjustment>
     */
    #[ORM\OneToMany(mappedBy: 'character', targetEntity: CharacterAbilityAdjustment::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['displayOrder' => 'ASC', 'id' => 'ASC'])]
    private Collection $abilityAdjustments;

    /**
     * @param array<string, mixed> $definition
     */
    public function __construct(
        Campaign $campaign,
        string $slug,
        string $name,
        string $type,
        array $definition = [],
    ) {
        if (!in_array($type, self::ALLOWED_TYPES, true)) {
            throw new \InvalidArgumentException(sprintf('Type de personnage invalide : "%s".', $type));
        }

        $this->campaign = $campaign;
        $this->slug = $slug;
        $this->name = $name;
        $this->type = $type;
        $this->definition = $definition;
        $this->wallet = new CharacterWallet($this);
        $this->abilityScores = new ArrayCollection();
        $this->magicItems  = new ArrayCollection();
        $this->classLevels = new ArrayCollection();
        $this->feats = new ArrayCollection();
        $this->raceAbilityChoices = new ArrayCollection();
        $this->abilityAdjustments = new ArrayCollection();

        foreach (Ability::cases() as $ability) {
            $this->abilityScores->add(new CharacterAbilityScore($this, $ability));
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCampaign(): Campaign
    {
        return $this->campaign;
    }

    public function getRace(): ?CharacterRace
    {
        return $this->race;
    }

    public function setRace(?CharacterRace $race): static
    {
        if ($this->race !== $race) {
            $this->raceAbilityChoices->clear();
        }

        $this->race = $race;

        return $this;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): self
    {
        $this->slug = $slug;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getPlayerName(): ?string
    {
        return $this->playerName;
    }

    public function setPlayerName(?string $playerName): self
    {
        $this->playerName = $playerName;

        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    /**
     * @return array<string, mixed>
     */
    public function getDefinition(): array
    {
        return $this->definition;
    }

    /**
     * @param array<string, mixed> $definition
     */
    public function setDefinition(array $definition): self
    {
        $this->definition = $definition;

        return $this;
    }

    public function getWallet():
    CharacterWallet {
    if (!$this->wallet) {
        $this->wallet =
            new CharacterWallet($this);
    }

    return $this->wallet;
}

    public function setWallet(
        CharacterWallet $wallet,
    ): static {
        if (
            $wallet->getCharacter()
            !== $this
        ) {
            throw new \InvalidArgumentException(
                'Cette bourse appartient à un autre personnage.',
            );
        }

        $this->wallet = $wallet;

        return $this;
    }

    /**
     * @return Collection<int, CharacterAbilityScore>
     */
    public function getAbilityScores():
        Collection {
        return $this->abilityScores;
    }

    public function getAbilityScore(
        Ability $ability,
    ): CharacterAbilityScore {
        foreach (
            $this->abilityScores
            as $abilityScore
        ) {
            if (
                $abilityScore->getAbility()
                === $ability
            ) {
                return $abilityScore;
            }
        }

        /*
        * Sécurité pour un ancien personnage auquel
        * il manquerait exceptionnellement une ligne.
        */
        $abilityScore =
            new CharacterAbilityScore(
                $this,
                $ability,
            );

        $this->abilityScores->add(
            $abilityScore,
        );

        return $abilityScore;
    }

    /**
     * @return Collection<int, CharacterMagicItem>
     */
    public function getMagicItems(): Collection
    {
        return $this->magicItems;
    }

    public function addMagicItem(CharacterMagicItem $magicItem): static
    {
        if (
            $magicItem->getCharacter()
            !== $this
        ) {
            throw new \InvalidArgumentException(
                'Cet objet appartient à un autre personnage.',
            );
        }

        if (
            !$this->magicItems
                ->contains($magicItem)
        ) {
            $this->magicItems->add(
                $magicItem,
            );
        }

        return $this;
    }

    public function removeMagicItem( CharacterMagicItem $magicItem ): static {
        $this->magicItems
            ->removeElement($magicItem);

        return $this;
    }

    public function getAttunedMagicItemCount(): int
    {
        return $this->magicItems
            ->filter(
                static fn (
                    CharacterMagicItem $item,
                ): bool =>
                    $item->isAttuned(),
            )
            ->count();
    }

    /**
     * @return Collection<int, CharacterClassLevel>
     */
    public function getClassLevels(): Collection
    {
        return $this->classLevels;
    }

    public function addClassLevel(CharacterClassLevel $classLevel): self
    {
        if ($classLevel->getCharacter() !== $this) {
            throw new \InvalidArgumentException('Ce niveau appartient à un autre personnage.');
        }

        foreach ($this->classLevels as $existingLevel) {
            if ($existingLevel->getPosition() === $classLevel->getPosition()) {
                throw new \LogicException(sprintf(
                    'Le personnage possède déjà un niveau en position %d.',
                    $classLevel->getPosition(),
                ));
            }
        }

        if (!$this->classLevels->contains($classLevel)) {
            $this->classLevels->add($classLevel);
        }

        return $this;
    }

    public function removeClassLevel(CharacterClassLevel $classLevel): self
    {
        $this->classLevels->removeElement($classLevel);

        return $this;
    }

    public function getTotalLevel(): int
    {
        return $this->classLevels->count();
    }

    public function getLevelInClass(CharacterClass $characterClass): int
    {
        return $this->classLevels
            ->filter(
                static fn (CharacterClassLevel $level): bool =>
                    $level->getCharacterClass() === $characterClass,
            )
            ->count();
    }

    public function getProficiencyBonus(): int
    {
        $totalLevel = max(1, $this->getTotalLevel());

        return 2 + intdiv($totalLevel - 1, 4);
    }

    public function getNextLevelPosition(): int
    {
        return $this->getTotalLevel() + 1;
    }

    public function getSubclassFor(CharacterClass $characterClass): ?CharacterSubclass
    {
        $subclass = null;

        foreach ($this->classLevels as $level) {
            if ($level->getCharacterClass() === $characterClass && $level->getSubclass() !== null) {
                $subclass = $level->getSubclass();
            }
        }

        return $subclass;
    }

    /**
     * @return Collection<int, CharacterFeat>
     */
    public function getFeats(): Collection
    {
        return $this->feats;
    }

    public function addFeat(
        CharacterFeat $characterFeat,
    ): static {
        if (
            $characterFeat->getCharacter()
            !== $this
        ) {
            throw new \InvalidArgumentException(
                'Ce don appartient à un autre personnage.',
            );
        }

        if (
            !$characterFeat
                ->getFeat()
                ->isRepeatable()
        ) {
            foreach (
                $this->feats
                as $existingCharacterFeat
            ) {
                if (
                    $existingCharacterFeat
                        ->getFeat()
                        ===
                    $characterFeat
                        ->getFeat()
                ) {
                    throw new \InvalidArgumentException(
                        sprintf(
                            'Le personnage possède déjà le don %s.',
                            $characterFeat
                                ->getFeat()
                                ->getName(),
                        ),
                    );
                }
            }
        }

        $this->feats->add(
            $characterFeat,
        );

        return $this;
    }

    public function removeFeat(
        CharacterFeat $characterFeat,
    ): static {
        $this->feats->removeElement(
            $characterFeat,
        );

        return $this;
    }

    public function hasFeat(
        Feat $feat,
    ): bool {
        foreach (
            $this->feats
            as $characterFeat
        ) {
            if (
                $characterFeat->getFeat()
                === $feat
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return Collection<int, CharacterRaceAbilityChoice>
     */
    public function getRaceAbilityChoices(): Collection
    {
        return $this->raceAbilityChoices;
    }

    public function addRaceAbilityChoice(
        CharacterRaceAbilityChoice $choice,
    ): static {
        if ($choice->getCharacter() !== $this) {
            throw new \InvalidArgumentException(
                'Ce choix racial appartient à un autre personnage.',
            );
        }

        if ($this->race === null) {
            throw new \InvalidArgumentException(
                'Une race doit être sélectionnée avant ses bonus.',
            );
        }

        $modifierRace = $choice->getModifier()->getRace();

        if (!$this->race->inheritsFrom($modifierRace)) {
            throw new \InvalidArgumentException(
                'Ce bonus ne correspond pas à la race du personnage.',
            );
        }

        foreach ($this->raceAbilityChoices as $existingChoice) {
            if ($existingChoice->getModifier() === $choice->getModifier()) {
                throw new \InvalidArgumentException(
                    'Ce choix racial a déjà été renseigné.',
                );
            }

            if ($existingChoice->getAbility() === $choice->getAbility()) {
                throw new \InvalidArgumentException(
                    'Les bonus raciaux libres doivent cibler des caractéristiques différentes.',
                );
            }
        }

        $this->raceAbilityChoices->add($choice);

        return $this;
    }

    public function removeRaceAbilityChoice(
        CharacterRaceAbilityChoice $choice,
    ): static {
        $this->raceAbilityChoices->removeElement($choice);

        return $this;
    }

    public function hasCompletedRaceAbilityChoices(): bool
    {
        if ($this->race === null) {
            return false;
        }

        $requiredModifiers = array_filter(
            $this->race->getInheritedAbilityModifiers(),
            static fn (RaceAbilityModifier $modifier): bool =>
                $modifier->requiresChoice(),
        );

        return count($requiredModifiers) === $this->raceAbilityChoices->count();
    }

    /**
     * @return Collection<int, CharacterAbilityAdjustment>
     */
    public function getAbilityAdjustments(): Collection
    {
        return $this->abilityAdjustments;
    }

    public function addAbilityAdjustment(CharacterAbilityAdjustment $adjustment): self
    {
        if ($adjustment->getCharacter() !== $this) {
            throw new \InvalidArgumentException('Cet ajustement appartient à un autre personnage.');
        }

        if (!$this->abilityAdjustments->contains($adjustment)) {
            $this->abilityAdjustments->add($adjustment);
        }

        return $this;
    }

    public function removeAbilityAdjustment(CharacterAbilityAdjustment $adjustment): self
    {
        $this->abilityAdjustments->removeElement($adjustment);

        return $this;
    }
}
