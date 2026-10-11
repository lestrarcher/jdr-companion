<?php

declare(strict_types=1);

use App\Entity\{CharacterClass, CharacterSubclass, CharacterRace, Feat, ProgressionDefinition, CharacterFeatureDefinition, CharacterFeatureRule, TrackableResourceDefinition, TrackableResourceRule, User};
use App\Enum\ResourceRechargeType;
use App\Entity\{CharacterClassLevelRule, CharacterActionDefinition, CharacterActionClassRule, RaceAbilityModifier, ProgressionStage, ProgressionAdjustmentRule};
use App\Service\AdminReferenceCatalogue;
use App\Kernel;
use App\Service\AdminFeatureReference;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

require __DIR__.'/../vendor/autoload.php';
(new Dotenv())->bootEnv(__DIR__.'/../.env');
putenv('SHELL_VERBOSITY=-1');
$_SERVER['SHELL_VERBOSITY'] = $_ENV['SHELL_VERBOSITY'] = -1;
$kernel = new Kernel('dev', true);
$kernel->boot();
$container = $kernel->getContainer();
$em = $container->get('doctrine')->getManager();
$db = $em->getConnection();
$reference = new AdminFeatureReference($em);
$session = new Session(new MockArraySessionStorage());
$session->start();
$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) throw new RuntimeException($message);
    ++$checks;
};
$request = static function (string $path, string $method = 'GET', array $payload = []) use ($kernel, $session) {
    $request = Request::create($path, $method, $payload, [$session->getName() => $session->getId()]);
    $request->setSession($session);
    return $kernel->handle($request);
};
$dom = static function ($response): DOMXPath {
    $document = new DOMDocument();
    @$document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
    return new DOMXPath($document);
};
$token = static fn ($response) => $dom($response)->evaluate('string(//input[@name="_token"]/@value)');
$rows = static fn ($response) => $dom($response)->query('//tr[@data-feature-id]')->length;
$total = static fn ($response) => (int) $dom($response)->evaluate('string(//*[@data-total]/@data-total)');
$checkAssets = static function ($response) use ($dom, $check): void {
    $html = $dom($response);
    $check($html->evaluate('string(//link[@rel="stylesheet"]/@href)') === '/assets/admin/admin.css', 'Shared layout CSS URL');
    $check($html->evaluate('string(//script[@src]/@src)') === '/assets/admin/admin.js', 'Shared layout JS URL');
};
$preview = static function (string $name, $response) use ($argv): void {
    if (!in_array('--render-preview', $argv, true)) return;
    $directory = __DIR__.'/../var/admin-preview';
    if (!is_dir($directory)) mkdir($directory, 0775, true);
    // Keep the original asset URLs: previews must use the real HTTP server too.
    $html = str_replace('<head>', '<head><base href="http://localhost:8000/">', $response->getContent());
    file_put_contents($directory.'/'.$name.'.html', $html);
};

