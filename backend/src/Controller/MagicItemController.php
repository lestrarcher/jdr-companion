<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Campaign;
use App\Entity\MagicItem;
use App\Entity\MagicItemAbilityEffect;
use App\Entity\MagicItemSpellGrant;
use App\Entity\SpellDefinition;
use App\Entity\User;
use App\Enum\Ability;
use App\Enum\AbilityEffectOperation;
use App\Enum\MagicItemRarity;
use App\Enum\MagicItemRechargeType;
use App\Repository\CampaignRepository;
use App\Service\ItemCatalogue;
use App\Service\SpellCatalogue;
use App\Security\Voter\CampaignVoter;
use Doctrine\ORM\EntityManagerInterface;
use JsonException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class MagicItemController
    extends AbstractController
{
    #[Route('/campaigns/{campaignId}/magic-items/{itemId}/armor-profile', name: 'api_campaign_item_armor_profile', requirements: ['campaignId' => '\d+', 'itemId' => '\d+'], methods: ['PUT'])]
    public function armorProfile(int $campaignId, int $itemId, Request $request, CampaignRepository $campaignRepository, EntityManagerInterface $entityManager): JsonResponse
    {
        $campaign = $this->getOwnedCampaign($campaignId, $campaignRepository);
        $item = $entityManager->find(MagicItem::class, $itemId);
        if (!$item || $item->getCampaign()?->getId() !== $campaign->getId()) {
            throw $this->createNotFoundException('Objet de campagne introuvable.');
        }
        try { $payload = $request->toArray(); } catch (JsonException) {
            return $this->json(['message' => 'JSON invalide.'], 400);
        }
        try {
            if (array_diff(array_keys($payload), ['armorType', 'baseArmorClass']) !== []) {
                throw new \InvalidArgumentException('Propriétés de profil invalides.');
            }
            $this->configureArmor($item, $payload);
        } catch (\InvalidArgumentException $error) {
            return $this->json(['message' => $error->getMessage()], 422);
        }
        $entityManager->flush();
        return $this->json(['item' => $this->serializeItem($item)]);
    }

    private function configureArmor(MagicItem $item, array $payload): void
    {
        $type = $payload['armorType'] ?? null;
        $base = $payload['baseArmorClass'] ?? null;
        if (($type !== null && !is_string($type)) || ($base !== null && !is_int($base))) {
            throw new \InvalidArgumentException('Type d’armure et CA de base invalides.');
        }
        $item->configureArmor($type, $base);
    }

    #[Route('/dnd/items', name: 'api_official_item_reference', methods: ['GET'])]
    public function officialReference(EntityManagerInterface $entityManager): JsonResponse
    {
        $items = $entityManager->getRepository(MagicItem::class)->findBy(
            ['origin' => \App\Enum\ReferenceOrigin::Official, 'campaign' => null, 'owner' => null],
            ['name' => 'ASC'],
        );
        return $this->json(['items' => array_map(static fn (MagicItem $item): array => [
            'id' => $item->getId(),
            'name' => $item->getName(),
            'englishName' => $item->getEnglishName(),
            'armorClassEffects' => $item->getArmorClassEffects(),
            'armorProfile' => $item->getArmorProfile(),
            'description' => $item->getDescription(),
            'category' => $item->getCategory(),
            'rarity' => $item->getRarity()?->value,
            'priceCopper' => $item->getPriceCopper(),
            'weightGrams' => $item->getWeightGrams(),
        ], $items)]);
    }

    #[Route(
        '/campaigns/{campaignId}/magic-items',
        name: 'api_campaign_magic_item_list',
        requirements: [
            'campaignId' => '\d+',
        ],
        methods: ['GET'],
    )]
    public function list(
        int $campaignId,
        CampaignRepository $campaignRepository,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        $campaign = $this->getOwnedCampaign(
            $campaignId,
            $campaignRepository,
        );

        $items = $entityManager
            ->getRepository(MagicItem::class)
            ->findBy(
                ['campaign' => $campaign],
                ['name' => 'ASC'],
            );

        return $this->json([
            'items' => array_map(
                fn (MagicItem $item): array =>
                    $this->serializeItem($item),
                $items,
            ),
        ]);
    }

    #[Route('/campaigns/{campaignId}/item-catalogue', name: 'api_gm_item_catalogue', requirements: ['campaignId' => '\d+'], methods: ['GET'])]
    public function itemCatalogue(int $campaignId, Request $request, CampaignRepository $campaignRepository, ItemCatalogue $catalogue): JsonResponse
    {
        $campaign = $this->getOwnedCampaign($campaignId, $campaignRepository);
        $page = filter_var($request->query->get('page', '1'), FILTER_VALIDATE_INT);
        if ($page === false || $page < 1) {
            return $this->json(['message' => 'Page invalide.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        return $this->json($catalogue->list($campaign, false, (string) $request->query->get('q', ''), null, $page));
    }

    #[Route(
        '/campaigns/{campaignId}/magic-items',
        name: 'api_campaign_magic_item_create',
        requirements: [
            'campaignId' => '\d+',
        ],
        methods: ['POST'],
    )]
    public function create(
        int $campaignId,
        Request $request,
        CampaignRepository $campaignRepository,
        EntityManagerInterface $entityManager,
        SpellCatalogue $spellCatalogue,
    ): JsonResponse {
        $campaign = $this->getOwnedCampaign(
            $campaignId,
            $campaignRepository,
        );

        try {
            $payload = $request->toArray();
        } catch (JsonException) {
            return $this->json(
                [
                    'message' =>
                        'Le corps JSON est invalide.',
                ],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $name = trim(
            (string) ($payload['name'] ?? ''),
        );

        $rarity = MagicItemRarity::tryFrom(
            (string) (
                $payload['rarity']
                ?? MagicItemRarity::Common->value
            ),
        );

        if ($name === '') {
            return $this->json(
                [
                    'message' =>
                        'Le nom de l’objet est obligatoire.',
                ],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        if (!$rarity) {
            return $this->json(
                [
                    'message' =>
                        'La rareté est invalide.',
                ],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $rechargeType =
            MagicItemRechargeType::tryFrom(
                (string) (
                    $payload['rechargeType']
                    ?? MagicItemRechargeType::None->value
                ),
            );

        if (!$rechargeType) {
            return $this->json(
                [
                    'message' =>
                        'Le type de recharge est invalide.',
                ],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $maximumCharges = null;

        if (
            array_key_exists(
                'maximumCharges',
                $payload,
            )
            && $payload['maximumCharges']
                !== null
            && $payload['maximumCharges']
                !== ''
        ) {
            $maximumCharges = filter_var(
                $payload['maximumCharges'],
                FILTER_VALIDATE_INT,
            );

            if (
                $maximumCharges === false
                || $maximumCharges <= 0
            ) {
                return $this->json(
                    [
                        'message' =>
                            'Le maximum de charges doit être un entier supérieur à zéro.',
                    ],
                    Response::HTTP_UNPROCESSABLE_ENTITY,
                );
            }
        }

        $effectsPayload =
            $payload['abilityEffects'] ?? [];

        if (!is_array($effectsPayload)) {
            return $this->json(
                [
                    'message' =>
                        'Les effets de caractéristiques doivent former une liste.',
                ],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $grantsPayload = $payload['spellGrants'] ?? [];
        if (!is_array($grantsPayload) || !array_is_list($grantsPayload) || count($grantsPayload) > 100) {
            return $this->json(['message' => 'Les octrois de sorts doivent former une liste (100 maximum).'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $item = new MagicItem(
                campaign: $campaign,
                name: $name,
                rarity: $rarity,
            );

            $item
                ->setDescription(
                    $this->nullableString(
                        $payload['description']
                        ?? null,
                    ),
                )
                ->setRequiresAttunement(
                    (bool) (
                        $payload[
                            'requiresAttunement'
                        ] ?? false
                    ),
                )
                ->setMaximumCharges(
                    $maximumCharges,
                )
                ->setRechargeType(
                    $rechargeType,
                )
                ->setRechargeFormula(
                    $this->nullableString(
                        $payload[
                            'rechargeFormula'
                        ] ?? null,
                    ),
                );

            $category = $payload['category'] ?? 'other';
            if (!is_string($category) || !in_array($category, ['other', 'spellbook', 'armor', 'shield'], true)) {
                throw new \InvalidArgumentException('Catégorie inconnue.');
            }
            if ($category === 'spellbook') {
                if ($maximumCharges !== null || ($payload['requiresAttunement'] ?? false) || $effectsPayload !== [] || $grantsPayload !== []) {
                    throw new \InvalidArgumentException('Un grimoire ordinaire ne possède ni charges, ni harmonisation, ni octrois de sorts.');
                }
                $item->classify('spellbook', false, false);
            }
            if (in_array($category, ['armor', 'shield'], true)) {
                $magical = $payload['magical'] ?? true;
                if (!is_bool($magical) || (!$magical && ($effectsPayload !== [] || $grantsPayload !== []))) {
                    throw new \InvalidArgumentException('Propriétés magiques incompatibles.');
                }
                $item->classify($category, $magical, true, $category === 'armor' ? 'armor' : 'hand', $category === 'shield' ? 'one' : null);
                $this->configureArmor($item, $payload);
            }
            foreach ($this->buildSpellGrants($item, $grantsPayload, $entityManager, $spellCatalogue) as $grant) {
                $item->addSpellGrant($grant);
            }

            foreach (
                $effectsPayload
                as $effectPayload
            ) {
                if (!is_array($effectPayload)) {
                    throw new \InvalidArgumentException(
                        'Un effet de caractéristique est invalide.',
                    );
                }

                $effect =
                    $this->createAbilityEffect(
                        $item,
                        $effectPayload,
                    );

                $item->addAbilityEffect(
                    $effect,
                );
            }
        } catch (\InvalidArgumentException $exception) {
            return $this->json(
                [
                    'message' =>
                        $exception->getMessage(),
                ],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $entityManager->persist($item);
        $entityManager->flush();

        return $this->json(
            [
                'item' =>
                    $this->serializeItem($item),
            ],
            Response::HTTP_CREATED,
        );
    }

    #[Route('/campaigns/{campaignId}/magic-items/{itemId}/spell-grants', name: 'api_campaign_magic_item_grants_replace', requirements: ['campaignId' => '\d+', 'itemId' => '\d+'], methods: ['PUT'])]
    public function replaceSpellGrants(
        int $campaignId,
        int $itemId,
        Request $request,
        CampaignRepository $campaignRepository,
        EntityManagerInterface $entityManager,
        SpellCatalogue $spellCatalogue,
    ): JsonResponse {
        $campaign = $this->getOwnedCampaign($campaignId, $campaignRepository);
        $item = $entityManager->find(MagicItem::class, $itemId);
        if (!$item || $item->getCampaign()?->getId() !== $campaign->getId()) {
            throw $this->createNotFoundException('Objet de campagne introuvable.');
        }
        try {
            $payload = $request->toArray();
        } catch (\Throwable) {
            return $this->json(['message' => 'JSON invalide.'], 400);
        }
        if (array_keys($payload) !== ['spellGrants']) {
            return $this->json(['message' => 'Seul spellGrants est accepté.'], 422);
        }
        try {
            $grants = $this->buildSpellGrants($item, $payload['spellGrants'], $entityManager, $spellCatalogue);
        } catch (\InvalidArgumentException $error) {
            return $this->json(['message' => $error->getMessage()], 422);
        }
        $entityManager->wrapInTransaction(function () use ($item, $grants, $entityManager): void {
            // Preserve unchanged grant IDs, so future per-instance usage counters remain stable.
            $existing = [];
            foreach ($item->getSpellGrants() as $grant) {
                $existing[$grant->getSpell()->getId()] = $grant;
            }
            foreach ($grants as $grant) {
                $spellId = $grant->getSpell()->getId();
                $previous = $existing[$spellId] ?? null;
                if ($previous && $previous->getActivation() === $grant->getActivation()
                    && $previous->getCostMode() === $grant->getCostMode()
                    && $previous->getCost() === $grant->getCost()
                    && $previous->getUsesPerReset() === $grant->getUsesPerReset()
                    && $previous->getResetTrigger() === $grant->getResetTrigger()) {
                    unset($existing[$spellId]);
                    continue;
                }
            }
            foreach ($existing as $old) {
                $item->removeSpellGrant($old);
                $entityManager->remove($old);
            }
            $keptIds = array_map(static fn (MagicItemSpellGrant $g) => $g->getSpell()->getId(), $item->getSpellGrants()->toArray());
            // Flush deletions first to respect the unique (item, spell) constraint when replacing a changed grant.
            $entityManager->flush();
            foreach ($grants as $grant) {
                if (!in_array($grant->getSpell()->getId(), $keptIds, true)) {
                    $item->addSpellGrant($grant);
                    $entityManager->persist($grant);
                }
            }
            $entityManager->flush();
        });
        return $this->json(['item' => $this->serializeItem($item)]);
    }

    /** @return list<MagicItemSpellGrant> */
    private function buildSpellGrants(MagicItem $item, mixed $payload, EntityManagerInterface $entityManager, SpellCatalogue $catalogue): array
    {
        if (!is_array($payload) || !array_is_list($payload) || count($payload) > 100) {
            throw new \InvalidArgumentException('Liste de sorts invalide (100 maximum).');
        }
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }
        $seen = [];
        $result = [];
        foreach ($payload as $row) {
            if (!is_array($row) || array_is_list($row) || array_diff(array_keys($row), ['spellId', 'activation', 'costMode', 'cost', 'usesPerReset', 'resetTrigger', 'castingLevel', 'maxCastingLevel'])) {
                throw new \InvalidArgumentException('Octroi de sort invalide.');
            }
            $id = $row['spellId'] ?? null;
            if (!is_int($id) || $id < 1 || isset($seen[$id])) {
                throw new \InvalidArgumentException('Sort invalide ou dupliqué.');
            }
            $seen[$id] = true;
            try {
                $catalogue->get($id, $user);
            } catch (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException) {
                throw new \InvalidArgumentException('Sort inaccessible.');
            }
            $spell = $entityManager->find(SpellDefinition::class, $id);
            if (!$spell) {
                throw new \InvalidArgumentException('Sort introuvable.');
            }
            $activation = $row['activation'] ?? null;
            $mode = $row['costMode'] ?? null;
            $cost = $row['cost'] ?? 1;
            $uses = $row['usesPerReset'] ?? null;
            $reset = $row['resetTrigger'] ?? null;
            $castingLevel = $row['castingLevel'] ?? null;
            $maxCastingLevel = $row['maxCastingLevel'] ?? null;
            if (!is_string($activation) || !is_string($mode) || !is_int($cost)
                || ($uses !== null && !is_int($uses)) || ($reset !== null && !is_string($reset))
                || ($castingLevel !== null && !is_int($castingLevel)) || ($maxCastingLevel !== null && !is_int($maxCastingLevel))) {
                throw new \InvalidArgumentException('Paramètres de sort invalides.');
            }
            $result[] = new MagicItemSpellGrant($item, $spell, $activation, $mode, $cost, $uses, $reset, $castingLevel, $maxCastingLevel);
        }
        return $result;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function createAbilityEffect(
        MagicItem $item,
        array $payload,
    ): MagicItemAbilityEffect {
        $ability = Ability::tryFrom(
            (string) (
                $payload['ability'] ?? ''
            ),
        );

        if (!$ability) {
            throw new \InvalidArgumentException(
                'La caractéristique ciblée est invalide.',
            );
        }

        $operation =
            AbilityEffectOperation::tryFrom(
                (string) (
                    $payload['operation'] ?? ''
                ),
            );

        if (!$operation) {
            throw new \InvalidArgumentException(
                'Le type d’effet est invalide.',
            );
        }

        $value = filter_var(
            $payload['value'] ?? null,
            FILTER_VALIDATE_INT,
        );

        if ($value === false) {
            throw new \InvalidArgumentException(
                'La valeur de l’effet doit être un entier.',
            );
        }

        $effect =
            new MagicItemAbilityEffect(
                magicItem: $item,
                ability: $ability,
                operation: $operation,
                value: $value,
            );

        if (
            array_key_exists(
                'scoreCap',
                $payload,
            )
            && $payload['scoreCap'] !== null
            && $payload['scoreCap'] !== ''
        ) {
            $scoreCap = filter_var(
                $payload['scoreCap'],
                FILTER_VALIDATE_INT,
            );

            if ($scoreCap === false) {
                throw new \InvalidArgumentException(
                    'La limite de caractéristique doit être un entier.',
                );
            }

            $effect->setScoreCap(
                $scoreCap,
            );
        }

        if (
            array_key_exists(
                'maximumIncrease',
                $payload,
            )
        ) {
            $maximumIncrease = filter_var(
                $payload['maximumIncrease'],
                FILTER_VALIDATE_INT,
            );

            if (
                $maximumIncrease === false
            ) {
                throw new \InvalidArgumentException(
                    'L’augmentation du maximum doit être un entier.',
                );
            }

            $effect->setMaximumIncrease(
                $maximumIncrease,
            );
        }

        return $effect;
    }

    private function getOwnedCampaign(
        int $campaignId,
        CampaignRepository $campaignRepository,
    ): Campaign {
        $campaign =
            $campaignRepository->find(
                $campaignId,
            );

        if (!$campaign instanceof Campaign) {
            throw $this
                ->createNotFoundException(
                    'Campagne introuvable.',
                );
        }

        $this->denyAccessUnlessGranted(
            CampaignVoter::MANAGE,
            $campaign,
        );

        return $campaign;
    }

    private function nullableString(
        mixed $value,
    ): ?string {
        if ($value === null) {
            return null;
        }

        $value = trim(
            (string) $value,
        );

        return $value !== ''
            ? $value
            : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeItem(
        MagicItem $item,
    ): array {
        return [
            'id' => $item->getId(),
            'campaignId' =>
                $item
                    ->getCampaign()
                    ?->getId(),
            'name' => $item->getName(),
            'category' => $item->getCategory(),
            'armorType' => $item->getArmorType(),
            'baseArmorClass' => $item->getBaseArmorClass(),
            'armorClassEffects' => $item->getArmorClassEffects(),
            'armorProfile' => $item->getArmorProfile(),
            'quantityMode' => $item->getQuantityMode(),
            'description' =>
                $item->getDescription(),
            'rarity' =>
                $item->getRarity()?->value,
            'rarityLabel' =>
                $item->getRarity()?->label(),
            'requiresAttunement' =>
                $item->requiresAttunement(),
            'maximumCharges' =>
                $item->getMaximumCharges(),
            'rechargeType' =>
                $item
                    ->getRechargeType()
                    ->value,
            'rechargeLabel' =>
                $item
                    ->getRechargeType()
                    ->label(),
            'rechargeFormula' =>
                $item->getRechargeFormula(),

            'abilityEffects' =>
                array_map(
                    static fn (
                        MagicItemAbilityEffect $effect,
                    ): array => [
                        'id' =>
                            $effect->getId(),
                        'ability' =>
                            $effect
                                ->getAbility()
                                ->value,
                        'abilityLabel' =>
                            $effect
                                ->getAbility()
                                ->label(),
                        'operation' =>
                            $effect
                                ->getOperation()
                                ->value,
                        'operationLabel' =>
                            $effect
                                ->getOperation()
                                ->label(),
                        'value' =>
                            $effect->getValue(),
                        'scoreCap' =>
                            $effect
                                ->getScoreCap(),
                        'maximumIncrease' =>
                            $effect
                                ->getMaximumIncrease(),
                    ],
                    $item
                        ->getAbilityEffects()
                        ->toArray(),
                ),

            'spellGrants' => array_map(
                static fn (MagicItemSpellGrant $grant): array => [
                    'id' => $grant->getId(),
                    'spellId' => $grant->getSpell()->getId(),
                    'spellName' => $grant->getSpell()->getName(),
                    'spellLevel' => $grant->getSpell()->getLevel(),
                    'activation' => $grant->getActivation(),
                    'costMode' => $grant->getCostMode(),
                    'cost' => $grant->getCost(),
                    'castingLevel' => $grant->getCastingLevel(),
                    'maxCastingLevel' => $grant->getMaxCastingLevel(),
                    'usesPerReset' => $grant->getUsesPerReset(),
                    'resetTrigger' => $grant->getResetTrigger(),
                ],
                $item->getSpellGrants()->toArray(),
            ),

            'createdAt' =>
                $item
                    ->getCreatedAt()
                    ->format(DATE_ATOM),
            'updatedAt' =>
                $item
                    ->getUpdatedAt()
                    ->format(DATE_ATOM),
        ];
    }
}
