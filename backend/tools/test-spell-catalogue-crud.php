<?php

declare(strict_types=1);

use App\Entity\User;
use App\Service\SpellCatalogue;
use Symfony\Component\HttpFoundation\{Request, Session\Session, Session\Storage\MockArraySessionStorage};
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

require __DIR__.'/../vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv(__DIR__.'/../.env');
putenv('SHELL_VERBOSITY=-1'); $_SERVER['SHELL_VERBOSITY'] = $_ENV['SHELL_VERBOSITY'] = -1;
$kernel = new App\Kernel('dev', true); $kernel->boot();
$em = $kernel->getContainer()->get('doctrine')->getManager(); $db = $em->getConnection();
$service = new SpellCatalogue($db); $checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void { if (!$ok) throw new RuntimeException($message); ++$checks; };
$refuse = static function (callable $fn, int $status) use ($check): void {
    try { $fn(); } catch (HttpExceptionInterface $error) { $check($error->getStatusCode() === $status, 'Expected HTTP '.$status.', got '.$error->getStatusCode()); return; }
    throw new RuntimeException('Expected refusal '.$status);
};
$session = new Session(new MockArraySessionStorage()); $session->start();
$login = static function (?User $user) use ($session): void {
    if ($user) $session->set('_security_main', serialize(new UsernamePasswordToken($user, 'main', $user->getRoles())));
    else $session->remove('_security_main');
};
$request = static function (string $method, string $path, ?array $payload = null, bool $form = false) use ($kernel, $session): Symfony\Component\HttpFoundation\Response {
    $r = Request::create($path, $method, $form ? ($payload ?? []) : [], [$session->getName() => $session->getId()], [], ['CONTENT_TYPE' => $form ? 'application/x-www-form-urlencoded' : 'application/json', 'HTTP_ACCEPT' => $form ? 'text/html' : 'application/json'], !$form && $payload !== null ? json_encode((object) $payload) : null);
    $r->setSession($session); return $kernel->handle($r);
};
$token = static function (string $html): string {
    if (!preg_match('/name="_token" value="([^"]+)"/', $html, $match)) throw new RuntimeException('Missing CSRF input');
    return html_entity_decode($match[1]);
};
$fixture = ['slug' => 'fixture-spell', 'name' => 'Sort fictif', 'level' => 0, 'school' => 'illusion', 'castingTime' => 'Test', 'range' => 'Test', 'duration' => 'Test', 'description' => 'Texte fictif de test, aucun import'];
try {
    $db->beginTransaction();
    $metadata = $em->getMetadataFactory()->getAllMetadata();
    foreach ((new Doctrine\ORM\Tools\SchemaTool($em))->getCreateSchemaSql($metadata) as $sql) $db->executeStatement(preg_replace(['/^CREATE TABLE /', '/^CREATE SEQUENCE /'], ['CREATE TEMP TABLE ', 'CREATE TEMP SEQUENCE '], $sql));
    foreach ($metadata as $meta) if ($db->fetchOne('SELECT relpersistence FROM pg_class WHERE oid=to_regclass(?)', [$meta->getTableName()]) !== 't') throw new RuntimeException('Not isolated');
    $owner = (new User())->setEmail('spell-a@example.invalid')->setPassword('unused');
    $other = (new User())->setEmail('spell-b@example.invalid')->setPassword('unused');
    $admin = (new User())->setEmail('spell-admin@example.invalid')->setPassword('unused')->setRoles([SpellCatalogue::ADMIN_ROLE]);
    foreach ([$owner, $other, $admin] as $user) $em->persist($user);
    $em->flush();
    $own = $service->create($fixture, $owner);
    $foreign = $service->create(array_replace($fixture, ['slug' => 'foreign', 'name' => 'Secret']), $other);
    $official = $service->create(array_replace($fixture, ['slug' => 'official', 'name' => 'Officiel fictif']), $admin, true);
    $check($own['origin'] === 'CUSTOM' && $official['origin'] === 'OFFICIAL', 'Explicit creation modes');
    $check($service->search([], $owner)['total'] === 2 && $service->search([], $admin)['total'] === 1, 'No admin bypass of CUSTOM visibility');
    $check($service->search(['origin' => 'CUSTOM', 'level' => '0', 'school' => 'illusion'], $owner)['total'] === 1, 'Combined filters and zero level');
    $check($service->search(['q' => 'secret'], $owner)['total'] === 0, 'Search and count cannot leak foreign content');
    $check($service->search(['q' => '%'], $owner)['total'] === 0, 'LIKE wildcard escaped');
    $page = $service->search(['pageSize' => 1, 'page' => 2], $owner);
    $check($page['total'] === 2 && count($page['spells']) === 1 && $page['spells'][0]['id'] === $own['id'], 'Stable pagination after visibility');
    foreach ([['level' => -1], ['level' => 10], ['level' => false], ['page' => 0], ['pageSize' => 101], ['school' => 'bad'], ['origin' => 'bad'], ['q' => []], ['classId' => 0]] as $filter) $refuse(fn () => $service->search($filter, $owner), 400);
    $refuse(fn () => $service->create($fixture, $owner, true), 403);
    foreach ([$owner, $admin] as $actor) {
        $refuse(fn () => $service->get($foreign['id'], $actor), 404);
        $refuse(fn () => $service->patch($foreign['id'], ['name' => 'Hacked'], $actor), 404);
        $refuse(fn () => $service->delete($foreign['id'], $actor), 404);
    }
    $refuse(fn () => $service->patch($official['id'], ['name' => 'Hacked'], $owner), 403);
    $refuse(fn () => $service->delete($official['id'], $owner), 403);
    foreach (['owner_id' => $other->getId(), 'origin' => 'OFFICIAL', 'edition' => '2024', 'importKey' => 'fake', 'slug' => 'changed'] as $field => $value) $refuse(fn () => $service->patch($own['id'], [$field => $value], $owner), 400);
    $refuse(fn () => $service->create($fixture + ['origin' => 'OFFICIAL'], $owner), 400);
    $refuse(fn () => $service->create($fixture, $other), 409);
    $refuse(fn () => $service->patch($own['id'], ['name' => 'Partial', 'material' => true], $owner), 400);
    $check($service->get($own['id'], $owner)['name'] === 'Sort fictif', 'Invalid PATCH is atomic');
    $mechanics = ['name' => 'Nom modifié', 'level' => 9, 'school' => 'abjuration', 'castingTime' => 'Nouveau temps', 'range' => 'Nouvelle portée', 'duration' => 'Nouvelle durée', 'verbal' => true, 'somatic' => true, 'material' => true, 'materialDescription' => 'Objet fictif', 'materialCostCp' => 42, 'materialConsumed' => true, 'concentration' => true, 'ritual' => true, 'description' => 'Nouveaux effets', 'higherLevels' => 'Autres effets', 'originalName' => 'Original', 'sourceCode' => 'TEST', 'sourceReference' => 'Fixture', 'sourceUrl' => 'https://example.invalid/test'];
    foreach ([$own['id'] => $owner, $official['id'] => $admin] as $id => $actor) {
        $saved = $service->patch($id, $mechanics, $actor, $id === $official['id']);
        foreach ($mechanics as $field => $value) $check($saved[$field] === $value, 'Editable property '.$field);
    }
    $check($service->search(['source' => 'TEST', 'q' => 'original'], $owner)['total'] === 2, 'Source and original-name search');
    $refuse(fn () => $service->patch($own['id'], ['ritual' => 'true'], $owner), 400);
    // A future relational dependency must fail safely without deleting the spell.
    $db->executeStatement('CREATE TEMP TABLE spell_test_usage (spell_id INT REFERENCES spell_definition(id) ON DELETE RESTRICT)');
    $db->insert('spell_test_usage', ['spell_id' => $own['id']]);
    $refuse(fn () => $service->delete($own['id'], $owner), 409);
    $check($service->get($own['id'], $owner)['id'] === $own['id'], 'Dependency rollback');
    $db->executeStatement('DELETE FROM spell_test_usage');

    $login(null);
    $check($request('GET', '/reference/custom/spells')->getStatusCode() === 401, 'API requires authentication');
    $check(in_array($request('GET', '/admin/reference/spells', null, true)->getStatusCode(), [302, 401], true), 'Admin requires authentication');
    $login($owner);
    $check($request('GET', '/reference/custom/spells?level=9')->getStatusCode() === 200, 'API filtered list');
    $check($request('POST', '/reference/custom/spells/official', $fixture)->getStatusCode() === 404, 'API official creation removed');
    $check($request('PATCH', '/reference/custom/spells/'.$official['id'], ['name' => 'No'])->getStatusCode() === 403, 'API official edit denied');
    $check($request('DELETE', '/reference/custom/spells/'.$official['id'])->getStatusCode() === 403, 'API official deletion denied');
    $check($request('GET', '/reference/custom/spells/'.$foreign['id'])->getStatusCode() === 404, 'API foreign hidden');
    $list = $request('GET', '/admin/reference/spells?q=Nom&level=9&origin=OFFICIAL', null, true);
    $check($list->getStatusCode() === 403, 'MJ cannot access Symfony spell administration');
    $read = $request('GET', '/admin/reference/spells/'.$official['id'], null, true);
    $check($read->getStatusCode() === 403, 'Symfony detail requires admin');
    $check($request('GET', '/admin/reference/spells/new', null, true)->getStatusCode() === 403, 'Official create form forbidden');
    $login($admin);
    $check($request('GET', '/admin/reference/spells/'.$own['id'], null, true)->getStatusCode() === 404, 'Symfony rejects CUSTOM');
    $check($request('PATCH', '/reference/custom/spells/'.$official['id'], ['name' => 'No'])->getStatusCode() === 403, 'Angular API cannot mutate OFFICIAL even as admin');
    $check(!$service->get($official['id'], $admin)['editable'], 'Angular OFFICIAL is read-only even as admin');
    $editPath = '/admin/reference/spells/'.$official['id'].'?q=Nom&level=9&page=2';
    $edit = $request('GET', $editPath, null, true);
    $check($edit->getStatusCode() === 200, 'Official edit form');
    foreach (array_keys($mechanics) as $field) $check(str_contains($edit->getContent(), 'name="'.$field.'"'), 'Form exposes '.$field);
    $check($request('POST', $editPath, ['_token' => 'bad', 'name' => 'No'], true)->getStatusCode() === 403, 'CSRF mandatory');
    $form = $mechanics;
    foreach (SpellCatalogue::FLAGS as $field => $_) $form[$field] = '1';
    $form['level'] = '8'; $form['materialCostCp'] = '123'; $form['_token'] = $token($edit->getContent());
    $response = $request('POST', $editPath, $form, true);
    $check($response->getStatusCode() === 303 && str_contains($response->headers->get('Location'), 'page=2') && str_contains($response->headers->get('Location'), 'level=9'), 'PRG retains filters and page');
    $check($service->get($official['id'], $admin)['level'] === 8 && $service->get($official['id'], $admin)['materialCostCp'] === 123, 'Form edits mechanics');
    $form['level'] = '10'; $form['name'] = 'Tentative conservée';
    $error = $request('POST', $editPath, $form, true);
    $check($error->getStatusCode() === 400 && str_contains($error->getContent(), 'Tentative conservée'), 'Rejected form retains input');
    $check($service->get($official['id'], $admin)['level'] === 8, 'Rejected form preserves database');
    $login($admin);
    $check($request('GET', '/admin/reference/spells/'.$foreign['id'], null, true)->getStatusCode() === 404, 'Admin cannot access another CUSTOM');
    $new = $request('GET', '/admin/reference/spells/new?origin=CUSTOM', null, true);
    $check($new->getStatusCode() === 200, 'Official create form available to role');
    $created = $request('POST', '/admin/reference/spells/new?origin=CUSTOM', array_replace($fixture, ['slug' => 'form-official', 'level' => '1', '_token' => $token($new->getContent())]), true);
    $check($created->getStatusCode() === 303, 'Official created via form');
    $check(str_contains($created->headers->get('Location'), 'origin=OFFICIAL'), 'Symfony forces official scope');
    $officialEdit = $request('GET', '/admin/reference/spells/'.$official['id'], null, true);
    $officialForm = $form; $officialForm['level'] = '7'; $officialForm['_token'] = $token($officialEdit->getContent());
    $check($request('POST', '/admin/reference/spells/'.$official['id'], $officialForm, true)->getStatusCode() === 303, 'Official mechanics edited via form');
    $check($service->get($official['id'], $admin)['level'] === 7, 'Official mechanics persisted');
    require __DIR__.'/test-spell-class-list-cases.php';
    require __DIR__.'/test-spell-subclass-grant-cases.php';
    $officialPage = $request('GET', '/admin/reference/spells/'.$official['id'], null, true);
    $deleted = $request('POST', '/admin/reference/spells/'.$official['id'], ['_token' => $token($officialPage->getContent()), '_action' => 'delete'], true);
    $check($deleted->getStatusCode() === 303, 'Official deleted via form');
    $login($owner);
    $apiCreate = $request('POST', '/reference/custom/spells', array_replace($fixture, ['slug' => 'api-custom']));
    $check($apiCreate->getStatusCode() === 201, 'API custom create');
    $apiId = json_decode($apiCreate->getContent(), true)['spell']['id'];
    $check($request('PATCH', '/reference/custom/spells/'.$apiId, ['ritual' => true])->getStatusCode() === 200, 'API custom PATCH');
    $check($request('DELETE', '/reference/custom/spells/'.$apiId)->getStatusCode() === 204, 'API custom delete');
    $command = new Symfony\Component\Console\Tester\CommandTester(new App\Command\ReferenceAdminCommand($em));
    $check($command->execute(['email' => $owner->getEmail()]) === 0 && $service->canManageOfficial($em->getRepository(User::class)->findOneBy(['email' => $owner->getEmail()])), 'Explicit role grant');
    $check($command->execute(['email' => $owner->getEmail(), '--revoke' => true]) === 0 && !$service->canManageOfficial($em->getRepository(User::class)->findOneBy(['email' => $owner->getEmail()])), 'Explicit role revoke');
    $check($command->execute(['email' => 'absent@example.invalid']) === 1, 'Unknown account refused');
    echo "OK: $checks spell CRUD/admin assertions; temporary tables only.\n";
} finally {
    while ($db->isTransactionActive()) $db->rollBack();
    $kernel->shutdown();
}
