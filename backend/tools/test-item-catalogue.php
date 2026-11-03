<?php

declare(strict_types=1);

// Only temporary PostgreSQL tables, always rolled back. No imports or real-data mutations.
use App\Controller\{ItemCatalogueController, PublicItemCatalogueController, MagicItemController, CharacterMagicItemController, PublicCharacterMagicItemController};
use App\Entity\{Campaign, Character, CharacterMagicItem, CharacterSessionState, GameSession, MagicItem, User};
use App\Enum\{MagicItemRarity, ReferenceOrigin};
use App\Repository\{CampaignRepository, CharacterRepository, CharacterSessionStateRepository};
use App\Security\Voter\CampaignVoter;
use App\Service\{ItemCatalogue, CharacterAbilityCalculator, PlayerCharacterAccess};
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\{NotFoundHttpException, UnprocessableEntityHttpException};
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\{AccessDecisionManager, AuthorizationChecker};
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use App\Service\CharacterInventory;

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/../migrations/Version20261030020000.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv(__DIR__.'/../.env');
$kernel = new App\Kernel('dev', true); $kernel->boot();
$registry = $kernel->getContainer()->get('doctrine'); $em = $registry->getManager(); $db = $em->getConnection();
$checks = 0;
$check = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) throw new RuntimeException($label); ++$checks;
};
$reject = static function (string $exception, callable $call, string $label) use ($check): void {
    try { $call(); } catch (Throwable $error) { if (!$error instanceof $exception) throw $error; $check(true, $label); return; }
    throw new RuntimeException($label);
};
$body = static fn ($response) => json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
$request = static fn (array $payload) => Request::create('/', 'POST', [], [], [], ['CONTENT_TYPE'=>'application/json'], json_encode($payload, JSON_THROW_ON_ERROR));
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
        if ($db->fetchOne('SELECT relpersistence FROM pg_class WHERE oid=to_regclass(?)', [$table]) !== 't') throw new RuntimeException('Unsafe table');
    }
    $alice = (new User())->setEmail('catalogue-alice@example.invalid')->setPassword('unused');
    $bob = (new User())->setEmail('catalogue-bob@example.invalid')->setPassword('unused');
    $a = new Campaign($alice, 'a', 'A'); $a2 = new Campaign($alice, 'a2', 'A2'); $b = new Campaign($bob, 'b', 'B');
    $character = new Character($a, 'hero', 'Hero', Character::TYPE_PLAYER);
    $session = (new GameSession($a, 'closed', 'Closed'))->setStatus(GameSession::STATUS_CLOSED);
    $state = new CharacterSessionState($session, $character);
    $legacy = (new MagicItem($a, 'Legacy ring', MagicItemRarity::Rare))->setRequiresAttunement(true);
    $owned = (new CharacterMagicItem($character, $legacy))->setEquipped(true)->setAttuned(true)->setNotes('Keep');
    $character->addMagicItem($owned);
    foreach ([$alice,$bob,$a,$a2,$b,$character,$session,$state,$legacy] as $entity) $em->persist($entity);
    $em->flush();

    // Replay the actual phase-2 migration from the phase-1 shape, using TEMP tables only.
    $shape = static fn (string $table) => $db->fetchAllAssociative('SELECT attname, format_type(atttypid,atttypmod) AS type, attnotnull FROM pg_attribute WHERE attrelid=to_regclass(?) AND attnum>0 AND NOT attisdropped ORDER BY attname', [$table]);
    $expected = ['magic_item'=>$shape('magic_item'), 'campaign_item_visibility'=>$shape('campaign_item_visibility')];
    $before = [];
    foreach (['magic_item','character_magic_item','magic_item_ability_effect','character_wallet','character_session_state'] as $table) $before[$table]=$db->fetchAllAssociative("SELECT * FROM $table ORDER BY id");
    $db->executeStatement('DROP TABLE pg_temp.campaign_item_visibility');
    $db->executeStatement('ALTER TABLE pg_temp.magic_item ALTER campaign_id SET NOT NULL, ALTER owner_id SET NOT NULL, ALTER rarity SET NOT NULL');
    $migration = new DoctrineMigrations\Version20261030020000($db, new Psr\Log\NullLogger());
    $migration->up(new Doctrine\DBAL\Schema\Schema());
    foreach ($migration->getSql() as $query) {
        $sql = preg_replace('/^CREATE TABLE /', 'CREATE TEMP TABLE ', $query->getStatement());
        $db->executeStatement($sql, $query->getParameters(), $query->getTypes());
    }
    foreach ($before as $table=>$rows) $check($rows === $db->fetchAllAssociative("SELECT * FROM $table ORDER BY id"), "$table unchanged by phase-2 migration");
    foreach ($expected as $table=>$columns) $check($columns === $shape($table), "$table migration matches mapping");

    $tokens = new TokenStorage(); $tokens->setToken(new UsernamePasswordToken($alice, 'main', ['ROLE_USER']));
    $container = new Container();
    $container->set('security.authorization_checker', new AuthorizationChecker($tokens, new AccessDecisionManager([new CampaignVoter()])));
    $catalogue = new ItemCatalogue($em);
    $gm = new ItemCatalogueController(new CampaignRepository($registry), $catalogue); $gm->setContainer($container);
    $access = new PlayerCharacterAccess(new CharacterSessionStateRepository($registry));
    $player = new PublicItemCatalogueController($access, $catalogue); $player->setContainer($container);
    $old = new MagicItemController(); $old->setContainer($container);
    $oldPossessions = new PublicCharacterMagicItemController(); $oldPossessions->setContainer($container);
    $token = $state->getAccessToken();
    $visibleIds = static fn (Campaign $campaign) => array_column($catalogue->list($campaign, true)['items'], 'id');
    $ownedBefore = $body($oldPossessions->list($token, $access, new CharacterAbilityCalculator()));

    // Synthetic official fixtures only, covering every rarity without importing any content.
    $officials = [];
    foreach (MagicItemRarity::cases() as $rarity) {
        $item = new MagicItem(null, 'Official '.$rarity->value, $rarity, null, ReferenceOrigin::Official);
        $item->classify('other', true, false); $em->persist($item); $officials[]=$item;
    }
    $em->flush();
    foreach ($officials as $item) {
        $check(in_array($item->getId(), $visibleIds($a), true) && in_array($item->getId(), $visibleIds($b), true), 'Every official rarity visible across campaigns');
        $check($body($player->show($token, $item->getId()))['item']['id'] === $item->getId(), 'Official direct access');
    }
    $check(!in_array($legacy->getId(), $visibleIds($a), true), 'Legacy custom private by default');
    $reject(NotFoundHttpException::class, fn ()=>$player->show($token,$legacy->getId()), 'Hidden custom direct ID denied');
    $reject(NotFoundHttpException::class, fn ()=>$gm->show($a2->getId(),$legacy->getId()), 'Legacy definition restricted to original campaign');
    $custom = $body($gm->create($a->getId(), $request(['name'=>'Personal catalogue torch','description'=>'Reusable definition','category'=>'gear'])))['item'];
    $customId = $custom['id'];
    $check($custom['origin']==='CUSTOM' && !$custom['visibleToPlayers'] && $custom['campaignId']===null && $custom['rarity']===null, 'GM creation is shared CUSTOM, private and supports ordinary rarity');
    $check($custom['quantityMode']==='stackable' && !$custom['equippable'], 'Ordinary definition classification');
    $check($body($gm->show($a2->getId(),$customId))['item']['id']===$customId, 'Owner can reuse shared definition across own campaigns');
    $reject(NotFoundHttpException::class, fn ()=>$player->show($token,$customId), 'Unpublished shared custom direct ID denied');
    $gm->visibility($a->getId(),$customId,$request(['decision'=>'published']));
    $check(in_array($customId,$visibleIds($a),true) && !in_array($customId,$visibleIds($a2),true), 'Publishing only affects selected campaign');
    $check($body($player->show($token,$customId))['item']['id']===$customId, 'Published definition direct access');
    $gm->visibility($a->getId(),$customId,$request(['decision'=>'published']));
    $check((int)$db->fetchOne('SELECT count(*) FROM campaign_item_visibility WHERE campaign_id=? AND magic_item_id=?',[$a->getId(),$customId])===1, 'Repeated publication does not duplicate decisions');
    $gm->visibility($a2->getId(),$customId,$request(['decision'=>'published']));
    $gm->visibility($a->getId(),$customId,$request(['decision'=>'default']));
    $check(!in_array($customId,$visibleIds($a),true) && in_array($customId,$visibleIds($a2),true), 'Unpublishing does not change another campaign');
    $artifact = $officials[array_key_last($officials)];
    $gm->visibility($a->getId(),$artifact->getId(),$request(['decision'=>'hidden']));
    $check(!in_array($artifact->getId(),$visibleIds($a),true) && in_array($artifact->getId(),$visibleIds($a2),true), 'Official hiding scoped to campaign');
    $reject(NotFoundHttpException::class, fn ()=>$player->show($token,$artifact->getId()), 'Hidden official direct ID denied');
    $check(!$body($gm->show($a->getId(),$artifact->getId()))['item']['visibleToPlayers'], 'GM can inspect hidden official');
    $gm->visibility($a->getId(),$artifact->getId(),$request(['decision'=>'default']));
    $check(in_array($artifact->getId(),$visibleIds($a),true), 'Default restores official visibility');
    $gm->visibility($a->getId(),$legacy->getId(),$request(['decision'=>'published']));
    $gm->visibility($a->getId(),$legacy->getId(),$request(['decision'=>'hidden']));
    $check($ownedBefore===$body($oldPossessions->list($token,$access,new CharacterAbilityCalculator())), 'Visibility does not change owned objects, effects or attunement');

    foreach (['origin'=>'OFFICIAL','ownerId'=>$bob->getId(),'campaignId'=>$b->getId(),'id'=>99] as $key=>$value) {
        $reject(UnprocessableEntityHttpException::class, fn ()=>$gm->create($a->getId(),$request(['name'=>'Forged',$key=>$value])), 'Protected creation property rejected: '.$key);
    }
    foreach ([['category'=>'weapon'],['name'=>[]],['magical'=>null],['category'=>'invalid'],['maximumCharges'=>'3'],['abilityEffects'=>'bad']] as $invalid) {
        $reject(UnprocessableEntityHttpException::class, fn ()=>$gm->create($a->getId(),$request(array_replace(['name'=>'Invalid'],$invalid))), 'Invalid catalogue input rejected');
    }
    $weapon=$body($gm->create($a->getId(),$request(['name'=>'Ordinary greatsword','category'=>'weapon','equippable'=>true,'equipmentSlot'=>'hand','handUsage'=>'two'])))['item'];
    $check(!$weapon['magical'] && $weapon['equippable'] && $weapon['quantityMode']==='individual', 'Ordinary weapon equippable without magical bonus');
    $potion=$body($gm->create($a->getId(),$request(['name'=>'Synthetic potion','category'=>'potion','magical'=>true])))['item'];
    $check($potion['quantityMode']==='stackable' && !$potion['equippable'], 'Magical potion stackable');
    $wand=$body($gm->create($a->getId(),$request(['name'=>'Synthetic wand','magical'=>true,'maximumCharges'=>4,'rechargeType'=>'manual'])))['item'];
    $check($wand['maximumCharges']===4 && $wand['quantityMode']==='individual', 'Existing charge definition reused');
    $changed=$body($gm->describe($a->getId(),$customId,$request(['name'=>'Renamed','description'=>null])))['item'];
    $check($changed['name']==='Renamed' && $changed['description']===null, 'Owner can edit descriptive fields');
    $reject(UnprocessableEntityHttpException::class, fn ()=>$gm->describe($a->getId(),$customId,$request(['ownerId'=>$bob->getId()])), 'Cannot change definition ownership');
    $reject(NotFoundHttpException::class, fn ()=>$gm->describe($a->getId(),$artifact->getId(),$request(['name'=>'Fake'])), 'GM cannot edit official definition');
    $reject(UnprocessableEntityHttpException::class, fn ()=>$gm->visibility($a->getId(),$customId,$request(['decision'=>'published','campaignId'=>$b->getId()])), 'Visibility campaign injection rejected');
    $reject(UnprocessableEntityHttpException::class, fn ()=>$gm->visibility($a->getId(),$customId,$request(['decision'=>'invalid'])), 'Invalid visibility rejected');
    $reject(UnprocessableEntityHttpException::class, fn ()=>$gm->visibility($a->getId(),$artifact->getId(),$request(['decision'=>'published'])), 'Official publication is not a separate override');
    $reject(NotFoundHttpException::class, fn ()=>$player->show($token,999999), 'Unknown ID returns 404');
    $reject(NotFoundHttpException::class, fn ()=>$player->list(str_repeat('0',64),new Request()), 'Invalid token rejected');
    $state->setParticipating(false); $em->flush();
    $reject(NotFoundHttpException::class, fn ()=>$player->list($token,new Request()), 'Nonparticipant token rejected');
    $state->setParticipating(true); $em->flush();
    $check($player->list($token,new Request())->getStatusCode()===200, 'Read-only catalogue available outside live session');

    // A GM cannot manage another campaign; even an administrator receives no owner bypass.
    foreach ([$bob,null] as $actor) {
        $tokens->setToken($actor ? new UsernamePasswordToken($actor,'main',['ROLE_USER','ROLE_ADMIN']) : null);
        foreach ([fn ()=>$gm->list($a->getId(),new Request()), fn ()=>$gm->show($a->getId(),$customId),
            fn ()=>$gm->create($a->getId(),$request(['name'=>'Bad'])), fn ()=>$gm->describe($a->getId(),$customId,$request(['name'=>'Bad'])),
            fn ()=>$gm->visibility($a->getId(),$customId,$request(['decision'=>'published']))] as $operation) {
            $reject(AccessDeniedException::class,$operation,'Foreign/anonymous campaign management denied');
        }
    }
    $tokens->setToken(new UsernamePasswordToken($bob,'main',['ROLE_USER']));
    $reject(NotFoundHttpException::class,fn ()=>$gm->show($b->getId(),$customId),'Another GM cannot read custom by ID through own campaign');
    $reject(NotFoundHttpException::class,fn ()=>$gm->describe($b->getId(),$customId,$request(['name'=>'Bad'])),'Another GM cannot edit custom');
    $reject(NotFoundHttpException::class,fn ()=>$gm->visibility($b->getId(),$customId,$request(['decision'=>'published'])),'Another GM cannot publish custom');
    $check(!in_array($customId,array_column($body($gm->list($b->getId(),new Request()))['items'],'id'),true),'Another GM list excludes custom');
    $tokens->setToken(new UsernamePasswordToken($alice,'main',['ROLE_USER']));
    $gm->visibility($a->getId(),$customId,$request(['decision'=>'published']));
    $filtered=$body($player->list($token,Request::create('/?q=renamed&category=gear')));
    $check($filtered['total']===1 && $filtered['items'][0]['id']===$customId,'Search/category apply after visibility');
    $check($body($player->list($token,Request::create('/?q=Legacy')))['total']===0,'Search does not reveal hidden definitions');
    $check($body($player->list($token,Request::create('/?q=%27%20OR%201=1')))['total']===0,'SQL-shaped search remains data');
    $reject(UnprocessableEntityHttpException::class,fn ()=>$player->list($token,Request::create('/?page=0')),'Invalid page rejected');
    for ($i=0;$i<52;++$i) $em->persist(new MagicItem(null,'Page '.str_pad((string)$i,2,'0',STR_PAD_LEFT),null,null,ReferenceOrigin::Official));
    $em->flush();
    $page1=$body($player->list($token,Request::create('/?q=Page'))); $page2=$body($player->list($token,Request::create('/?q=Page&page=2')));
    $check($page1['total']===52 && count($page1['items'])===50 && count($page2['items'])===2,'Bounded pagination');
    $check(array_intersect(array_column($page1['items'],'id'),array_column($page2['items'],'id'))===[],'Stable pagination without duplicate results');

    $oldList=$body($old->list($a->getId(),new CampaignRepository($registry),$em));
    $check(array_column($oldList['items'],'id')===[$legacy->getId()],'Historical list retains campaign-only contract');
    $newLegacy=$body($old->create($a->getId(),$request(['name'=>'Old API creation','rarity'=>'common']),new CampaignRepository($registry),$em))['item'];
    $check($newLegacy['campaignId']===$a->getId(),'Historical creation remains campaign-scoped');
    $check(!in_array($newLegacy['id'],$visibleIds($a),true),'Historical creation remains unpublished');
