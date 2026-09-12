<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CampaignFigureRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CampaignFigureRepository::class)]
class CampaignFigure
{

    public const TYPE_PC = 'pc';
    public const TYPE_NPC = 'npc';

    public const ENCOUNTER_UNMET = 'unmet';
    public const ENCOUNTER_MET = 'met';

    public const LIFE_ALIVE = 'alive';
    public const LIFE_DEAD = 'dead';

    public const PUBLICATION_VISIBLE = 'visible';
    public const PUBLICATION_HIDDEN = 'hidden';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'campaignFigures')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Campaign $campaign = null;

    #[ORM\ManyToOne(targetEntity: Character::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Character $character = null;

    #[ORM\Column(length: 120)]
    private ?string $name = null;

    #[ORM\Column(length: 20)]
    private ?string $encounterStatus = null;

    #[ORM\Column(length: 10)]
    private ?string $characterType = null;

    #[ORM\Column(length: 20)]
    private ?string $lifeStatus = null;

    #[ORM\Column(length: 255)]
    private ?string $description = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $deathLabel = null;

    #[ORM\ManyToOne]
    private ?Media $portrait = null;

    #[ORM\Column(length: 20)]
    private ?string $publicationStatus = null;

    #[ORM\Column]
    private ?int $displayOrder = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct(
        Campaign $campaign,
        string $name,
        string $characterType,
        string $description,
        int $displayOrder = 0,
    ) {
        if (
            !in_array(
                $characterType,
                self::allowedCharacterTypes(),
                true,
            )
        ) {
            throw new \InvalidArgumentException(
                'Type de personnage invalide.',
            );
        }

        $this->campaign = $campaign;
        $this->name = $name;
        $this->characterType = $characterType;
        $this->description = $description;
        $this->displayOrder = $displayOrder;

        $this->encounterStatus =
            self::ENCOUNTER_UNMET;

        $this->lifeStatus =
            self::LIFE_ALIVE;

        $this->publicationStatus =
            self::PUBLICATION_VISIBLE;

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

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;
        $this->touch();

        return $this;
    }

    public function getEncounterStatus(): ?string
    {
        return $this->encounterStatus;
    }

    public function setEncounterStatus(
        string $encounterStatus,
    ): static {
        if (
            !in_array(
                $encounterStatus,
                self::allowedEncounterStatuses(),
                true,
            )
        ) {
            throw new \InvalidArgumentException(
                'Statut de rencontre invalide.',
            );
        }

        $this->encounterStatus =
            $encounterStatus;

        $this->touch();

        return $this;
    }

    public function getCharacterType(): ?string
    {
        return $this->characterType;
    }

    public function setCharacterType(
        string $characterType,
    ): static {
        if (
            !in_array(
                $characterType,
                self::allowedCharacterTypes(),
                true,
            )
        ) {
            throw new \InvalidArgumentException(
                'Type de personnage invalide.',
            );
        }

        $this->characterType = $characterType;
        $this->touch();

        return $this;
    }

    public function getLifeStatus(): ?string
    {
        return $this->lifeStatus;
    }

    public function setLifeStatus(
        string $lifeStatus,
    ): static {
        if (
            !in_array(
                $lifeStatus,
                self::allowedLifeStatuses(),
                true,
            )
        ) {
            throw new \InvalidArgumentException(
                'État de vie invalide.',
            );
        }

        $this->lifeStatus = $lifeStatus;

        /*
        * Un mort ne doit conserver aucune mention
        * de décès obligatoire : deathLabel reste
        * volontairement facultatif.
        */
        $this->touch();

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(string $description): static
    {
        $this->description = $description;
        $this->touch();

        return $this;
    }

    public function getDeathLabel(): ?string
    {
        return $this->deathLabel;
    }

    public function setDeathLabel(?string $deathLabel): static
    {
        $this->deathLabel = $deathLabel;
        $this->touch();

        return $this;
    }

    public function getPortrait(): ?Media
    {
        return $this->portrait;
    }

    public function setPortrait(?Media $portrait): static
    {
        $this->portrait = $portrait;
        $this->touch();

        return $this;
    }

    public function getPublicationStatus(): ?string
    {
        return $this->publicationStatus;
    }

    public function setPublicationStatus(
        string $publicationStatus,
    ): static {
        if (
            !in_array(
                $publicationStatus,
                self::allowedPublicationStatuses(),
                true,
            )
        ) {
            throw new \InvalidArgumentException(
                'Statut de publication invalide.',
            );
        }

        $this->publicationStatus =
            $publicationStatus;

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

    /**
     * @return list<string>
     */
    public static function allowedCharacterTypes(): array
    {
        return [
            self::TYPE_PC,
            self::TYPE_NPC,
        ];
    }

    /**
     * @return list<string>
     */
    public static function allowedEncounterStatuses(): array
    {
        return [
            self::ENCOUNTER_UNMET,
            self::ENCOUNTER_MET,
        ];
    }

    /**
     * @return list<string>
     */
    public static function allowedLifeStatuses(): array
    {
        return [
            self::LIFE_ALIVE,
            self::LIFE_DEAD,
        ];
    }

    /**
     * @return list<string>
     */
    public static function allowedPublicationStatuses(): array
    {
        return [
            self::PUBLICATION_VISIBLE,
            self::PUBLICATION_HIDDEN,
        ];
    }

    private function touch(): void
    {
        $this->updatedAt =
            new \DateTimeImmutable();
    }

    public function getCharacter(): ?Character
    {
        return $this->character;
    }

    public function setCharacter(?Character $character): static
    {
        if (
            $character !== null
            && $character->getCampaign()->getId()
                !== $this->campaign?->getId()
        ) {
            throw new \InvalidArgumentException(
                'Le personnage doit appartenir à la même campagne.',
            );
        }

        $this->character = $character;
        $this->touch();

        return $this;
    }
}
