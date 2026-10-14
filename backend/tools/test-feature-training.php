<?php

declare(strict_types=1);

// Real services and ORM; all tables are transaction-local PostgreSQL temporary tables.
require __DIR__.'/character-training-fixture.php';
require __DIR__.'/training-presenter-fixture.php';

use App\Entity\{Character, CharacterSubclass, CharacterClassLevel, ProficiencyGrantRule};
use App\Service\Catalogue\FeatureProficiencyImportPlanner;

(new Symfony\Component\Dotenv\Dotenv())->bootEnv(__DIR__.'/../.env');
$kernel = new App\Kernel('dev', true); $kernel->boot();
$registry = $kernel->getContainer()->get('doctrine');
$em = $registry->getManager(); $db = $em->getConnection();
$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) throw new RuntimeException($message);
    ++$checks;
};
$reject = static function (callable $run, string $message) use ($check): void {
    try { $run(); } catch (DomainException|LogicException $e) { $check(true, $message); return; }
    throw new RuntimeException($message);
};
try {
    $db->beginTransaction();
    $metadata = $em->getMetadataFactory()->getAllMetadata();
    foreach ((new Doctrine\ORM\Tools\SchemaTool($em))->getCreateSchemaSql($metadata) as $sql) {
        $db->executeStatement(preg_replace(['/^CREATE TABLE /', '/^CREATE SEQUENCE /'], ['CREATE TEMP TABLE ', 'CREATE TEMP SEQUENCE '], $sql));
    }
    foreach ($metadata as $meta) if ($db->fetchOne('SELECT relpersistence FROM pg_class WHERE oid = to_regclass(?)', [$meta->getTableName()]) !== 't') throw new RuntimeException('Table not isolated');
    extract(trainingFixture());
    $classes['wizard']->setSubclassSelectionLevel(2);
    $blade = new CharacterSubclass($classes['wizard'], 'bladesinging', 'Chantelame Tasha');
    $assassin = new CharacterSubclass($classes['rogue'], 'assassin', 'Assassin');
    $sources = ['class' => $classes, 'subclass' => ['wizard:bladesinging' => $blade, 'rogue:assassin' => $assassin]];
    $catalogue = json_decode(file_get_contents(__DIR__.'/../data/reference/dnd-2014-feature-proficiencies.json'), true, 512, JSON_THROW_ON_ERROR);
    $importer = new FeatureProficiencyImportPlanner();
    $plan = $importer->plan($catalogue, $sources, $definitions, []);
    $rules = array_column($plan, 'candidate');
    $check(count($rules) === 3, 'Three rules, automatic and choice benefits separated');
    $check(array_column($importer->plan($catalogue, $sources, $definitions, $rules), 'action') === ['unchanged','unchanged','unchanged'], 'Import is idempotent');
    $bad = $catalogue; $bad['rules'][0]['options'] = ['unknown'];
    $reject(fn () => $importer->plan($bad, $sources, $definitions, []), 'Unknown proficiency rejected');
    $reject(fn () => $importer->plan($catalogue, [], $definitions, []), 'Unknown source rejected');
    $bad = $catalogue; $bad['rules'][] = $bad['rules'][0];
    $reject(fn () => $importer->plan($bad, $sources, $definitions, []), 'Repeated import key rejected');
    $bad['rules'][3]['key'] = 'different-key-same-rule';
    $reject(fn () => $importer->plan($bad, $sources, $definitions, []), 'Duplicate mechanics under another key rejected');
    $custom = new ProficiencyGrantRule('Protected');
    (new ReflectionProperty($custom, 'origin'))->setValue($custom, App\Enum\ReferenceOrigin::Custom);
    (new ReflectionProperty($custom, 'owner'))->setValue($custom, $owner);
    $custom->setCharacterSubclass($blade)->setImportKey($rules[0]->getImportKey())->addOption($definitions['performance']);
    $reject(fn () => $importer->plan($catalogue, $sources, $definitions, [$custom]), 'Personal rule protected');
    $owner->setEmail('feature-training@example.invalid')->setPassword('unused');
    foreach ([$owner, $campaign, ...array_values($classes), $blade, $assassin, ...array_values($races), ...array_values($backgrounds),
        ...array_values($definitions), ...array_values($existing['languages']), ...array_values($existing['languageRules']),
        ...array_values($existing['expertiseRules']), ...$repo->rules, ...$rules] as $entity) $em->persist($entity);
    $em->flush();
    $bad = $catalogue; $bad['rules'][1]['options'] = ['rapier'];
    $reject(fn () => $importer->plan($bad, $sources, $definitions, $rules, [$rules[1]->getId()], true), 'Used rule cannot be mechanically rewritten');
    $check($rules[1]->getOptions()->count() === 20, 'One-handed melee selection includes versatile weapons, excludes two-handed/ranged weapons');
    $training = trainingServiceForRegistry($registry);
    $presenter = trainingPresenterForRegistry($registry);
    $ability = new App\Service\CharacterAbilityCalculator();
    $hp = new App\Service\CharacterHitPointCalculator($ability);
    $features = new App\Service\CharacterFeatureResolver(new App\Repository\CharacterFeatureRuleRepository($registry));
    $resources = new App\Service\CharacterResourceResolver(new App\Repository\TrackableResourceRuleRepository($registry), $ability, $features);
    $sync = new App\Service\CharacterSessionStateSynchronizer($hp, new App\Service\CharacterHitPointStateService($hp), $resources,
        new App\Repository\CharacterSessionStateRepository($registry), new App\Service\CharacterSpellSlotStateService(new App\Service\CharacterSpellSlotCalculator()));
    $levelUp = new App\Service\CharacterLevelUpService($em, new App\Repository\CharacterClassLevelRuleRepository($registry), $sync,
        new App\Service\CharacterMulticlassEligibilityService($ability), $ability, $training, $presenter);
    $builder = new App\Service\CharacterBuilderService($em, new App\Repository\CharacterRepository($registry), $levelUp, $training);
    $scores = array_fill_keys(array_map(static fn ($a) => $a->value, App\Enum\Ability::cases()), 14);
    $wizardPayload = ['proficiencyChoices' => [['ruleId' => $rule('phb-2014-wizard-starting-skills'), 'proficiencyIds' => [$def('arcana'),$def('history')]]],
        'languageChoices' => [['sourceKey' => 'race:human-phb', 'languageSlugs' => ['elvish']]]];
    $wizard = $builder->create($campaign, 'blade-test', 'Blade', 'Player', 'player', $races['human-phb'], $scores, [], [], $classes['wizard'], null, null, $wizardPayload);
    $slugs = static fn ($c) => array_column($presenter->resolved($c)['proficiencies'], 'slug');
    $check(!in_array('light-armor', $slugs($wizard), true), 'Wizard level one has no subclass armor');
    $preview = $levelUp->trainingOptions($wizard, $classes['wizard'], $blade);
    $check($wizard->getTotalLevel() === 1, 'Preview leaves class levels unchanged');
    $check(in_array('performance', array_column($preview['resolved']['proficiencies'], 'slug'), true), 'Preview grants automatic Performance');
    $check(!isset($preview['resolved']['manualGrants']) && isset($preview['definitions']['proficiencies']), 'Player preview has option labels without GM audit log');
    $weapon = ['proficiencyChoices' => [['ruleId' => $rules[1]->getId(), 'proficiencyIds' => [$def('rapier')]]]];
    $levelUp->levelUp($wizard, $classes['wizard'], $blade, training: $weapon);
    $check(count(array_intersect(['light-armor','performance','rapier'], $slugs($wizard))) === 3, 'Wizard 1 to Bladesinger 2 grants automatic and selected benefits');
    $ids = array_map(static fn ($c) => $c->getId(), $wizard->getProficiencyChoices()->toArray());
    for ($i=0; $i<3; ++$i) { $training->applyChoices($wizard, $weapon + ['languageChoices' => $wizardPayload['languageChoices']]); $em->flush(); }
    $check($ids === array_map(static fn ($c) => $c->getId(), $wizard->getProficiencyChoices()->toArray()), 'Repeated selections retain row identities');
    $check(count($wizard->getLanguageChoices()) === 1, 'Repeated language selections do not duplicate');
    $reject(fn () => $training->applyChoices($wizard, ['manualGrants' => []]), 'Level-up payload cannot grant manual benefits');
    $reject(fn () => $training->applyChoices($wizard, ['proficiencyChoices' => [['ruleId'=>$rules[1]->getId(), 'proficiencyIds'=>[$def('longbow')]]]]), 'Ranged weapon rejected');

    $rogue = $builder->create($campaign, 'assassin-test', 'Assassin', 'Player', 'player', $races['human-phb'], $scores, [], [], $classes['rogue'], null, $backgrounds['criminal-phb'], $payload);
    $levelUp->levelUp($rogue, $classes['rogue']);
    $check(!in_array('poisoners-kit', $slugs($rogue), true), 'Rogue 2 has no Assassin tools');
    $levelUp->levelUp($rogue, $classes['rogue'], $assassin);
    $check(count(array_intersect(['disguise-kit','poisoners-kit'], $slugs($rogue))) === 2, 'Rogue 2 to Assassin 3 grants both tools');
    $expertiseIds = array_map(static fn ($c) => $c->getId(), $rogue->getExpertiseChoices()->toArray());
    $training->applyChoices($rogue, ['expertiseChoices' => $payload['expertiseChoices']]); $em->flush();
    $check($expertiseIds === array_map(static fn ($c) => $c->getId(), $rogue->getExpertiseChoices()->toArray()), 'Expertise replay retains rows');

    $multi = $builder->create($campaign, 'multi-test', 'Multi', 'Player', 'player', $races['human-phb'], $scores, [], [], $classes['wizard'], null, null, $wizardPayload);
    $multiPayload = [
        'proficiencyChoices' => [['ruleId'=>$rule('phb-2014-rogue-multiclass-skills'), 'proficiencyIds'=>[$def('stealth')]]],
        'expertiseChoices' => [['ruleId'=>$existing['expertiseRules']['phb-2014-rogue-expertise-1']->getId(), 'proficiencyIds'=>[$def('arcana'),$def('stealth')]]],
    ];
    $levelUp->levelUp($multi, $classes['rogue'], training: $multiPayload);
    $check(in_array('stealth', $slugs($multi), true) && count($presenter->resolved($multi)['expertises']) === 2, 'Multiclass skill and expertise choices acquired atomically');
    $check(!in_array('saving-throw-dexterity', $slugs($multi), true), 'Multiclass rogue does not grant starting saves');
    $levelUp->levelUp($multi, $classes['rogue']);
    $check(!in_array('poisoners-kit', $slugs($multi), true), 'Total level three does not unlock rogue level-three feature');
    $levelUp->levelUp($multi, $classes['rogue'], $assassin);
    $check(in_array('poisoners-kit', $slugs($multi), true) && in_array('arcana', $slugs($multi), true), 'Subclass benefits acquired later in secondary class preserve first class');

    $levelUp->levelUp($wizard, $classes['fighter']);
    $light = array_values(array_filter($presenter->resolved($wizard)['proficiencies'], static fn ($p) => $p['slug'] === 'light-armor'))[0];
    $check(count($light['grants']) === 2, 'Multiclass and subclass origins retained in one proficiency');
    $check(!in_array('saving-throw-strength', $slugs($wizard), true), 'Secondary fighter does not grant starting saves');
    $subclassLevel = $wizard->getClassLevels()->filter(static fn ($l) => $l->getSubclass() === $blade)->first();
    $subclassLevel->setSubclass(null); $em->flush();
    $check(in_array('light-armor', $slugs($wizard), true) && !in_array('rapier', $slugs($wizard), true), 'Removing subclass keeps other source; choice loses effect');
    $check(count($wizard->getProficiencyChoices()) === 3, 'Inactive choice history preserved');
    $subclassLevel->setSubclass($blade); $em->flush();
    $check(in_array('rapier', $slugs($wizard), true), 'Restoring subclass reactivates historical selection');

    // Existing level-two character lacking a weapon choice can catch up without another level.
    $legacy = new Character($campaign, 'legacy-blade', 'Legacy', 'player');
    $legacy->addClassLevel(new CharacterClassLevel($legacy,$classes['wizard'],1));
    $legacy->addClassLevel(new CharacterClassLevel($legacy,$classes['wizard'],2,$blade));
    $em->persist($legacy); $em->flush();
    $state = array_column($presenter->choices($legacy), null, 'key');
    $check($state['proficiency:'.$rules[1]->getId()]['status'] === 'missing', 'Legacy missing choice exposed');
    $tokens = new Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage();
    $tokens->setToken(new Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken($owner,'test',[]));
    $container = new Symfony\Component\DependencyInjection\Container();
    $container->set('security.authorization_checker', new Symfony\Component\Security\Core\Authorization\AuthorizationChecker($tokens,
        new Symfony\Component\Security\Core\Authorization\AccessDecisionManager([new App\Security\Voter\CampaignVoter()])));
    $controller = new App\Controller\CharacterTrainingController(); $controller->setContainer($container);
    $session = new App\Entity\GameSession($campaign,'training','Training');
    $sessionState = new App\Entity\CharacterSessionState($session,$legacy,['resources'=>[], 'hitPoints'=>['current'=>3]]);
    $em->persist($session); $em->persist($sessionState); $em->flush();
    $stateBefore = $sessionState->getState(); $revisionBefore = $sessionState->getRevision();
    $request = static fn ($body) => Symfony\Component\HttpFoundation\Request::create('/', 'PATCH', content: json_encode($body, JSON_THROW_ON_ERROR));
    $call = static fn () => $controller->choices($legacy,$request($weapon),$em,$training,$presenter,new App\Repository\CharacterSessionStateRepository($registry));
    $response = $call();
    $check($response->getStatusCode() === 200, 'GM catch-up endpoint accepts valid selection');
    $check($sessionState->getRevision() === $revisionBefore+1 && $sessionState->getState() === $stateBefore, 'Catch-up synchronizes revision without resetting state');
    $response = $call();
    $check($response->getStatusCode() === 200 && $sessionState->getRevision() === $revisionBefore+1, 'Replayed PATCH is idempotent including revision');
    $manualPayload = ['manualGrants' => [['kind' => 'PROFICIENCY', 'proficiencyId' => $def('nature'), 'reason' => 'Reliability test']]];
    $grant = static fn () => $controller->grant($legacy, $request($manualPayload), $em, $training, $presenter, new App\Repository\CharacterSessionStateRepository($registry));
    $check($grant()->getStatusCode() === 201, 'Manual grant endpoint accepts award');
    $check($sessionState->getRevision() === $revisionBefore+2 && $sessionState->getState() === $stateBefore, 'Manual grant invalidates revision without resetting state');
    $manualId = $legacy->getManualGrants()->first()->getId();
    $em->refresh($legacy);
    $check($grant()->getStatusCode() === 201 && $legacy->getManualGrants()->count() === 1, 'Persisted retry is idempotent');
    $check($legacy->getManualGrants()->first()->getId() === $manualId && $sessionState->getRevision() === $revisionBefore+2, 'Retry retains row identity and revision');
    $tokens->setToken(new Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken(new App\Entity\User(),'test',[]));
    try { $call(); throw new RuntimeException('Foreign GM accepted'); }
    catch (Symfony\Component\Security\Core\Exception\AccessDeniedException) { $check(true, 'Catch-up respects campaign ownership'); }
    $check(in_array('rapier', $slugs($legacy), true), 'Catch-up grants selected weapon');
    $wizardId = $wizard->getId(); $rogueId = $rogue->getId(); $legacyId = $legacy->getId();
    $em->clear();
    foreach ([$wizardId=>['light-armor','performance','rapier'], $rogueId=>['disguise-kit','poisoners-kit'], $legacyId=>['rapier']] as $cid=>$expected) {
        $reloaded = $em->find(Character::class,$cid);
        $check(count(array_intersect($expected,$slugs($reloaded))) === count($expected), 'ORM reload retains effective training');
    }
    // Missing new choice must roll back the real level-up, including its flush.
    $campaign = $em->find(App\Entity\Campaign::class, $campaign->getId());
    $class = $em->getRepository(App\Entity\CharacterClass::class)->findOneBy(['slug'=>'wizard']);
    $blade = $em->getRepository(CharacterSubclass::class)->findOneBy(['slug'=>'bladesinging']);
    $incomplete = new Character($campaign,'incomplete','Incomplete','player');
    $incomplete->addClassLevel(new CharacterClassLevel($incomplete,$class,1));
    $em->persist($incomplete); $em->flush(); $incompleteId=$incomplete->getId();
    $reject(fn () => $levelUp->levelUp($incomplete,$class,$blade), 'Missing new weapon choice rejects level-up');
    $check((int)$db->fetchOne('SELECT count(*) FROM character_class_level WHERE character_id=?',[$incompleteId]) === 1, 'Rejected level-up is atomic');
    echo "OK: $checks feature import/training/lifecycle assertions; temporary tables rolled back.\n";
} finally {
    while ($db->isTransactionActive()) $db->rollBack();
    $kernel->shutdown();
}
