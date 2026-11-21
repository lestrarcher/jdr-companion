<?php
declare(strict_types=1);
namespace App\Tests\Integration;

use App\Entity\{Beast, CharacterFeatureDefinition, User};
use App\Repository\BeastRepository;
use App\Service\{BeastEditor, BeastSerializer, BeastCatalogue, WildShapeRules2014, WildShapeSessionService, MediaStorageService};
use App\Controller\BeastFeatureAdminController;
use Doctrine\ORM\{EntityManagerInterface, Tools\SchemaTool};
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\HttpKernel\Exception\{BadRequestHttpException, ConflictHttpException};

final class BeastAdministrationTest extends TestCase
{
    private \App\Kernel $kernel;
    private EntityManagerInterface $em;
    private \Doctrine\DBAL\Connection $db;
    private BeastRepository $beasts;
    private BeastEditor $editor;

    protected function setUp(): void
    {
        $this->kernel = new \App\Kernel('test', true);
        $this->kernel->boot();
        $this->em = $this->kernel->getContainer()->get('doctrine')->getManager();
        $this->db = $this->em->getConnection();
        $this->db->beginTransaction();
        foreach ((new SchemaTool($this->em))->getCreateSchemaSql($this->em->getMetadataFactory()->getAllMetadata()) as $sql) {
            $sql = preg_replace('/^CREATE TABLE /', 'CREATE TEMP TABLE ', $sql);
            $sql = preg_replace('/^CREATE SEQUENCE /', 'CREATE TEMP SEQUENCE ', $sql);
            $this->db->executeStatement($sql);
        }
        $this->db->executeStatement('CREATE TEMP TABLE character_seen_beast (character_id INT NOT NULL, beast_id INT NOT NULL REFERENCES beast(id), PRIMARY KEY(character_id,beast_id))');
        foreach (['beast','beast_feature','beast_natural_weapon','beast_habitat','character_seen_beast','character_session_state'] as $table) {
            self::assertSame('t', $this->db->fetchOne('SELECT relpersistence FROM pg_class WHERE oid = to_regclass(?)', [$table]));
        }
        $this->beasts = $this->em->getRepository(Beast::class);
        $this->editor = new BeastEditor($this->em, $this->beasts);
    }
    protected function tearDown(): void
    {
        while ($this->db->isTransactionActive()) $this->db->rollBack();
        $this->em->clear(); $this->kernel->shutdown();
    }
    private function data(string $slug = 'test-beast'): array
    {
        return ['slug'=>$slug,'name'=>'Bête de test','challengeRating'=>'1','sourceUrl'=>null,
            'statBlock'=>['armorClass'=>14,'hitPoints'=>37,'hitDice'=>'5d10+10','size'=>'Large',
                'abilities'=>['str'=>17,'dex'=>15,'con'=>15,'int'=>3,'wis'=>12,'cha'=>7], 'speed'=>['walk'=>15], 'source'=>['keep'=>'metadata']],
            'habitats'=>['foret'], 'naturalWeapons'=>[['name'=>'Morsure','attackType'=>'melee','attackBonus'=>5,
                'damageComponents'=>[['dice'=>'2d6+3','type'=>'piercing']], 'description'=>'Allonge 1,50 m. La cible tombe à terre.']]];
    }
    private function fixture(): Beast
    {
        $beast = new Beast('test-beast'); $this->editor->save($beast, $this->data()); return $beast;
    }
    private function controller(bool $allowed = true, ?string $projectDir = null): BeastFeatureAdminController
    {
        $controller = new BeastFeatureAdminController($this->em, $this->beasts, $this->editor, new BeastSerializer(), new MediaStorageService($projectDir ?? dirname(__DIR__,2)));
        $container = new Container();
        $storage = new TokenStorage(); $storage->setToken(new UsernamePasswordToken(new User(), 'test', ['ROLE_USER']));
        $container->set('security.token_storage', $storage);
        $auth = $this->createMock(AuthorizationCheckerInterface::class);
        $auth->method('isGranted')->with('ROLE_REFERENCE_ADMIN')->willReturn($allowed);
        $container->set('security.authorization_checker', $auth);
        $controller->setContainer($container); return $controller;
    }
    public function testCrudRelationsIdentifiersAndPublicContract(): void
    {
        $feature = new CharacterFeatureDefinition('beast-test-trait', 'Trait partagé');
        $this->em->persist($feature); $this->em->flush();
        $response = $this->controller()->create(Request::create('/', 'POST', content: json_encode($this->data()+['featureIds'=>[$feature->getId()]])));
        self::assertSame(201, $response->getStatusCode());
        $record = json_decode($response->getContent(), true)['beast']; $id = $record['id'];
        $weaponId = $record['naturalWeapons'][0]['id'];
        $this->em->clear();
        $beast = $this->beasts->find($id);
        self::assertSame('foret', $beast->getHabitats()->first()->getHabitat());
        self::assertSame($feature->getId(), $beast->getFeatures()->first()->getDefinition()->getId());
        $record['name'] = 'Nom corrigé'; $record['habitats'] = ['marais','foret'];
        $record['naturalWeapons'][0]['description'] = 'Portée et effet corrigés.';
        $this->controller()->edit($id, Request::create('/', 'PUT', content: json_encode($record)));
        $this->em->clear(); $beast = $this->beasts->find($id);
        self::assertSame($weaponId, $beast->getNaturalWeapons()->first()->getId());
        self::assertSame(['keep'=>'metadata'], $beast->getStatBlock()['source']);
        self::assertSame(1, (int) $this->db->fetchOne('SELECT count(*) FROM beast'));
        $catalogue = new BeastCatalogue($this->db, $this->beasts, new BeastSerializer());
        $catalogue->setSeen(99, $id, true);
        $row = $catalogue->listForCharacter(99)[0];
        self::assertTrue($row['seen']); self::assertSame($id, $row['id']);
        self::assertSame(['foret','marais'], $row['habitats']);
        $service = new WildShapeSessionService(new WildShapeRules2014());
        $state = $service->transform(['hitPoints'=>['current'=>20]], $row, 2, true, true, 'merge', 50);
        self::assertSame(60, $state['wildShape']['active']['hitPoints']['maximum']);
        self::assertSame(1, $state['wildShape']['usesRemaining']);
        $state = $service->transform($state, $row, 2, true, true, 'merge');
        self::assertSame(0, $state['wildShape']['usesRemaining']);
        self::assertNull($service->revert($state)['wildShape']['active']);
        self::assertSame(20, $state['hitPoints']['current']);
        try { $this->controller()->delete($id); self::fail('Observed beast deleted'); }
        catch (ConflictHttpException) { self::assertSame(1, (int) $this->db->fetchOne('SELECT count(*) FROM beast')); }
    }
    public function testUnreferencedDeletionRemovesOnlyOwnedRelations(): void
    {
        $beast = $this->fixture(); $id = $beast->getId();
        self::assertSame(200, $this->controller()->delete($id)->getStatusCode());
        foreach (['beast','beast_habitat','beast_natural_weapon'] as $table) self::assertSame(0, (int) $this->db->fetchOne('SELECT count(*) FROM '.$table));
    }
    public function testRejectsDuplicateAndInvalidProfilesWithoutChangingData(): void
    {
        $beast = $this->fixture(); $invalid = $this->data(); $invalid['statBlock']['abilities']['str'] = 99;
        try { $this->editor->save($beast, $invalid); self::fail('Invalid stats accepted'); }
        catch (BadRequestHttpException) { self::assertSame(17, $beast->getStatBlock()['abilities']['str']); }
        $this->expectException(ConflictHttpException::class); $this->editor->save(new Beast('test-beast'), $this->data());
    }
    public function testImportsPreserveEditedStatsWeaponsAndRemovedTraits(): void
    {
        $beast = $this->fixture(); $id = $beast->getId();
        $block = $beast->getStatBlock();
        $block['actions'] = [['name'=>'Ancienne action importée','description'=>'Ancien texte','attackBonus'=>9,'damage'=>[]]];
        $block['traits'] = [['name'=>'Old trait','desc'=>'Must not be restored after a GM removal.']];
        $beast->edit($beast->getSlug(),$beast->getName(),$beast->getChallengeRating(),null,$block); $this->em->flush();
        $root = sys_get_temp_dir().'/beast-import-'.bin2hex(random_bytes(6)); mkdir($root); mkdir($root.'/data');
        try {
            $data = $this->data(); $data['name'] = 'Import must not win'; $data['statBlock']['actions'] = [];
            file_put_contents($root.'/data/beasts-2014-stats.json', json_encode([$data]));
            file_put_contents($root.'/data/beasts-2014-habitats.json', json_encode([['slug'=>'test-beast','habitat'=>'plaine']]));
            file_put_contents($root.'/data/beasts-2014-seed.json', json_encode([['slug'=>'test-beast','sourceUrl'=>'https://example.invalid']]));
            foreach ([new \App\Command\EnrichBeastCatalogue2014Command($this->db,$root), new \App\Command\ImportBeastHabitats2014Command($this->db,$root), new \App\Command\ImportBeastNaturalWeapons2014Command($this->db), new \App\Command\ImportBeastTraits2014Command($this->db)] as $command) {
                $options = ['--apply'=>true];
                if ($command->getDefinition()->hasOption('replace-unmanaged')) $options['--replace-unmanaged'] = true;
                $tester = new CommandTester($command); self::assertSame(0, $tester->execute($options), $tester->getDisplay());
                self::assertSame(0, $tester->execute($options), $tester->getDisplay());
            }
            (new CommandTester(new \App\Command\ImportBeastCatalogue2014Command($this->db,$root)))->execute([]);
            self::assertSame('Bête de test', $this->db->fetchOne('SELECT name FROM beast WHERE id = ?',[$id]));
            self::assertSame(['foret'], $this->db->fetchFirstColumn('SELECT habitat FROM beast_habitat WHERE beast_id = ?',[$id]));
            self::assertSame(1, (int) $this->db->fetchOne('SELECT count(*) FROM beast_natural_weapon'));
            self::assertSame('Morsure', $this->db->fetchOne('SELECT name FROM beast_natural_weapon'));
            self::assertSame(0, (int) $this->db->fetchOne('SELECT count(*) FROM beast_feature'));
            self::assertSame($block, json_decode($this->db->fetchOne('SELECT stat_block FROM beast WHERE id = ?',[$id]),true));
            self::assertNull($this->db->fetchOne('SELECT source_url FROM beast WHERE id = ?',[$id]));
        } finally {
            foreach (glob($root.'/data/*') as $file) unlink($file); rmdir($root.'/data'); rmdir($root);
        }
    }
    public function testAdministratorRoleRequired(): void
    {
        $this->expectException(AccessDeniedException::class); $this->controller(false)->list();
    }
    public function testActiveSessionPreventsDeletionWithoutObservation(): void
    {
        $beast = $this->fixture();
        $user = (new User())->setEmail('beast@example.invalid')->setPassword('unused');
        $campaign = new \App\Entity\Campaign($user, 'beast-test', 'Test');
        $character = new \App\Entity\Character($campaign, 'druid-test', 'Druide', 'player');
        $session = new \App\Entity\GameSession($campaign, 'test-session', 'Test');
        $state = new \App\Entity\CharacterSessionState($session, $character, ['wildShape'=>['active'=>['beastId'=>$beast->getId()]]]);
        foreach ([$user,$campaign,$character,$session,$state] as $entity) $this->em->persist($entity);
        $this->em->flush();
        self::assertTrue($this->beasts->isReferenced($beast->getId()));
        $this->expectException(ConflictHttpException::class); $this->controller()->delete($beast->getId());
    }
    public function testAdditiveMigrationPreservesEveryExistingValue(): void
    {
        $this->fixture();
        // Only a temporary fixture table is brought back to the pre-migration shape.
        $this->db->executeStatement('ALTER TABLE pg_temp.beast DROP COLUMN customized');
        $before = $this->db->fetchAllAssociative('SELECT * FROM pg_temp.beast');
        require_once dirname(__DIR__,2).'/migrations/Version20261121010000.php';
        $migration = new \DoctrineMigrations\Version20261121010000($this->db, new \Psr\Log\NullLogger());
        $migration->up(new \Doctrine\DBAL\Schema\Schema());
        foreach ($migration->getSql() as $query) $this->db->executeStatement($query->getStatement(),$query->getParameters(),$query->getTypes());
        $after = $this->db->fetchAllAssociative('SELECT * FROM pg_temp.beast');
        foreach ($after as &$row) { self::assertFalse($row['customized']); unset($row['customized']); } unset($row);
        self::assertSame($before, $after);
    }
    public function testImageUploadReplacementThumbnailAndDeletion(): void
    {
        self::assertTrue(extension_loaded('gd'), 'GD must be installed to serve thumbnails.');
        $root = sys_get_temp_dir().'/beast-image-'.bin2hex(random_bytes(6)); mkdir($root);
        $beast = $this->fixture(); $id = $beast->getId();
        $controller = $this->controller(true, $root);
        $upload = function () use ($root, $controller, $id): string {
            $image = imagecreatetruecolor(1024,512); imagepng($image,$root.'/upload.png'); imagedestroy($image);
            $file = new \Symfony\Component\HttpFoundation\File\UploadedFile($root.'/upload.png','beast.png','image/png',null,true);
            $response = $controller->uploadImage($id,Request::create('/','POST',files:['image'=>$file]));
            self::assertSame(200,$response->getStatusCode());
            return basename(json_decode($response->getContent(),true)['imageUrl']);
        };
        try {
            $first = $upload();
            $public = new \App\Controller\PublicBeastImageController($this->beasts,new MediaStorageService($root),$root);
            self::assertSame('image/png',$public->show($first)->headers->get('Content-Type'));
            $response = $public->thumbnail($first);
            self::assertSame('image/webp',$response->headers->get('Content-Type'));
            $thumbnail = $response->getFile()->getPathname();
            self::assertSame([256,128], array_slice(getimagesize($thumbnail),0,2));
            self::assertSame([1024,512], array_slice(getimagesize($root.'/public/uploads/beasts/'.$first),0,2));
            $second = $upload(); self::assertNotSame($first,$second);
            self::assertFileDoesNotExist($root.'/public/uploads/beasts/'.$first); self::assertFileDoesNotExist($thumbnail);
            $controller->deleteImage($id); self::assertNull($beast->getImageFilename());
            self::assertFileDoesNotExist($root.'/public/uploads/beasts/'.$second);
        } finally {
            foreach (glob($root.'/public/uploads/beasts/thumbnails/*') ?: [] as $file) unlink($file);
            foreach (glob($root.'/public/uploads/beasts/*') ?: [] as $file) if (is_file($file)) unlink($file);
            foreach (['/public/uploads/beasts/thumbnails','/public/uploads/beasts','/public/uploads','/public',''] as $suffix) if (is_dir($root.$suffix)) rmdir($root.$suffix);
        }
    }
    public function testRemovingRelationsKeepsSharedDefinitionsAndOtherBeasts(): void
    {
        $beast = $this->fixture(); $id = $beast->getId();
        $feature = new CharacterFeatureDefinition('shared-trait', 'Trait partagé');
        $this->em->persist($feature); $this->em->flush();
        $this->editor->replaceFeatures($beast, [$feature->getId()]);
        $other = new Beast('other'); $this->editor->save($other, $this->data('other')+['featureIds'=>[$feature->getId()]]);
        $data = $this->data(); $data['habitats'] = []; $data['naturalWeapons'] = []; $data['featureIds'] = [];
        $this->editor->save($beast, $data);
        $this->em->clear(); $beast = $this->beasts->find($id);
        self::assertCount(0,$beast->getHabitats()); self::assertCount(0,$beast->getFeatures()); self::assertCount(0,$beast->getNaturalWeapons());
        self::assertSame(1,(int)$this->db->fetchOne('SELECT count(*) FROM character_feature_definition WHERE id = ?',[$feature->getId()]));
        self::assertSame(1,(int)$this->db->fetchOne('SELECT count(*) FROM beast_feature'));
        self::assertSame(1,(int)$this->db->fetchOne('SELECT count(*) FROM beast_natural_weapon'));
        $data['slug'] = 'renamed';
        $this->expectException(BadRequestHttpException::class); $this->editor->save($beast,$data);
    }
}
