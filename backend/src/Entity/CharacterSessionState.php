<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CharacterSessionStateRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(
    repositoryClass: CharacterSessionStateRepository::class,
)]
#[ORM\Table(name: 'character_session_state')]
#[ORM\UniqueConstraint(
    name: 'uniq_session_character',
    columns: ['game_session_id', 'character_id'],
)]
class CharacterSessionState
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: GameSession::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private GameSession $gameSession;

    #[ORM\ManyToOne(targetEntity: Character::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Character $character;

    #[ORM\Column(length: 64, unique: true)]
    private string $accessToken;

    /**
     * Données modifiables pendant la séance :
     * PV actuels, PV temporaires, ressources restantes,
     * dés de vie, corruption, présages, etc.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: 'json')]
    private array $state;

    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    #[ORM\Column]
    private DateTimeImmutable $updatedAt;

    /**
     * @param array<string, mixed> $state
     */
    public function __construct(
        GameSession $gameSession,
        Character $character,
        array $state,
    ) {
        if (
            $gameSession->getCampaign() !==
            $character->getCampaign()
        ) {
            throw new \InvalidArgumentException(
                'La session et le personnage doivent appartenir à la même campagne.',
            );
        }

        $this->gameSession = $gameSession;
        $this->character = $character;
        $this->state = $state;

        $this->accessToken = bin2hex(
            random_bytes(32),
        );

        $now = new DateTimeImmutable();

        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getGameSession(): GameSession
    {
        return $this->gameSession;
    }

    public function getCharacter(): Character
    {
        return $this->character;
    }

    public function getAccessToken(): string
    {
        return $this->accessToken;
    }

    /**
     * @return array<string, mixed>
     */
    public function getState(): array
    {
        return $this->state;
    }

    /**
     * @param array<string, mixed> $state
     */
    public function setState(array $state): static
    {
        $this->state = $state;
        $this->updatedAt = new \DateTimeImmutable();

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
}
