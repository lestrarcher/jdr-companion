<?php
declare(strict_types=1);
namespace App\Entity;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity, ORM\Table(name: 'beast_feature')]
#[ORM\Index(name: 'idx_beast_feature_definition', columns: ['feature_definition_id'])]
class BeastFeature
{
    public function __construct(
        #[ORM\Id, ORM\ManyToOne(inversedBy: 'features'), ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Beast $beast,
        #[ORM\Id, ORM\ManyToOne, ORM\JoinColumn(name: 'feature_definition_id', nullable: false, onDelete: 'RESTRICT')]
        private CharacterFeatureDefinition $definition,
        #[ORM\Column(options: ['default' => 0])]
        private int $displayOrder = 0,
    ) {}
    public function getDefinition(): CharacterFeatureDefinition { return $this->definition; }
    public function setDisplayOrder(int $order): void { $this->displayOrder = $order; }
}
