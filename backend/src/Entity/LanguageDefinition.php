<?php
declare(strict_types=1);
namespace App\Entity;

use App\Enum\ReferenceOrigin;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uniq_language_slug', columns: ['slug'])]
#[ORM\HasLifecycleCallbacks]
class LanguageDefinition
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;
    #[ORM\Column(length: 8, enumType: ReferenceOrigin::class)]
    private ReferenceOrigin $origin = ReferenceOrigin::Official;
    #[ORM\ManyToOne, ORM\JoinColumn(onDelete: 'RESTRICT')]
    private ?User $owner = null;
    public function __construct(
        #[ORM\Column(length: 80)] private string $slug,
        #[ORM\Column(length: 120)] private string $name,
        #[ORM\Column(length: 12)] private string $category = 'STANDARD',
    ) { $this->validate(); }
    public static function forOwner(User $owner, string $slug, string $name): self
    {
        $language = new self($slug, $name, 'CUSTOM');
        $language->origin = ReferenceOrigin::Custom;
        $language->owner = $owner;
        return $language;
    }
    public function getId(): ?int { return $this->id; }
    public function getSlug(): string { return $this->slug; }
    public function getName(): string { return $this->name; }
    public function getCategory(): string { return $this->category; }
    public function getOrigin(): ReferenceOrigin { return $this->origin; }
    public function getOwner(): ?User { return $this->owner; }
    public function revise(string $name, string $category): void
    {
        self::validateValues($this->slug, $name, $category);
        $this->name = $name; $this->category = $category;
    }
    private static function validateValues(string $slug, string $name, string $category): void
    {
        if (strlen($slug) > 80 || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)
            || trim($name) === '' || mb_strlen($name) > 120 || !in_array($category, ['STANDARD', 'EXOTIC', 'SECRET', 'CUSTOM'], true)) {
            throw new \DomainException('Langue invalide.');
        }
    }
    #[ORM\PrePersist, ORM\PreUpdate, ORM\PostLoad]
    public function validate(): void
    {
        self::validateValues($this->slug, $this->name, $this->category);
        if (($this->origin === ReferenceOrigin::Official) !== ($this->owner === null)) throw new \LogicException('Invalid reference origin/owner pair.');
    }
}
