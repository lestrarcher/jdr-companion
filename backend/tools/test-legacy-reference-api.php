<?php

declare(strict_types=1);

// Former HTTP contracts must stay closed for OFFICIAL, own CUSTOM and foreign CUSTOM.
use App\Entity\{User, Campaign, Character, GameSession, CharacterSessionState, CharacterClass, CharacterSubclass, CharacterRace, Feat, CharacterFeatureDefinition, TrackableResourceDefinition, ProgressionDefinition, CharacterActionDefinition, CharacterFeatureRule, TrackableResourceRule, CharacterActionClassRule, ProgressionStage, ProgressionAdjustmentRule, RaceAbilityModifier};
use App\Enum\{ReferenceOrigin, ResourceRechargeType, CharacterActionHandlerType, ProgressionAdjustmentDirection, Ability};
use Symfony\Component\HttpFoundation\{Request, Session\Session, Session\Storage\MockArraySessionStorage};
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

require __DIR__.'/../vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv(__DIR__.'/../.env');
putenv('SHELL_VERBOSITY=-1'); $_SERVER['SHELL_VERBOSITY'] = $_ENV['SHELL_VERBOSITY'] = -1;
$kernel = new App\Kernel('dev', true); $kernel->boot();
$container = $kernel->getContainer(); $em = $container->get('doctrine')->getManager(); $db = $em->getConnection();
$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks): void { if (!$ok) throw new RuntimeException($label); ++$checks; };
$session = new Session(new MockArraySessionStorage()); $session->start();
$request = static function (string $path, string $method = 'GET', array $payload = [] ) use ($kernel, $session): array {
    $r = Request::create($path, $method, [], [$session->getName() => $session->getId()], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload));
    $r->setSession($session); $response = $kernel->handle($r);
    return [$response->getStatusCode(), json_decode($response->getContent(), true), $response->getContent()];
};
// Exact inventory of the 38 retired routes (9 reads + 29 mutations).
$retired = [];
foreach (['classes','subclasses','races','feats','features','resources','feature-rules','progressions'] as $type) {
    $retired[] = ['GET', '/dnd/'.$type, $type];
    $retired[] = ['POST', '/dnd/'.$type, $type];
    $retired[] = ['PATCH', '/dnd/'.$type.'/{id}', $type];
}
foreach (['feats','feature-rules','progressions'] as $type) $retired[] = ['DELETE', '/dnd/'.$type.'/{id}', $type];
$retired[] = ['POST', '/dnd/races/{id}/ability-modifiers', 'races'];
foreach (['GET','POST'] as $method) $retired[] = [$method, '/dnd/resources/{id}/rules', 'resources'];
foreach (['PATCH','DELETE'] as $method) $retired[] = [$method, '/dnd/resource-rules/{id}', 'resource-rules'];
foreach (['stages','adjustment-rules'] as $child) {
    $retired[] = ['POST', '/dnd/progressions/{id}/'.$child, 'progressions'];
    foreach (['PATCH','DELETE'] as $method) $retired[] = [$method, '/dnd/progressions/{id}/'.$child.'/{child}', 'progressions'];
}
$check(count($retired) === 38, 'Exact retired route inventory');
try {
    $db->beginTransaction();
    $metadata = $em->getMetadataFactory()->getAllMetadata();
    foreach ((new Doctrine\ORM\Tools\SchemaTool($em))->getCreateSchemaSql($metadata) as $sql) {
        $db->executeStatement(preg_replace(['/^CREATE TABLE /', '/^CREATE SEQUENCE /'], ['CREATE TEMP TABLE ', 'CREATE TEMP SEQUENCE '], $sql));
    }
    foreach ($metadata as $meta) $check($db->fetchOne('SELECT relpersistence FROM pg_class WHERE oid=to_regclass(?)', [$meta->getTableName()]) === 't', 'Isolated '.$meta->getTableName());
    $fingerprint = static function () use ($db, $metadata): array {
        $hashes = [];
        foreach ($metadata as $meta) {
            $table = $db->getDatabasePlatform()->quoteIdentifier($meta->getTableName());
            $hashes[$table] = $db->fetchOne("SELECT md5(coalesce(string_agg(row_to_json(t)::text, '' ORDER BY id), '')) FROM $table t");
        }
        return $hashes;
    };
    $users = []; $campaigns = []; $fixtures = []; $ids = [];
    foreach (['a','b'] as $key) {
        $users[$key] = (new User())->setEmail($key.'@legacy.invalid')->setPassword('unused');
        $campaigns[$key] = new Campaign($users[$key], 'legacy-'.$key, 'Legacy '.$key);
        $em->persist($users[$key]); $em->persist($campaigns[$key]);
    }
    $save = static function (object $entity, string $scope) use ($em, &$users): object {
        if ($scope !== 'o') {
            (new ReflectionProperty($entity, 'origin'))->setValue($entity, ReferenceOrigin::Custom);
            (new ReflectionProperty($entity, 'owner'))->setValue($entity, $users[$scope]);
        }
        $em->persist($entity); return $entity;
    };
    foreach (['o','a','b'] as $key) {
        $class = $save(new CharacterClass('legacy-class-'.$key, 'Class '.$key, 8, 20), $key);
        $subclass = $save(new CharacterSubclass($class, 'legacy-subclass-'.$key, 'Subclass '.$key), $key);
        $race = $save((new CharacterRace('legacy-race-'.$key, 'Race '.$key))->setCustom($key !== 'a'), $key);
        $feat = $save(new Feat('legacy-feat-'.$key, 'Feat '.$key), $key);
        $resource = $save(new TrackableResourceDefinition('legacy-resource-'.$key, 'Resource '.$key, ResourceRechargeType::LongRest), $key);
        $feature = $save((new CharacterFeatureDefinition('legacy-feature-'.$key, 'Feature '.$key))->setResourceDefinition($resource), $key);
        $progression = $save(new ProgressionDefinition('legacy-progress-'.$key, 'Progression '.$key), $key);
        $action = $save(new CharacterActionDefinition('legacy-action-'.$key, 'Action '.$key, CharacterActionHandlerType::Aid), $key);
        $featureRule = $save(CharacterFeatureRule::forClass($feature, $class, 1), $key);
        $resourceRule = $save(TrackableResourceRule::forClass($resource, $class, 1), $key);
        $save(new CharacterActionClassRule($action, $class, 1), $key);
        $stage = new ProgressionStage($progression, 'Stage', 0); $progression->addStage($stage); $em->persist($stage);
        $rule = new ProgressionAdjustmentRule($progression, ProgressionAdjustmentDirection::GAIN, 'Rule', '+1'); $progression->addAdjustmentRule($rule); $em->persist($rule);
        $em->persist(new RaceAbilityModifier($race, 1, Ability::Strength));
        $fixtures[$key] = ['classes'=>$class, 'subclasses'=>$subclass, 'races'=>$race, 'feats'=>$feat, 'features'=>$feature, 'resources'=>$resource, 'progressions'=>$progression, 'feature-rules'=>$featureRule, 'resource-rules'=>$resourceRule, 'stages'=>$stage, 'adjustment-rules'=>$rule];
    }
    foreach (['a','b'] as $key) {
        $character = new Character($campaigns[$key], 'legacy-character', 'Character', Character::TYPE_PLAYER);
        $game = new GameSession($campaigns[$key], 'legacy-game', 'Game');
        $state = new CharacterSessionState($game, $character, ['resources'=>[['id'=>'legacy-resource-'.$key,'currentValue'=>0,'storedValues'=>[9]]], 'progressions'=>[]]);
        foreach ([$character,$game,$state] as $entity) $em->persist($entity);
    }
    $em->flush();
    foreach ($fixtures as $key => $items) foreach ($items as $type => $entity) $ids[$key][$type] = $entity->getId();
    $routes = $container->get('router')->getRouteCollection(); $global = [];
    foreach ($routes as $name => $route) if (str_starts_with($route->getPath(), '/dnd/')) $global[$name] = [$route->getPath(),$route->getMethods()];
    $check($global === ['api_dnd_reference'=>['/dnd/reference',['GET']]], 'Exactly one global read and zero catalogue mutations/maintenance HTTP routes');
    $console = new Symfony\Bundle\FrameworkBundle\Console\Application($kernel);
    foreach (['app:dnd:import-class-features','app:dnd:import-feats','app:dnd:import-races','app:dnd:initialize-reference'] as $command) $check($console->has($command), 'CLI retained without execution: '.$command);
    $before = $fingerprint();
    foreach (['a','b'] as $viewer) {
        $session->set('_security_main', serialize(new UsernamePasswordToken($users[$viewer], 'main', ['ROLE_USER'])));
        foreach (['o','a','b'] as $scope) {
            foreach ($retired as [$method,$pattern,$type]) {
                $childType = str_contains($pattern,'adjustment-rules') ? 'adjustment-rules' : 'stages';
                $path = strtr($pattern, ['{id}'=>(string)$ids[$scope][$type],'{child}'=>(string)$ids[$scope][$childType]]);
                [$status] = $request($path, $method, ['slug'=>'forged','name'=>'Forged','description'=>'Forged','ownerId'=>$users[$viewer]->getId(),'custom'=>true]);
                $check($status === 404, "$viewer/$scope: retired $method $path returns 404, got $status");
            }
            foreach (['classes','subclasses','races','feats','features','resources','progressions','feature-rules','resource-rules'] as $type) {
                $check($request('/dnd/'.$type.'/'.$ids[$scope][$type])[0] === 404, 'No direct-ID bypass '.$viewer.'/'.$scope.'/'.$type);
            }
        }
        $check($before === $fingerprint(), 'All references/acquisitions/states unchanged after forged requests '.$viewer);
        [$status,$catalogue] = $request('/campaigns/'.$campaigns[$viewer]->getId().'/dnd/reference');
        $check($status === 200, 'Campaign catalogue retained '.$viewer);
        foreach (['classes','subclasses','races','feats'] as $type) {
            $actual = array_column($catalogue[$type],'id'); sort($actual); $expected = [$ids['o'][$type],$ids[$viewer][$type]]; sort($expected);
            $check($actual === $expected, 'Campaign O+owner '.$viewer.'/'.$type);
        }
    }
    $session->remove('_security_main');
    [$status,$catalogue] = $request('/dnd/reference');
    $check($status === 200, 'Public catalogue retained');
    foreach (['classes','subclasses','races','feats'] as $type) $check(array_column($catalogue[$type],'id') === [$ids['o'][$type]], 'Public OFFICIAL only '.$type);
    $check($request('/dnd/reference', 'POST', ['name'=>'Forged'])[0] === 405, 'Public catalogue GET only');
    foreach ($retired as [$method,$pattern,$type]) {
        $path = strtr($pattern, ['{id}'=>(string)$ids['a'][$type],'{child}'=>(string)$ids['a']['stages']]);
        $check($request($path,$method)[0] === 404, 'No anonymous legacy endpoint '.$method.' '.$path);
    }
    $check($before === $fingerprint(), 'Complete fixture graph unchanged');
    echo "OK: $checks legacy API assertions; 38 retired routes closed for A/B/O, temporary tables rolled back.\n";
} finally {
    while ($db->isTransactionActive()) $db->rollBack();
    $em->clear(); $kernel->shutdown();
}
