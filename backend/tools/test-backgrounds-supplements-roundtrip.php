<?php

declare(strict_types=1);

// Integration fixtures only: all ORM tables/sequences are temporary and rolled back.
require __DIR__.'/character-training-fixture.php';
require __DIR__.'/training-presenter-fixture.php';

use App\Entity\{Character, CharacterBackground, LanguageDefinition, LanguageGrantRule, ProficiencyDefinition, ProficiencyGrantRule, User};
use App\Service\Catalogue\BackgroundImportPlanner;
use App\Service\ReferenceVisibility;

(new Symfony\Component\Dotenv\Dotenv())->bootEnv(__DIR__.'/../.env');
putenv('SHELL_VERBOSITY=-1'); $_SERVER['SHELL_VERBOSITY'] = $_ENV['SHELL_VERBOSITY'] = -1;
$kernel = new App\Kernel('dev', true); $kernel->boot();
$registry = $kernel->getContainer()->get('doctrine');
$em = $registry->getManager(); $db = $em->getConnection();
$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void { if (!$ok) throw new RuntimeException($message); ++$checks; };
// Doctrine does not guarantee ManyToMany option order. Compare complete values as sets, retaining multiplicity.
$canonical = static function (mixed $value) use (&$canonical): mixed {
    if (!is_array($value)) return $value;
    $result = array_map($canonical, $value);
    if (array_is_list($result)) usort($result, static fn ($a, $b) => strcmp(json_encode($a), json_encode($b)));
    else ksort($result);
    return $result;
};
try {
    $db->beginTransaction();
    $metadata = $em->getMetadataFactory()->getAllMetadata();
    foreach ((new Doctrine\ORM\Tools\SchemaTool($em))->getCreateSchemaSql($metadata) as $sql) {
        $db->executeStatement(preg_replace(['/^CREATE TABLE /', '/^CREATE SEQUENCE /'], ['CREATE TEMP TABLE ', 'CREATE TEMP SEQUENCE '], $sql));
    }
    foreach ($metadata as $meta) if ($db->fetchOne('SELECT relpersistence FROM pg_class WHERE oid = to_regclass(?)', [$meta->getTableName()]) !== 't') throw new RuntimeException('Table not isolated');
    extract(trainingFixture());
    $owner->setEmail('background-owner@example.invalid')->setPassword('unused');
    foreach ([$owner, $campaign, ...array_values($classes), ...array_values($races), ...array_values($backgrounds),
        ...array_values($definitions), ...array_values($existing['languages']), ...array_values($existing['languageRules']),
        ...array_values($existing['expertiseRules']), ...$repo->rules] as $entity) $em->persist($entity);
    $legacy = $make('fighter', 'human-phb', 'acolyte-phb');
    $em->persist($legacy);
    $em->flush();
    $legacyId = $legacy->getId(); $legacyBackgroundId = $legacy->getBackground()->getId();
    $priorIds = array_map(static fn ($b) => $b->getId(), $backgrounds);
    $catalogue = json_decode(file_get_contents(__DIR__.'/../data/reference/dnd-2014-backgrounds-supplements.json'), true, 512, JSON_THROW_ON_ERROR);
    $planner = new BackgroundImportPlanner();
    $plan = $planner->plan($catalogue, $backgrounds, $definitions, $repo->rules, [], false, $existing['languages'], $existing['languageRules']);
    foreach ($plan as $items) foreach ($items as $item) $em->persist($item['candidate']);
    $em->flush();
    foreach ($plan['backgrounds'] as $item) $backgrounds[$item['candidate']->getSlug()] = $item['candidate'];
    $check($db->fetchOne('SELECT COUNT(*) FROM character_background') === 32, '13 PHB plus 19 supplements in isolated database.');
    foreach ($priorIds as $slug => $beforeId) $check($backgrounds[$slug]->getId() === $beforeId, 'Original background ID unchanged.');

    $other = (new User())->setEmail('background-other@example.invalid')->setPassword('unused');
    $private = CharacterBackground::forOwner($owner, 'private-background', 'Privé du MJ');
    $foreign = CharacterBackground::forOwner($other, 'foreign-background', 'Autre MJ');
    foreach ([$other, $private, $foreign] as $entity) $em->persist($entity);
    $em->flush();
    $visible = ReferenceVisibility::choices($em, CharacterBackground::class, $owner);
    $check(count($visible) === 33 && in_array($private, $visible, true) && !in_array($foreign, $visible, true), 'Campaign catalogue includes own private background, excludes foreign.');
    $controller = new App\Controller\DndReferenceController(new App\Service\CharacterRaceMetadataResolver(), dirname(__DIR__));
    $controller->setContainer(new Symfony\Component\DependencyInjection\Container());
    $response = json_decode($controller->show($em)->getContent(), true, 512, JSON_THROW_ON_ERROR);
    $listed = array_column($response['backgrounds'], null, 'slug');
    $check(count($listed) === 32, 'Actual public reference response exposes 32 official backgrounds.');
    foreach ($catalogue['backgrounds'] as $row) $check(isset($listed[$row['slug']]) && $listed[$row['slug']]['description'] === $row['description'], 'Builder catalogue exposes new background and limitations.');

    $ability = new App\Service\CharacterAbilityCalculator();
    $hp = new App\Service\CharacterHitPointCalculator($ability);
    $features = new App\Service\CharacterFeatureResolver(new App\Repository\CharacterFeatureRuleRepository($registry));
    $resources = new App\Service\CharacterResourceResolver(new App\Repository\TrackableResourceRuleRepository($registry), $ability, $features);
    $sync = new App\Service\CharacterSessionStateSynchronizer($hp, new App\Service\CharacterHitPointStateService($hp), $resources,
        new App\Repository\CharacterSessionStateRepository($registry), new App\Service\CharacterSpellSlotStateService(new App\Service\CharacterSpellSlotCalculator()));
    $levelUp = new App\Service\CharacterLevelUpService($em, new App\Repository\CharacterClassLevelRuleRepository($registry), $sync,
        new App\Service\CharacterMulticlassEligibilityService($ability), $ability, trainingServiceForRegistry($registry), trainingPresenterForRegistry($registry));
    $builder = new App\Service\CharacterBuilderService($em, new App\Repository\CharacterRepository($registry), $levelUp, trainingServiceForRegistry($registry));
    $presenter = trainingPresenterForRegistry($registry);
    $scores = array_fill_keys(array_map(static fn ($a) => $a->value, App\Enum\Ability::cases()), 12);
    $created = [];
    foreach ($catalogue['backgrounds'] as $row) {
        $background = $backgrounds[$row['slug']];
        $preview = $make('fighter', 'human-phb', null)->setBackground($background);
        $options = $presenter->options($preview);
        $payload = ['proficiencyChoices' => [], 'languageChoices' => []];
        foreach ($options['proficiencyRules'] as $r) if ($r['choiceCount'] > 0) $payload['proficiencyChoices'][] = ['ruleId' => $r['ruleId'], 'proficiencyIds' => array_slice($r['optionIds'], 0, $r['choiceCount'])];
        $known = [];
        foreach ($options['languageRules'] as $r) $known = [...$known, ...$r['automaticSlugs']];
        foreach ($options['languageRules'] as $r) if ($r['choiceCount'] > 0) {
            $selected = array_slice(array_values(array_diff($r['optionSlugs'], $known)), 0, $r['choiceCount']);
            $payload['languageChoices'][] = ['sourceKey' => $r['sourceKey'], 'languageSlugs' => $selected];
            $known = [...$known, ...$selected];
        }
        // Same final builder path as the POST, with explicit test-only choices.
        $character = $builder->create($campaign, $row['slug'], $row['name'], 'Test', 'player', $races['human-phb'], $scores, [], [], $classes['fighter'], null, $background, $payload);
        $created[$character->getId()] = ['slug' => $row['slug'], 'backgroundId' => $background->getId(), 'training' => $presenter->resolved($character), 'choices' => $presenter->choices($character)];
        $check($character->getFeats()->isEmpty(), 'Builder never adds unsupported background feats.');
    }
    $em->clear();
    $check($em->find(Character::class, $legacyId)->getBackground()->getId() === $legacyBackgroundId, 'Existing character association survives supplement import and reload.');
    foreach ($created as $characterId => $before) {
        $reloaded = $em->find(Character::class, $characterId);
        $check($reloaded->getBackground()->getId() === $before['backgroundId'], 'Selected background persisted.');
        $check($canonical($presenter->resolved($reloaded)) === $canonical($before['training']), 'Actual proficiencies/languages survive ORM reload: '.$before['slug']);
        $check($canonical($presenter->choices($reloaded)) === $canonical($before['choices']), 'Choices retain IDs and source keys after reload.');
    }
    $loaded = [];
    foreach (['backgrounds' => CharacterBackground::class, 'definitions' => ProficiencyDefinition::class, 'languages' => LanguageDefinition::class] as $kind => $type) {
        $loaded[$kind] = [];
        foreach ($em->getRepository($type)->findAll() as $entity) $loaded[$kind][$entity->getSlug()] = $entity;
    }
    $again = $planner->plan($catalogue, $loaded['backgrounds'], $loaded['definitions'], $em->getRepository(ProficiencyGrantRule::class)->findAll(), [], true, $loaded['languages'], $em->getRepository(LanguageGrantRule::class)->findAll());
    foreach ($again as $items) foreach ($items as $item) $check($item['action'] === 'unchanged', 'Persisted reimport is idempotent.');
    // Exercise the real command in dry-run only, against the isolated tables.
    $tester = new Symfony\Component\Console\Tester\CommandTester(new App\Command\ImportBackgroundsCommand($em, $planner, dirname(__DIR__)));
    $beforeCounts = $db->fetchAssociative('SELECT (SELECT COUNT(*) FROM character_background) AS backgrounds, (SELECT COUNT(*) FROM proficiency_grant_rule) AS proficiencies, (SELECT COUNT(*) FROM language_grant_rule) AS languages');
    $check($tester->execute(['path' => dirname(__DIR__).'/data/reference/dnd-2014-backgrounds-supplements.json', '--dry-run' => true, '--update-existing' => true]) === 0, 'Command validates persisted supplement catalogue.');
    $check(str_contains($tester->getDisplay(), 'unchanged') && !str_contains($tester->getDisplay(), 'create :'), 'Command plans no duplicate references.');
    $check($beforeCounts === $db->fetchAssociative('SELECT (SELECT COUNT(*) FROM character_background) AS backgrounds, (SELECT COUNT(*) FROM proficiency_grant_rule) AS proficiencies, (SELECT COUNT(*) FROM language_grant_rule) AS languages'), 'Dry-run performs no writes.');
    echo "OK: $checks supplement builder/catalogue/ORM assertions; temporary tables rolled back.\n";
} finally {
    while ($db->isTransactionActive()) $db->rollBack();
    $kernel->shutdown();
}
