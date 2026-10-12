<?php

declare(strict_types=1);

require __DIR__.'/character-training-fixture.php';
require __DIR__.'/training-presenter-fixture.php';

(new Symfony\Component\Dotenv\Dotenv())->bootEnv(__DIR__.'/../.env');
$kernel = new App\Kernel('dev', true); $kernel->boot();
$registry = $kernel->getContainer()->get('doctrine');
$em = $registry->getManager(); $db = $em->getConnection();
$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void {
    if (!$ok) throw new RuntimeException($message);
    ++$checks;
};
try {
    $db->beginTransaction();
    $metadata = $em->getMetadataFactory()->getAllMetadata();
    foreach ((new Doctrine\ORM\Tools\SchemaTool($em))->getCreateSchemaSql($metadata) as $sql) {
        $db->executeStatement(preg_replace(['/^CREATE TABLE /', '/^CREATE SEQUENCE /'], ['CREATE TEMP TABLE ', 'CREATE TEMP SEQUENCE '], $sql));
    }
    foreach ($metadata as $meta) if ($db->fetchOne('SELECT relpersistence FROM pg_class WHERE oid = to_regclass(?)', [$meta->getTableName()]) !== 't') throw new RuntimeException('Table not isolated');
    extract(trainingFixture());
    $race = $races['aarakocra-mpmm'];
    $race->setLanguages(null)->setLanguageChoiceCount(null);
    $pending = $planner->plan($catalogue, $raceCatalogue, $existing);
    $check($pending['races'][$race->getSlug()]['action'] === 'keep', 'Default import leaves missing metadata unchanged');
    try { $service->apply($make('fighter', $race->getSlug(), 'acolyte-phb'), [], $owner, false); throw new RuntimeException('Missing metadata accepted'); }
    catch (DomainException $e) { $check(str_contains($e->getMessage(), 'import-training'), 'Missing metadata fails preview with repair command'); }
    $repair = $planner->plan($catalogue, $raceCatalogue, $existing, true);
    $check($repair['races'][$race->getSlug()]['action'] === 'update', 'Explicit import plans missing racial language metadata');
    $planner->applyUpdate('races', $repair['races'][$race->getSlug()]);
    $check($planner->plan($catalogue, $raceCatalogue, $existing, true)['races'][$race->getSlug()]['action'] === 'unchanged', 'Repair is idempotent');
    $owner->setEmail('languages@example.invalid')->setPassword('unused');
    foreach ([$owner, $campaign, ...array_values($classes), ...array_values($races), ...array_values($backgrounds),
        ...array_values($definitions), ...array_values($existing['languages']), ...array_values($existing['languageRules']),
        ...array_values($existing['expertiseRules']), ...$repo->rules] as $entity) $em->persist($entity);
    $em->flush();
    $payload = [
        'proficiencyChoices' => [['ruleId' => $rule('phb-2014-fighter-starting-skills'), 'proficiencyIds' => [$def('athletics'), $def('survival')]]],
        'languageChoices' => [
            ['sourceKey' => 'race:aarakocra-mpmm', 'languageSlugs' => ['infernal']],
            ['sourceKey' => 'phb-2014-background-acolyte-languages', 'languageSlugs' => ['elvish', 'draconic']],
        ],
    ];
    $presenter = new App\Service\CharacterTrainingPresenter($refs, $resolver, $proficiencyResolver);
    for ($i = 0; $i < 3; ++$i) {
        // Each refresh reconstructs a new preview from the submitted JSON, like the GET endpoint.
        $restored = json_decode(json_encode($payload, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        $preview = $make('fighter', $race->getSlug(), 'acolyte-phb');
        $service->apply($preview, $restored, $owner, false);
        $options = array_column($presenter->options($preview)['languageRules'], null, 'sourceKey');
        foreach ($restored['languageChoices'] as $choice) {
            $available = $options[$choice['sourceKey']]['optionSlugs'];
            $check(count(array_intersect($choice['languageSlugs'], $available)) === count($choice['languageSlugs']), 'Visible selected languages match counter after refresh');
        }
        $check(!in_array('infernal', $options['phb-2014-background-acolyte-languages']['optionSlugs'], true), 'Language from another source remains unavailable');
        $check(!in_array('common', $options['race:aarakocra-mpmm']['optionSlugs'], true), 'Automatic language cannot be chosen again');
    }
    $ability = new App\Service\CharacterAbilityCalculator();
    $hp = new App\Service\CharacterHitPointCalculator($ability);
    $features = new App\Service\CharacterFeatureResolver(new App\Repository\CharacterFeatureRuleRepository($registry));
    $resources = new App\Service\CharacterResourceResolver(new App\Repository\TrackableResourceRuleRepository($registry), $ability, $features);
    $sync = new App\Service\CharacterSessionStateSynchronizer($hp, new App\Service\CharacterHitPointStateService($hp), $resources,
        new App\Repository\CharacterSessionStateRepository($registry), new App\Service\CharacterSpellSlotStateService(new App\Service\CharacterSpellSlotCalculator()));
    $levelUp = new App\Service\CharacterLevelUpService($em, new App\Repository\CharacterClassLevelRuleRepository($registry), $sync,
        new App\Service\CharacterMulticlassEligibilityService($ability), $ability);
    $builder = new App\Service\CharacterBuilderService($em, new App\Repository\CharacterRepository($registry), $levelUp, $service);
    $scores = array_fill_keys(array_map(static fn ($a) => $a->value, App\Enum\Ability::cases()), 12);
    $character = $builder->create($campaign, 'language-roundtrip', 'Language roundtrip', 'Player', 'player', $race, $scores, [], [], $classes['fighter'], null, $backgrounds['acolyte-phb'], $restored);
    $characterId = $character->getId();
    $check(count($character->getLanguageChoices()) === 3, 'Only three choices persisted, automatic Common stays derived');
    $em->clear();
    $reloaded = $em->find(App\Entity\Character::class, $characterId);
    $actual = trainingPresenterForRegistry($registry)->resolved($reloaded);
    $check(array_column($actual['languages'], 'slug') === ['common', 'draconic', 'elvish', 'infernal'], 'Real builder flush and ORM reload preserve automatic and chosen languages');
    $choices = [];
    foreach ($reloaded->getLanguageChoices() as $choice) $choices[$choice->getSourceKey()][] = $choice->getLanguage()->getSlug();
    $check(count($choices['phb-2014-background-acolyte-languages']) === 2 && $choices['race:aarakocra-mpmm'] === ['infernal'], 'Persisted choices keep their own source keys');
    echo "OK: $checks language import/preview/builder/ORM roundtrip assertions; temporary tables rolled back.\n";
} finally {
    while ($db->isTransactionActive()) $db->rollBack();
    $kernel->shutdown();
}
