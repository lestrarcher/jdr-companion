<?php

declare(strict_types=1);
namespace App\Service;

use App\Entity\{Character, CharacterMagicItem, CharacterSessionState};
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\{ConflictHttpException, NotFoundHttpException, UnprocessableEntityHttpException};

/** Infusion lifecycle and rest authorization; never changes definitions or equipment. */
final readonly class ItemInfusions
{
    public function __construct(private EntityManagerInterface $em, private FeatureChoiceReader $choices, private CharacterFeatureResolver $features) {}

    public function known(Character $creator): array
    {
        $result = $this->choices->evaluate($creator, $this->features->applicableRules($creator, [], false));
        $known = [];
        foreach ($result['valid'] as $index => $option) {
            if ($option['group_key'] !== 'artificer-infusions') continue;
            $key = $result['selections'][$index]['key'];
            $known[$key] = ['choiceKey'=>$key, 'featureKey'=>$option['featureKey'], 'name'=>$option['name'],
                'mode'=>str_starts_with($option['featureKey'], 'artificer-infusion-replicate-') ? 'create-object' : 'existing-item'];
        }
        return $known;
    }

    public function classLevel(Character $creator): int
    {
        $level = 0;
        foreach ($creator->getClassLevels() as $row) if ($row->getCharacterClass()->getSlug() === 'artificer') ++$level;
        return $level;
    }

    public static function quota(int $level): int
    {
        return match (true) { $level >= 18 => 6, $level >= 14 => 5, $level >= 10 => 4, $level >= 6 => 3, $level >= 2 => 2, default => 0 };
    }

    public function eligible(Character $character): bool
    {
        if ($this->classLevel($character) < 2) return false;
        foreach ($this->features->resolve($character) as $rule) {
            if ($rule->getFeatureDefinition()->getSlug() === 'infusions-known') return true;
        }
        return false;
    }

    /** Called only by the approved-rest path, with its explicit session state. */
    public function afterLongRest(Character $character, array $state): array
    {
        $state['infusionPreparation'] = ['id'=>bin2hex(random_bytes(16)), 'open'=>$this->eligible($character)];
        return $state;
    }

    public function preparation(CharacterSessionState $session): array
    {
        $phase = $session->getState()['infusionPreparation'] ?? [];
        return ['id'=>$phase['id'] ?? null, 'open'=>($phase['open'] ?? false) === true && $this->eligible($session->getCharacter())];
    }

    private function requirePreparation(CharacterSessionState $session): void
    {
        if (!$this->preparation($session)['open']) throw new ConflictHttpException('Les imprégnations sont verrouillées jusqu’au prochain repos long validé.');
    }

    public function finishPreparation(CharacterSessionState $actor, string $phaseId, ?array $selections = null): array
    {
        // Canonical keys, but preserve list order: it determines FIFO for new applications.
        if ($selections !== null) {
            if (!array_is_list($selections) || count($selections) > 100) throw new UnprocessableEntityHttpException('Liste d’imprégnations invalide (100 maximum).');
            foreach ($selections as &$entry) {
                if (!is_array($entry) || count($entry) !== 3
                    || !is_string($entry['choiceKey'] ?? null) || !preg_match('/^[a-f0-9]{32}$/D', $entry['choiceKey'])
                    || !is_int($entry['recipientId'] ?? null) || $entry['recipientId'] < 1
                    || !is_int($entry['itemId'] ?? null) || $entry['itemId'] < 1) throw new UnprocessableEntityHttpException('Chaque sélection exige choiceKey, recipientId et itemId valides.');
                $entry = ['choiceKey'=>$entry['choiceKey'], 'recipientId'=>$entry['recipientId'], 'itemId'=>$entry['itemId']];
            }
            unset($entry);
        }
        $hash = hash('sha256', json_encode($selections, JSON_THROW_ON_ERROR));
        return $this->em->getConnection()->transactional(function () use ($actor, $phaseId, $selections, $hash) {
            PlayerCharacterAccess::lockForMutation($this->em, $actor);
            $phase = $this->preparation($actor);
            if ($phase['id'] !== $phaseId) throw new ConflictHttpException('La phase de préparation a changé. Rechargez le personnage.');
            $state = $actor->getState();
            if (!$phase['open']) {
                if (($state['infusionPreparation']['confirmedHash'] ?? null) === $hash) {
                    return $this->view($actor->getCharacter()) + ['preparation'=>$phase, 'replayed'=>true, 'evicted'=>$state['infusionPreparation']['evicted'] ?? []];
                }
                $this->requirePreparation($actor);
            }
            $creator = $actor->getCharacter();
            $db = $this->em->getConnection();
            $rows = $db->fetchAllAssociative('SELECT i.*, c.character_id AS recipient_id FROM character_item_infusion i JOIN character_magic_item c ON c.id=i.item_id WHERE i.creator_id=? AND i.active ORDER BY i.created_at,i.id', [$creator->getId()]);
            $desired = $selections ?? array_map(static fn (array $row) => ['choiceKey'=>$row['choice_key'], 'recipientId'=>(int)$row['recipient_id'], 'itemId'=>(int)$row['item_id']], $rows);
            $known = $this->known($creator);
            $choices = []; $supports = [];
            foreach ($desired as $entry) {
                if (isset($choices[$entry['choiceKey']])) throw new UnprocessableEntityHttpException('Une formule ne peut être appliquée qu’une fois.');
                if (isset($supports[$entry['itemId']])) throw new UnprocessableEntityHttpException('Un objet ne peut recevoir qu’une imprégnation.');
                if (!isset($known[$entry['choiceKey']])) throw new ConflictHttpException('Imprégnation non acquise ou prérequis non satisfaits.');
                $choices[$entry['choiceKey']] = $entry;
                $supports[$entry['itemId']] = true;
            }
            // Shared supports are locked in ascending order, before any infusion row mutation.
            $ids = array_unique([...array_keys($supports), ...array_map('intval', array_column($rows, 'item_id'))]);
            sort($ids, SORT_NUMERIC);
            $items = [];
            foreach ($ids as $id) {
                $item = $this->em->find(CharacterMagicItem::class, $id);
                if ($item === null) throw new ConflictHttpException('Un objet a disparu. Rechargez les imprégnations.');
                $this->em->lock($item, LockMode::PESSIMISTIC_WRITE);
                $this->em->refresh($item);
                $items[$id] = $item;
            }
            $retained = []; $new = [];
            foreach ($desired as $entry) {
                $item = $items[$entry['itemId']];
                $recipient = $this->recipient($creator, $entry['recipientId']);
                if ($item->getCharacter()?->getId() !== $recipient->getId() || $item->getCampaign()->getId() !== $creator->getCampaign()->getId()) throw new NotFoundHttpException('Objet indisponible pour ce destinataire.');
                if (($reason = $this->supportReason($known[$entry['choiceKey']], $item)) !== null) throw new ConflictHttpException($reason);
                $existing = $db->fetchAssociative('SELECT id, creator_id, choice_key FROM character_item_infusion WHERE item_id=? AND active', [$entry['itemId']]);
                if ($existing && (int)$existing['creator_id'] !== $creator->getId()) throw new ConflictHttpException('Objet déjà imprégné par un autre artificier.');
                if ($existing && $existing['choice_key'] === $entry['choiceKey']) $retained[(int)$existing['id']] = $entry;
                else $new[] = $entry;
            }
            $ordered = [];
            foreach ($rows as $row) if (isset($retained[(int)$row['id']])) $ordered[] = $retained[(int)$row['id']] + ['id'=>(int)$row['id']];
            $ordered = [...$ordered, ...$new];
            $quota = self::quota($this->classLevel($creator));
            $evicted = array_slice($ordered, 0, max(0, count($ordered) - $quota));
            $final = array_slice($ordered, max(0, count($ordered) - $quota));
            $keep = array_column($final, 'id');
            foreach ($rows as $row) {
                $id = (int)$row['id'];
                if (in_array($id, $keep, true)) continue;
                $reason = isset($retained[$id]) ? 'quota_replaced' : (isset($choices[$row['choice_key']]) ? 'formula_reapplied' : 'preparation_removed');
                $this->end($id, $reason);
            }
            $createdAt = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
            foreach ($final as $entry) {
                if (isset($entry['id'])) continue;
                $db->insert('character_item_infusion', ['creator_id'=>$creator->getId(), 'item_id'=>$entry['itemId'], 'choice_key'=>$entry['choiceKey'],
                    'item_name'=>$items[$entry['itemId']]->getDisplayName(), 'created_at'=>$createdAt, 'active'=>true], ['active'=>\Doctrine\DBAL\Types\Types::BOOLEAN]);
            }
            $state['infusionPreparation']['open'] = false;
            $state['infusionPreparation']['confirmedHash'] = $hash;
            $state['infusionPreparation']['evicted'] = $evicted;
            $actor->setState($state);
            $this->em->flush();
            return $this->view($creator) + ['preparation'=>$this->preparation($actor), 'replayed'=>false, 'evicted'=>$evicted];
        });
    }

    public function view(Character $creator): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative('SELECT i.*, c.character_id AS recipient_id FROM character_item_infusion i LEFT JOIN character_magic_item c ON c.id=i.item_id WHERE i.creator_id=? AND i.active ORDER BY i.created_at,i.id', [$creator->getId()]);
        return ['known'=>array_values($this->known($creator)), 'quota'=>self::quota($this->classLevel($creator)),
            'active'=>array_map(static fn (array $row) => ['id'=>(int)$row['id'], 'creatorId'=>(int)$row['creator_id'],
                'itemId'=>(int)$row['item_id'], 'itemName'=>$row['item_name'], 'recipientId'=>$row['recipient_id'] === null ? null : (int)$row['recipient_id'],
                'choiceKey'=>$row['choice_key'], 'createdAt'=>$row['created_at']], $rows)];
    }

    public function recipients(Character $creator): array
    {
        if (self::quota($this->classLevel($creator)) === 0) throw new ConflictHttpException('Niveau d’artificier insuffisant.');
        return array_map(static fn (Character $c) => ['id'=>$c->getId(), 'name'=>$c->getName()],
            $this->em->getRepository(Character::class)->findBy(['campaign'=>$creator->getCampaign()], ['name'=>'ASC']));
    }

    private function recipient(Character $creator, int $id): Character
    {
        $recipient = $this->em->find(Character::class, $id);
        if ($recipient === null || $recipient->getCampaign()->getId() !== $creator->getCampaign()->getId()) throw new NotFoundHttpException('Personnage indisponible dans cette campagne.');
        return $recipient;
    }

    public function items(Character $creator, int $recipientId, string $choiceKey): array
    {
        $recipient = $this->recipient($creator, $recipientId);
        $choice = $this->known($creator)[$choiceKey] ?? throw new ConflictHttpException('Imprégnation non acquise ou prérequis non satisfaits.');
        $items = [];
        foreach ($this->em->getRepository(CharacterMagicItem::class)->findBy(['character'=>$recipient]) as $item) {
            $reason = $this->supportReason($choice, $item);
            if ($reason === null && $this->em->getConnection()->fetchOne('SELECT 1 FROM character_item_infusion WHERE item_id=? AND active AND creator_id<>?', [$item->getId(), $creator->getId()])) $reason = 'Objet déjà imprégné par un autre artificier.';
            $items[] = ['id'=>$item->getId(), 'name'=>$item->getDisplayName(), 'eligible'=>$reason === null, 'reason'=>$reason];
        }
        return $items;
    }

    public function supportReason(array $choice, CharacterMagicItem $item): ?string
    {
        if ($choice['mode'] === 'create-object') return 'Création d’objet reproduit prévue au lot B4.';
        if ($item->getQuantity() !== 1 || $item->isLegacyStack()) return 'Séparez un exemplaire avant de l’imprégner.';
        $definition = $item->getMagicItem();
        if ($definition === null) return 'Support physique non identifié.';
        if ($definition->isMagical()) return 'Un objet magique ne peut pas être imprégné.';
        // Only explicit catalogue categories are reliable. No guesses from names or prose.
        $categories = match ($choice['featureKey']) {
            'artificer-infusion-enhanced-defense' => ['armor', 'shield'],
            'artificer-infusion-armor-of-magical-strength', 'artificer-infusion-resistant-armor',
            'artificer-infusion-arcane-propulsion-armor', 'artificer-infusion-mind-sharpener' => ['armor'],
            'artificer-infusion-repulsion-shield' => ['shield'],
            default => null,
        };
        if ($categories === null) return 'Propriétés du support insuffisantes pour valider cette imprégnation.';
        return in_array($definition->getCategory(), $categories, true) ? null : 'Support incompatible avec cette imprégnation.';
    }

    public function apply(CharacterSessionState $actor, string $choiceKey, int $recipientId, int $itemId): array
    {
        return $this->em->getConnection()->transactional(function () use ($actor, $choiceKey, $recipientId, $itemId) {
            PlayerCharacterAccess::lockForMutation($this->em, $actor);
            $this->requirePreparation($actor);
            $creator = $actor->getCharacter();
            $recipient = $this->recipient($creator, $recipientId);
            $choice = $this->known($creator)[$choiceKey] ?? throw new ConflictHttpException('Imprégnation non acquise ou prérequis non satisfaits.');
            $quota = self::quota($this->classLevel($creator));
            if (!$quota) throw new ConflictHttpException('Niveau d’artificier insuffisant.');
            $item = $this->em->find(CharacterMagicItem::class, $itemId);
            if ($item === null) throw new NotFoundHttpException('Objet indisponible.');
            $this->em->lock($item, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($item);
            if ($item->getCharacter()?->getId() !== $recipient->getId() || $item->getCampaign()->getId() !== $creator->getCampaign()->getId()) throw new NotFoundHttpException('Objet indisponible pour ce destinataire.');
            if (($reason = $this->supportReason($choice, $item)) !== null) throw new ConflictHttpException($reason);
            $db = $this->em->getConnection();
            $existing = $db->fetchAssociative('SELECT choice_key,creator_id FROM character_item_infusion WHERE active AND item_id=?', [$itemId]);
            if ($existing && $existing['choice_key'] === $choiceKey && (int)$existing['creator_id'] === $creator->getId()) return $this->view($creator);
            if ($existing) throw new ConflictHttpException('Objet déjà imprégné.');
            $rows = $db->fetchAllAssociative('SELECT id,choice_key FROM character_item_infusion WHERE creator_id=? AND active ORDER BY created_at,id FOR UPDATE', [$creator->getId()]);
            $known = $this->known($creator);
            $valid = [];
            foreach ($rows as $row) {
                if ($row['choice_key'] === $choiceKey) $this->end((int)$row['id'], 'formula_reapplied');
                elseif (!isset($known[$row['choice_key']])) $this->end((int)$row['id'], 'choice_lost');
                else $valid[] = $row;
            }
            while (count($valid) >= $quota) $this->end((int)array_shift($valid)['id'], 'quota_replaced');
            $db->insert('character_item_infusion', ['creator_id'=>$creator->getId(), 'item_id'=>$itemId, 'choice_key'=>$choiceKey,
                'item_name'=>$item->getDisplayName(), 'created_at'=>(new \DateTimeImmutable())->format('Y-m-d H:i:s'), 'active'=>true], ['active'=>\Doctrine\DBAL\Types\Types::BOOLEAN]);
            return $this->view($creator);
        });
    }

    public function remove(CharacterSessionState $actor, int $id): array
    {
        return $this->em->getConnection()->transactional(function () use ($actor, $id) {
            PlayerCharacterAccess::lockForMutation($this->em, $actor);
            $db = $this->em->getConnection();
            if (!$db->fetchOne('SELECT 1 FROM character_item_infusion WHERE id=? AND creator_id=?', [$id,$actor->getCharacter()->getId()])) throw new NotFoundHttpException('Imprégnation indisponible.');
            $this->requirePreparation($actor);
            $this->end($id, 'voluntary');
            return $this->view($actor->getCharacter());
        });
    }

    /** Central retirement primitive; a future game-clock job can supply a death-expiry reason. */
    private function end(int $id, string $reason): void
    {
        $this->em->getConnection()->executeStatement('UPDATE character_item_infusion SET active=FALSE, ended_at=CURRENT_TIMESTAMP, end_reason=? WHERE id=? AND active', [$reason,$id]);
    }
}
