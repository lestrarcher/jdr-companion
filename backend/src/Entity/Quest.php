<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\QuestRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: QuestRepository::class)]
class Quest
{

    public const STATUS_ACTIVE = 'active';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_HIDDEN = 'hidden';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'quests')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Campaign $campaign = null;

    #[ORM\Column(length: 150)]
    private ?string $title = null;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $objective = null;

    #[ORM\Column(length: 80)]
    private ?string $category = null;

    #[ORM\Column(length: 20)]
    private ?string $status = null;

    #[ORM\Column]
    private ?int $displayOrder = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct(
        Campaign $campaign,
        string $title,
        string $objective,
        string $category,
        int $displayOrder = 0,
    ) {
        $this->campaign = $campaign;
        $this->title = $title;
        $this->objective = $objective;
        $this->category = $category;
        $this->displayOrder = $displayOrder;
        $this->status = self::STATUS_ACTIVE;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCampaign(): ?Campaign
    {
        return $this->campaign;
    }

    public function setCampaign(?Campaign $campaign): static
    {
        $this->campaign = $campaign;

        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getObjective(): ?string
    {
        return $this->objective;
    }

    public function setObjective(string $objective): static
    {
        $this->objective = $objective;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getCategory(): ?string
    {
        return $this->category;
    }

    public function setCategory(string $category): static
    {
        $this->category = $category;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        if (!in_array($status, self::allowedStatuses(), true)) {
            throw new \InvalidArgumentException(
                sprintf('Statut de quête invalide : "%s".', $status),
            );
        }

        $this->status = $status;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getDisplayOrder(): ?int
    {
        return $this->displayOrder;
    }

    public function setDisplayOrder(int $displayOrder): static
    {
        $this->displayOrder = $displayOrder;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    /**
     * @return list<string>
     */
    public static function allowedStatuses(): array
    {
        return [
            self::STATUS_ACTIVE,
            self::STATUS_COMPLETED,
            self::STATUS_HIDDEN,
        ];
    }
}
