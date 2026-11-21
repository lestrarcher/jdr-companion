<?php
declare(strict_types=1);
namespace App\Entity;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity, ORM\Table(name: 'beast_natural_weapon')]
#[ORM\UniqueConstraint(name: 'uniq_beast_natural_weapon_source', columns: ['beast_id', 'source_key'])]
#[ORM\Index(name: 'idx_beast_natural_weapon_beast', columns: ['beast_id', 'sort_order'])]
class BeastNaturalWeapon
{
    #[ORM\Id, ORM\GeneratedValue(strategy: 'IDENTITY'), ORM\Column]
    private ?int $id = null;
    #[ORM\Column(length: 160)]
    private string $sourceKey;
    #[ORM\Column(length: 160)]
    private string $name;
    #[ORM\Column(length: 24)]
    private string $attackType = 'special';
    #[ORM\Column(nullable: true)]
    private ?int $attackBonus = null;
    #[ORM\Column(type: 'json')]
    private array $damageComponents = [];
    #[ORM\Column(type: 'text')]
    private string $description = '';
    #[ORM\Column]
    private int $sortOrder = 0;
    #[ORM\Column(length: 64)]
    private string $sourceHash = '';
    #[ORM\Column(options: ['default' => false])]
    private bool $customized = false;
    public function __construct(
        #[ORM\ManyToOne(inversedBy: 'naturalWeapons'), ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Beast $beast,
    ) { $this->sourceKey = 'custom-'.bin2hex(random_bytes(16)); }
    public function getId(): ?int { return $this->id; }
    public function edit(string $name, string $type, ?int $bonus, array $damage, string $description, int $order): void
    {
        $this->name = $name;
        $this->attackType = $type;
        $this->attackBonus = $bonus;
        $this->damageComponents = $damage;
        $this->description = $description;
        $this->sortOrder = $order;
        $this->customized = true;
        $this->beast->markCustomized();
    }
    /** Stable player API; range and special effects remain in description. */
    public function toArray(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'attackType' => $this->attackType,
            'attackBonus' => $this->attackBonus, 'damageComponents' => $this->damageComponents,
            'description' => $this->description, 'sortOrder' => $this->sortOrder];
    }
}
