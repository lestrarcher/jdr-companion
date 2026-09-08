<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\HitPointGainMethod;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'character_class_level')]
#[ORM\UniqueConstraint(
    name: 'uniq_character_level_position',
    columns: ['character_id', 'position'],
)]
class CharacterClassLevel
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'classLevels')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Character $character;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private CharacterClass $characterClass;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?CharacterSubclass $subclass = null;

    #[ORM\Column]
    private int $position;

    /**
     * Gain brut du niveau, sans le modificateur de Constitution.
     *
     * La valeur reste nullable pour les niveaux créés avant
     * l’introduction du calcul des points de vie.
     */
    #[ORM\Column(nullable: true)]
    private ?int $hitPointGain = null;

    #[ORM\Column(enumType: HitPointGainMethod::class, nullable: true)]
    private ?HitPointGainMethod $hitPointGainMethod = null;

    #[ORM\Column]
    private \DateTimeImmutable $acquiredAt;

    public function __construct(
        Character $character,
        CharacterClass $characterClass,
        int $position,
        ?CharacterSubclass $subclass = null,
        ?int $hitPointGain = null,
        ?HitPointGainMethod $hitPointGainMethod = null,
    ) {
        if ($position < 1 || $position > 20) {
            throw new \InvalidArgumentException(
                'La position du niveau doit être comprise entre 1 et 20.',
            );
        }

        if ($subclass !== null && $subclass->getCharacterClass() !== $characterClass) {
            throw new \InvalidArgumentException(
                'Cette sous-classe n’appartient pas à la classe sélectionnée.',
            );
        }

        $this->character = $character;
        $this->characterClass = $characterClass;
        $this->position = $position;
        $this->subclass = $subclass;
        $this->acquiredAt = new \DateTimeImmutable();

        if ($hitPointGain !== null || $hitPointGainMethod !== null) {
            if ($hitPointGain === null || $hitPointGainMethod === null) {
                throw new \InvalidArgumentException(
                    'Le gain de PV et sa méthode doivent être renseignés ensemble.',
                );
            }

            $this->setHitPointGain($hitPointGain, $hitPointGainMethod);
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCharacter(): Character
    {
        return $this->character;
    }

    public function getCharacterClass(): CharacterClass
    {
        return $this->characterClass;
    }

    public function getSubclass(): ?CharacterSubclass
    {
        return $this->subclass;
    }

    public function setSubclass(?CharacterSubclass $subclass): self
    {
        if ($subclass !== null && $subclass->getCharacterClass() !== $this->characterClass) {
            throw new \InvalidArgumentException(
                'Cette sous-classe n’appartient pas à la classe sélectionnée.',
            );
        }

        $this->subclass = $subclass;

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getHitPointGain(): ?int
    {
        return $this->hitPointGain;
    }

    public function getHitPointGainMethod(): ?HitPointGainMethod
    {
        return $this->hitPointGainMethod;
    }

    public function hasHitPointGain(): bool
    {
        return $this->hitPointGain !== null;
    }

    public function setHitPointGain(
        int $hitPointGain,
        HitPointGainMethod $method,
    ): self {
        $hitDie = $this->characterClass->getHitDie();

        if ($hitPointGain < 1 || $hitPointGain > $hitDie) {
            throw new \InvalidArgumentException(sprintf(
                'Le gain brut de PV doit être compris entre 1 et %d pour cette classe.',
                $hitDie,
            ));
        }

        if ($method === HitPointGainMethod::FirstLevel) {
            if ($this->position !== 1) {
                throw new \InvalidArgumentException(
                    'La méthode du premier niveau ne peut être utilisée qu’au niveau global 1.',
                );
            }

            if ($hitPointGain !== $hitDie) {
                throw new \InvalidArgumentException(
                    'Le premier niveau doit utiliser la valeur maximale du dé de vie.',
                );
            }
        }

        if (
            $method === HitPointGainMethod::Average
            && $hitPointGain !== $this->getAverageHitPointGain()
        ) {
            throw new \InvalidArgumentException(sprintf(
                'La valeur moyenne d’un d%d est %d.',
                $hitDie,
                $this->getAverageHitPointGain(),
            ));
        }

        $this->hitPointGain = $hitPointGain;
        $this->hitPointGainMethod = $method;

        return $this;
    }

    public function getAverageHitPointGain(): int
    {
        return intdiv($this->characterClass->getHitDie(), 2) + 1;
    }

    public function getAcquiredAt(): \DateTimeImmutable
    {
        return $this->acquiredAt;
    }
}