try {
    // Exercise the actual Docker HTTP server, not the in-process Symfony kernel.
    foreach (['css' => 'text/css', 'js' => 'application/javascript'] as $extension => $mime) {
        $content = file_get_contents('http://127.0.0.1:8000/assets/admin/admin.'.$extension);
        $headers = $http_response_header;
        $check(str_contains($headers[0], '200 OK'), 'HTTP asset status '.$extension);
        $check((bool) preg_grep('/^Content-Type:\\s*'.preg_quote($mime, '/').'(?:;|$)/i', $headers), 'HTTP asset MIME '.$extension);
        $check($content === file_get_contents(__DIR__.'/../public/assets/admin/admin.'.$extension), 'HTTP asset bytes '.$extension);
    }
    $db->beginTransaction();
    $entities = [User::class, CharacterClass::class, CharacterSubclass::class, CharacterRace::class, Feat::class, ProgressionDefinition::class, TrackableResourceDefinition::class, CharacterFeatureDefinition::class, CharacterFeatureRule::class, TrackableResourceRule::class, CharacterClassLevelRule::class, CharacterActionDefinition::class, CharacterActionClassRule::class, RaceAbilityModifier::class, ProgressionStage::class, ProgressionAdjustmentRule::class];
    $metadata = array_map($em->getClassMetadata(...), $entities);
    $before = [];
    foreach ($metadata as $meta) {
        $table = $db->quoteIdentifier($meta->getTableName());
        $before[$table] = $db->fetchAllAssociative('SELECT * FROM public.'.$table.' ORDER BY id');
    }
    // PostgreSQL temporary tables AND sequences isolate all writes from real data.
    foreach ((new SchemaTool($em))->getCreateSchemaSql($metadata) as $sql) {
        $db->executeStatement(preg_replace('/^CREATE TABLE /', 'CREATE TEMP TABLE ', $sql));
    }
    $user = (new User())->setEmail('admin-test@example.invalid')->setPassword('test-only');
    $class = new CharacterClass('wizard-test', 'Magicien test', 6, 2);
    $subclass = new CharacterSubclass($class, 'divination-test', 'Divination test');
    $race = new CharacterRace('elf-test', 'Elfe test');
    $feat = new Feat('feat-test', 'Don test');
    $progression = new ProgressionDefinition('progression-test', 'Progression test');
    $resource = new TrackableResourceDefinition('dice-test', 'Dés test', ResourceRechargeType::LongRest);
    $shared = (new CharacterFeatureDefinition('shared-test', 'Éclat partagé'))->setDescription("Description\n\nDeuxième paragraphe.")->setResourceDefinition($resource);
    $sub = new CharacterFeatureDefinition('sub-test', 'Vision');
    $racial = new CharacterFeatureDefinition('race-test', 'Trait');
    $featFeature = new CharacterFeatureDefinition('feat-feature-test', 'Talent');
    $progressionFeature = new CharacterFeatureDefinition('progression-feature-test', 'Corruption');
    foreach ([$user, $class, $subclass, $race, $feat, $progression, $resource, $shared, $sub, $racial, $featFeature, $progressionFeature] as $entity) $em->persist($entity);
    for ($i = 1; $i <= 25; ++$i) $em->persist(new CharacterFeatureDefinition('unassigned-'.$i, sprintf('Sans attribution %02d', $i)));
    $em->flush();
    foreach ([
        CharacterFeatureRule::forClass($shared, $class, 2), CharacterFeatureRule::forClass($shared, $class, 6),
        CharacterFeatureRule::forSubclass($sub, $subclass, 2), CharacterFeatureRule::forRace($racial, $race),
        CharacterFeatureRule::forFeat($featFeature, $feat), CharacterFeatureRule::forProgression($progressionFeature, $progression, 3),
        TrackableResourceRule::forClass($resource, $class, 2, 0),
    ] as $rule) $em->persist($rule);
    $em->flush();
    $session->set('_security_main', serialize(new UsernamePasswordToken($user, 'main', ['ROLE_USER'])));
    $id = $shared->getId();
    $assertDashboard = static function (array $expected) use ($request, $dom, $check): void {
        $response = $request('/admin');
        $check($response->getStatusCode() === 200, 'Authorized dashboard');
        $html = $dom($response);
        $check($html->query('//main//article[@data-category]')->length === 7, 'Seven dashboard cards');
        $check($html->query('//main//a[@href="/admin/reference"]')->length === 1, 'General catalogue link');
        $totals = $missingDescriptions = 0;
        foreach ($expected as $category => [$label, $count, $names, $descriptions]) {
            $card = '//main//article[@data-category="'.$category.'"]';
            $check($html->evaluate('string('.$card.'//h3/a)') === $label, 'Category label '.$category);
            $check($html->evaluate('string('.$card.'//h3/a/@href)') === '/admin/reference/'.$category, 'Category link '.$category);
            foreach (['total' => $count, 'missing-names' => $names, 'missing-descriptions' => $descriptions] as $key => $value) {
                $check($html->evaluate('string('.$card.'//*[@data-count="'.$key.'"])') === (string) $value, 'Database count '.$category.' '.$key);
            }
            $totals += $count;
            $missingDescriptions += $descriptions;
        }
        foreach (['total' => $totals, 'missing-descriptions' => $missingDescriptions, 'categories' => 7] as $key => $value) {
            $check($html->evaluate('string(//*[@data-summary="'.$key.'"])') === (string) $value, 'Dashboard summary '.$key);
        }
    };
    $expectedDashboard = [
        'features' => ['Capacités', 30, 0, 29],
        'classes' => ['Classes', 1, 0, 1],
        'subclasses' => ['Sous-classes', 1, 0, 1],
        'races' => ['Races', 1, 0, 1],
        'feats' => ['Dons', 1, 0, 1],
        'resources' => ['Ressources', 1, 0, 1],
        'progressions' => ['Progressions', 1, 0, 1],
    ];
    $assertDashboard($expectedDashboard);
    // Direct SQL exercises legacy empty/space-only text without setter normalization.
    // These writes target temporary tables only and are reverted before other tests.
    $db->createSavepoint('dashboard_editorial');
    foreach (['features' => CharacterFeatureDefinition::class] + array_column(AdminReferenceCatalogue::CATEGORIES, 'entity') as $entityClass) {
        $table = $db->quoteIdentifier($em->getClassMetadata($entityClass)->getTableName());
        $db->executeStatement("UPDATE $table SET name = '   ', description = ''");
    }
    foreach ($expectedDashboard as &$expected) { $expected[2] = $expected[3] = $expected[1]; }
    unset($expected);
    $assertDashboard($expectedDashboard);
    foreach (['features' => CharacterFeatureDefinition::class] + array_column(AdminReferenceCatalogue::CATEGORIES, 'entity') as $entityClass) {
        $table = $db->quoteIdentifier($em->getClassMetadata($entityClass)->getTableName());
        $db->executeStatement("UPDATE $table SET name = '', description = '   '");
    }
    $assertDashboard($expectedDashboard);
    // Empty categories must render zero rather than NULL from SUM().
    $db->executeStatement('DELETE FROM character_feature_rule');
    $db->executeStatement('DELETE FROM character_feature_definition');
    $expectedDashboard['features'] = ['Capacités', 0, 0, 0];
    $assertDashboard($expectedDashboard);
    $db->rollbackSavepoint('dashboard_editorial');
    $db->releaseSavepoint('dashboard_editorial');
    foreach (['/admin', '/admin/reference'] as $path) {
        $response = $request($path);
        $check($response->getStatusCode() === 200, 'Dashboard GET '.$path.' status '.$response->getStatusCode());
        $check(str_contains($response->getContent(), 'JdR Companion') && str_contains($response->getContent(), 'Capacités'), 'Dashboard content');
        $check(str_contains($response->headers->get('Content-Type', ''), 'text/html'), 'HTML response');
        $checkAssets($response);
        $preview($path === '/admin' ? 'dashboard' : 'reference', $response);
    }
    $base = '/admin/reference/features';
    $response = $request($base);
    $check($response->getStatusCode() === 200 && $total($response) === 30 && $rows($response) === 25, 'List pagination and distinct definitions');
    $checkAssets($response);
    $preview('list', $response);
    $next = html_entity_decode($dom($response)->evaluate('string(//a[@rel="next"]/@href)'));
    $second = $request($next);
    $check($rows($second) === 5 && $total($second) === 30, 'Second page');
    $preview('page-2', $second);
    $ids = [];
    foreach ([$response, $second] as $page) foreach ($dom($page)->query('//tr[@data-feature-id]') as $row) $ids[] = $row->getAttribute('data-feature-id');
    $check(count(array_unique($ids)) === 30, 'No duplicate or missing capability across pages');
    foreach (['éCLAT', 'shared-test'] as $search) {
        $found = $request($base.'?'.http_build_query(['q' => $search]));
        $check($total($found) === 1 && $rows($found) === 1, 'Case insensitive search '.$search);
    }
    foreach (['%', '_', "' OR 1=1 --"] as $search) $check($total($request($base.'?'.http_build_query(['q' => $search]))) === 0, 'Literal safe search');
    foreach (['class' => [$class, 2], 'subclass' => [$subclass, 1], 'race' => [$race, 1], 'feat' => [$feat, 1], 'progression' => [$progression, 1]] as $key => [$source, $count]) {
        $check($total($request($base.'?'.$key.'='.$source->getId())) === $count, 'Source filter '.$key);
        $check($total($request($base.'?sourceType='.$key)) === 1, 'Origin filter '.$key);
    }
    $check($total($request($base.'?class='.$class->getId().'&subclass='.$subclass->getId())) === 1, 'Class includes subclasses');
    foreach (['page=0', 'page=x', 'race=-1', 'q[]=x', 'class[]=1', 'sourceType[]=x', 'sourceType=wrong'] as $query) $check($request($base.'?'.$query)->getStatusCode() === 422, 'Invalid query '.$query);
    $context = ['q' => 'shared-test', 'class' => $class->getId(), 'page' => 1];
    $filtered = $request($base.'?'.http_build_query($context));
    $preview('filtered', $filtered);
    $link = html_entity_decode($dom($filtered)->evaluate('string(//tr[@data-feature-id]/td/a/@href)'));
    $edit = $request($link);
    $check($edit->getStatusCode() === 200 && $token($edit) !== '', 'GET edit form with CSRF');
    $checkAssets($edit);
    $preview('edit', $edit);
    $back = html_entity_decode($dom($edit)->evaluate('string(//a[@class="back-link"]/@href)'));
    parse_str(parse_url($back, PHP_URL_QUERY), $backContext);
    $check($backContext == $context, 'List context round-trip');
    foreach (['shared-test', 'Activation', 'Visibilité', 'Magicien test', 'Dés test', 'Maximum imposé : 0'] as $text) $check(str_contains($edit->getContent(), $text), 'Technical info '.$text);
    $check($dom($edit)->query('//form[@data-editor]//input[@name!="_token"]')->length === 1 && $dom($edit)->query('//form[@data-editor]//textarea[@name="description"]')->length === 1, 'Only editorial inputs');
    $mechanics = $reference->detail($shared);
    $csrf = $token($edit);
    $payload = ['_token' => $csrf, 'name' => "Éclat d'été", 'description' => $shared->getDescription()];
    $saved = $request($link, 'POST', $payload);
    $check($saved->getStatusCode() === 303, 'POST redirects after name save');
    $location = $saved->headers->get('Location');
    parse_str(parse_url($location, PHP_URL_QUERY), $savedContext);
    $check($savedContext == $context, 'POST preserves filtered list context');
    $success = $request($location);
    $check(str_contains($success->getContent(), 'Modifications enregistrées.'), 'Success flash');
    $description = "L’éclat d'été protège l’allié.\n\nDeuxième paragraphe.\n<script>alert('texte')</script>";
    $payload['description'] = $description;
    $check($request($link, 'POST', $payload)->getStatusCode() === 303, 'Description POST');
    $em->clear();
    $savedFeature = $reference->find($id);
    $check($savedFeature->getName() === "Éclat d'été" && $savedFeature->getDescription() === $description, 'PostgreSQL persistence with UTF8 and paragraphs');
    $rendered = $request($link);
    $check($dom($rendered)->evaluate('string(//textarea)') === $description, 'Textarea round-trip');
    $check(!str_contains($rendered->getContent(), "<script>alert('texte')</script>"), 'Twig escapes stored HTML');
    foreach (['slug', 'id', 'resourceDefinition', 'resourceDefinitionId', 'rules', 'featureRules', 'resourceRules', 'actionRules', 'active', 'activationType', 'visible', 'custom', 'unlockLevel'] as $field) {
        $rejected = $request($link, 'POST', $payload + [$field => 'change']);
        $check($rejected->getStatusCode() === 422 && str_contains($rejected->getContent(), $field), 'Reject forged '.$field);
    }
    foreach ([['name' => ' '], ['name' => []], ['name' => str_repeat('é', 151)], ['description' => []], ['description' => "a\0b"]] as $invalid) {
        $check($request($link, 'POST', array_replace($payload, $invalid))->getStatusCode() === 422, 'Invalid editorial data');
    }
    $invalidCsrf = $request($link, 'POST', array_replace($payload, ['_token' => 'bad', 'description' => 'Brouillon conservé']));
    $check($invalidCsrf->getStatusCode() === 403 && str_contains($invalidCsrf->getContent(), 'Brouillon conservé'), 'CSRF rejected, draft preserved');
    $missingToken = $payload; unset($missingToken['_token']);
    $check($request($link, 'POST', $missingToken)->getStatusCode() === 403, 'Missing CSRF rejected');
    $check($request($base.'/'.$sub->getId(), 'POST', $payload)->getStatusCode() === 403, 'CSRF token bound to feature');
    $em->clear();
    $after = $reference->detail($reference->find($id));
    foreach (['id', 'slug', 'origins', 'resource', 'activationLabel', 'visible'] as $field) $check($after[$field] === $mechanics[$field], 'Mechanics unchanged '.$field);
    $check($after['name'] === $payload['name'] && $after['description'] === $description, 'Rejected updates never persist');
    $check($request($base.'/999999')->getStatusCode() === 404, 'Missing feature');
    $check($request($base.'/'.$id, 'PATCH', $payload)->getStatusCode() === 405, 'Former PATCH route removed');
    $progressionDetail = $reference->detail($reference->find($progressionFeature->getId()));
    $check($progressionDetail['origins'][0]['unlockLevel'] === null && $progressionDetail['origins'][0]['progressionThreshold'] === 3, 'No dummy progression level');
    $routes = $container->get('router')->getRouteCollection();
    $check($routes->get('api_admin_reference_features') === null && $routes->get('api_admin_reference_feature_update') === null, 'Former API route names removed');
    // The new categories share editorial handling, but exercise each concrete mapping.
    $catalogue = new AdminReferenceCatalogue($em);
    $class = $em->find(CharacterClass::class, $class->getId());
    $subclass = $em->find(CharacterSubclass::class, $subclass->getId());
    $race = $em->find(CharacterRace::class, $race->getId());
    $feat = $em->find(Feat::class, $feat->getId());
    $resource = $em->find(TrackableResourceDefinition::class, $resource->getId());
    $progression = $em->find(ProgressionDefinition::class, $progression->getId());
    $action = new CharacterActionDefinition('test-action', 'Action test', App\Enum\CharacterActionHandlerType::Aid);
    foreach ([$action, new CharacterActionClassRule($action, $class, 3), (new CharacterClassLevelRule($class, 4))->setNotes('Choix de niveau test'),
        new ProgressionStage($progression, 'Palier test', 0), new ProgressionAdjustmentRule($progression, App\Enum\ProgressionAdjustmentDirection::GAIN, 'Action narrative test', '+1'),
        new RaceAbilityModifier($race, 2, App\Enum\Ability::Strength), TrackableResourceRule::forClass($resource, $class, 14, 3),
    ] as $fixture) $em->persist($fixture);
    $factories = [
        'classes' => fn ($slug, $name) => new CharacterClass($slug, $name, 8, 3),
        'subclasses' => fn ($slug, $name) => new CharacterSubclass($class, $slug, $name),
        'races' => fn ($slug, $name) => (new CharacterRace($slug, $name))->setParentRace($race),
        'feats' => fn ($slug, $name) => new Feat($slug, $name),
        'resources' => fn ($slug, $name) => new TrackableResourceDefinition($slug, $name, ResourceRechargeType::LongRest),
        'progressions' => fn ($slug, $name) => new ProgressionDefinition($slug, $name),
    ];
    $targets = ['classes' => $class, 'subclasses' => $subclass, 'races' => $race, 'feats' => $feat, 'resources' => $resource, 'progressions' => $progression];
    foreach ($factories as $factory) for ($i = 1; $i <= 26; ++$i) $em->persist($factory('extra-'.$i, sprintf('Autre %02d', $i)));
    $em->flush();
    $navigation = $request('/admin/reference');
    $essential = ['classes' => 'Choix de niveau test', 'subclasses' => 'Classe parente', 'races' => 'Modificateurs raciaux propres', 'feats' => 'Bonus choisi', 'resources' => 'Niveau : 14', 'progressions' => 'Palier test'];
    $sensitive = ['classes' => 'hitDie', 'subclasses' => 'characterClass', 'races' => 'parentRace', 'feats' => 'chosenAbilityIncrease', 'resources' => 'baseMaximum', 'progressions' => 'minimumValue'];
    foreach ($targets as $category => $target) {
        $path = '/admin/reference/'.$category;
        $targetId = $target->getId();
        $check($dom($navigation)->query('//a[@href="'.$path.'"]')->length > 0, 'Navigation '.$category);
        $listPage = $request($path);
        $check($listPage->getStatusCode() === 200 && $total($listPage) === 27 && $dom($listPage)->query('//tr[@data-reference-id]')->length === 25, 'List and page size '.$category);
        $lastPage = $request($path.'?page=2');
        $check($dom($lastPage)->query('//tr[@data-reference-id]')->length === 2, 'Second page '.$category);
        $check($total($request($path.'?q='.rawurlencode(mb_strtoupper($target->getName())))) === 1, 'Name search '.$category);
        $check($total($request($path.'?q='.rawurlencode($target->getSlug()))) === 1, 'Slug search '.$category);
        $editPath = $path.'/'.$targetId.'?q='.$target->getSlug().'&page=2';
        $page = $request($editPath);
        $check($page->getStatusCode() === 200 && str_contains($page->getContent(), $essential[$category]), 'Essential detail '.$category);
        $checkAssets($page);
        $preview($category, $page);
        $savedLink = html_entity_decode($dom($page)->evaluate('string(//a[@class="back-link"]/@href)'));
        parse_str(parse_url($savedLink, PHP_URL_QUERY), $listContext);
        $check($listContext == ['q' => $target->getSlug(), 'page' => 2], 'Context return '.$category);
        $table = $db->quoteIdentifier($em->getClassMetadata(AdminReferenceCatalogue::CATEGORIES[$category]['entity'])->getTableName());
        $original = $db->fetchAssociative('SELECT * FROM '.$table.' WHERE id = ?', [$targetId]);
        $form = ['_token' => $token($page), 'name' => 'Édité '.$category, 'description' => "L’été d’aventure.\n\nDeuxième paragraphe <texte>."];
        if ($category === 'progressions') $form += ['gainLabel' => 'Gagner des points', 'spendLabel' => 'Dépenser'];
        $savedResponse = $request($editPath, 'POST', $form);
        $check($savedResponse->getStatusCode() === 303, 'Editorial POST '.$category);
        $savedPage = $request($savedResponse->headers->get('Location'));
        $check(str_contains($savedPage->getContent(), 'Modifications enregistrées.') && $dom($savedPage)->evaluate('string(//textarea)') === $form['description'], 'PRG and UTF8 '.$category);
        $em->clear();
        $persisted = $catalogue->find($category, $targetId);
        $check($persisted->getName() === $form['name'] && $persisted->getDescription() === $form['description'], 'PostgreSQL persistence '.$category);
        if ($category === 'progressions') $check($persisted->getGainLabel() === $form['gainLabel'] && $persisted->getSpendLabel() === $form['spendLabel'], 'Progression labels persisted');
        $afterRow = $db->fetchAssociative('SELECT * FROM '.$table.' WHERE id = ?', [$targetId]);
        foreach (['name', 'description', 'updated_at', 'gain_label', 'spend_label'] as $field) { unset($original[$field], $afterRow[$field]); }
        $check($original === $afterRow, 'Identity and mechanical columns unchanged '.$category);
        foreach (['slug', 'rules', $sensitive[$category]] as $field) $check($request($editPath, 'POST', $form + [$field => 'forged'])->getStatusCode() === 422, 'Forged '.$field.' '.$category);
        $check($request($editPath, 'POST', array_replace($form, ['_token' => 'bad']))->getStatusCode() === 403, 'CSRF '.$category);
        $check($request($editPath, 'POST', array_replace($form, ['name' => '  ']))->getStatusCode() === 422, 'Invalid name '.$category);
        $check($request($editPath, 'POST', array_replace($form, ['description' => []]))->getStatusCode() === 422, 'Invalid description '.$category);
        $check($request($path.'/999999')->getStatusCode() === 404, 'Missing ID '.$category);
    }
    $check($total($request('/admin/reference/subclasses?class='.$class->getId())) === 27, 'Subclass parent filter');
    $check($total($request('/admin/reference/races?parent='.$race->getId())) === 26, 'Race parent filter');
    $check($total($request('/admin/reference/races?selectable=0')) === 0, 'False selectable filter');
    $check($total($request('/admin/reference/resources?recharge=long-rest')) === 27, 'Recharge filter');
    $check($total($request('/admin/reference/classes?custom=0')) === 27, 'Obsolete custom parameter ignored');
    $linkedPages = [
        ['/admin/reference/features/'.$id, '/admin/reference/classes/'.$class->getId()],
        ['/admin/reference/features/'.$sub->getId(), '/admin/reference/subclasses/'.$subclass->getId()],
        ['/admin/reference/features/'.$id, '/admin/reference/resources/'.$resource->getId()],
        ['/admin/reference/classes/'.$class->getId(), '/admin/reference/subclasses/'.$subclass->getId()],
        ['/admin/reference/subclasses/'.$subclass->getId(), '/admin/reference/features/'.$sub->getId()],
        ['/admin/reference/resources/'.$resource->getId(), '/admin/reference/features/'.$id],
        ['/admin/reference/progressions/'.$progression->getId(), '/admin/reference/features/'.$progressionFeature->getId()],
    ];
    foreach ($linkedPages as [$from, $to]) $check($dom($request($from))->query('//main//a[@href="'.$to.'"]')->length > 0, 'Related link '.$from.' -> '.$to);
    // Editorial list filters: raw legacy values in isolated temporary tables only.
    foreach (['features' => CharacterFeatureDefinition::class] + array_map(static fn ($definition) => $definition['entity'], AdminReferenceCatalogue::CATEGORIES) as $category => $entityClass) {
        $meta = $em->getClassMetadata($entityClass);
        $check($meta->hasField('description'), 'All current catalogue categories support descriptions '.$category);
        $check(!$meta->isNullable('name'), 'NULL names forbidden by actual schema '.$category);
        $table = $db->quoteIdentifier($meta->getTableName());
        $fixtures = $db->fetchAllAssociative('SELECT id, slug FROM '.$table.' ORDER BY id');
        $path = '/admin/reference/'.$category;
        $db->createSavepoint('editorial_lists');
        $db->executeStatement("UPDATE $table SET description = 'Texte renseigné'");
        foreach ([null, '', '   '] as $index => $descriptionValue) {
            $db->update($meta->getTableName(), ['description' => $descriptionValue], ['id' => $fixtures[$index]['id']]);
        }
        foreach (['', '   '] as $index => $nameValue) {
            $db->update($meta->getTableName(), ['name' => $nameValue], ['id' => $fixtures[$index]['id']]);
        }
        $em->clear();
        $all = $request($path.'?editorial=');
        $check($all->getStatusCode() === 200 && $total($all) === count($fixtures), 'Editorial all '.$category);
        $filtered = $request($path.'?editorial=missing-description');
        $check($filtered->getStatusCode() === 200 && $total($filtered) === 3, 'NULL empty and spaces descriptions '.$category);
        $check($dom($filtered)->query('//tbody//div[@class="badge" and text()="Description manquante"]')->length === 3, 'Description badges '.$category);
        $names = $request($path.'?editorial=missing-name');
        $check($total($names) === 2, 'Empty and spaces names '.$category);
        $check($dom($names)->query('//tbody//div[@class="badge" and text()="Nom manquant"]')->length === 2, 'Name badges '.$category);
        $check($dom($names)->evaluate('string(//tbody/tr[1]/td/a)') !== '', 'Unnamed item keeps accessible link '.$category);
        $search = $request($path.'?'.http_build_query(['q' => $fixtures[0]['slug'], 'editorial' => 'missing-description']));
        $check($total($search) === 1, 'Search plus editorial filter '.$category);
        $check($total($request($path.'?q=no-such-entry&editorial=missing-description')) === 0, 'Empty intersection '.$category);
        foreach (['editorial=unknown', 'editorial[]=missing-name', 'editorial=description%20OR%201%3D1'] as $invalid) {
            $response = $request($path.'?'.$invalid);
            $check($response->getStatusCode() === 200 && $total($response) === count($fixtures), 'Invalid editorial value falls back to all '.$category);
            $check($dom($response)->query('//select[@name="editorial"]/option[@selected and @value!=""]')->length === 0, 'Invalid editorial value not retained '.$category);
        }
        $dashboardPage = $dom($request('/admin'));
        $dashboardLink = $dashboardPage->evaluate('string(//article[@data-category="'.$category.'"]//*[@data-count="missing-descriptions"]/a/@href)');
        $check($dashboardLink === $path.'?editorial=missing-description', 'Dashboard filtered link '.$category);
        $check($total($request($dashboardLink)) === 3, 'Dashboard counter and filtered list agree '.$category);
        // Enough matching rows to prove filtering precedes pagination.
        $db->executeStatement("UPDATE $table SET name = 'Éditorial test', description = NULL");
        $em->clear();
        $params = ['q' => 'Éditorial', 'editorial' => 'missing-description'];
        $first = $request($path.'?'.http_build_query($params));
        $check($total($first) === count($fixtures), 'Combined filters count '.$category);
        $next = $dom($first)->evaluate('string(//a[@rel="next"]/@href)');
        parse_str(parse_url($next, PHP_URL_QUERY), $nextParams);
        $check($nextParams == $params + ['page' => 2], 'Next page retains all filters '.$category);
        $second = $request($next);
        $check($dom($second)->query('//tr[@data-reference-id or @data-feature-id]')->length === count($fixtures) - 25, 'Filtered second page '.$category);
        $previous = $dom($second)->evaluate('string(//a[@rel="prev"]/@href)');
        parse_str(parse_url($previous, PHP_URL_QUERY), $previousParams);
        $check($previousParams == $params + ['page' => 1], 'Previous page retains all filters '.$category);
        $check($dom($second)->query('//form//input[@name="page"]')->length === 0, 'New search resets page '.$category);
        $check($dom($second)->evaluate('string(//select[@name="editorial"]/option[@selected]/@value)') === 'missing-description', 'Selected editorial filter retained '.$category);
        $editLink = $dom($second)->evaluate('string(//tbody/tr[1]/td/a/@href)');
        $editResponse = $request($editLink);
        $check($editResponse->getStatusCode() === 200, 'Filtered edit page '.$category);
        $back = $dom($editResponse)->evaluate('string(//a[@class="back-link"]/@href)');
        parse_str(parse_url($back, PHP_URL_QUERY), $backParams);
        $check($backParams == $nextParams, 'Edit back link retains filters and page '.$category);
        if ($category === 'features') {
            $check($total($request($path.'?q=Éditorial&editorial=missing-description&sourceType=class')) === 1, 'Editorial filter with feature origin, no join duplicates');
        }
        $db->rollbackSavepoint('editorial_lists');
        $db->releaseSavepoint('editorial_lists');
        $em->clear();
    }
    require __DIR__.'/test-admin-reference-editor-cases.php';
    require __DIR__.'/test-admin-reference-official-cases.php';
    foreach ($before as $table => $data) $check($data === $db->fetchAllAssociative('SELECT * FROM public.'.$table.' ORDER BY id'), 'Real data unchanged '.$table);
    $session->remove('_security_main');
    $check($request('/admin')->getStatusCode() === 401, 'Anonymous dashboard requires existing login');
    foreach (['features', ...array_keys($targets)] as $category) $check($request('/admin/reference/'.$category.'?editorial=missing-description')->getStatusCode() === 401, 'Anonymous filtered list '.$category);
    foreach (['features' => $shared, ...$targets] as $category => $target) {
        $path = '/admin/reference/'.$category.'/'.$target->getId();
        $check($request($path)->getStatusCode() === 401, 'Anonymous editor '.$category);
        $check($request($path, 'POST', ['_action' => 'save-next'])->getStatusCode() === 401, 'Anonymous save-next '.$category);
    }
    foreach ($targets as $category => $target) $check($request('/admin/reference/'.$category)->getStatusCode() === 401, 'Anonymous category '.$category);
    echo "OK: $checks Symfony/Twig dashboard assertions; PostgreSQL temporary tables rolled back.\n";
} finally {
    while ($db->isTransactionActive()) $db->rollBack();
    $kernel->shutdown();
}
