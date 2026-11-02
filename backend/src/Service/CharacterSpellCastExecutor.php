<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CharacterSessionState;
use App\Entity\CharacterMagicItem;
use App\Entity\MagicItemSpellGrant;
use App\Entity\CharacterMagicItemSpellUsage;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/** Single transaction boundary for casting costs, effects and replay receipts. */
final readonly class CharacterSpellCastExecutor
{
    public function __construct(
        private EntityManagerInterface $em,
        private CharacterSpellCastCommandValidator $validator,
        private CharacterSpellHitPointEffects $effects,
    ) {}

    /**
     * Owns the transaction; call with a managed session and no pending unrelated writes.
     * @return array{cast:array,revision:int,state:array,replayed:bool} Returned only after commit.
     */
    public function execute(CharacterSessionState $session, array $command): array
    {
        $db = $this->em->getConnection();
        if ($db->isTransactionActive()) {
            throw new \LogicException('Spell execution must own its transaction.');
        }
        $mutated = false;
        $db->beginTransaction();
        try {
            $result = (function () use ($session, $command, &$mutated): array {
                try {
                    $command = SpellCastReceipts::normalizeCommand($command);
                } catch (\InvalidArgumentException $error) {
                    throw new UnprocessableEntityHttpException('invalid_command', $error);
                }
                $this->effects->lockCharacters($session, $command);
                PlayerCharacterAccess::lockForMutation($this->em, $session);
                $state = $session->getState();
                try {
                    $receiptStatus = SpellCastReceipts::compare($state, $command);
                } catch (\UnexpectedValueException $error) {
                    throw new ConflictHttpException($error->getMessage(), $error);
                }
                if ($receiptStatus === 'conflict') {
                    throw new ConflictHttpException('request_id_conflict');
                }
                if ($receiptStatus === 'identical') {
                    return [
                        'cast'=>$state[SpellCastReceipts::STATE_KEY][$command['requestId']]['cast'],
                        'revision'=>$session->getRevision(),
                        'state'=>$state,
                        'replayed'=>true,
                    ];
                }
                $revision = $command['revision'];
                if ($session->getRevision() !== $revision) {
                    throw new ConflictHttpException('stale_revision');
                }
                $this->em->lock($session, LockMode::OPTIMISTIC, $revision);
                $validation = $this->validator->validate($session->getCharacter(), $state, $command);
                if ($validation['valid'] !== true) {
                    throw new UnprocessableEntityHttpException($validation['reason'] ?? 'cast_not_allowed');
                }
                $effect = $this->effects->prepare($session, $command, $validation);
                $method = $validation['method'];
                if (in_array($method, ['standard-slot', 'pact-slot', 'resource', 'arcanum'], true)) {
                    $slug = $validation['poolSlug'];
                    $cost = $validation['cost'];
                    $expectedSlug = $method === 'standard-slot' ? 'spell-slot-'.$validation['castingLevel'] : 'warlock-pact-slots';
                    if ($method === 'arcanum') {
                        if (($validation['source']['sourceType'] ?? null) !== 'class'
                            || $validation['castingLevel'] < 6 || $validation['castingLevel'] > 9) {
                            throw new UnprocessableEntityHttpException('invalid_cast_cost');
                        }
                        $expectedSlug = 'mystic-arcanum-'.$validation['castingLevel'].'-use';
                    }
                    if ($method === 'resource'
                        ? (($validation['source']['sourceType'] ?? null) !== 'feature'
                            || !is_string($slug) || $slug === '' || str_starts_with($slug, 'spell-slot-') || $slug === 'warlock-pact-slots'
                            || !is_int($cost) || $cost < 1 || $cost > 2147483647)
                        : ($slug !== $expectedSlug || $cost !== 1)) {
                        throw new UnprocessableEntityHttpException('invalid_cast_cost');
                    }
                    $indices = [];
                    foreach ($state['resources'] ?? [] as $index => $resource) {
                        if (is_array($resource) && ($resource['id'] ?? null) === $slug) $indices[] = $index;
                    }
                    if (count($indices) !== 1) throw new UnprocessableEntityHttpException('invalid_counter');
                    $index = $indices[0];
                    $remaining = $state['resources'][$index]['currentValue'] ?? null;
                    if (!is_int($remaining) || $remaining < $cost) throw new UnprocessableEntityHttpException('invalid_counter');
                    $state['resources'][$index]['currentValue'] = $remaining - $cost;
                } elseif ($method === 'scroll') {
                    if (($validation['source']['sourceType'] ?? null) !== 'item'
                        || $validation['poolSlug'] !== null || $validation['cost'] !== 1
                        || !preg_match('/^scroll:([1-9][0-9]*)$/', $command['sourceKey'], $parts)) {
                        throw new UnprocessableEntityHttpException('invalid_scroll_cast');
                    }
                    $owned = $this->em->find(CharacterMagicItem::class, (int)$parts[1], LockMode::PESSIMISTIC_WRITE);
                    if ($owned === null || $owned->getCharacter()->getId() !== $session->getCharacter()->getId()
                        || $owned->getMagicItem()->getImportKey() !== 'srd-5.1:magic-item:spell-scroll'
                        || $owned->getInscribedSpell()?->getId() !== $validation['source']['spellId']
                        || $owned->getQuantity() !== 1) {
                        throw new UnprocessableEntityHttpException('invalid_scroll_cast');
                    }
                    $this->em->remove($owned);
                } elseif (in_array($method, ['item-charges', 'item-limited'], true)) {
                    if (($validation['source']['sourceType'] ?? null) !== 'item'
                        || $validation['poolSlug'] !== null || $validation['cost'] < 1
                        || !preg_match('/^item:([1-9][0-9]*):([1-9][0-9]*)$/', $command['sourceKey'], $parts)) {
                        throw new UnprocessableEntityHttpException('invalid_item_cast');
                    }
                    $owned = $this->em->find(CharacterMagicItem::class, (int)$parts[1], LockMode::PESSIMISTIC_WRITE);
                    $grant = $this->em->find(MagicItemSpellGrant::class, (int)$parts[2]);
                    if ($owned === null || $grant === null || $owned->getCharacter()->getId() !== $session->getCharacter()->getId()
                        || $owned->getMagicItem()->getId() !== $grant->getMagicItem()->getId()
                        || $grant->getSpell()->getId() !== $validation['source']['spellId']
                        || $owned->getQuantity() !== 1
                        || ($grant->getCostMode() === 'charges' ? 'item-charges' : 'item-limited') !== $method) {
                        throw new UnprocessableEntityHttpException('invalid_item_cast');
                    }
                    $active = match ($grant->getActivation()) {
                        'possession' => true, 'equipped' => $owned->isEquipped(),
                        'attuned' => $owned->isAttuned(), default => false,
                    };
                    try {
                        $expectedCost = $grant->costAtLevel($validation['castingLevel']);
                    } catch (\InvalidArgumentException) {
                        throw new UnprocessableEntityHttpException('invalid_item_cast_level');
                    }
                    if (!$active || $expectedCost !== $validation['cost']) {
                        throw new UnprocessableEntityHttpException('item_inactive');
                    }
                    if ($method === 'item-charges') {
                        if (($owned->getCurrentCharges() ?? -1) < $expectedCost) {
                            throw new UnprocessableEntityHttpException('insufficient_item_charges');
                        }
                        $owned->adjustCharges(-$expectedCost);
                    } else {
                        $usage = $this->em->getRepository(CharacterMagicItemSpellUsage::class)
                            ->findOneBy(['ownedItem'=>$owned, 'grant'=>$grant]);
                        if ($usage === null) {
                            $usage = new CharacterMagicItemSpellUsage($owned, $grant);
                            $this->em->persist($usage);
                        } else {
                            $this->em->lock($usage, LockMode::PESSIMISTIC_WRITE);
                        }
                        if ($usage->getRemaining() < $grant->getCost()) {
                            throw new UnprocessableEntityHttpException('insufficient_item_uses');
                        }
                        $usage->spend();
                    }
                } elseif (str_starts_with($command['sourceKey'], 'pact-tome:')) {
                    if (!in_array($method, ['cantrip', 'ritual'], true)
                        || $validation['poolSlug'] !== null || $validation['cost'] !== 0
                        || !preg_match('/^pact-tome:([1-9][0-9]*):([1-9][0-9]*)$/', $command['sourceKey'], $parts)
                        || ($validation['source']['sourceType'] ?? null) !== 'item'
                        || (int)$parts[2] !== $validation['source']['spellId']) {
                        throw new UnprocessableEntityHttpException('invalid_pact_tome_cast');
                    }
                    $book = $this->em->find(CharacterMagicItem::class, (int)$parts[1], LockMode::PESSIMISTIC_WRITE);
                    $entries = $book === null ? [] : $this->em->getRepository(\App\Entity\PactTomeEntry::class)->findBy(['book'=>$book]);
                    $valid = false;
                    foreach ($entries as $entry) {
                        if ($entry->getSpell()->getId() === (int)$parts[2] && $entry->getKind() === $method) $valid = true;
                    }
                    if ($book === null || $book->getCharacter()?->getId() !== $session->getCharacter()->getId()
                        || !$valid) throw new UnprocessableEntityHttpException('pact_tome_unavailable');
                } elseif (!in_array($method, ['cantrip', 'ritual'], true)
                    || $validation['poolSlug'] !== null || $validation['cost'] !== 0) {
                    throw new UnprocessableEntityHttpException('invalid_cast_cost');
                }
                $cast = array_intersect_key($validation, array_flip(['source','method','castingLevel','poolSlug','cost']));
                $state[SpellCastReceipts::STATE_KEY][$command['requestId']] = SpellCastReceipts::create($command, $cast);
                $mutated = true;
                // The receipt makes zero-cost acceptance a durable state change too.
                $session->setState($state);
                // Apply after setting cost + receipt, so self-targeting preserves both.
                $this->effects->apply($session, $effect);
                $this->em->flush();
                return [
                    'cast'=>$cast,
                    'revision'=>$session->getRevision(),
                    'state'=>$session->getState(),
                    'replayed'=>false,
                ];
            })();
            $db->commit();
            return $result;
        } catch (\Throwable $error) {
            // A failed COMMIT may already have ended the database transaction.
            if ($db->isTransactionActive()) $db->rollBack();
            // Never leave a failed flush/commit's mutated objects reusable.
            if ($mutated && $this->em->isOpen()) $this->em->close();
            throw $error;
        }
    }
}
