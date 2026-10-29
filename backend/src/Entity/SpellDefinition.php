<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\{ReferenceOrigin, SpellSchool};
use Doctrine\ORM\Mapping as ORM;

/** Catalogue only: ownership of a definition never grants a character a spell. */
#[ORM\Entity]
#[ORM\Table(name: 'spell_definition')]
#[ORM\UniqueConstraint(name: 'uniq_spell_slug', columns: ['slug'])]
#[ORM\UniqueConstraint(name: 'uniq_spell_import', columns: ['edition', 'import_key'])]
#[ORM\Index(name: 'idx_spell_owner', columns: ['owner_id'])]
#[ORM\Index(name: 'idx_spell_level', columns: ['level'])]
#[ORM\Index(name: 'idx_spell_school', columns: ['school'])]
#[ORM\Index(name: 'idx_spell_source', columns: ['source_code'])]
#[ORM\HasLifecycleCallbacks]
class SpellDefinition
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private string $slug;

    #[ORM\Column(length: 150)]
    private string $name;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $originalName = null;

    #[ORM\Column]
    private int $level;

    #[ORM\Column(length: 20, enumType: SpellSchool::class)]
    private SpellSchool $school;

    #[ORM\Column(type: 'text')]
    private string $castingTime;

    #[ORM\Column(type: 'text')]
    private string $range;

    #[ORM\Column(type: 'text')]
    private string $duration;

    #[ORM\Column(options: ['default' => false])]
    private bool $verbal = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $somatic = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $material = false;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $materialDescription = null;

    #[ORM\Column(nullable: true)]
    private ?int $materialCostCp = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $materialConsumed = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $concentration = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $ritual = false;

    #[ORM\Column(type: 'text')]
    private string $description;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $higherLevels = null;

    #[ORM\Column(length: 4, options: ['default' => '2014'])]
    private string $edition = '2014';

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $rulesVariant = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $sourceCode = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $sourceReference = null;

    #[ORM\Column(length: 2048, nullable: true)]
    private ?string $sourceUrl = null;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $importKey = null;

    #[ORM\Column(length: 8, enumType: ReferenceOrigin::class, options: ['default' => 'OFFICIAL'])]
    private ReferenceOrigin $origin = ReferenceOrigin::Official;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?User $owner = null;

    public function __construct(string $slug, string $name, int $level, SpellSchool $school, string $castingTime, string $range, string $duration, string $description)
    {
        $slug = strtolower(trim($slug));
        if (strlen($slug) > 100 || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug)) {
            throw new \InvalidArgumentException('Slug de sort invalide.');
        }
        $this->slug = $slug;
        $this->setName($name);
        $this->setLevel($level);
        $this->school = $school;
        $this->setCastingTime($castingTime);
        $this->setRange($range);
        $this->setDuration($duration);
        $this->setDescription($description);
    }

    public static function forOwner(User $owner, string $slug, string $name, int $level, SpellSchool $school, string $castingTime, string $range, string $duration, string $description): self
    {
        $spell = new self($slug, $name, $level, $school, $castingTime, $range, $duration, $description);
        $spell->origin = ReferenceOrigin::Custom;
        $spell->owner = $owner;

        return $spell;
    }

    public function getId(): ?int { return $this->id; }
    public function getSlug(): string { return $this->slug; }
    public function getName(): string { return $this->name; }
    public function getOriginalName(): ?string { return $this->originalName; }
    public function getLevel(): int { return $this->level; }
    public function getSchool(): SpellSchool { return $this->school; }
    public function getCastingTime(): string { return $this->castingTime; }
    public function getRange(): string { return $this->range; }
    public function getDuration(): string { return $this->duration; }
    public function isVerbal(): bool { return $this->verbal; }
    public function isSomatic(): bool { return $this->somatic; }
    public function isMaterial(): bool { return $this->material; }
    public function getMaterialDescription(): ?string { return $this->materialDescription; }
    public function getMaterialCostCp(): ?int { return $this->materialCostCp; }
    public function isMaterialConsumed(): bool { return $this->materialConsumed; }
    public function isConcentration(): bool { return $this->concentration; }
    public function isRitual(): bool { return $this->ritual; }
    public function getDescription(): string { return $this->description; }
    public function getHigherLevels(): ?string { return $this->higherLevels; }
    public function getEdition(): string { return $this->edition; }
    public function getRulesVariant(): ?string { return $this->rulesVariant; }
    public function getSourceCode(): ?string { return $this->sourceCode; }
    public function getSourceReference(): ?string { return $this->sourceReference; }
    public function getSourceUrl(): ?string { return $this->sourceUrl; }
    public function getImportKey(): ?string { return $this->importKey; }
    public function getOrigin(): ReferenceOrigin { return $this->origin; }
    public function getOwner(): ?User { return $this->owner; }

    public function setName(string $name): static { $this->name = self::requiredText($name, 150); return $this; }
    public function setOriginalName(?string $name): static { $this->originalName = self::optionalText($name, 150); return $this; }
    public function setSchool(SpellSchool $school): static { $this->school = $school; return $this; }
    public function setCastingTime(string $value): static { $this->castingTime = self::requiredText($value); return $this; }
    public function setRange(string $value): static { $this->range = self::requiredText($value); return $this; }
    public function setDuration(string $value): static { $this->duration = self::requiredText($value); return $this; }
    public function setDescription(string $value): static { $this->description = self::requiredText($value); return $this; }
    public function setHigherLevels(?string $value): static { $this->higherLevels = self::optionalText($value); return $this; }
    public function setConcentration(bool $value): static { $this->concentration = $value; return $this; }
    public function setRitual(bool $value): static { $this->ritual = $value; return $this; }

    public function setLevel(int $level): static
    {
        if ($level < 0 || $level > 9) throw new \InvalidArgumentException('Le niveau du sort doit être compris entre 0 et 9.');
        $this->level = $level;
        return $this;
    }

    /** Validate the whole component group before changing any field. */
    public function setComponents(bool $verbal, bool $somatic, bool $material, ?string $description = null, ?int $costCp = null, bool $consumed = false): static
    {
        $description = self::optionalText($description);
        if ($costCp !== null && ($costCp < 0 || $costCp > 2147483647)) throw new \InvalidArgumentException('Coût matériel invalide.');
        if ($material ? $description === null : ($description !== null || $costCp !== null || $consumed)) {
            throw new \InvalidArgumentException('Composantes matérielles incohérentes.');
        }
        $this->verbal = $verbal;
        $this->somatic = $somatic;
        $this->material = $material;
        $this->materialDescription = $description;
        $this->materialCostCp = $costCp;
        $this->materialConsumed = $consumed;
        return $this;
    }

    public function setRulesVariant(?string $variant): static
    {
        $variant = self::optionalText($variant, 150);
        if ($variant !== null && $this->origin !== ReferenceOrigin::Custom) throw new \InvalidArgumentException('Une variante maison doit être CUSTOM.');
        $this->rulesVariant = $variant;
        return $this;
    }

    public function setSource(?string $code, ?string $reference = null, ?string $url = null): static
    {
        $code = self::optionalText($code, 40);
        $reference = self::optionalText($reference, 255);
        $url = self::optionalText($url, 2048);
        if ($url !== null && (!filter_var($url, FILTER_VALIDATE_URL) || !in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true))) {
            throw new \InvalidArgumentException('URL de source invalide.');
        }
        $this->sourceCode = $code;
        $this->sourceReference = $reference;
        $this->sourceUrl = $url;
        return $this;
    }

    /** Import identity is separate from names and cannot be reassigned. */
    public function setImportKey(string $key): static
    {
        $key = self::requiredText($key, 150);
        if ($this->origin !== ReferenceOrigin::Official || ($this->importKey !== null && $this->importKey !== $key)) {
            throw new \InvalidArgumentException('Identité d’import protégée.');
        }
        $this->importKey = $key;
        return $this;
    }

    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    #[ORM\PostLoad]
    public function validateReferenceOwnership(): void
    {
        if (($this->origin === ReferenceOrigin::Official) !== ($this->owner === null)
            || ($this->origin === ReferenceOrigin::Official && $this->rulesVariant !== null)
            || ($this->origin === ReferenceOrigin::Custom && $this->importKey !== null)) {
            throw new \LogicException('Invalid spell origin/owner or provenance.');
        }
    }

    private static function requiredText(string $value, int $maximum = 50000): string
    {
        return self::optionalText($value, $maximum) ?? throw new \InvalidArgumentException('Texte de sort requis.');
    }

    private static function optionalText(?string $value, int $maximum = 50000): ?string
    {
        if ($value === null) return null;
        if (str_contains($value, "\0") || mb_strlen($value) > $maximum) throw new \InvalidArgumentException('Texte de sort invalide ou trop long.');
        $value = trim($value);
        return $value === '' ? null : $value;
    }
}
