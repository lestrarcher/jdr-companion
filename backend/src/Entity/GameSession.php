<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\GameSessionRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: GameSessionRepository::class)]
#[ORM\Table(name: 'game_session')]
#[ORM\UniqueConstraint(
    name: 'uniq_session_campaign_slug',
    columns: ['campaign_id', 'slug'],
)]
class GameSession
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_LIVE = 'live';
    public const STATUS_CLOSED = 'closed';

    private const ALLOWED_STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_LIVE,
        self::STATUS_CLOSED,
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

    #[ORM\Column(length: 150)]
    private string $name;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_DRAFT;

    #[ORM\Column(length: 64, unique: true)]
    private string $displayAccessToken;

    /**
     * @var array<string, mixed>
     */
    #[ORM\Column(type: 'json')]
    private array $displayState = [];

    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    #[ORM\Column]
    private DateTimeImmutable $updatedAt;

    public function __construct(
        Campaign $campaign,
        string $slug,
        string $name,
    ) {
        $this->campaign = $campaign;
        $this->slug = $slug;
        $this->name = $name;

        $now = new DateTimeImmutable();

        $this->createdAt = $now;
        $this->updatedAt = $now;

        $this->displayAccessToken = bin2hex(
            random_bytes(32),
        );
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
        $this->touch();

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;
        $this->touch();

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        if (!in_array($status, self::ALLOWED_STATUSES, true)) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Statut de session invalide : "%s".',
                    $status,
                ),
            );
        }

        $this->status = $status;
        $this->touch();

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getDisplayState(): array
    {
        return $this->displayState;
    }

    /**
     * @param array<string, mixed> $displayState
     */
    public function setDisplayState(array $displayState): self
    {
        $this->displayState = $displayState;
        $this->touch();

        return $this;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private function touch(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }

    public function getDisplayAccessToken(): string
    {
        return $this->displayAccessToken;
    }
}
