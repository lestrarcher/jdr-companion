<?php

declare(strict_types=1);

// docker compose exec -T backend php tools/test-media-item-removal.php
// All database fixtures use transaction-local tables; files live in a temporary directory.
use App\Controller\{MediaController, CharacterMagicItemController};
use App\Entity\{Campaign, CampaignFigure, Character, CharacterMagicItem, GameSession, MagicItem, Media, User};
use App\Repository\{CampaignRepository, CampaignFigureRepository, CharacterRepository, MediaRepository};
use App\Service\{MediaStorageService, CharacterAbilityCalculator};
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\{AccessDecisionManager, AuthorizationChecker};

require __DIR__ . '/../vendor/autoload.php';
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
$tokens = new TokenStorage();
$container = new Container();
$container->set('security.authorization_checker', new AuthorizationChecker($tokens, new AccessDecisionManager([new App\Security\Voter\CampaignVoter()])));
$root = sys_get_temp_dir() . '/jdr-removal-' . bin2hex(random_bytes(8));
$storage = new MediaStorageService($root);
$mediaRepository = new MediaRepository($registry);
$figures = new CampaignFigureRepository($registry);
$characters = new CharacterRepository($registry);
$mediaController = new MediaController(new CampaignRepository($registry), $mediaRepository, $em, $storage);
$mediaController->setContainer($container);
$items = new CharacterMagicItemController();
$items->setContainer($container);
$body = static fn ($response) => json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
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
        if ($db->fetchOne('SELECT relpersistence FROM pg_class WHERE oid = to_regclass(?)', [$table]) !== 't') throw new RuntimeException('Unsafe fixture table');
    }
    $gm = (new User())->setEmail('removal@example.invalid')->setPassword('unused');
    $other = (new User())->setEmail('removal-other@example.invalid')->setPassword('unused');
    $campaign = new Campaign($gm, 'removal', 'Removal');
    $foreign = new Campaign($other, 'other', 'Other');
    $character = new Character($campaign, 'one', 'One', Character::TYPE_PLAYER);
    $second = new Character($campaign, 'two', 'Two', Character::TYPE_PLAYER);
    $session = new GameSession($campaign, 'one', 'One');
    $catalog = new MagicItem($campaign, 'Ring', App\Enum\MagicItemRarity::Common);
    foreach ([$gm, $other, $campaign, $foreign, $character, $second, $session, $catalog] as $entity) $em->persist($entity);
    $em->flush();
    $tokens->setToken(new UsernamePasswordToken($gm, 'main', ['ROLE_USER']));
    $campaignId = $campaign->getId();
    $directory = "$root/public/uploads/campaigns/$campaignId";
    mkdir($directory, 0775, true);
    $filename = str_repeat('a', 32) . '.png';
    $media = (new Media())->setCampaign($campaign)->setFilename($filename)->setOriginalName('image.png')->setMimeType('image/png')->setSize(4);
    file_put_contents("$directory/$filename", 'test');
    $em->persist($media); $em->flush();
    $mediaId = $media->getId();
    $deleteMedia = fn () => $mediaController->delete($campaignId, $mediaId, $figures);

    $figure = (new CampaignFigure($campaign, 'NPC', CampaignFigure::TYPE_NPC, 'Portrait'))->setPortrait($media);
    $em->persist($figure); $em->flush();
    $check($deleteMedia()->getStatusCode() === 409, 'Referenced portrait rejected');
    $check(is_file("$directory/$filename"), 'Referenced file preserved');
    $figure->setPortrait(null); $em->flush();
    foreach ([['displayedMedia' => ['source' => "/api/public/media/$filename"]], ['initiative' => ['participants' => [['imageUrl' => "/api/public/media/$filename"]]]]] as $state) {
        $session->setStatus(GameSession::STATUS_CLOSED)->setDisplayState($state); $em->flush();
        $check($deleteMedia()->getStatusCode() === 409, 'Closed session scene/initiative reference rejected');
    }
    $session->setDisplayState([]); $em->flush();
    $session->setPreparationNotes("![Scene](/api/public/media/$filename)"); $em->flush();
    $check($deleteMedia()->getStatusCode() === 409, 'Preparation image reference rejected');
    $session->setPreparationNotes(null); $em->flush();
    try {
        $mediaController->delete($campaignId, $mediaId + 999, $figures);
        throw new RuntimeException('Unknown media accepted');
    } catch (Symfony\Component\HttpKernel\Exception\NotFoundHttpException) {
        $check(true, 'Unknown media returns 404');
    }

    $request = Request::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['magicItemId' => $catalog->getId()]));
    $ability = new CharacterAbilityCalculator();
    $firstId = $body($items->assign($character->getId(), $request, $characters, $em, $ability))['ownedItem']['id'];
    $secondId = $body($items->assign($character->getId(), $request, $characters, $em, $ability))['ownedItem']['id'];
    $check($firstId !== $secondId, 'Duplicate assignments have distinct IDs');
    $check($items->remove($second->getId(), $firstId, $characters, $em)->getStatusCode() === 404, 'Wrong character rejected');
    foreach ([$other, null] as $actor) {
        $tokens->setToken($actor ? new UsernamePasswordToken($actor, 'main', ['ROLE_USER']) : null);
        foreach ([$deleteMedia, fn () => $items->remove($character->getId(), $firstId, $characters, $em)] as $operation) {
            try { $operation(); throw new RuntimeException('Access should be denied'); }
            catch (Symfony\Component\Security\Core\Exception\AccessDeniedException) { $check(true, 'Foreign/anonymous access denied'); }
        }
    }
    $tokens->setToken(new UsernamePasswordToken($gm, 'main', ['ROLE_USER']));
    $check($items->remove($character->getId(), $firstId, $characters, $em)->getStatusCode() === 204, 'One assignment removed');
    $check($db->fetchOne('SELECT count(*) FROM character_magic_item WHERE id = ?', [$firstId]) === 0, 'Removed assignment absent in DB');
    $check($db->fetchOne('SELECT count(*) FROM character_magic_item WHERE id = ?', [$secondId]) === 1, 'Duplicate preserved');
    $check($db->fetchOne('SELECT count(*) FROM magic_item WHERE id = ?', [$catalog->getId()]) === 1, 'Catalog item preserved');
    $remaining = $em->find(CharacterMagicItem::class, $secondId);
    $remaining->setQuantity(2); $em->flush();
    $items->remove($character->getId(), $secondId, $characters, $em);
    $check((int) $db->fetchOne('SELECT quantity FROM character_magic_item WHERE id = ?', [$secondId]) === 1, 'Stack loses only one copy');

    $check($deleteMedia()->getStatusCode() === 204, 'Unused media deleted');
    $check(!is_file("$directory/$filename"), 'Physical file deleted');
    $check($db->fetchOne('SELECT count(*) FROM media WHERE id = ?', [$mediaId]) === 0, 'Media row deleted');
    $check($body($mediaController->list($campaignId, new Request()))['media'] === [], 'Media list refreshed without deleted entry');
    $storage->remove($filename, $campaignId); // Missing file is harmless.
    foreach (['../outside.png', '/tmp/outside.png', 'external.png'] as $unsafe) {
        try { $storage->remove($unsafe, $campaignId); throw new LogicException('Unsafe path accepted'); }
        catch (RuntimeException) { $check(true, 'Unsafe filename rejected'); }
    }
    file_put_contents("$root/outside.png", 'keep');
    symlink("$root/outside.png", "$directory/$filename");
    try { $storage->remove($filename, $campaignId); throw new LogicException('Symlink accepted'); }
    catch (RuntimeException) { $check(is_file("$root/outside.png"), 'Symlink target preserved'); }
    unlink("$directory/$filename"); unlink("$root/outside.png");
    // A storage failure must roll back the row deletion as well.
    $unsafeMedia = (new Media())->setCampaign($campaign)->setFilename('../outside.png')->setOriginalName('outside.png')->setMimeType('image/png')->setSize(4);
    $em->persist($unsafeMedia); $em->flush();
    $unsafeId = $unsafeMedia->getId();
    $check($mediaController->delete($campaignId, $unsafeId, $figures)->getStatusCode() === 500, 'Storage refusal reported');
    $check($db->fetchOne('SELECT count(*) FROM media WHERE id = ?', [$unsafeId]) === 1, 'Storage failure preserves database record');
    echo "OK: $checks removal assertions; isolated fixtures rolled back.\n";
} finally {
    while ($db->isTransactionActive()) $db->rollBack();
    if (isset($directory) && is_dir($directory)) {
        foreach (glob($directory . '/*') as $file) unlink($file);
        rmdir($directory); rmdir(dirname($directory)); rmdir(dirname($directory, 2)); rmdir(dirname($directory, 3));
    }
    if (is_file("$root/outside.png")) unlink("$root/outside.png");
    if (is_dir($root)) rmdir($root);
    $kernel->shutdown();
}
