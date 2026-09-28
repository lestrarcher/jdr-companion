<?php

declare(strict_types=1);

use App\Entity\{User, Campaign, Character, GameSession, CharacterSessionState, CharacterFeatureDefinition, TrackableResourceDefinition, TrackableResourceRule, CharacterClass};
use App\Enum\{ResourceRechargeType, ResourceMaximumType};
use Symfony\Component\HttpFoundation\{Request, Session\Session, Session\Storage\MockArraySessionStorage};
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

require __DIR__.'/../vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv(__DIR__.'/../.env');
putenv('SHELL_VERBOSITY=-1');
$_SERVER['SHELL_VERBOSITY'] = $_ENV['SHELL_VERBOSITY'] = -1;
$kernel = new App\Kernel('dev', true); $kernel->boot();
$em = $kernel->getContainer()->get('doctrine')->getManager(); $db = $em->getConnection();
$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks): void {
    if (!$ok) throw new RuntimeException($label);
    ++$checks;
};
$snapshot = static function () use ($db): array {
    $out = [];
    foreach ($db->fetchFirstColumn("SELECT tablename FROM pg_tables WHERE schemaname='public' AND tablename<>'doctrine_migration_versions' ORDER BY tablename") as $table) {
        $quoted = 'public.'.$db->quoteIdentifier($table);
        $out[$table] = $db->fetchOne("SELECT md5(COALESCE(string_agg(to_jsonb(t)::text, '' ORDER BY to_jsonb(t)::text),'')) FROM $quoted t");
    }
    return $out;
};
$before = $snapshot();
$session = new Session(new MockArraySessionStorage()); $session->start();
$login = static function (?User $user) use ($session): void {
    if ($user === null) $session->remove('_security_main');
    else $session->set('_security_main', serialize(new UsernamePasswordToken($user, 'main', ['ROLE_USER'])));
};
$base = '/reference/custom/resources';
$request = static function (string $method, string $suffix = '', array|string|null $payload = null) use ($kernel, $session, $base): array {
    $body = is_array($payload) ? json_encode((object) $payload, JSON_THROW_ON_ERROR) : $payload;
    $request = Request::create($base.$suffix, $method, [], [$session->getName() => $session->getId()], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
    ], $body);
    $request->setSession($session);
    $response = $kernel->handle($request);
    return [$response->getStatusCode(), json_decode($response->getContent(), true)];
};
try {
    // Real catalogue probe: authenticated GET only, under READ ONLY.
    $db->beginTransaction(); $db->executeStatement('SET TRANSACTION READ ONLY');
    $owner1 = $em->find(User::class, 1); $login($owner1);
    [$status, $body] = $request('GET');
    $ids = array_column($body['resources'] ?? [], 'id'); sort($ids);
    $check($status === 200 && $ids === [54, 55, 56], 'Real owner1 catalogue exactly 54/55/56, never official 4/53');
    $db->rollBack(); $em->clear();

    $db->beginTransaction();
    foreach ((new Doctrine\ORM\Tools\SchemaTool($em))->getCreateSchemaSql($em->getMetadataFactory()->getAllMetadata()) as $sql) {
        $db->executeStatement(preg_replace(['/^CREATE TABLE /', '/^CREATE SEQUENCE /'], ['CREATE TEMP TABLE ', 'CREATE TEMP SEQUENCE '], $sql));
    }
    foreach ($em->getMetadataFactory()->getAllMetadata() as $meta) {
        $check($db->fetchOne('SELECT relpersistence FROM pg_class WHERE oid=to_regclass(?)', [$meta->getTableName()]) === 't', 'Isolated '.$meta->getTableName());
    }
    $a = (new User())->setEmail('a@custom-resource.invalid')->setPassword('unused');
    $b = (new User())->setEmail('b@custom-resource.invalid')->setPassword('unused');
    $official = (new TrackableResourceDefinition('official-test', 'Official', ResourceRechargeType::None))->setCustom(true);
    foreach ([$a, $b, $official] as $entity) $em->persist($entity);
    $em->flush();
    $login($a);
    $create = static function (string $name) use ($request, $check): array {
        [$status, $body] = $request('POST', '', ['name' => $name, 'baseMaximum' => 3, 'rechargeType' => 'long-rest']);
        $check($status === 201, 'POST own resource');
        return $body['resource'];
    };
    $own = $create(' Zeta ');
    $alpha = $create('Alpha');
    $alpha2 = $create('Alpha');
    $row = $db->fetchAssociative('SELECT * FROM trackable_resource_definition WHERE id=?', [$own['id']]);
    $check($row['origin'] === 'CUSTOM' && (int) $row['owner_id'] === $a->getId(), 'Server-owned origin and owner');
    $check(preg_match('/^custom-[0-9a-f]{32}$/D', $own['slug']) === 1, 'Generated technical slug');
    $check($own['name'] === 'Zeta' && !array_key_exists('ownerId', $own) && !array_key_exists('storedValuesConfig', $own), 'Trimmed minimal response, stored values closed');
    $login($b); $foreign = $create('Foreign'); $login($a);
    $db->createSavepoint('duplicate_slug');
    try {
        $db->executeStatement(
            "INSERT INTO trackable_resource_definition SELECT (jsonb_populate_record(NULL::trackable_resource_definition, to_jsonb(t) || ?::jsonb)).* FROM trackable_resource_definition t WHERE id=?",
            [json_encode(['id' => 999999, 'owner_id' => $b->getId()]), $own['id']],
        );
        $check(false, 'Duplicate slug must fail across owners');
    } catch (Doctrine\DBAL\Exception\UniqueConstraintViolationException $error) {
        $check(str_contains($error->getMessage(), 'uniq_trackable_resource_slug'), 'Global slug index is the final guard');
    } finally {
        $db->rollbackSavepoint('duplicate_slug'); $db->releaseSavepoint('duplicate_slug');
    }
    [$status, $body] = $request('GET');
    $check($status === 200 && array_column($body['resources'], 'id') === [$alpha['id'], $alpha2['id'], $own['id']], 'Owned-only deterministic name/id list; duplicate names allowed');
    $check($request('GET', '?ownerId='.$b->getId())[1] === $body, 'Query cannot change owner scope');
    $check($request('GET', '/'.$own['id'])[0] === 200, 'Own GET');
    foreach ([$foreign['id'], $official->getId(), 999999] as $id) {
        foreach (['GET', 'PATCH', 'DELETE'] as $method) {
            [$status, $error] = $request($method, '/'.$id, ['name' => 'intrusion']);
            $check($status === 404 && $error === ['message' => 'Ressource introuvable.'], 'Uniform inaccessible '.$method);
        }
        $check($request('PATCH', '/'.$id, '{broken')[0] === 404, 'Inaccessible before payload parsing');
    }
    foreach (['owner' => 1, 'ownerId' => 1, 'origin' => 'OFFICIAL', 'slug' => 'chosen',
        'unknown' => true, 'custom' => true, 'storedValuesConfig' => null, 'maximumOverride' => 4, 'maximumBonus' => 1] as $key => $value) {
        foreach (['POST', 'PATCH'] as $method) {
            $check($request($method, $method === 'POST' ? '' : '/'.$own['id'], ['name' => 'bad', $key => $value])[0] === 400, 'Forbidden '.$key.' '.$method);
        }
    }
    foreach ([
        [], ['name' => ' '], ['name' => str_repeat('x', 151)], ['name' => 4],
        ['name' => 'bad', 'baseMaximum' => -1], ['name' => 'bad', 'baseMaximum' => '2'],
        ['name' => 'bad', 'baseMaximum' => true], ['name' => 'bad', 'baseMaximum' => 2147483648],
        ['name' => 'bad', 'multiplier' => 0], ['name' => 'bad', 'minimumMaximum' => -1],
        ['name' => 'bad', 'rechargeType' => 'daily'], ['name' => 'bad', 'maximumType' => 'arbitrary'],
        ['name' => 'bad', 'description' => []], ['name' => 'bad', 'maximumType' => 'ability-modifier'],
        ['name' => 'bad', 'scalingAbility' => 'wisdom'],
    ] as $payload) $check($request('POST', '', $payload)[0] === 400, 'Invalid typed POST');
    foreach (['[]', 'null', '{broken', '42'] as $raw) {
        $check($request('POST', '', $raw)[0] === 400, 'Invalid JSON object');
        $check($request('PATCH', '/'.$own['id'], $raw)[0] === 400, 'Invalid PATCH JSON object');
    }
    foreach (['none', 'short-rest', 'long-rest'] as $recharge) {
        foreach (['fixed', 'proficiency-bonus', 'ability-modifier'] as $maximum) {
            [$status, $body] = $request('POST', '', ['name' => 'Formula', 'rechargeType' => $recharge,
                'maximumType' => $maximum, 'scalingAbility' => $maximum === 'ability-modifier' ? 'wisdom' : null]);
            $check($status === 201 && $body['resource']['maximumType'] === $maximum
                && $body['resource']['rechargeType'] === $recharge, 'Supported formula/recharge');
            $check($request('DELETE', '/'.$body['resource']['id'])[0] === 204, 'Unused formula resource deleted');
        }
    }
    [$status, $body] = $request('PATCH', '/'.$own['id'], [
        'baseMaximum' => 5, 'maximumType' => 'ability-modifier', 'scalingAbility' => 'wisdom',
        'multiplier' => 2, 'minimumMaximum' => 1, 'rechargeType' => 'short-rest', 'description' => ' 0 ',
    ]);
    $check($status === 200 && $body['resource']['scalingAbility'] === 'wisdom' && $body['resource']['description'] === '0', 'Unused mechanics and zero string description');
    $check($body['resource']['slug'] === $own['slug'], 'Slug unchanged after PATCH');
    $check($request('PATCH', '/'.$own['id'], ['maximumType' => 'fixed'])[0] === 400, 'Atomic invalid formula change');
    $check($request('PATCH', '/'.$own['id'], ['maximumType' => 'fixed', 'scalingAbility' => null])[0] === 200, 'Atomic formula transition');
    $check($request('DELETE', '/'.$alpha['id'])[0] === 204 && $request('GET', '/'.$alpha['id'])[0] === 404, 'Unused deletion');

    $a = $em->find(User::class, $a->getId());
    $b = $em->find(User::class, $b->getId());
    $campaign = new Campaign($a, 'resource-usage', 'Usage');
    $otherCampaign = new Campaign($b, 'foreign-resource-usage', 'Foreign usage');
    $character = new Character($campaign, 'resource-character', 'Usage', Character::TYPE_PLAYER);
    $foreignCharacter = new Character($otherCampaign, 'foreign-character', 'Foreign usage', Character::TYPE_PLAYER);
    $game = new GameSession($campaign, 'resource-session', 'Usage');
    $foreignGame = new GameSession($otherCampaign, 'foreign-session', 'Foreign usage');
    $class = new CharacterClass('resource-class', 'Class', 8, 3);
    foreach ([$campaign, $otherCampaign, $character, $foreignCharacter, $game, $foreignGame, $class] as $entity) $em->persist($entity);
    $em->flush();
    foreach (['feature', 'rule', 'history-zero', 'foreign-history', 'legacy-definition', 'foreign-feature'] as $usage) {
        $resource = $create('Used '.$usage);
        $a = $em->find(User::class, $a->getId());
        $b = $em->find(User::class, $b->getId());
        $class = $em->find(CharacterClass::class, $class->getId());
        $character = $em->find(Character::class, $character->getId());
        $foreignCharacter = $em->find(Character::class, $foreignCharacter->getId());
        $game = $em->find(GameSession::class, $game->getId());
        $foreignGame = $em->find(GameSession::class, $foreignGame->getId());
        $entity = $em->find(TrackableResourceDefinition::class, $resource['id']);
        if ($usage === 'feature' || $usage === 'foreign-feature') {
            $feature = (new CharacterFeatureDefinition('use-'.$usage, 'Feature'))->setResourceDefinition($entity);
            $em->persist($feature); $em->flush();
            $db->update('character_feature_definition', ['origin' => 'CUSTOM', 'owner_id' => ($usage === 'feature' ? $a : $b)->getId()], ['id' => $feature->getId()]);
        } elseif ($usage === 'rule') {
            $rule = TrackableResourceRule::forClass($entity, $class, 1);
            $em->persist($rule); $em->flush();
            $db->update('trackable_resource_rule', ['origin' => 'CUSTOM', 'owner_id' => $a->getId()], ['id' => $rule->getId()]);
        } elseif ($usage === 'legacy-definition') {
            $db->update('character', ['definition' => json_encode(['resources' => [['id' => $resource['slug']]]])], ['id' => $character->getId()]);
        } else {
            $state = new CharacterSessionState($usage === 'history-zero' ? $game : $foreignGame,
                $usage === 'history-zero' ? $character : $foreignCharacter,
                ['resources' => [['id' => $resource['slug'], 'currentValue' => 0]], 'progressions' => []]);
            $state->setParticipating(false);
            $em->persist($state); $em->flush();
        }
        $stateBefore = $db->fetchAllAssociative('SELECT * FROM character_session_state ORDER BY id');
        $rowBefore = $db->fetchAssociative('SELECT * FROM trackable_resource_definition WHERE id=?', [$resource['id']]);
        $check($request('PATCH', '/'.$resource['id'], ['name' => 'Must not persist', 'baseMaximum' => 99])[0] === 409, 'Used mixed PATCH '.$usage);
        $check($rowBefore === $db->fetchAssociative('SELECT * FROM trackable_resource_definition WHERE id=?', [$resource['id']]), 'Rejected PATCH fully atomic '.$usage);
        $check($request('DELETE', '/'.$resource['id'])[0] === 409, 'Used DELETE '.$usage);
        $check($request('PATCH', '/'.$resource['id'], ['name' => 'Edited '.$usage, 'description' => 'Allowed'])[0] === 200, 'Used descriptive PATCH '.$usage);
        $check($request('PATCH', '/'.$resource['id'], ['baseMaximum' => 3])[0] === 200, 'Unchanged mechanics allowed '.$usage);
        $check($stateBefore === $db->fetchAllAssociative('SELECT * FROM character_session_state ORDER BY id'), 'Historical state never purged '.$usage);
    }
    // Index collision path: force a deterministic unique violation on the isolated table.
    $db->createSavepoint('collision_test');
    $db->executeStatement('CREATE UNIQUE INDEX test_resource_name_collision ON trackable_resource_definition (name)');
    $check($request('POST', '', ['name' => 'Zeta'])[0] === 409, 'Unique violation translated, transaction usable afterwards');
    $db->rollbackSavepoint('collision_test'); $db->releaseSavepoint('collision_test');

    $login($b);
    $check($request('GET', '/'.$own['id'])[0] === 404, 'B cannot read A');
    [$status, $body] = $request('GET');
    $check($status === 200 && array_column($body['resources'], 'id') === [$foreign['id']], 'B list isolated');
    $login(null);
    foreach (['GET', 'POST', 'PATCH', 'DELETE'] as $method) {
        $check(in_array($request($method, in_array($method, ['PATCH', 'DELETE'], true) ? '/'.$own['id'] : '', ['name' => 'anonymous'])[0], [401, 403], true), 'Anonymous '.$method);
    }
} finally {
    while ($db->isTransactionActive()) $db->rollBack();
    $em->clear();
    $check($before === $snapshot(), 'All public business fingerprints unchanged');
    $kernel->shutdown();
}
echo "OK: $checks custom resource CRUD assertions; real owner1 READ ONLY; isolated fixtures rolled back.\n";
