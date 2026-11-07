<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Character;
use App\Entity\CharacterMagicItem;
use App\Entity\CharacterMagicItemSpellUsage;
use App\Enum\MagicItemRechargeType;
use Doctrine\ORM\EntityManagerInterface;

/** Applies an explicit recharge event; callers own the transaction and authorization. */
final readonly class MagicItemRechargeService
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    /** @return array{items:int, grants:int} */
    public function apply(Character $character, string $event): array
    {
        if (!in_array($event, ['dawn', 'short-rest', 'long-rest'], true)) {
            throw new \InvalidArgumentException('Événement de recharge inconnu.');
        }
        $items = 0;
        $grants = 0;
        foreach ($character->getMagicItems() as $owned) {
            $definition = $owned->getMagicItem();
            if ($definition === null) continue;
            $type = $definition->getRechargeType();
            $recharge = match ($event) {
                'dawn' => $type === MagicItemRechargeType::Daily,
                'short-rest' => $type === MagicItemRechargeType::ShortRest,
                'long-rest' => in_array($type, [MagicItemRechargeType::ShortRest, MagicItemRechargeType::LongRest], true),
            };
            if ($recharge && $owned->getCurrentCharges() !== null) {
                $maximum = $definition->getMaximumCharges();
                if ($maximum !== null) {
                    $formula = $definition->getRechargeFormula();
                    $next = $formula === null ? $maximum : min($maximum, $owned->getCurrentCharges() + self::roll($formula));
                    $owned->setCurrentCharges($next);
                    ++$items;
                }
            }
            foreach ($definition->getSpellGrants() as $grant) {
                if ($grant->getCostMode() !== 'limited') continue;
                $trigger = $grant->getResetTrigger();
                if ($trigger !== $event && !($event === 'long-rest' && $trigger === 'short-rest')) continue;
                $usage = $this->entityManager->getRepository(CharacterMagicItemSpellUsage::class)
                    ->findOneBy(['ownedItem' => $owned, 'grant' => $grant]);
                if ($usage !== null) {
                    $usage->reset();
                    ++$grants;
                }
            }
        }
        return ['items' => $items, 'grants' => $grants];
    }

    /** Supported formulae: 1d6, 1d6+1, 2d4-1, or a fixed positive integer. */
    public static function roll(string $formula): int
    {
        $formula = strtolower(preg_replace('/\s+/', '', $formula) ?? '');
        if (preg_match('/^\d{1,3}$/D', $formula)) return (int) $formula;
        if (!preg_match('/^(\d{1,2})d(\d{1,3})([+-]\d{1,3})?$/D', $formula, $matches)) {
            throw new \InvalidArgumentException('Formule de recharge invalide.');
        }
        $count = (int) $matches[1];
        $faces = (int) $matches[2];
        if ($count < 1 || $count > 20 || $faces < 2 || $faces > 100) {
            throw new \InvalidArgumentException('Dés de recharge invalides.');
        }
        $result = (int) ($matches[3] ?? 0);
        for ($i = 0; $i < $count; ++$i) $result += random_int(1, $faces);
        return max(0, $result);
    }
}
