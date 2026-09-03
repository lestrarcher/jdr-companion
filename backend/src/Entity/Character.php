<?php

declare(strict_types=1);

namespace App\Entity;

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
            throw new \InvalidArgumentException(
                sprintf(
                    'Type de personnage invalide : "%s".',
                    $type,
                ),
            );
        }

        $this->campaign = $campaign;
        $this->slug = $slug;
        $this->name = $name;
        $this->type = $type;
        $this->definition = $definition;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCampaign(): Campaign
    {
        return $this->campaign;
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
}
