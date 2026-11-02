<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\{Character, CharacterMagicItem, SpellDefinition};
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\{BadRequestHttpException, ConflictHttpException, NotFoundHttpException};

/** Livre des secrets anciens (2014): physical sources, no wallet mutations. */
final readonly class PactRitualCopy
{
    public function __construct(private EntityManagerInterface $em, private PactTomeLibrary $tomes) {}

    public function quote(Character $character, int $sourceId, int $destinationId, int $spellId): array
    {
        $level = $this->tomes->warlockLevel($character);
        if ($level < 3) throw new BadRequestHttpException('Pacte du Grimoire requis.');
        // Temporary feature flag: Book of Ancient Secrets considered enabled.
        $source = $this->owned($character, $sourceId);
        $destination = $this->owned($character, $destinationId);
        if ($sourceId === $destinationId || !$this->tomes->isPactTome($destination)) {
            throw new BadRequestHttpException('Destination : Grimoire des Ombres requis.');
        }
        $spell = $this->em->find(SpellDefinition::class, $spellId);
        if (!$spell || !$spell->isRitual() || $spell->getLevel() < 1 || $spell->getLevel() > (int) ceil($level / 2)) {
            throw new BadRequestHttpException('Rituel non admissible pour le niveau d’occultiste.');
        }
        $sourceType = $source->getMagicItem()->getCategory();
        if ($sourceType === 'scroll') {
            if ($source->getInscribedSpell()?->getId() !== $spellId) throw new BadRequestHttpException('Sort absent du parchemin.');
        } elseif ($sourceType === 'spellbook') {
            $db = $this->em->getConnection();
            if (!$db->fetchOne('SELECT 1 FROM spellbook_entry WHERE owned_item_id = ? AND spell_id = ?', [$sourceId, $spellId])
                && !$db->fetchOne('SELECT 1 FROM pact_tome_entry WHERE owned_item_id = ? AND spell_id = ?', [$sourceId, $spellId])) {
                throw new BadRequestHttpException('Sort absent du grimoire source.');
            }
        } else {
            throw new BadRequestHttpException('Source invalide.');
        }
        if ($this->em->getConnection()->fetchOne('SELECT 1 FROM pact_tome_entry WHERE owned_item_id = ? AND spell_id = ?', [$destinationId, $spellId])) {
            throw new ConflictHttpException('Rituel déjà inscrit.');
        }
        return ['spellLevel' => $spell->getLevel(), 'baseCostCp' => $spell->getLevel() * 5000,
            'baseMinutes' => $spell->getLevel() * 120, 'sourceType' => $sourceType,
            'arcanaDC' => $sourceType === 'scroll' ? 10 + $spell->getLevel() : null,
            'walletDebited' => false];
    }

    public function execute(Character $character, array $payload): array
    {
        foreach (['sourceOwnedItemId', 'destinationOwnedItemId', 'spellId'] as $key) {
            if (!is_int($payload[$key] ?? null) || $payload[$key] < 1) throw new BadRequestHttpException('Identifiant invalide.');
        }
        if (($payload['confirmed'] ?? false) !== true) throw new BadRequestHttpException('Confirmation requise.');
        return $this->em->wrapInTransaction(function () use ($character, $payload): array {
            $db = $this->em->getConnection();
            $ids = [$payload['sourceOwnedItemId'], $payload['destinationOwnedItemId']];
            sort($ids);
            foreach ($ids as $id) $db->fetchOne('SELECT id FROM character_magic_item WHERE id = ? FOR UPDATE', [$id]);
            $quote = $this->quote($character, $payload['sourceOwnedItemId'], $payload['destinationOwnedItemId'], $payload['spellId']);
            $scroll = $quote['sourceType'] === 'scroll';
            if ($scroll && (!is_int($payload['arcanaTotal'] ?? null) || $payload['arcanaTotal'] < -100 || $payload['arcanaTotal'] > 1000)) {
                throw new BadRequestHttpException('Résultat total d’Arcanes requis.');
            }
            $success = !$scroll || $payload['arcanaTotal'] >= $quote['arcanaDC'];
            if ($success) {
                $this->tomes->inscribe($this->owned($character, $payload['destinationOwnedItemId']),
                    $this->em->find(SpellDefinition::class, $payload['spellId']), 'ritual');
            }
            if ($scroll) {
                $source = $this->owned($character, $payload['sourceOwnedItemId']);
                if ($source->getQuantity() > 1) $source->setQuantity($source->getQuantity() - 1);
                else { $character->removeMagicItem($source); $this->em->remove($source); }
            }
            return ['success' => $success, 'scrollConsumed' => $scroll, 'walletDebited' => false];
        });
    }

    private function owned(Character $character, int $id): CharacterMagicItem
    {
        $item = $this->em->find(CharacterMagicItem::class, $id);
        if (!$item || $item->getCharacter()?->getId() !== $character->getId()
            || $item->getCampaign()->getId() !== $character->getCampaign()->getId()) {
            throw new NotFoundHttpException('Objet indisponible.');
        }
        return $item;
    }
}
