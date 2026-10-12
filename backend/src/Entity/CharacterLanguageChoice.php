<?php
declare(strict_types=1);
namespace App\Entity;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\UniqueConstraint(columns: ['character_id', 'source_key', 'language_id'])]
class CharacterLanguageChoice
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne(inversedBy: 'languageChoices'), ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Character $character,
        #[ORM\Column(length: 160)]
        private string $sourceKey,
        #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
        private LanguageDefinition $language,
    ) {

    }
    public function getId(): ?int { return $this->id; }
    public function getCharacter(): Character { return $this->character; }
    public function getSourceKey(): string { return $this->sourceKey; }
    public function getLanguage(): LanguageDefinition { return $this->language; }

}

