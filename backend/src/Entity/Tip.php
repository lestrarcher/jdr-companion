<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TipRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TipRepository::class)]
class Tip
{

    public const CATEGORY_RULE = 'rule';
    public const CATEGORY_ADVICE = 'advice';
    public const CATEGORY_LORE = 'lore';

    public const STATUS_VISIBLE = 'visible';
    public const STATUS_HIDDEN = 'hidden';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'tips')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Campaign $campaign = null;

    #[ORM\Column(length: 100)]
    private ?string $label = null;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $text = null;

    #[ORM\Column(length: 20)]
    private ?string $category = null;

    #[ORM\Column]
    private ?int $displayOrder = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\Column(length: 20)]
    private ?string $status = null;

    public function __construct(
        Campaign $campaign,
        string $label,
        string $text,
        string $category,
        int $displayOrder = 0,
    ) {
        if (
            !in_array(
                $category,
                self::allowedCategories(),
                true,
            )
        ) {
            throw new \InvalidArgumentException(
                'Catégorie de conseil invalide.',
            );
        }

        $this->campaign = $campaign;
        $this->label = $label;
        $this->text = $text;
        $this->category = $category;
        $this->status = self::STATUS_VISIBLE;
        $this->displayOrder = $displayOrder;
        $this->createdAt =
            new \DateTimeImmutable();
        $this->updatedAt = null;
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

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(string $label): static
    {
        $this->label = $label;
        $this->touch();

        return $this;
    }

    public function getText(): ?string
    {
        return $this->text;
    }

    public function setText(string $text): static
    {
        $this->text = $text;
        $this->touch();

        return $this;
    }

    public function getCategory(): ?string
    {
        return $this->category;
    }

    public function setCategory(
        string $category,
    ): static {
        if (
            !in_array(
                $category,
                self::allowedCategories(),
                true,
            )
        ) {
            throw new \InvalidArgumentException(
                'Catégorie de conseil invalide.',
            );
        }

        $this->category = $category;
        $this->touch();

        return $this;
    }

    public function getDisplayOrder(): ?int
    {
        return $this->displayOrder;
    }

    public function setDisplayOrder(int $displayOrder): static
    {
        $this->displayOrder = $displayOrder;
        $this->touch();

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

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        if (
            !in_array(
                $status,
                self::allowedStatuses(),
                true,
            )
        ) {
            throw new \InvalidArgumentException(
                'Statut de conseil invalide.',
            );
        }

        $this->status = $status;
        $this->touch();

        return $this;
    }

    /**
     * @return list<string>
     */
    public static function allowedCategories(): array
    {
        return [
            self::CATEGORY_RULE,
            self::CATEGORY_ADVICE,
            self::CATEGORY_LORE,
        ];
    }

    /**
     * @return list<string>
     */
    public static function allowedStatuses(): array
    {
        return [
            self::STATUS_VISIBLE,
            self::STATUS_HIDDEN,
        ];
    }

    private function touch(): void
    {
        $this->updatedAt =
            new \DateTimeImmutable();
    }
}
