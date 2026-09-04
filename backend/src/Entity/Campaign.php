<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CampaignRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CampaignRepository::class)]
#[ORM\Table(name: 'campaign')]
#[ORM\UniqueConstraint(
    name: 'uniq_campaign_owner_slug',
    columns: ['owner_id', 'slug'],
)]
class Campaign
{

    public const CONFIGURATION_STRAHD = 'strahd';
    public const CONFIGURATION_VECNA = 'vecna';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $owner;

    #[ORM\Column(length: 80)]
    private string $slug;

    #[ORM\Column(length: 150)]
    private string $name;

    #[ORM\Column(
        length: 50,
        options: [
            'default' => self::CONFIGURATION_STRAHD,
        ],
    )]
    private string $configurationKey =
        self::CONFIGURATION_STRAHD;

    /**
     * @var Collection<int, Media>
     */
    #[ORM\OneToMany(targetEntity: Media::class, mappedBy: 'campaign', orphanRemoval: true,)]
    private Collection $media;

    public function __construct(
        User $owner,
        string $slug,
        string $name,
    ) {
        $this->owner = $owner;
        $this->slug = $slug;
        $this->name = $name;
        $this->media = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOwner(): User
    {
        return $this->owner;
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

    public function belongsTo(User $user): bool
    {
        return $this->owner === $user;
    }

    public function getConfigurationKey(): ?string
    {
        return $this->configurationKey;
    }

    public function setConfigurationKey(
        string $configurationKey,
    ): static {
        if (
            !in_array(
                $configurationKey,
                self::allowedConfigurationKeys(),
                true,
            )
        ) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Configuration de campagne invalide : "%s".',
                    $configurationKey,
                ),
            );
        }

        $this->configurationKey =
            $configurationKey;

        return $this;
    }

    /**
     * @return list<string>
     */
    public static function allowedConfigurationKeys(): array
    {
        return [
            self::CONFIGURATION_STRAHD,
            self::CONFIGURATION_VECNA,
        ];
    }

    /**
     * @return Collection<int, Media>
     */
    public function getMedia(): Collection
    {
        return $this->media;
    }

    public function addMedia(Media $media): static
    {
        if (!$this->media->contains($media)) {
            $this->media->add($media);
            $media->setCampaign($this);
        }

        return $this;
    }

    public function removeFilename(Media $media): static
    {
        if ($this->media->removeElement($media)) {
            // set the owning side to null (unless already changed)
            if ($media->getCampaign() === $this) {
                $media->setCampaign(null);
            }
        }

        return $this;
    }
}
