<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Character;
use App\Entity\CharacterMagicItem;
use App\Entity\MagicItem;
use Doctrine\ORM\EntityManagerInterface;

/** Persists wizard spell acquisitions in an individual physical spellbook.
 * Caller must hold the character lock and an open transaction.
 */
final readonly class WizardSpellbookWriter
{
    public function __construct(private EntityManagerInterface $em) {}

    public function record(Character $character, int $spellId): void
    {
        $db = $this->em->getConnection();
        $characterId = (int) $character->getId();
        $bookId = $db->fetchOne(
            "SELECT cmi.id FROM character_magic_item cmi JOIN magic_item mi ON mi.id = cmi.magic_item_id WHERE cmi.character_id = ? AND mi.category = 'spellbook' AND cmi.quantity = 1 AND NOT EXISTS (SELECT 1 FROM pact_tome_entry p WHERE p.owned_item_id = cmi.id) ORDER BY cmi.id ASC LIMIT 1",
            [$characterId],
        );

        if ($bookId === false) {
            // Reuse a campaign-owned definition when possible, but never a plain 'gear' item
            // that happens to be named Grimoire.
            $definition = $this->em->getRepository(MagicItem::class)->findOneBy([
                'campaign' => $character->getCampaign(),
                'category' => 'spellbook',
                'name' => 'Grimoire',
            ]);
            if ($definition === null) {
                $definition = new MagicItem($character->getCampaign(), 'Grimoire', null);
                $definition->classify('spellbook', false, false);
                $this->em->persist($definition);
            }
            $owned = new CharacterMagicItem($character, $definition);
            $owned->setDisplayName('Grimoire de ' . $character->getName());
            $this->em->persist($owned);
            $this->em->flush();
            $bookId = $owned->getId();
        }

        $db->executeStatement(
            'INSERT INTO spellbook_entry (owned_item_id, spell_id, written_at) VALUES (?, ?, CURRENT_TIMESTAMP) ON CONFLICT (owned_item_id, spell_id) DO NOTHING',
            [(int) $bookId, $spellId],
        );
    }
}
