<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\WeatherRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: WeatherRepository::class)]
#[ORM\Table(name: 'weather')]
#[ORM\UniqueConstraint(
    name: 'uniq_weather_system_key',
    columns: ['system', 'key'],
)]
#[ORM\UniqueConstraint(
    name: 'uniq_weather_campaign_key',
    columns: ['campaign_id', 'key'],
)]
class Weather
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Campaign::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Campaign $campaign = null;

    #[ORM\Column(length: 80)]
    private string $key;

    #[ORM\Column(length: 120)]
    private string $label;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $imageUrl = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $alt = null;

    #[ORM\Column]
    private bool $system = false;

    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    #[ORM\Column]
    private DateTimeImmutable $updatedAt;

    public function __construct(
        string $key,
        string $label,
    ) {
        $this->key = $key;
        $this->label = $label;

        $now = new DateTimeImmutable();

        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCampaign(): ?Campaign
    {
        return $this->campaign;
    }

    public function setCampaign(?Campaign $campaign): self
    {
        if ($this->system && $campaign !== null) {
            throw new \InvalidArgumentException(
                'Une météo système ne peut pas appartenir à une campagne.',
            );
        }

        $this->campaign = $campaign;
        $this->touch();

        return $this;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function setKey(string $key): self
    {
        $this->key = $key;
        $this->touch();

        return $this;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): self
    {
        $this->label = $label;
        $this->touch();

        return $this;
    }

    public function getImageUrl(): ?string
    {
        return $this->imageUrl;
    }

    public function setImageUrl(?string $imageUrl): self
    {
        $this->imageUrl = $imageUrl;
        $this->touch();

        return $this;
    }

    public function getAlt(): ?string
    {
        return $this->alt;
    }

    public function setAlt(?string $alt): self
    {
        $this->alt = $alt;
        $this->touch();

        return $this;
    }

    public function isSystem(): bool
    {
        return $this->system;
    }

    public function setSystem(bool $system): self
    {
        if ($system && $this->campaign !== null) {
            throw new \InvalidArgumentException(
                'Une météo système ne peut pas appartenir à une campagne.',
            );
        }

        $this->system = $system;
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
}
