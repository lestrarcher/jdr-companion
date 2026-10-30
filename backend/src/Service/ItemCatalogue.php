<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\{Campaign, CampaignItemVisibility, MagicItem, MagicItemAbilityEffect};
use App\Enum\{Ability, AbilityEffectOperation, MagicItemRarity, MagicItemRechargeType, ReferenceOrigin};
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\{EntityManagerInterface, QueryBuilder};
use Symfony\Component\HttpKernel\Exception\{NotFoundHttpException, UnprocessableEntityHttpException};

/** Campaign is always supplied explicitly. Public queries and direct access share one predicate. */
final readonly class ItemCatalogue
{
    public function __construct(private EntityManagerInterface $em) {}

    private function query(Campaign $campaign, bool $player): QueryBuilder
    {
        $query = $this->em->createQueryBuilder()->select('i')->from(MagicItem::class, 'i')
            ->leftJoin(CampaignItemVisibility::class, 'v', 'WITH', 'v.magicItem = i AND v.campaign = :campaign')
            ->where("((i.origin = :official AND i.owner IS NULL AND i.campaign IS NULL) OR (i.origin = :custom AND i.owner = :owner AND (i.campaign IS NULL OR i.campaign = :campaign)))")
            ->andWhere('i.archivedAt IS NULL')
            ->setParameter('campaign', $campaign)->setParameter('owner', $campaign->getOwner())
            ->setParameter('official', ReferenceOrigin::Official->value)->setParameter('custom', ReferenceOrigin::Custom->value);
        if ($player) {
            $query->andWhere("((i.origin = :official AND (v.id IS NULL OR v.decision <> 'hidden')) OR (i.origin = :custom AND v.decision = 'published'))");
        }
        return $query;
    }

    public function list(Campaign $campaign, bool $player, string $search = '', ?string $category = null, int $page = 1): array
    {
        if (mb_strlen($search) > 150 || $page < 1 || $page > 100000
            || ($category !== null && !in_array($category, ['gear','weapon','armor','shield','potion','scroll','other'], true))) {
            throw new UnprocessableEntityHttpException('Filtres de catalogue invalides.');
        }
        $query = $this->query($campaign, $player);
        if (trim($search) !== '') $query->andWhere('LOWER(i.name) LIKE :search')->setParameter('search', '%'.mb_strtolower(trim($search)).'%');
        if ($category !== null) $query->andWhere('i.category = :category')->setParameter('category', $category);
        $count = (int) (clone $query)->select('COUNT(i.id)')->getQuery()->getSingleScalarResult();
        $items = $query->orderBy('i.name', 'ASC')->addOrderBy('i.id', 'ASC')->setFirstResult(($page - 1) * 50)->setMaxResults(50)->getQuery()->getResult();
        return ['items' => array_map(fn (MagicItem $item) => $this->present($campaign, $item, $player), $items), 'total' => $count, 'page' => $page, 'pageSize' => 50];
    }

    public function requireDefinition(Campaign $campaign, int $id, bool $player): MagicItem
    {
        return $this->query($campaign, $player)->andWhere('i.id = :id')->setParameter('id', $id)->getQuery()->getOneOrNullResult()
            ?? throw new NotFoundHttpException('Définition introuvable.');
    }

    public function present(Campaign $campaign, MagicItem $item, bool $player): array
    {
        $result = [
            'id'=>$item->getId(), 'name'=>$item->getName(), 'description'=>$item->getDescription(),
            'origin'=>$item->getOrigin()->value, 'category'=>$item->getCategory(), 'magical'=>$item->isMagical(),
            'rarity'=>$item->getRarity()?->value, 'equippable'=>$item->isEquippable(),
            'quantityMode'=>$item->getQuantityMode(), 'equipmentSlot'=>$item->getEquipmentSlot(), 'handUsage'=>$item->getHandUsage(),
            'requiresAttunement'=>$item->requiresAttunement(), 'maximumCharges'=>$item->getMaximumCharges(),
            'rechargeType'=>$item->getRechargeType()->value, 'rechargeFormula'=>$item->getRechargeFormula(),
            'edition'=>$item->getEdition(), 'sourceReference'=>$item->getSourceReference(),
            'abilityEffects'=>array_map(static fn (MagicItemAbilityEffect $effect) => [
                'ability'=>$effect->getAbility()->value, 'operation'=>$effect->getOperation()->value,
                'value'=>$effect->getValue(), 'scoreCap'=>$effect->getScoreCap(), 'maximumIncrease'=>$effect->getMaximumIncrease(),
            ], $item->getAbilityEffects()->toArray()),
        ];
        if (!$player) {
            $decision = $this->em->getRepository(CampaignItemVisibility::class)->findOneBy(['campaign'=>$campaign, 'magicItem'=>$item])?->getDecision();
            $result['visibility'] = $decision ?? 'default';
            $result['visibleToPlayers'] = $item->getOrigin() === ReferenceOrigin::Official ? $decision !== 'hidden' : $decision === 'published';
            $result['campaignId'] = $item->getCampaign()?->getId();
        }
        return $result;
    }

    /** Serialize concurrent decisions for the same campaign, including the initial insert. */
    public function visibility(Campaign $campaign, int $id, string $decision): array
    {
        if (!in_array($decision, ['default','hidden','published'], true)) throw new UnprocessableEntityHttpException('Visibilité invalide.');
        $item = $this->requireDefinition($campaign, $id, false);
        if ($item->getOrigin() === ReferenceOrigin::Official && $decision === 'published') {
            throw new UnprocessableEntityHttpException('Un objet officiel est visible par défaut ; utilisez default ou hidden.');
        }
        return $this->em->wrapInTransaction(function () use ($campaign, $id, $decision): array {
            $this->em->lock($campaign, LockMode::PESSIMISTIC_WRITE);
            $item = $this->requireDefinition($campaign, $id, false);
            $record = $this->em->getRepository(CampaignItemVisibility::class)->findOneBy(['campaign'=>$campaign, 'magicItem'=>$item]);
            if ($record !== null) $this->em->refresh($record);
            if ($decision === 'default') {
                if ($record !== null) $this->em->remove($record);
            } elseif ($record === null) {
                $this->em->persist(new CampaignItemVisibility($campaign, $item, $decision));
            } else {
                $record->setDecision($decision);
            }
            $this->em->flush();
            return $this->present($campaign, $item, false);
        });
    }

    /** Only CUSTOM creation is available to a GM; origin and owner never come from the request. */
    public function create(Campaign $campaign, array $payload): MagicItem
    {
        $allowed = ['name','description','category','magical','rarity','equippable','equipmentSlot','handUsage','requiresAttunement','maximumCharges','rechargeType','rechargeFormula','abilityEffects'];
        if (array_diff(array_keys($payload), $allowed)) throw new UnprocessableEntityHttpException('Propriété de définition inconnue ou protégée.');
        try {
            $name = $this->text($payload['name'] ?? null, 150, false);
            $description = $this->text($payload['description'] ?? null, 50000);
            foreach (['magical','equippable','requiresAttunement'] as $field) {
                if (isset($payload[$field]) && !is_bool($payload[$field])) throw new \InvalidArgumentException('Un booléen est attendu.');
                if (array_key_exists($field, $payload) && $payload[$field] === null) throw new \InvalidArgumentException('Un booléen est attendu.');
            }
            $rarity = isset($payload['rarity']) ? MagicItemRarity::tryFrom($this->text($payload['rarity'], 20, false)) : null;
            if (isset($payload['rarity']) && $rarity === null) throw new \InvalidArgumentException('Rareté invalide.');
            $item = new MagicItem(null, $name, $rarity, $campaign->getOwner());
            $item->setDescription($description)->setRequiresAttunement($payload['requiresAttunement'] ?? false);
            $charges = $payload['maximumCharges'] ?? null;
            if ($charges !== null && !is_int($charges)) throw new \InvalidArgumentException('Charges invalides.');
            $item->setMaximumCharges($charges);
            $recharge = MagicItemRechargeType::tryFrom($this->text($payload['rechargeType'] ?? 'none', 20, false));
            if ($recharge === null) throw new \InvalidArgumentException('Recharge invalide.');
            $item->setRechargeType($recharge)->setRechargeFormula($this->text($payload['rechargeFormula'] ?? null, 50));
            $effects = $payload['abilityEffects'] ?? [];
            if (!is_array($effects) || !array_is_list($effects) || count($effects) > 20) throw new \InvalidArgumentException('Effets invalides.');
            foreach ($effects as $effect) {
                if (!is_array($effect) || array_diff(array_keys($effect), ['ability','operation','value','scoreCap','maximumIncrease'])
                    || !is_string($effect['ability'] ?? null) || !is_string($effect['operation'] ?? null)
                    || !is_int($effect['value'] ?? null) || (isset($effect['scoreCap']) && !is_int($effect['scoreCap']))
                    || (array_key_exists('maximumIncrease', $effect) && !is_int($effect['maximumIncrease']))) throw new \InvalidArgumentException('Effet invalide.');
                $ability = Ability::tryFrom($effect['ability']); $operation = AbilityEffectOperation::tryFrom($effect['operation']);
                if ($ability === null || $operation === null) throw new \InvalidArgumentException('Effet invalide.');
                $item->addAbilityEffect((new MagicItemAbilityEffect($item, $ability, $operation, $effect['value']))
                    ->setScoreCap($effect['scoreCap'] ?? null)->setMaximumIncrease($effect['maximumIncrease'] ?? 0));
            }
            $item->classify($this->text($payload['category'] ?? 'gear', 24, false), $payload['magical'] ?? false,
                $payload['equippable'] ?? false, $this->text($payload['equipmentSlot'] ?? 'free', 16, false), $this->text($payload['handUsage'] ?? null, 16));
        } catch (\InvalidArgumentException $exception) {
            throw new UnprocessableEntityHttpException($exception->getMessage(), $exception);
        }
        $this->em->persist($item); $this->em->flush();
        return $item;
    }

    /** Descriptive edits only: changing mechanics of existing possessions is outside this stage. */
    public function describe(Campaign $campaign, int $id, array $payload): MagicItem
    {
        $item = $this->requireDefinition($campaign, $id, false);
        if ($item->getOrigin() !== ReferenceOrigin::Custom) throw new NotFoundHttpException('Définition modifiable introuvable.');
        if ($payload === [] || array_diff(array_keys($payload), ['name','description'])) throw new UnprocessableEntityHttpException('Seuls le nom et la description sont modifiables.');
        try {
            $name = array_key_exists('name', $payload) ? $this->text($payload['name'], 150, false) : $item->getName();
            $description = array_key_exists('description', $payload) ? $this->text($payload['description'], 50000) : $item->getDescription();
        } catch (\InvalidArgumentException $exception) {
            throw new UnprocessableEntityHttpException($exception->getMessage(), $exception);
        }
        $item->setName($name)->setDescription($description); $this->em->flush();
        return $item;
    }

    private function text(mixed $value, int $maximum, bool $nullable = true): ?string
    {
        if ($value === null && $nullable) return null;
        if (!is_string($value) || mb_strlen($value) > $maximum || (!$nullable && trim($value) === '')) throw new \InvalidArgumentException('Texte invalide.');
        return trim($value) === '' ? null : trim($value);
    }
}
