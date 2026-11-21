<?php
declare(strict_types=1);
namespace App\Entity;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity, ORM\Table(name: 'beast_habitat')]
#[ORM\Index(name: 'idx_beast_habitat_habitat', columns: ['habitat'])]
class BeastHabitat
{
    public const VALUES = ['aquatique', 'arctique', 'collines', 'desert', 'foret', 'littoral', 'marais', 'montagne', 'plaine', 'outreterre'];
    public function __construct(
        #[ORM\Id, ORM\ManyToOne(inversedBy: 'habitats'), ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Beast $beast,
        #[ORM\Id, ORM\Column(length: 40)]
        private string $habitat,
    ) {
        if (!in_array($habitat, self::VALUES, true)) throw new \InvalidArgumentException('Habitat inconnu.');
    }
    public function getHabitat(): string { return $this->habitat; }
}
