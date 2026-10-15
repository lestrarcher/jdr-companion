<?php
declare(strict_types=1);

require __DIR__.'/character-training-fixture.php';
require __DIR__.'/training-presenter-fixture.php';

use App\Entity\{CharacterBackground, User};
use App\Service\CustomReferenceBackgroundService;
use Symfony\Component\HttpFoundation\{Request, Session\Session, Session\Storage\MockArraySessionStorage};
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

(new Symfony\Component\Dotenv\Dotenv())->bootEnv(__DIR__.'/../.env');
putenv('SHELL_VERBOSITY=-1'); $_SERVER['SHELL_VERBOSITY'] = $_ENV['SHELL_VERBOSITY'] = -1;
$kernel = new App\Kernel('dev', true); $kernel->boot();
$registry = $kernel->getContainer()->get('doctrine');
$em = $registry->getManager(); $db = $em->getConnection();
$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void { if (!$ok) throw new RuntimeException($message); ++$checks; };
$session = new Session(new MockArraySessionStorage()); $session->start();
$login = static function (?User $owner) use ($session): void {
    if ($owner) $session->set('_security_main', serialize(new UsernamePasswordToken($owner, 'main', ['ROLE_USER'])));
    else $session->remove('_security_main');
};
$request = static function (string $method, string $path = '', ?array $payload = null) use ($kernel, $session): array {
    $r = Request::create('/reference/custom/backgrounds'.$path, $method, [], [$session->getName() => $session->getId()], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], $payload === null ? null : json_encode((object) $payload));
    $r->setSession($session); $response = $kernel->handle($r);
    return [$response->getStatusCode(), json_decode($response->getContent(), true)];
};
try {
    $db->beginTransaction();
    $metadata = $em->getMetadataFactory()->getAllMetadata();
    foreach ((new Doctrine\ORM\Tools\SchemaTool($em))->getCreateSchemaSql($metadata) as $sql) $db->executeStatement(preg_replace(['/^CREATE TABLE /', '/^CREATE SEQUENCE /'], ['CREATE TEMP TABLE ', 'CREATE TEMP SEQUENCE '], $sql));
    foreach ($metadata as $meta) if ($db->fetchOne('SELECT relpersistence FROM pg_class WHERE oid=to_regclass(?)', [$meta->getTableName()]) !== 't') throw new RuntimeException('Not isolated');
    extract(trainingFixture());
    $owner->setEmail('background-a@example.invalid')->setPassword('unused');
    $other = (new User())->setEmail('background-b@example.invalid')->setPassword('unused');
    foreach ([$owner, $other, $campaign, ...array_values($classes), ...array_values($races), ...array_values($backgrounds), ...array_values($definitions), ...array_values($existing['languages']), ...array_values($existing['languageRules']), ...array_values($existing['expertiseRules']), ...$repo->rules] as $entity) $em->persist($entity);
    $em->flush();
    // Exercise the additive migration on the old temporary schema, preserving references.
    $officialBefore = $db->fetchAllAssociative('SELECT id, slug, name, description, origin, owner_id FROM character_background ORDER BY id');
    foreach (['equipment', 'feature_name', 'feature_description', 'personality_traits', 'ideals', 'bonds', 'flaws'] as $column) $db->executeStatement('ALTER TABLE character_background DROP COLUMN '.$column);
    require_once __DIR__.'/../migrations/Version20261015120000.php';
    $migration = new DoctrineMigrations\Version20261015120000($db, new Psr\Log\NullLogger());
    $migration->up(new Doctrine\DBAL\Schema\Schema());
    foreach ($migration->getSql() as $sql) $db->executeStatement($sql->getStatement());
    $check($officialBefore === $db->fetchAllAssociative('SELECT id, slug, name, description, origin, owner_id FROM character_background ORDER BY id'), 'Migration preserves existing official rows.');
    $check($db->fetchOne('SELECT personality_traits::text FROM character_background LIMIT 1') === '[]', 'Migration defaults narrative lists.');
    $login(null); $check($request('GET')[0] === 401, 'Authentication required.');
    $login($owner);
    $check($request('GET', '/options')[0] === 200, 'Options endpoint.');
    $payload = ['name' => 'Test Brumes', 'slug' => 'test-brumes', 'description' => 'Original', 'equipment' => 'Un talisman', 'featureName' => 'Brumes', 'featureDescription' => 'Reconnaissance', 'personalityTraits' => ['Calme', 'Curieux'], 'ideals' => ['Liberté'], 'bonds' => [], 'flaws' => ['Méfiant'],
        'proficiencyRules' => [['label' => 'Compétences', 'choiceCount' => 0, 'optionIds' => array_map(fn ($s) => $definitions[$s]->getId(), ['stealth', 'survival', 'acrobatics'])]],
        'languageRules' => [['label' => 'Langues', 'choiceCount' => 1, 'optionIds' => [$existing['languages']['elvish']->getId(), $existing['languages']['dwarvish']->getId()]]]];
    [$status, $body] = $request('POST', '', $payload);
    $check($status === 201, 'Create: '.json_encode($body)); $created = $body['background']; $id = $created['id'];
    $check(count($created['proficiencyRules'][0]['optionIds']) === 3, 'Custom allows more than two skills.');
    $check($created['personalityTraits'] === ['Calme', 'Curieux'], 'Narrative arrays roundtrip.');
    $check($request('POST', '', $payload)[0] === 409, 'Global slug collision.');
    $check($request('PATCH', '/'.$id, ['owner_id' => $other->getId()])[0] === 400, 'Owner cannot be supplied.');
    foreach ([['optionIds' => [999999]], ['choiceCount' => 9], ['optionIds' => [$definitions['stealth']->getId(), $definitions['stealth']->getId()]]] as $bad) {
        $check($request('PATCH', '/'.$id, ['proficiencyRules' => [array_replace($created['proficiencyRules'][0], $bad)]])[0] === 400, 'Invalid rule rejected.');
    }
    $bad = $payload['proficiencyRules'][0]; $bad['choiceCount'] = 2;
    $check($request('PATCH', '/'.$id, ['proficiencyRules' => [$bad, $bad]])[0] === 400, 'Impossible overlapping groups rejected.');
    $privateLanguage = App\Entity\LanguageDefinition::forOwner($em->find(User::class, $other->getId()), 'private-language', 'Privée');
    $em->persist($privateLanguage); $em->flush();
    $check($request('PATCH', '/'.$id, ['languageRules' => [['label' => 'Privée', 'choiceCount' => 0, 'optionIds' => [$privateLanguage->getId()]]]])[0] === 400, 'Foreign language cannot be referenced.');
    $check(!in_array($privateLanguage->getId(), array_column($request('GET', '/options')[1]['languages'], 'id'), true), 'Foreign language hidden from choices.');
    $official = $backgrounds['acolyte-phb']->getId();
    $check($request('PATCH', '/'.$official, ['name' => 'No'])[0] === 404, 'Official patch forbidden.');
    $check($request('DELETE', '/'.$official)[0] === 404, 'Official delete forbidden.');
    $login($other);
    foreach (['GET', 'PATCH', 'DELETE'] as $method) $check($request($method, '/'.$id, $method === 'PATCH' ? ['name' => 'No'] : null)[0] === 404, 'Foreign record hidden: '.$method);
    $check(!in_array($id, array_column($request('GET')[1]['backgrounds'], 'id'), true), 'Foreign list isolation.');
    $login($owner);
    $updated = $request('PATCH', '/'.$id, ['slug' => 'test-brumes-renamed', 'proficiencyRules' => $created['proficiencyRules']])[1]['background'];
    $check($updated['proficiencyRules'][0]['id'] === $created['proficiencyRules'][0]['id'], 'Rule identity preserved.');
    $languageKey = $db->fetchOne('SELECT import_key FROM language_grant_rule WHERE id = ?', [$created['languageRules'][0]['id']]);
    $check($request('PATCH', '/'.$id, ['languageRules' => $created['languageRules']])[0] === 200, 'Unchanged language rule accepted.');
    $check($languageKey === $db->fetchOne('SELECT import_key FROM language_grant_rule WHERE id = ?', [$created['languageRules'][0]['id']]), 'Language source key survives edits.');
    $background = $em->find(CharacterBackground::class, $id);
    $campaign = $em->find(App\Entity\Campaign::class, $campaign->getId());
    $race = $em->find(App\Entity\CharacterRace::class, $races['human-phb']->getId());
    $class = $em->find(App\Entity\CharacterClass::class, $classes['fighter']->getId());
    $character = (new App\Entity\Character($campaign, 'test-hero', 'Test', 'player'))->setRace($race)->setBackground($background);
    $character->addClassLevel(new App\Entity\CharacterClassLevel($character, $class, 1));
    $em->persist($character); $em->flush();
    $presenter = trainingPresenterForRegistry($registry);
    $options = $presenter->options($character);
    $language = $options['languageRules'][0];
    trainingServiceForRegistry($registry)->applyChoices($character, ['languageChoices' => [['sourceKey' => $language['sourceKey'], 'languageSlugs' => ['elvish']]]]);
    $em->flush();
    $resolved = $presenter->resolved($character);
    $check(in_array('stealth', array_column($resolved['proficiencies'], 'slug'), true), 'Existing runtime grants custom skill.');
    $check(in_array('elvish', array_column($resolved['languages'], 'slug'), true), 'Existing runtime resolves language choice.');
    $check($request('PATCH', '/'.$id, ['slug' => 'no-change'])[0] === 409, 'Used slug locked.');
    $check($request('PATCH', '/'.$id, ['proficiencyRules' => []])[0] === 409, 'Used mechanics locked.');
    $check($request('DELETE', '/'.$id)[0] === 409, 'Used delete blocked.');
    $check($request('PATCH', '/'.$id, ['name' => 'Nouveau nom', 'equipment' => 'Sac', 'featureDescription' => 'Nouveau texte', 'ideals' => ['Paix', 'Justice']])[0] === 200, 'Used descriptive fields remain editable.');
    $character = $em->find(App\Entity\Character::class, $character->getId());
    $after = $presenter->resolved($character);
    foreach (['proficiencies', 'languages'] as $kind) {
        $beforeSlugs = array_column($resolved[$kind], 'slug'); sort($beforeSlugs);
        $afterSlugs = array_column($after[$kind], 'slug'); sort($afterSlugs);
        $check($afterSlugs === $beforeSlugs, 'Descriptive edit preserves '.$kind);
    }
    $service = new CustomReferenceBackgroundService($db);
    $empty = $service->create(['name' => 'Libre', 'slug' => 'libre'], $owner);
    $check($request('DELETE', '/'.$empty['id'])[0] === 204, 'Unused delete succeeds.');
    $check($request('GET', '/'.$empty['id'])[0] === 404, 'Deleted record gone.');
    $withRules = $service->create(array_replace($payload, ['name' => 'Supprimable', 'slug' => 'delete-with-rules']), $owner);
    $check($request('DELETE', '/'.$withRules['id'])[0] === 204, 'Unused background with rules can be deleted.');
    $check(!$db->fetchOne('SELECT 1 FROM proficiency_grant_rule WHERE id = ?', [$withRules['proficiencyRules'][0]['id']]), 'Only owned deleted background rules cascade.');
    // Full builder path: automatic skills, tool choices, fixed languages and language choices.
    $owner = $em->find(User::class, $owner->getId());
    $campaign = $em->find(App\Entity\Campaign::class, $campaign->getId());
    $race = $em->find(App\Entity\CharacterRace::class, $race->getId());
    $class = $em->find(App\Entity\CharacterClass::class, $class->getId());
    $tools = array_map('intval', $db->fetchFirstColumn("SELECT id FROM proficiency_definition WHERE type = 'TOOL' ORDER BY id LIMIT 2"));
    $builderRecord = $service->create(array_replace($payload, ['name' => 'Builder', 'slug' => 'builder-test',
        'proficiencyRules' => [...$payload['proficiencyRules'], ['label' => 'Outil au choix', 'choiceCount' => 1, 'optionIds' => $tools]],
        'languageRules' => [...$payload['languageRules'], ['label' => 'Géant', 'choiceCount' => 0, 'optionIds' => [$existing['languages']['giant']->getId()]]],
    ]), $owner);
    $background = $em->find(CharacterBackground::class, $builderRecord['id']);
    $preview = (new App\Entity\Character($campaign, 'preview', 'Preview', 'player'))->setRace($race)->setBackground($background);
    $preview->addClassLevel(new App\Entity\CharacterClassLevel($preview, $class, 1));
    $training = ['proficiencyChoices' => [], 'languageChoices' => []];
    foreach ($presenter->options($preview)['proficiencyRules'] as $r) if ($r['choiceCount'] > 0) $training['proficiencyChoices'][] = ['ruleId' => $r['ruleId'], 'proficiencyIds' => array_slice($r['optionIds'], 0, $r['choiceCount'])];
    $languageOptions = $presenter->options($preview)['languageRules'];
    $known = ['elvish']; // Reserve this option for the custom background.
    foreach ($languageOptions as $r) $known = [...$known, ...$r['automaticSlugs']];
    foreach ($languageOptions as $r) if ($r['choiceCount'] > 0) {
        $chosen = $r['sourceType'] === 'background' ? ['elvish'] : array_slice(array_values(array_diff($r['optionSlugs'], $known)), 0, $r['choiceCount']);
        $training['languageChoices'][] = ['sourceKey' => $r['sourceKey'], 'languageSlugs' => $chosen];
        $known = [...$known, ...$chosen];
    }
    $ability = new App\Service\CharacterAbilityCalculator();
    $hp = new App\Service\CharacterHitPointCalculator($ability);
    $features = new App\Service\CharacterFeatureResolver(new App\Repository\CharacterFeatureRuleRepository($registry));
    $resources = new App\Service\CharacterResourceResolver(new App\Repository\TrackableResourceRuleRepository($registry), $ability, $features);
    $sync = new App\Service\CharacterSessionStateSynchronizer($hp, new App\Service\CharacterHitPointStateService($hp), $resources,
        new App\Repository\CharacterSessionStateRepository($registry), new App\Service\CharacterSpellSlotStateService(new App\Service\CharacterSpellSlotCalculator()));
    $levelUp = new App\Service\CharacterLevelUpService($em, new App\Repository\CharacterClassLevelRuleRepository($registry), $sync,
        new App\Service\CharacterMulticlassEligibilityService($ability), $ability, trainingServiceForRegistry($registry), $presenter);
    $builder = new App\Service\CharacterBuilderService($em, new App\Repository\CharacterRepository($registry), $levelUp, trainingServiceForRegistry($registry));
    $scores = array_fill_keys(array_map(static fn ($a) => $a->value, App\Enum\Ability::cases()), 12);
    $built = $builder->create($campaign, 'built', 'Built', null, 'player', $race, $scores, [], [], $class, null, $background, $training);
    $builtId = $built->getId(); $em->clear();
    $built = $em->find(App\Entity\Character::class, $builtId);
    $effective = $presenter->resolved($built);
    $check(count(array_intersect(['stealth', 'survival', 'acrobatics'], array_column($effective['proficiencies'], 'slug'))) === 3, 'Builder persists three automatic custom skills.');
    $check(count(array_intersect($tools, array_column($effective['proficiencies'], 'id'))) === 1, 'Builder persists chosen tool.');
    $check(count(array_intersect(['giant', 'elvish'], array_column($effective['languages'], 'slug'))) === 2, 'Builder persists fixed and chosen language.');
    // A historical selection still protects the rule after the character changes background.
    $built->setBackground(null); $em->flush();
    $check($service->get($builderRecord['id'], $owner)['mechanicsLocked'], 'Historical language and proficiency choices protect detached background.');
    $check($request('DELETE', '/'.$builderRecord['id'])[0] === 409, 'Historical dependencies block deletion.');
    // Targeted command content is recognized without recreation or reinterpretation.
    $command = new Symfony\Component\Console\Tester\CommandTester(new App\Command\CreateMistWandererBackgroundCommand($em));
    $check($command->execute(['owner-email' => $owner->getEmail()]) === 0, 'Mist Wanderer fixture created.');
    $mistId = (int) $db->fetchOne("SELECT id FROM character_background WHERE slug = 'vagabond-des-brumes'");
    $mist = $service->get($mistId, $owner);
    $check($mist['proficiencyRules'][0]['choiceCount'] === 0 && count($mist['proficiencyRules'][0]['optionIds']) === 2 && str_contains($mist['description'], 'talisman'), 'Existing Mist Wanderer loaded faithfully.');
    $service->patch($mistId, ['equipment' => 'Un souvenir'], $owner);
    $check($service->get($mistId, $owner)['proficiencyRules'] === $mist['proficiencyRules'], 'Mist Wanderer rule IDs and options preserved.');
    echo "OK: $checks custom background assertions, temporary tables only.\n";
} finally {
    while ($db->isTransactionActive()) $db->rollBack();
    $kernel->shutdown();
}
