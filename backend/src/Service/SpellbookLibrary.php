<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Character;
use App\Entity\CharacterMagicItem;
use App\Entity\SpellbookEntry;
use App\Entity\SpellDefinition;
use Doctrine\ORM\EntityManagerInterface;

/** Physical spellbook storage. Does not grant spell acquisitions or preparation. */
final readonly class SpellbookLibrary
{
    public function __construct(private EntityManagerInterface $em) {}

    /** @return list<array{id:int, spellId:int, name:string, level:int}> */
    public function contents(CharacterMagicItem $book): array
    {
        $this->assertSpellbook($book);
        $entries = $this->em->getRepository(SpellbookEntry::class)->findBy(['spellbook' => $book]);
        $result = [];
        foreach ($entries as $entry) {
            $spell = $entry->getSpell();
            $result[] = ['id' => (int) $entry->getId(), 'spellId' => (int) $spell->getId(),
                'name' => $spell->getName(), 'level' => $spell->getLevel()];
        }
        usort($result, static fn (array $a, array $b): int => [$a['level'], $a['name']] <=> [$b['level'], $b['name']]);
        return $result;
    }

    /** @return list<int> Spell IDs available in the character's currently held books. */
    public function accessibleSpellIds(Character $character): array
    {
        $ids = [];
        foreach ($character->getMagicItems() as $owned) {
            if ($owned->getMagicItem()?->getCategory() !== 'spellbook') {
                continue;
            }
            foreach ($this->contents($owned) as $entry) {
                $ids[$entry['spellId']] = $entry['spellId'];
            }
        }
        return array_values($ids);
    }

    /** Add an inscription without granting a spell or consuming its source. Caller validates copy costs and permissions. */
    public function inscribe(CharacterMagicItem $book, SpellDefinition $spell): bool
    {
        $this->assertSpellbook($book);
        if ($spell->getLevel() < 1) {
            throw new \InvalidArgumentException('Un sort mineur ne peut pas être inscrit dans un grimoire.');
        }
        $existing = $this->em->getRepository(SpellbookEntry::class)->findOneBy(['spellbook' => $book, 'spell' => $spell]);
        if ($existing !== null) {
            return false;
        }
        $this->em->persist(new SpellbookEntry($book, $spell));
        // Caller flushes inside its transaction; the unique DB constraint also prevents races.
        return true;
    }

    private function assertSpellbook(CharacterMagicItem $book): void
    {
        if ($book->getMagicItem()?->getCategory() !== 'spellbook' || $book->getQuantity() !== 1) {
            throw new \InvalidArgumentException('Objet invalide : un grimoire doit être individuel.');
        }
    }
}
