<?php

declare(strict_types=1);

// docker compose exec -T backend php tools/test-inventory-foundation.php
// Only transaction-local PostgreSQL tables; no migration on the application schema.
use App\Controller\PublicCharacterMagicItemController;
use App\Entity\{Campaign, Character, CharacterMagicItem, CharacterSessionState, GameSession, MagicItem, MagicItemAbilityEffect, User};
use App\Enum\{Ability, AbilityEffectOperation, MagicItemRarity, ReferenceOrigin};
use App\Repository\{CharacterMagicItemRepository, CharacterSessionStateRepository};
use App\Service\{CharacterAbilityCalculator, PlayerCharacterAccess};
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../migrations/Version20261030010000.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv(__DIR__ . '/../.env');
$kernel = new App\Kernel('dev', true);
$kernel->boot();
$registry = $kernel->getContainer()->get('doctrine');
$em = $registry->getManager();
$db = $em->getConnection();
$checks = 0;
$check = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) throw new RuntimeException($label);
    ++$checks;
};
$body = static fn ($response) => json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
$expectInvalid = static function (callable $operation, string $label) use ($check): void {
    try { $operation(); } catch (InvalidArgumentException) { $check(true, $label); return; }
    throw new RuntimeException($label);
};

try {
    $db->beginTransaction();
    $metadata = $em->getMetadataFactory()->getAllMetadata();
    foreach ((new SchemaTool($em))->getCreateSchemaSql($metadata) as $sql) {
        $sql = preg_replace('/^CREATE TABLE /', 'CREATE TEMP TABLE ', $sql);
        $sql = preg_replace('/^CREATE SEQUENCE /', 'CREATE TEMP SEQUENCE ', $sql);
        $db->executeStatement($sql);
    }
    foreach ($metadata as $class) {
        $table = $db->getDatabasePlatform()->quoteIdentifier($class->getTableName());
        if ($db->fetchOne('SELECT relpersistence FROM pg_class WHERE oid = to_regclass(?)', [$table]) !== 't') {
            throw new RuntimeException('Unsafe fixture table');
        }
    }
    $owner = (new User())->setEmail('inventory@example.invalid')->setPassword('unused');
    $campaign = new Campaign($owner, 'inventory', 'Inventory');
    $character = new Character($campaign, 'hero', 'Hero', Character::TYPE_PLAYER);
    $other = new Character($campaign, 'other', 'Other', Character::TYPE_PLAYER);
    $session = (new GameSession($campaign, 'live', 'Live'))->setStatus(GameSession::STATUS_LIVE);
    $state = new CharacterSessionState($session, $character, ['resources' => [['id' => 'keep', 'currentValue' => 2]]]);
    $character->getWallet()->setGoldPieces(73)->setSilverPieces(12);
    $definition = (new MagicItem($campaign, 'Legacy ring', MagicItemRarity::Rare))->setRequiresAttunement(true);
    $definition->addAbilityEffect(new MagicItemAbilityEffect($definition, Ability::Strength, AbilityEffectOperation::Bonus, 2));
    $definition->addAbilityEffect(new MagicItemAbilityEffect($definition, Ability::Intelligence, AbilityEffectOperation::Minimum, 19));
    $chargedDefinition = (new MagicItem($campaign, 'Legacy wand', MagicItemRarity::Rare))->setMaximumCharges(7);
    $ring = (new CharacterMagicItem($character, $definition))->setEquipped(true)->setAttuned(true)->setNotes('Preserve');
    $stack = (new CharacterMagicItem($character, $definition))->setQuantity(3);
    $wand = (new CharacterMagicItem($character, $chargedDefinition))->setCurrentCharges(2);
    foreach ([$ring, $stack, $wand] as $item) $character->addMagicItem($item);
    foreach ([$owner, $campaign, $character, $other, $session, $state, $definition, $chargedDefinition] as $entity) $em->persist($entity);
    $em->flush();
    $ids = [$character->getId(), $ring->getId(), $stack->getId(), $wand->getId(), $definition->getId(), $owner->getId(), $session->getId()];
    $token = $state->getAccessToken();
    $calculator = new CharacterAbilityCalculator();
    $beforeAbilities = array_map(fn ($score) => $score->toArray(), $calculator->calculateAll($character));

    // Reconstruct the previous schema on temporary tables, then run the real migration.
    $newColumns = [
        'magic_item' => ['origin','owner_id','category','magical','equippable','quantity_mode','equipment_slot','hand_usage','edition','source_reference','import_key','archived_at'],
        'character_magic_item' => ['equipped_slot','hands_used','legacy_stack'],
    ];
    $columnShape = static fn (string $table) => $db->fetchAllAssociative('SELECT attname, format_type(atttypid, atttypmod) AS type, attnotnull FROM pg_attribute WHERE attrelid = to_regclass(?) AND attnum > 0 AND NOT attisdropped ORDER BY attname', [$table]);
    $expectedShape = [];
    foreach ($newColumns as $table => $columns) {
        $expectedShape[$table] = $columnShape($table);
        // This harness verifies the phase-1 migration, before phase-2 nullability changes.
        if ($table === 'magic_item') {
            foreach ($expectedShape[$table] as &$column) {
                if (in_array($column['attname'], ['campaign_id', 'owner_id', 'rarity'], true)) $column['attnotnull'] = true;
            }
            unset($column);
            $db->executeStatement('ALTER TABLE magic_item ALTER campaign_id SET NOT NULL, ALTER rarity SET NOT NULL');
        }
        foreach ($columns as $column) $db->executeStatement("ALTER TABLE $table DROP COLUMN $column");
    }
    $tables = ['magic_item', 'character_magic_item', 'magic_item_ability_effect', 'character_wallet', 'character_session_state'];
    $snapshot = []; $oldColumns = [];
    foreach ($tables as $table) {
        $oldColumns[$table] = array_column($columnShape($table), 'attname');
        $snapshot[$table] = $db->fetchAllAssociative('SELECT '.implode(', ', $oldColumns[$table])." FROM $table ORDER BY id");
    }
    $migration = new DoctrineMigrations\Version20261030010000($db, new Psr\Log\NullLogger());
    $migration->up(new Schema());
    foreach ($migration->getSql() as $query) $db->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
    foreach ($tables as $table) {
        $check($snapshot[$table] === $db->fetchAllAssociative('SELECT '.implode(', ', $oldColumns[$table])." FROM $table ORDER BY id"), "$table: every old column and ID preserved");
    }
    foreach ($expectedShape as $table => $shape) $check($shape === $columnShape($table), "$table: migration matches ORM columns/types/nullability");
    $em->clear();
    [$characterId, $ringId, $stackId, $wandId, $definitionId, $ownerId, $sessionId] = $ids;
    $character = $em->find(Character::class, $characterId);
    $ring = $em->find(CharacterMagicItem::class, $ringId);
    $stack = $em->find(CharacterMagicItem::class, $stackId);
    $wand = $em->find(CharacterMagicItem::class, $wandId);
    $definition = $ring->getMagicItem();
    $check($definition->getOrigin() === ReferenceOrigin::Custom && $definition->getOwner()->getId() === $ownerId, 'Legacy definition remains CUSTOM and belongs to campaign owner');
    $check($definition->isEquippable() && $definition->isMagical() && $definition->getQuantityMode() === 'legacy', 'Legacy definition retains compatible defaults');
    $check($definition->getEquipmentSlot() === 'free' && $definition->getHandUsage() === null, 'No inferred equipment slot');
    $check($definition->getEdition() === null && $definition->getImportKey() === null && $definition->getSourceReference() === null, 'No invented official provenance');
    $check($stack->isLegacyStack() && !$ring->isLegacyStack() && $stack->getQuantity() === 3, 'Legacy stack marked without splitting');
    $check($ring->getEquippedSlot() === null && $ring->getHandsUsed() === null, 'Legacy equipped state retained without hand occupation');
    $check($wand->getCurrentCharges() === 2, 'Spent charges not replenished');
    $check($beforeAbilities === array_map(fn ($score) => $score->toArray(), $calculator->calculateAll($character)), 'All effective abilities preserved after migration');
    $expectInvalid(fn () => $wand->setQuantity(2), 'Charged item still individual');
    $expectInvalid(fn () => $wand->setCurrentCharges(8), 'Charge upper bound preserved');
    $expectInvalid(fn () => $wand->setCurrentCharges(-1), 'Charge lower bound preserved');
    $expectInvalid(fn () => $ring->setQuantity(0), 'Positive quantity required');

    $controller = new PublicCharacterMagicItemController();
    $controller->setContainer(new Container());
    $access = new PlayerCharacterAccess(new CharacterSessionStateRepository($registry));
    $repository = new CharacterMagicItemRepository($registry);
    $update = static function (int $id, array $payload) use ($controller, $token, $access, $repository, $calculator, $em) {
        return $controller->update($token, $id, Request::create('/', 'PATCH', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload, JSON_THROW_ON_ERROR)), $access, $repository, $calculator, $em);
    };
    $response = $body($update($ringId, ['equipped' => false]));
    $check($response['ownedItem']['attuned'] && !$response['ownedItem']['equipped'] && !$response['ownedItem']['effectActive'], 'Unequipping retains attunement and disables effects through public controller');
    $check($response['effectiveAbilities']['strength']['effectiveValue'] === 10 && $response['effectiveAbilities']['intelligence']['effectiveValue'] === 10, 'Unequipping removes bonus and minimum effects');
    $check($response['attunedCount'] === 1 && $response['attunementLimit'] === 3, 'Unequipped attunement still counts');
    $update($ringId, ['attuned' => false]);
    $response = $body($update($ringId, ['attuned' => true]));
    $check(!$response['ownedItem']['equipped'] && $response['ownedItem']['attuned'], 'Attuning does not implicitly equip');
    $response = $body($update($ringId, ['equipped' => true]));
    $check($response['effectiveAbilities']['strength']['effectiveValue'] === 12 && $response['effectiveAbilities']['intelligence']['effectiveValue'] === 19, 'Re-equipping restores existing effects');
    $response = $body($update($ringId, ['attuned' => false]));
    $check($response['ownedItem']['equipped'] && !$response['ownedItem']['effectActive'], 'Unattuning does not unequip and disables conditional effects');
    $update($wandId, ['chargeChange' => -1]);
    $check($em->find(CharacterMagicItem::class, $wandId)->getCurrentCharges() === 1, 'Existing charge operation works after migration');

    // Test limit before any flush: no transaction is aborted and the EntityManager stays usable.
    $ring = $em->find(CharacterMagicItem::class, $ringId);
    $character = $ring->getCharacter();
    $ring->setAttuned(true)->setEquipped(false);
    $stack = $em->find(CharacterMagicItem::class, $stackId);
    $stack->setAttuned(true);
    $third = (new CharacterMagicItem($character, $ring->getMagicItem()))->setAttuned(true);
    $fourth = new CharacterMagicItem($character, $ring->getMagicItem());
    $character->addMagicItem($third)->addMagicItem($fourth);
    $apply = new ReflectionMethod($controller, 'applyUpdate');
    $expectInvalid(fn () => $apply->invoke($controller, $fourth, $character, ['attuned' => true]), 'Fourth attunement rejected even when others are unequipped');
    $em->persist($third); $em->persist($fourth); $em->flush();
    $check($update(999999, ['equipped' => true])->getStatusCode() === 404, 'Unknown possession rejected');
    $other = $em->find(Character::class, $other->getId());
    $foreignItem = new CharacterMagicItem($other, $ring->getMagicItem());
    $other->addMagicItem($foreignItem); $em->persist($foreignItem); $em->flush();
    $check($update($foreignItem->getId(), ['equipped' => true])->getStatusCode() === 404, 'Another character possession rejected');
    $session = $em->find(GameSession::class, $sessionId);
    $session->setStatus(GameSession::STATUS_CLOSED); $em->flush();
    $check($controller->list($token, $access, $calculator)->getStatusCode() === 200, 'Inventory readable outside live session');
    $check($update($ringId, ['equipped' => true])->getStatusCode() === 409, 'Inventory mutation rejected outside live session');
    $check($snapshot['character_wallet'] === $db->fetchAllAssociative('SELECT '.implode(', ', $oldColumns['character_wallet']).' FROM character_wallet ORDER BY id'), 'Wallet untouched by item operations');
    try {
        $controller->list(str_repeat('0', 64), $access, $calculator);
        throw new RuntimeException('Invalid token accepted');
    } catch (Symfony\Component\HttpKernel\Exception\NotFoundHttpException) {
        $check(true, 'Invalid token rejected');
    }
    $session->setStatus(GameSession::STATUS_LIVE); $em->flush();
    // An invalid transactional update closes the EntityManager: keep it last.
    try {
        $update($fourth->getId(), ['attuned' => true]);
        throw new RuntimeException('Fourth attunement accepted by public endpoint');
    } catch (Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException) {
        $check(true, 'Public endpoint enforces three attunements');
    }
    $check(!$db->fetchOne('SELECT attuned FROM character_magic_item WHERE id = ?', [$fourth->getId()]), 'Rejected fourth attunement not persisted');
    echo "OK: $checks inventory foundation assertions; temporary tables rolled back.\n";
} finally {
    while ($db->isTransactionActive()) $db->rollBack();
    $kernel->shutdown();
}
