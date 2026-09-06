<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CharacterWalletRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(
    repositoryClass: CharacterWalletRepository::class,
)]
#[ORM\Table(name: 'character_wallet')]
class CharacterWallet
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(
        inversedBy: 'wallet',
        targetEntity: Character::class,
    )]
    #[ORM\JoinColumn(
        nullable: false,
        unique: true,
        onDelete: 'CASCADE',
    )]
    private Character $character;

    #[ORM\Column(options: ['default' => 0])]
    private int $copperPieces = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $silverPieces = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $electrumPieces = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $goldPieces = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $platinumPieces = 0;

    #[ORM\Column]
    private DateTimeImmutable $updatedAt;

    public function __construct(
        Character $character,
    ) {
        $this->character = $character;
        $this->updatedAt =
            new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCharacter(): Character
    {
        return $this->character;
    }

    public function getCopperPieces(): int
    {
        return $this->copperPieces;
    }

    public function setCopperPieces(
        int $copperPieces,
    ): static {
        $this->copperPieces =
            $this->validateAmount(
                $copperPieces,
            );

        $this->touch();

        return $this;
    }

    public function getSilverPieces(): int
    {
        return $this->silverPieces;
    }

    public function setSilverPieces(
        int $silverPieces,
    ): static {
        $this->silverPieces =
            $this->validateAmount(
                $silverPieces,
            );

        $this->touch();

        return $this;
    }

    public function getElectrumPieces(): int
    {
        return $this->electrumPieces;
    }

    public function setElectrumPieces(
        int $electrumPieces,
    ): static {
        $this->electrumPieces =
            $this->validateAmount(
                $electrumPieces,
            );

        $this->touch();

        return $this;
    }

    public function getGoldPieces(): int
    {
        return $this->goldPieces;
    }

    public function setGoldPieces(
        int $goldPieces,
    ): static {
        $this->goldPieces =
            $this->validateAmount(
                $goldPieces,
            );

        $this->touch();

        return $this;
    }

    public function getPlatinumPieces(): int
    {
        return $this->platinumPieces;
    }

    public function setPlatinumPieces(
        int $platinumPieces,
    ): static {
        $this->platinumPieces =
            $this->validateAmount(
                $platinumPieces,
            );

        $this->touch();

        return $this;
    }

    public function getUpdatedAt():
        DateTimeImmutable {
        return $this->updatedAt;
    }

    private function validateAmount(
        int $amount,
    ): int {
        if ($amount < 0) {
            throw new \InvalidArgumentException(
                'Le nombre de pièces ne peut pas être négatif.',
            );
        }

        return $amount;
    }

    private function touch(): void
    {
        $this->updatedAt =
            new DateTimeImmutable();
    }
}
