<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'character_progression')]
#[ORM\UniqueConstraint(
    name: 'uniq_character_progression',
    columns: ['character_id', 'progression_definition_id'],
)]
class CharacterProgression
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(
        targetEntity: Character::class,
        inversedBy: 'progressions',
    )]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Character $character;

    #[ORM\ManyToOne(targetEntity: ProgressionDefinition::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ProgressionDefinition $progressionDefinition;

    #[ORM\Column(nullable: true)]
    private ?int $maximumReached = null;

    /** Explicit administrative corrections, not reconstructed session history. */
    #[ORM\Column(type: 'json', options: ['jsonb' => true, 'default' => '[]'])]
    private array $maximumCorrections = [];

    public function getMaximumReached(): ?int { return $this->maximumReached; }

    public function recordReachedValue(int $value): void
    {
        $this->maximumReached = $this->maximumReached === null ? $value : max($this->maximumReached, $value);
    }

    public function correctMaximumReached(int $value, string $reason): void
    {
        if (trim($reason) === '') throw new \InvalidArgumentException('Un motif est requis.');
        $this->maximumCorrections[] = ['before'=>$this->maximumReached, 'after'=>$value,
            'reason'=>trim($reason), 'at'=>(new \DateTimeImmutable())->format(DATE_ATOM)];
        $this->maximumReached = $value;
    }

    public function __construct(
        Character $character,
        ProgressionDefinition $progressionDefinition,
    ) {
        $this->character = $character;
        $this->progressionDefinition = $progressionDefinition;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCharacter(): Character
    {
        return $this->character;
    }

    public function getProgressionDefinition(): ProgressionDefinition
    {
        return $this->progressionDefinition;
    }
}
