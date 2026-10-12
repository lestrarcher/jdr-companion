<?php
declare(strict_types=1);
namespace App\Entity;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]

class CharacterManualGrant
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;
    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;
    public function __construct(
        #[ORM\ManyToOne(inversedBy: 'manualGrants'), ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Character $character,
        #[ORM\Column(length: 12)]
        private string $kind,
        #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
        private User $grantedBy,
        #[ORM\Column(length: 500)]
        private string $reason,
        #[ORM\ManyToOne, ORM\JoinColumn(onDelete: 'RESTRICT')]
        private ?ProficiencyDefinition $proficiencyDefinition,
        #[ORM\ManyToOne, ORM\JoinColumn(onDelete: 'RESTRICT')]
        private ?LanguageDefinition $language,
    ) {
        if (!in_array($kind, ['PROFICIENCY', 'EXPERTISE', 'LANGUAGE'], true) || trim($reason) === '' || mb_strlen($reason) > 500
            || ($kind === 'LANGUAGE' ? ($language === null || $proficiencyDefinition !== null) : ($proficiencyDefinition === null || $language !== null))) throw new \DomainException('Attribution manuelle invalide.');
        $this->createdAt = new \DateTimeImmutable();
    }
    public function getId(): ?int { return $this->id; }
    public function getCharacter(): Character { return $this->character; }
    public function getKind(): string { return $this->kind; }
    public function getGrantedBy(): User { return $this->grantedBy; }
    public function getReason(): string { return $this->reason; }
    public function getProficiencyDefinition(): ?ProficiencyDefinition { return $this->proficiencyDefinition; }
    public function getLanguage(): ?LanguageDefinition { return $this->language; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}

