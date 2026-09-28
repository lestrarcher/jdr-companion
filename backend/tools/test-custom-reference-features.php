<?php

declare(strict_types=1);

use App\Entity\{User, Campaign, Character, GameSession, CharacterSessionState, CharacterFeatureDefinition, TrackableResourceDefinition, CharacterFeatureRule, CharacterClass, ProgressionDefinition};
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
$base = '/reference/custom/features';
$request = static function (string $method, string $suffix = '', array|string|null $payload = null) use ($kernel, $session, $base): array {
    $body = is_array($payload) ? json_encode((object) $payload, JSON_THROW_ON_ERROR) : $payload;
    $request = Request::create(str_starts_with($suffix, '/reference/') ? $suffix : $base.$suffix, $method, [], [$session->getName() => $session->getId()], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
    ], $body);
    $request->setSession($session);
    $response = $kernel->handle($request);
    return [$response->getStatusCode(), json_decode($response->getContent(), true)];
};
try {
    $db->beginTransaction(); $db->executeStatement('SET TRANSACTION READ ONLY');
    $owner1 = $em->find(User::class, 1); $login($owner1);
    [$status, $body] = $request('GET');
    $ids = array_column($body['features'] ?? [], 'id'); sort($ids);
    $check($status === 200 && $ids === [247, 248, 249], 'Real owner1 list: only three CUSTOM features');
    foreach ([247 => 54, 248 => 55, 249 => 56] as $featureId => $resourceId) {
        [$status, $body] = $request('GET', '/'.$featureId);
        $resource = $db->fetchAssociative('SELECT * FROM trackable_resource_definition WHERE id=?', [$resourceId]);
        $check($status === 200 && $body['feature']['resourceDefinition'] === [
            'id' => $resourceId, 'name' => $resource['name'], 'slug' => $resource['slug'], 'origin' => 'CUSTOM',
        ], 'Real feature resource serialization '.$featureId);
        $check((int) $db->fetchOne('SELECT count(*) FROM character_feature_rule WHERE feature_definition_id=?', [$featureId]) > 0, 'Real feature used: relation frozen and delete blocked '.$featureId);
    }
    $legacy = $db->fetchAssociative('SELECT * FROM trackable_resource_definition WHERE id=4');
    $check($legacy['origin'] === 'OFFICIAL' && (bool) $legacy['custom'], 'Real resource 4 OFFICIAL despite legacy custom=true');
    $db->rollBack(); $em->clear();

    $db->beginTransaction();
    foreach ((new Doctrine\ORM\Tools\SchemaTool($em))->getCreateSchemaSql($em->getMetadataFactory()->getAllMetadata()) as $sql) {
        $db->executeStatement(preg_replace(['/^CREATE TABLE /', '/^CREATE SEQUENCE /'], ['CREATE TEMP TABLE ', 'CREATE TEMP SEQUENCE '], $sql));
    }
    foreach ($em->getMetadataFactory()->getAllMetadata() as $meta) {
        $check($db->fetchOne('SELECT relpersistence FROM pg_class WHERE oid=to_regclass(?)', [$meta->getTableName()]) === 't', 'Isolated '.$meta->getTableName());
    }
    // Exact copy of the real official legacy resource, only in the isolated table.
    $db->executeStatement('INSERT INTO pg_temp.trackable_resource_definition SELECT (jsonb_populate_record(NULL::pg_temp.trackable_resource_definition, to_jsonb(r))).* FROM public.trackable_resource_definition r WHERE id=4');
    $db->executeStatement('ALTER TABLE pg_temp.trackable_resource_definition ALTER COLUMN id RESTART WITH 100');
    $a = (new User())->setEmail('a@custom-feature.invalid')->setPassword('unused');
    $b = (new User())->setEmail('b@custom-feature.invalid')->setPassword('unused');
    $official = (new CharacterFeatureDefinition('official-feature', 'Official'))->setCustom(true);
    foreach ([$a, $b, $official] as $entity) $em->persist($entity);
    $em->flush();
    $resourcePath = '/reference/custom/resources';
    $login($a);
    [$status, $body] = $request('POST', $resourcePath, ['name' => 'Resource A']);
    $check($status === 201, 'Resource A via existing CRUD'); $ra = $body['resource'];
    $login($b);
    [$status, $body] = $request('POST', $resourcePath, ['name' => 'Resource B']);
    $check($status === 201, 'Resource B via existing CRUD'); $rb = $body['resource'];
    $create = static function (string $name, ?int $resource = null) use ($request, $check): array {
        [$status, $body] = $request('POST', '', ['name' => $name, 'resourceDefinitionId' => $resource]);
        $check($status === 201, 'Create feature '.$name);
        return $body['feature'];
    };
    $foreign = $create('Foreign', $rb['id']);
    $login($a);
    $own = $create(' Zeta ');
    $alpha = $create('Alpha', 4);
    $alpha2 = $create('Alpha', $ra['id']);
    [$status, $body] = $request('GET');
    $check($status === 200 && array_column($body['features'], 'id') === [$alpha['id'], $alpha2['id'], $own['id']], 'Owned-only name/id ordering, duplicate names');
    $check($request('GET', '?ownerId='.$b->getId())[1] === $body, 'Query cannot change ownership');
    $check($own['resourceDefinition'] === null && $own['name'] === 'Zeta', 'Descriptive feature without resource');
    $check($alpha['resourceDefinition']['id'] === 4 && $alpha['resourceDefinition']['origin'] === 'OFFICIAL', 'Link to actual official resource 4 copy, ignoring legacy bool');
    $check($alpha2['resourceDefinition']['id'] === $ra['id'] && $alpha2['resourceDefinition']['origin'] === 'CUSTOM', 'Link to same-owner custom resource');
    $row = $db->fetchAssociative('SELECT * FROM character_feature_definition WHERE id=?', [$own['id']]);
    $check($row['origin'] === 'CUSTOM' && (int) $row['owner_id'] === $a->getId(), 'Server origin/owner');
    $check($row['activation_type'] === 'passive' && (bool) $row['visible'], 'Server presentation defaults');
    $check(preg_match('/^custom-[0-9a-f]{32}$/D', $own['slug']) === 1 && !array_key_exists('ownerId', $own), 'Technical slug and minimal response');

    foreach ([$foreign['id'], $official->getId(), 999999] as $id) {
        foreach (['GET', 'PATCH', 'DELETE'] as $method) {
            [$status, $error] = $request($method, '/'.$id, ['name' => 'intrusion']);
            $check($status === 404 && $error === ['message' => 'Capacité introuvable.'], 'Uniform root 404 '.$method);
        }
        $check($request('PATCH', '/'.$id, '{broken')[0] === 404, 'Scope before JSON');
    }
    foreach (['POST', 'PATCH'] as $method) {
        $suffix = $method === 'POST' ? '' : '/'.$own['id'];
        $beforeRow = $db->fetchAssociative('SELECT * FROM character_feature_definition WHERE id=?', [$own['id']]);
        $foreignError = $request($method, $suffix, ['name' => 'Must not persist', 'resourceDefinitionId' => $rb['id']]);
        $missingError = $request($method, $suffix, ['name' => 'Must not persist', 'resourceDefinitionId' => 999999]);
        $check($foreignError[0] === 400 && $foreignError === $missingError, 'Same unavailable resource error '.$method);
        $check($beforeRow === $db->fetchAssociative('SELECT * FROM character_feature_definition WHERE id=?', [$own['id']]), 'Invalid relation atomic '.$method);
        foreach (['owner' => 1, 'ownerId' => 1, 'origin' => 'OFFICIAL', 'slug' => 'chosen', 'unknown' => true,
            'resourceDefinition' => ['id' => 4], 'classId' => 1, 'unlockLevel' => 1, 'progressionThreshold' => 1,
            'activationType' => 'action', 'visible' => false, 'custom' => true] as $key => $value) {
            $check($request($method, $suffix, ['name' => 'Bad', $key => $value])[0] === 400, 'Forbidden '.$key.' '.$method);
        }
        foreach (['0', 0, -1, true, [], 2147483648] as $badId) {
            $check($request($method, $suffix, ['name' => 'Bad', 'resourceDefinitionId' => $badId])[0] === 400, 'Typed relation ID '.$method);
        }
        foreach (['[]', 'null', '{broken', '42'] as $raw) $check($request($method, $suffix, $raw)[0] === 400, 'JSON object '.$method);
    }
    foreach ([[], ['name' => ' '], ['name' => str_repeat('x', 151)], ['name' => 1], ['name' => 'Bad', 'description' => []]] as $payload) {
        $check($request('POST', '', $payload)[0] === 400, 'Invalid feature payload');
    }
    // All permitted transitions, including detachment and same-ID round trips.
    foreach ([4, $ra['id'], 4, null, $ra['id'], null] as $resourceId) {
        [$status, $body] = $request('PATCH', '/'.$own['id'], ['resourceDefinitionId' => $resourceId]);
        $check($status === 200 && ($body['feature']['resourceDefinition']['id'] ?? null) === $resourceId, 'Unused relation transition');
        $check($body['feature']['slug'] === $own['slug'], 'Immutable slug');
    }
    $check($request('PATCH', '/'.$own['id'], ['description' => ' 0 '])[1]['feature']['description'] === '0', 'Description normalized without dropping zero');
    $check($request('PATCH', '/'.$own['id'], ['description' => null])[0] === 200, 'Nullable description');
    [$status, $body] = $request('POST', '', ['name' => 'Optional fields omitted']);
    $check($status === 201 && $body['feature']['description'] === null && $body['feature']['resourceDefinition'] === null, 'POST optional fields default to null');
    $check($request('DELETE', '/'.$body['feature']['id'])[0] === 204, 'Unused descriptive feature deleted');

    // Existing Resource CRUD observes Feature FK without any duplicated guard.
    $check($request('PATCH', $resourcePath.'/'.$ra['id'], ['baseMaximum' => 7])[0] === 409, 'Feature makes Resource mechanics immutable');
    $check($request('DELETE', $resourcePath.'/'.$ra['id'])[0] === 409, 'Feature blocks Resource deletion');
    $check($request('DELETE', '/'.$alpha2['id'])[0] === 204, 'Unused linked Feature deleted');
    $check($request('GET', $resourcePath.'/'.$ra['id'])[0] === 200, 'Feature deletion preserves Resource');
    $check($request('PATCH', $resourcePath.'/'.$ra['id'], ['baseMaximum' => 7])[0] === 200, 'Resource unblocked when no usage remains');
    $check($request('DELETE', $resourcePath.'/'.$ra['id'])[0] === 204, 'Resource can now be deleted');

    // Own rules, a progression rule, and an inconsistent foreign rule all block usage.
    foreach (['class', 'progression', 'foreign-rule'] as $usage) {
        $feature = $create('Used '.$usage, 4);
        $a = $em->find(User::class, $a->getId()); $b = $em->find(User::class, $b->getId());
        $entity = $em->find(CharacterFeatureDefinition::class, $feature['id']);
        if ($usage === 'progression') {
            $source = new ProgressionDefinition('feature-progression', 'Progression');
            $rule = CharacterFeatureRule::forProgression($entity, $source, 50);
        } else {
            $source = new CharacterClass('feature-'.$usage, 'Class', 8, 3);
            $rule = CharacterFeatureRule::forClass($entity, $source, 20);
        }
        $em->persist($source); $em->persist($rule); $em->flush();
        $db->update('character_feature_rule', ['origin' => 'CUSTOM', 'owner_id' => ($usage === 'foreign-rule' ? $b : $a)->getId()], ['id' => $rule->getId()]);
        $beforeRow = $db->fetchAssociative('SELECT * FROM character_feature_definition WHERE id=?', [$feature['id']]);
        $check($request('PATCH', '/'.$feature['id'], ['name' => 'Must not persist', 'resourceDefinitionId' => null])[0] === 409, 'Used mixed PATCH '.$usage);
        $check($beforeRow === $db->fetchAssociative('SELECT * FROM character_feature_definition WHERE id=?', [$feature['id']]), 'Used PATCH fully atomic '.$usage);
        $check($request('DELETE', '/'.$feature['id'])[0] === 409, 'Used DELETE '.$usage);
        $check($request('PATCH', '/'.$feature['id'], ['name' => 'Edited '.$usage, 'description' => 'Allowed'])[0] === 200, 'Used description allowed '.$usage);
        $check($request('PATCH', '/'.$feature['id'], ['resourceDefinitionId' => 4])[0] === 200, 'Same ID is not mechanical change '.$usage);
    }

    // A resource-only historical trace must NOT count as Feature usage.
    [$status, $body] = $request('POST', $resourcePath, ['name' => 'Historical resource']);
    $historicalResource = $body['resource'];
    $historyFeature = $create('No feature history', $historicalResource['id']);
    $a = $em->find(User::class, $a->getId());
    $campaign = new Campaign($a, 'feature-history', 'History');
    $character = new Character($campaign, 'feature-history', 'History', Character::TYPE_PLAYER);
    $game = new GameSession($campaign, 'feature-history', 'History');
    $state = new CharacterSessionState($game, $character, ['resources' => [['id' => $historicalResource['slug'], 'currentValue' => 0]]]);
    foreach ([$campaign, $character, $game, $state] as $entity) $em->persist($entity);
    $em->flush();
    $stateBefore = $db->fetchAllAssociative('SELECT * FROM character_session_state ORDER BY id');
    $check($request('PATCH', '/'.$historyFeature['id'], ['resourceDefinitionId' => null])[0] === 200, 'Resource trace does not freeze unrelated Feature');
    $check($request('PATCH', '/'.$historyFeature['id'], ['resourceDefinitionId' => $historicalResource['id']])[0] === 200, 'Reattach unused Feature');
    $check($request('DELETE', '/'.$historyFeature['id'])[0] === 204, 'Resource trace does not block Feature DELETE');
    $check($request('DELETE', $resourcePath.'/'.$historicalResource['id'])[0] === 409, 'Historical Resource stays protected by Resource CRUD');
    $check($stateBefore === $db->fetchAllAssociative('SELECT * FROM character_session_state ORDER BY id'), 'No state purge');

    // Corrupt pre-existing relation must not leak the foreign resource through GET/LIST.
    $corrupt = $create('Corrupt fixture');
    $db->update('character_feature_definition', ['resource_definition_id' => $rb['id']], ['id' => $corrupt['id']]);
    $check($request('GET', '/'.$corrupt['id'])[0] === 404, 'Corrupt foreign dependency hidden');
    $check(!in_array($corrupt['id'], array_column($request('GET')[1]['features'], 'id'), true), 'Corrupt dependency excluded before serialization');

    $db->createSavepoint('duplicate_slug');
    try {
        $db->executeStatement("INSERT INTO character_feature_definition SELECT (jsonb_populate_record(NULL::character_feature_definition, to_jsonb(t) || ?::jsonb)).* FROM character_feature_definition t WHERE id=?",
            [json_encode(['id' => 999999, 'owner_id' => $b->getId()]), $own['id']]);
        $check(false, 'Duplicate slug must fail');
    } catch (Doctrine\DBAL\Exception\UniqueConstraintViolationException $error) {
        $check(str_contains($error->getMessage(), 'uniq_character_feature_slug'), 'Global slug index across owners');
    } finally {
        $db->rollbackSavepoint('duplicate_slug'); $db->releaseSavepoint('duplicate_slug');
    }
    // Force the same unique-error handling path without controlling random generation.
    $db->createSavepoint('collision_response');
    $db->executeStatement('CREATE UNIQUE INDEX test_feature_name_collision ON character_feature_definition (name)');
    $check($request('POST', '', ['name' => 'Zeta'])[0] === 409, 'Unique error translated into 409');
    $db->rollbackSavepoint('collision_response'); $db->releaseSavepoint('collision_response');

    $login($b);
    $check($request('GET', '/'.$own['id'])[0] === 404, 'B cannot read A');
    $check(array_column($request('GET')[1]['features'], 'id') === [$foreign['id']], 'B only sees B');
    $login(null);
    foreach (['GET', 'POST', 'PATCH', 'DELETE'] as $method) {
        $check(in_array($request($method, in_array($method, ['PATCH', 'DELETE'], true) ? '/'.$own['id'] : '', ['name' => 'Anonymous'])[0], [401,403], true), 'Anonymous '.$method);
    }
} finally {
    while ($db->isTransactionActive()) $db->rollBack();
    $em->clear();
    $check($before === $snapshot(), 'All public fingerprints unchanged');
    $kernel->shutdown();
}
echo "OK: $checks custom feature CRUD assertions; owner1 READ ONLY; isolated fixtures rolled back.\n";