$assign = new CharacterMagicItemController();
$assign->setContainer($container);

$inventory = new CharacterInventory($em, $catalogue);

// Les opérations du catalogue seules ne doivent modifier ni les possessions ni le portefeuille.
$check(
    $before['character_magic_item'] === $db->fetchAllAssociative('SELECT * FROM character_magic_item ORDER BY id'),
    'Catalogue operations never modify possessions'
);

$check(
    $before['character_wallet'] === $db->fetchAllAssociative('SELECT * FROM character_wallet ORDER BY id'),
    'Catalogue operations never modify wallet'
);

// Étape 3 : le MJ peut désormais attribuer un objet officiel partagé.
$response = $assign->assign(
    $character->getId(),
    $request(['magicItemId' => $artifact->getId()]),
    new CharacterRepository($registry),
    $em,
    new CharacterAbilityCalculator(),
    $inventory
);

$result = $body($response);

$check($response->getStatusCode() === 201, 'GM can acquire shared official item');

$check(
    (int) $db->fetchOne(
        'SELECT COUNT(*) FROM character_magic_item WHERE character_id = ? AND magic_item_id = ?',
        [$character->getId(), $artifact->getId()]
    ) === 1,
    'Shared official acquisition persisted'
);

$check(
    $before['character_wallet'] === $db->fetchAllAssociative('SELECT * FROM character_wallet ORDER BY id'),
    'Acquisition does not modify wallet'
);
    echo "OK: $checks item catalogue assertions; temporary fixtures rolled back.\n";
} finally {
    while ($db->isTransactionActive()) $db->rollBack();
    $kernel->shutdown();
}
