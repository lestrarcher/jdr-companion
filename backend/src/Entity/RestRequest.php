<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\RestRequestRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: RestRequestRepository::class)]
class RestRequest
{
    public const TYPE_SHORT_REST = 'short-rest';
    public const TYPE_LONG_REST = 'long-rest';

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(
        nullable: false,
        onDelete: 'CASCADE',
    )]
    private CharacterSessionState $characterSessionState;

    #[ORM\Column(length: 20)]
    private string $type;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column]
    private \DateTimeImmutable $requestedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $resolvedAt = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(
        nullable: true,
        onDelete: 'SET NULL',
    )]
    private ?User $resolvedBy = null;

    public function __construct(
        CharacterSessionState $characterSessionState,
        string $type,
    ) {
        if (!self::isValidType($type)) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Type de repos invalide : "%s".',
                    $type,
                ),
            );
        }

        $this->characterSessionState =
            $characterSessionState;

        $this->type = $type;
        $this->requestedAt =
            new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCharacterSessionState():
        CharacterSessionState
    {
        return $this->characterSessionState;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getRequestedAt():
        \DateTimeImmutable
    {
        return $this->requestedAt;
    }

    public function getResolvedAt():
        ?\DateTimeImmutable
    {
        return $this->resolvedAt;
    }

    public function getResolvedBy(): ?User
    {
        return $this->resolvedBy;
    }

    public function isPending(): bool
    {
        return $this->status ===
            self::STATUS_PENDING;
    }

    public function approve(User $user): void
    {
        $this->resolve(
            self::STATUS_APPROVED,
            $user,
        );
    }

    public function reject(User $user): void
    {
        $this->resolve(
            self::STATUS_REJECTED,
            $user,
        );
    }

    /**
     * @return list<string>
     */
    public static function allowedTypes(): array
    {
        return [
            self::TYPE_SHORT_REST,
            self::TYPE_LONG_REST,
        ];
    }

    public static function isValidType(
        string $type,
    ): bool {
        return in_array(
            $type,
            self::allowedTypes(),
            true,
        );
    }

    private function resolve(
        string $status,
        User $user,
    ): void {
        if (!$this->isPending()) {
            throw new \DomainException(
                'Cette demande de repos a déjà été traitée.',
            );
        }

        $this->status = $status;
        $this->resolvedBy = $user;
        $this->resolvedAt =
            new \DateTimeImmutable();
    }
}
