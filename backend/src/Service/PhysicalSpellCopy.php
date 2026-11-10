<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\SpellbookCopyDecision;
use App\Entity\{Character, CharacterMagicItem, User};
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\{BadRequestHttpException, ConflictHttpException, NotFoundHttpException};

/** Copy an actual held scroll/book into a specific held spellbook. No wallet writes. */
final readonly class PhysicalSpellCopy
{
    public function __construct(private EntityManagerInterface $em, private CharacterSpellAcquisitions $acquisitions) {}

    public function quote(Character $character, User $actor, int $policyId, int $sourceId, int $destinationId, int $spellId): array
    {
        $this->authorize($character, $actor);
        $source = $this->owned($character, $sourceId);
        $destination = $this->owned($character, $destinationId);
        if ($destination->getPactOwner() !== null || $destination->getMagicItem()?->getCategory() !== 'spellbook' || $destination->getQuantity() !== 1 || $sourceId === $destinationId || $this->em->getConnection()->fetchOne('SELECT 1 FROM pact_tome_entry WHERE owned_item_id = ?', [$destinationId])) {
            throw new BadRequestHttpException('Le grimoire de destination est invalide.');
        }
        $sourceType = $source->getMagicItem()?->getCategory();
        if ($sourceType === 'spellbook') {
            if ($this->em->getConnection()->fetchOne('SELECT 1 FROM pact_tome_entry WHERE owned_item_id = ?', [$sourceId])) throw new BadRequestHttpException('Ce Grimoire des Ombres ne contient pas de sorts de magicien à recopier.');
            if (!$this->em->getConnection()->fetchOne('SELECT 1 FROM spellbook_entry WHERE owned_item_id = ? AND spell_id = ?', [$sourceId, $spellId])) {
                throw new BadRequestHttpException('Ce sort ne figure pas dans le grimoire source.');
            }
        } elseif ($sourceType === 'scroll') {
            if ($source->getInscribedSpell()?->getId() !== $spellId) {
                throw new BadRequestHttpException('Ce parchemin ne contient pas ce sort.');
            }
        } else {
            throw new BadRequestHttpException('La source doit être un grimoire ou un parchemin.');
        }
        if ($this->em->getConnection()->fetchOne('SELECT 1 FROM spellbook_entry WHERE owned_item_id = ? AND spell_id = ?', [$destinationId, $spellId])) {
            throw new ConflictHttpException('Le sort est déjà inscrit dans le grimoire de destination.');
        }
        $quote = $this->acquisitions->copyQuote($character, $policyId, $spellId, $actor);
        return [...$quote, 'sourceOwnedItemId' => $sourceId, 'destinationOwnedItemId' => $destinationId,
            'sourceType' => $sourceType, 'arcanaDC' => $sourceType === 'scroll' ? 10 + $quote['spellLevel'] : null,
            'walletDebited' => false];
    }

    public function execute(Character $character, User $actor, array $data): array
    {
        foreach (['policyId', 'sourceOwnedItemId', 'destinationOwnedItemId', 'spellId'] as $key) {
            if (!is_int($data[$key] ?? null) || $data[$key] < 1) throw new BadRequestHttpException('Identifiant de copie invalide.');
        }
        if (($data['confirmed'] ?? null) !== true) throw new BadRequestHttpException('Confirmation requise.');
        $db = $this->em->getConnection();
        return $this->em->wrapInTransaction(function () use ($character, $actor, $data, $db): array {
            $this->em->lock($character, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($character);
            // Locks serialize inventory transfers and scroll consumption.
            $ids = [$data['sourceOwnedItemId'], $data['destinationOwnedItemId']];
            sort($ids);
            foreach ($ids as $id) $db->fetchOne('SELECT id FROM character_magic_item WHERE id = ? FOR UPDATE', [$id]);
            $quote = $this->quote($character, $actor, $data['policyId'], $data['sourceOwnedItemId'], $data['destinationOwnedItemId'], $data['spellId']);
            $isScroll = $quote['sourceType'] === 'scroll';
            if ($isScroll && (!is_int($data['arcanaTotal'] ?? null) || $data['arcanaTotal'] < -100 || $data['arcanaTotal'] > 1000)) {
                throw new BadRequestHttpException('Résultat total du jet d’Arcanes requis.');
            }
            if (!$isScroll && array_key_exists('arcanaTotal', $data)) throw new BadRequestHttpException('Aucun jet pour une copie depuis un grimoire.');
            $successful = !$isScroll || $data['arcanaTotal'] >= $quote['arcanaDC'];
            $source = $this->owned($character, $data['sourceOwnedItemId']);
            $acquisitionId = null;
            if ($successful) {
                // The legacy acquisition path auto-inscribes into the first book.
                // Preserve existing inscriptions, and relocate only the newly written one.
                $firstId = $db->fetchOne("SELECT cmi.id FROM character_magic_item cmi JOIN magic_item mi ON mi.id = cmi.magic_item_id WHERE cmi.character_id = ? AND mi.category = 'spellbook' AND cmi.quantity = 1 AND cmi.pact_owner_id IS NULL AND NOT EXISTS (SELECT 1 FROM pact_tome_entry p WHERE p.owned_item_id = cmi.id) ORDER BY cmi.id LIMIT 1", [$character->getId()]);
                $firstHadSpell = $firstId !== false && (bool) $db->fetchOne('SELECT 1 FROM spellbook_entry WHERE owned_item_id = ? AND spell_id = ?', [$firstId, $data['spellId']]);
                $decision = [...SpellbookCopyDecision::quote($quote['spellLevel']),
                    'costCp' => $data['costCp'] ?? $quote['baseCostCp'],
                    'minutes' => $data['minutes'] ?? $quote['baseMinutes'],
                    'source' => $isScroll ? 'parchemin physique #'.$source->getId() : 'grimoire physique #'.$source->getId(),
                    'adjustment' => $data['adjustment'] ?? 'none', 'reason' => $data['reason'] ?? '', 'confirmed' => true];
                $acquisitionId = $this->acquisitions->copy($character, $data['policyId'], $data['spellId'], $decision, $actor);
                if ($firstId !== false && (int) $firstId !== $data['destinationOwnedItemId'] && !$firstHadSpell) {
                    $db->executeStatement('DELETE FROM spellbook_entry WHERE owned_item_id = ? AND spell_id = ?', [$firstId, $data['spellId']]);
                }
                $db->executeStatement('INSERT INTO spellbook_entry (owned_item_id, spell_id, written_at) VALUES (?, ?, CURRENT_TIMESTAMP) ON CONFLICT (owned_item_id, spell_id) DO NOTHING', [$data['destinationOwnedItemId'], $data['spellId']]);
            }
            if ($isScroll) {
                if ($source->getQuantity() > 1) $source->setQuantity($source->getQuantity() - 1);
                else { $character->removeMagicItem($source); $this->em->remove($source); }
            }
            return ['success' => $successful, 'acquisitionId' => $acquisitionId,
                'scrollConsumed' => $isScroll, 'arcanaDC' => $quote['arcanaDC'], 'walletDebited' => false,
                'destinationOwnedItemId' => $data['destinationOwnedItemId']];
        });
    }

    private function authorize(Character $character, User $actor): void
    {
        if ($character->getCampaign()->getOwner()->getId() !== $actor->getId()) throw new NotFoundHttpException('Personnage indisponible.');
    }

    private function owned(Character $character, int $id): CharacterMagicItem
    {
        $item = $this->em->find(CharacterMagicItem::class, $id);
        if (!$item || $item->getCharacter()?->getId() !== $character->getId() || $item->getCampaign()->getId() !== $character->getCampaign()->getId()) {
            throw new NotFoundHttpException('Objet indisponible.');
        }
        return $item;
    }
}
