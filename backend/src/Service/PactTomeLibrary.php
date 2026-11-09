<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\{Character, CharacterMagicItem, PactTomeEntry, SpellDefinition};
use Doctrine\ORM\EntityManagerInterface;

final readonly class PactTomeLibrary
{
    public function __construct(private EntityManagerInterface $em) {}

    public function entries(CharacterMagicItem $book): array
    {
        $entries = $this->em->getRepository(PactTomeEntry::class)->findBy(['book' => $book]);
        return array_map(static fn(PactTomeEntry $entry): array => [
            'spellId' => $entry->getSpell()->getId(), 'name' => $entry->getSpell()->getName(),
            'level' => $entry->getSpell()->getLevel(), 'kind' => $entry->getKind(),
        ], $entries);
    }

    public function isPactTome(CharacterMagicItem $book): bool
    {
        return $book->getPactOwner() !== null || $this->em->getRepository(PactTomeEntry::class)->count(['book' => $book]) > 0;
    }

    public function inscribe(CharacterMagicItem $book, SpellDefinition $spell, string $kind): void
    {
        if ($this->em->getRepository(PactTomeEntry::class)->findOneBy(['book' => $book, 'spell' => $spell]) !== null) {
            throw new \InvalidArgumentException('Ce sort est déjà inscrit.');
        }
        $this->em->persist(new PactTomeEntry($book, $spell, $kind));
    }

    public function warlockLevel(Character $character): int
    {
        return count(array_filter($character->getClassLevels()->toArray(),
            static fn($level): bool => $level->getCharacterClass()->getSlug() === 'warlock'));
    }
}
