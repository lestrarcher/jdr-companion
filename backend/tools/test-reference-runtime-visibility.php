<?php

declare(strict_types=1);

// All ORM tables and sequences are shadowed in a rolled-back transaction.
use App\Entity\{User, Campaign, Character, CharacterClass, CharacterSubclass, CharacterRace, Feat, CharacterFeat, CharacterClassLevel, CharacterProgression, ProgressionDefinition, CharacterFeatureDefinition, CharacterFeatureRule, TrackableResourceDefinition, TrackableResourceRule, CharacterActionDefinition, CharacterActionClassRule, CharacterSessionState, GameSession, RestRequest};
use App\Enum\{Ability, HitPointGainMethod, ReferenceOrigin, ResourceRechargeType, ResourceMaximumType, CharacterActionHandlerType, SpellcastingProgressionType};
use App\Repository\{CharacterFeatureRuleRepository, TrackableResourceRuleRepository, CharacterActionClassRuleRepository, TrackableResourceDefinitionRepository, CharacterSessionStateRepository, CharacterActiveEffectRepository, CharacterClassLevelRuleRepository};
use App\Service\{ReferenceVisibility, CharacterFeatureResolver, CharacterResourceResolver, CharacterActionResolver, CharacterAbilityCalculator, CharacterHitPointCalculator, CharacterHitPointStateService, CharacterSpellSlotCalculator, CharacterSpellSlotStateService, CharacterSessionStateFactory, CharacterSessionStateSynchronizer, CharacterProfileSerializer, CharacterSessionStateSerializer, CharacterRestService, CharacterActiveEffectService, CharacterLevelUpService, CharacterMulticlassEligibilityService};
use Symfony\Component\HttpFoundation\{Request, Session\Session, Session\Storage\MockArraySessionStorage};
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

require __DIR__.'/../vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv(__DIR__.'/../.env');
putenv('SHELL_VERBOSITY=-1');
$_SERVER['SHELL_VERBOSITY'] = $_ENV['SHELL_VERBOSITY'] = -1;
$kernel = new App\Kernel('dev', true); $kernel->boot();
$registry = $kernel->getContainer()->get('doctrine');
$em = $registry->getManager(); $db = $em->getConnection();
$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void {
    if (!$ok) throw new RuntimeException($message);
    ++$checks;
};
$keys = static function (array $items): array { $keys = array_keys($items); sort($keys); return $keys; };
$entries = static fn (array $state): array => array_column($state['resources'], null, 'id');
$session = new Session(new MockArraySessionStorage()); $session->start();
$request = static function (string $path, string $method = 'GET', ?array $payload = null) use ($kernel, $session): array {
    $request = Request::create($path, $method, [], [$session->getName() => $session->getId()], [], ['CONTENT_TYPE' => 'application/json'], $payload === null ? null : json_encode($payload));
    $request->setSession($session);
    $response = $kernel->handle($request);
    return [$response->getStatusCode(), json_decode($response->getContent(), true)];
};
try {
    $db->beginTransaction();
    $metadata = $em->getMetadataFactory()->getAllMetadata();
    foreach ((new Doctrine\ORM\Tools\SchemaTool($em))->getCreateSchemaSql($metadata) as $sql) {
        $db->executeStatement(preg_replace(['/^CREATE TABLE /', '/^CREATE SEQUENCE /'], ['CREATE TEMP TABLE ', 'CREATE TEMP SEQUENCE '], $sql));
    }
    foreach ($metadata as $meta) $check($db->fetchOne('SELECT relpersistence FROM pg_class WHERE oid = to_regclass(?)', [$meta->getTableName()]) === 't', 'Temporary table '.$meta->getTableName());

    $users = []; $campaigns = []; $characters = []; $states = [];
    foreach (['a', 'b'] as $key) {
        $users[$key] = (new User())->setEmail($key.'@runtime.invalid')->setPassword('unused');
        $campaigns[$key] = new Campaign($users[$key], 'campaign-'.$key, 'Campaign '.$key);
        $em->persist($users[$key]); $em->persist($campaigns[$key]);
    }
    // Ownership has no production setter yet. Reflection is confined to disposable fixtures.
    $save = static function (object $entity, string $scope = 'o') use ($em, &$users): object {
        if ($scope !== 'o') {
            (new ReflectionProperty($entity, 'origin'))->setValue($entity, ReferenceOrigin::Custom);
            (new ReflectionProperty($entity, 'owner'))->setValue($entity, $users[$scope]);
        }
        $em->persist($entity);
        return $entity;
    };
    $resource = static fn (string $slug, string $scope = 'o', ResourceRechargeType $recharge = ResourceRechargeType::LongRest): TrackableResourceDefinition => $save(new TrackableResourceDefinition($slug, $slug, $recharge, ResourceMaximumType::Fixed, 5), $scope);
    $class = $save(new CharacterClass('shared', 'Shared official class', 8, 20));
    foreach (['a', 'b'] as $key) {
        $character = new Character($campaigns[$key], 'character-'.$key, 'Character '.$key, Character::TYPE_PLAYER);
        foreach (Ability::cases() as $ability) $character->getAbilityScore($ability)->setBaseValue(14);
        for ($level = 1; $level <= 3; ++$level) {
            $row = new CharacterClassLevel($character, $class, $level, null, 5, HitPointGainMethod::Average);
            $character->addClassLevel($row); $em->persist($row);
        }
        $em->persist($character); $characters[$key] = $character;
    }
    // Definitions deliberately OFFICIAL: only the assignment scope distinguishes A and B.
    foreach (['o', 'a', 'b'] as $key) {
        $feature = $save(new CharacterFeatureDefinition('feature-'.$key, 'Feature '.$key));
        $save(CharacterFeatureRule::forClass($feature, $class, 1), $key);
        $save(TrackableResourceRule::forClass($resource('resource-'.$key), $class, 1), $key);
        $action = $save((new CharacterActionDefinition('action-'.$key, 'Action '.$key, CharacterActionHandlerType::Aid))->setRequiresPreparation(true));
        $save(new CharacterActionClassRule($action, $class, 1), $key);
    }
    $em->flush();
    $featureRepo = new CharacterFeatureRuleRepository($registry);
    $resourceRepo = new TrackableResourceRuleRepository($registry);
    $actionRepo = new CharacterActionClassRuleRepository($registry);
    $definitions = new TrackableResourceDefinitionRepository($registry);
    $ability = new CharacterAbilityCalculator(); $hp = new CharacterHitPointCalculator($ability);
    $hpState = new CharacterHitPointStateService($hp); $slots = new CharacterSpellSlotCalculator();
    $slotState = new CharacterSpellSlotStateService($slots);
    $features = new CharacterFeatureResolver($featureRepo);
    $resources = new CharacterResourceResolver($resourceRepo, $ability, $features);
    $actions = new CharacterActionResolver($actionRepo);
    $effectRepo = new CharacterActiveEffectRepository($registry);
    $sync = new CharacterSessionStateSynchronizer($hp, $hpState, $resources, new CharacterSessionStateRepository($registry), $slotState);
    $factory = new CharacterSessionStateFactory($hp, $resources, $slots, $effectRepo);
    $profile = new CharacterProfileSerializer($ability, $features, $resources, $hp, $slots, $actions);
    $serializer = new CharacterSessionStateSerializer($profile, $hpState, $effectRepo, $slotState);
    $rest = new CharacterRestService($hp, $hpState, $resources, $sync, $slots, $definitions, $ability, $effectRepo, new CharacterActiveEffectService($hpState), $em);
    $levelUp = new CharacterLevelUpService($em, new CharacterClassLevelRuleRepository($registry), $sync, new CharacterMulticlassEligibilityService($ability), $ability);
    foreach (['a', 'b'] as $key) {
        foreach (['feature' => $features, 'resource' => $resources, 'action' => $actions] as $type => $resolver) {
            $check($keys($resolver->resolve($characters[$key])) === [$type.'-'.$key, $type.'-o'], "$type O + $key only on shared official parent");
        }
        foreach ([$featureRepo, $resourceRepo, $actionRepo] as $repo) {
            $check(count($repo->findVisibleForOwner($users[$key])) === 2, 'Doctrine filters root assignments before hydration');
        }
    }
    $check(count($featureRepo->findOrdered()) === 3 && count($resourceRepo->findOrderedRules()) === 3 && count($actionRepo->findActiveOrdered()) === 3, 'Global maintenance queries unchanged');

    // Validate every edge, including OFFICIAL -> own CUSTOM (also forbidden).
    foreach ([['o','o',true], ['a','o',true], ['a','a',true], ['a','b',false], ['o','a',false]] as $i => [$featureScope, $resourceScope, $valid]) {
        $direct = $resource('direct-'.$i, $resourceScope);
        $feature = $save((new CharacterFeatureDefinition('direct-feature-'.$i, 'Direct '.$i))->setResourceDefinition($direct), $featureScope);
        $rule = $save(CharacterFeatureRule::forClass($feature, $class, 1), $featureScope);
        $em->flush();
        $check(ReferenceVisibility::allows($rule, $users['a']) === $valid, 'Direct edge matrix '.$i);
        $check(isset($features->resolve($characters['a'])[$feature->getSlug()]) === $valid, 'Invalid feature ignored entirely '.$i);
        $check(isset($resources->resolve($characters['a'])[$direct->getSlug()]) === $valid, 'Direct resource matrix '.$i);
        $check(isset($resources->resolve($characters['b'])[$direct->getSlug()]) === ($i === 0), 'Direct resource owner B '.$i);
    }
    foreach (['o', 'a'] as $ruleScope) {
        foreach (['a', 'b'] as $targetScope) {
            $valid = $ruleScope === 'a' && $targetScope === 'a';
            $slug = 'edge-'.$ruleScope.'-'.$targetScope;
            $f = $save(new CharacterFeatureDefinition($slug, $slug), $targetScope);
            $r = $resource($slug, $targetScope);
            $a = $save(new CharacterActionDefinition($slug, $slug, CharacterActionHandlerType::Aid), $targetScope);
            $save(CharacterFeatureRule::forClass($f, $class, 1), $ruleScope);
            $save(TrackableResourceRule::forClass($r, $class, 1), $ruleScope);
            $save(new CharacterActionClassRule($a, $class, 1), $ruleScope);
            $em->flush();
            foreach ([$features, $resources, $actions] as $resolver) $check(isset($resolver->resolve($characters['a'])[$slug]) === $valid, 'Assignment target matrix '.$slug);
        }
    }
    $bonus = $resource('bonus');
    $save(TrackableResourceRule::forClass($bonus, $class, 1)->setMaximumBonus(2));
    $save(TrackableResourceRule::forClass($bonus, $class, 2)->setMaximumBonus(3), 'a');
    $save(TrackableResourceRule::forClass($bonus, $class, 3)->setMaximumBonus(7), 'b');
    $override = $resource('override');
    $save(TrackableResourceRule::forClass($override, $class, 1, 11), 'a');
    $save(TrackableResourceRule::forClass($override, $class, 2, 13));
    $save(TrackableResourceRule::forClass($override, $class, 3, 17), 'b');
    $em->flush();
    $check($resources->resolve($characters['a'])['bonus']->getMaximum() === 10, 'Official + A bonuses additive');
    $check($resources->resolve($characters['b'])['bonus']->getMaximum() === 14, 'Official + B bonuses additive');
    $check($resources->resolve($characters['a'])['override']->getMaximum() === 13, 'Higher OFFICIAL level wins over lower CUSTOM');
    $check($resources->resolve($characters['b'])['override']->getMaximum() === 17, 'Higher CUSTOM level wins over lower OFFICIAL');

    $progression = $save(new ProgressionDefinition('progress-a', 'Progress A'), 'a');
    $progressResource = $resource('progress-resource-a', 'a', ResourceRechargeType::ShortRest);
    $progressFeature = $save((new CharacterFeatureDefinition('progress-feature-a', 'Progress A'))->setResourceDefinition($progressResource), 'a');
    $low = $save(CharacterFeatureRule::forProgression($progressFeature, $progression, 3), 'a');
    $high = $save(CharacterFeatureRule::forProgression($progressFeature, $progression, 5), 'a');
    foreach ($characters as $character) {
        // B's historical foreign acquisition is retained, but cannot activate A's rules.
        $assignment = new CharacterProgression($character, $progression);
        $character->addProgression($assignment); $em->persist($assignment);
    }
    $em->flush();
    foreach ([null, 0, 2, 3, 4, 5, 6] as $value) {
        $values = $value === null ? [] : ['progress-a' => $value];
        $found = $features->resolve($characters['a'], $values)['progress-feature-a'] ?? null;
        $check(($found !== null) === ($value !== null && $value >= 3), 'Explicit progression threshold '.json_encode($value));
        if ($found) $check($found === ($value >= 5 ? $high : $low), 'Highest threshold, no dummy unlock level');
        $check(isset($resources->resolve($characters['a'], $values)['progress-resource-a']) === ($found !== null), 'Same resource progression context');
        $check(!isset($features->resolve($characters['b'], $values)['progress-feature-a']), 'Foreign historical progression cannot activate');
    }
    $foreignHistory = $resource('historical-b', 'b', ResourceRechargeType::ShortRest);
    $ownLong = $resource('historical-a-long', 'a');
    $officialHistory = $resource('historical-official');
    $resource('never-unlocked-a', 'a');
    foreach (['a', 'b'] as $key) {
        foreach ([1 => 2, 2 => 5] as $number => $value) {
            $game = (new GameSession($campaigns[$key], 'game-'.$number, 'Game '.$number))->setStatus(GameSession::STATUS_LIVE);
            $em->persist($game);
            $state = $factory->create($characters[$key]);
            $check(!in_array('resource-'.($key === 'a' ? 'b' : 'a'), array_column($state['resources'], 'id'), true), 'Factory respects owner');
            $state['progressions'] = [['id' => 'progress-a', 'currentValue' => $value]];
            $state['resources'][] = ['id' => 'progress-resource-a', 'currentValue' => $number, 'storedValues' => $number === 1 ? [12] : [7, 18], 'notes' => 'historical'];
            $state['resources'][] = ['id' => 'historical-b', 'currentValue' => 2, 'storedValues' => [4, 17], 'notes' => 'preserve'];
            $state['resources'][] = ['id' => 'historical-a-long', 'currentValue' => 0];
            $state['resources'][] = ['id' => 'historical-official', 'currentValue' => 0];
            $state['resources'][] = ['id' => 'unknown', 'currentValue' => 9, 'notes' => 'unknown'];
            $state['hitPoints']['current'] -= 2;
            $state['hitPoints']['temporary'] = 3;
            $state['characterActions'] = ['prepared' => ['action-'.$key], 'preparationPending' => false];
            $css = new CharacterSessionState($game, $characters[$key], $state);
            $css->setLevelUpAllowed(true); $em->persist($css); $states[$key][$number] = $css;
        }
    }
    $em->flush();
    $historical = array_map(static fn ($r) => $r->getSlug(), $definitions->findVisibleBySlugs(['historical-b', 'historical-a-long', 'historical-official'], $users['a']));
    sort($historical);
    $check($historical === ['historical-a-long', 'historical-official'], 'Historical lookup scopes slugs in Doctrine');

    foreach (['a', 'b'] as $key) {
        foreach ($states[$key] as $number => $css) {
            $before = $css->getState(); $token = $css->getAccessToken(); $participating = $css->isParticipating();
            $synced = $sync->synchronize($characters[$key], $before);
            $check($entries($synced)['historical-b'] === $entries($before)['historical-b'], 'Sync preserves historical entries');
            $check($entries($synced)['progress-resource-a'] === $entries($before)['progress-resource-a'], 'Each state preserves its own consumption and stored values');
            $check($synced['progressions'] === $before['progressions'] && $synced['hitPoints'] === $before['hitPoints'], 'Sync preserves progressions and HP');
            $css->setState($synced);
            $serialized = $serializer->serialize($css, true);
            $check($serialized['accessToken'] === $token && $serialized['participating'] === $participating, 'Serializer preserves token and participation');
            $check(in_array('progress-feature-a', array_column($serialized['character']['features'], 'slug'), true) === ($key === 'a' && $number === 2), 'Per-state profile progression values');
            $foreign = $key === 'a' ? 'b' : 'a';
            foreach (['features' => 'feature', 'resources' => 'resource', 'actions' => 'action'] as $field => $prefix) {
                $check(!in_array($prefix.'-'.$foreign, array_column($serialized['character'][$field], 'slug'), true), 'Profile excludes foreign '.$field);
            }
        }
    }
    $css = $states['a'][1];
    $before = $css->getState();
    $rest->apply($css, RestRequest::TYPE_SHORT_REST);
    $after = $css->getState();
    $check($entries($after)['progress-resource-a']['currentValue'] === 5, 'Own inactive historical resource recharges on short rest');
    $check($entries($after)['progress-resource-a']['storedValues'] === [12], 'Ordinary stored values preserved');
    $check($entries($after)['historical-a-long']['currentValue'] === 0, 'Long-only resource unchanged on short rest');
    $check($entries($after)['historical-b'] === $entries($before)['historical-b'], 'Foreign historical key preserved byte-for-byte, no foreign recharge');
    $check($after['progressions'] === $before['progressions'] && $after['hitPoints'] === $before['hitPoints'], 'Short rest preserves progression and HP');
    $rest->apply($css, RestRequest::TYPE_LONG_REST);
    $after = $css->getState();
    $check($entries($after)['historical-a-long']['currentValue'] === 5 && $entries($after)['historical-official']['currentValue'] === 5, 'Own and official historical long rest');
    $check($entries($after)['historical-b'] === $entries($before)['historical-b'], 'Foreign historical entry unchanged on long rest');
    $check($entries($after)['unknown'] === $entries($before)['unknown'], 'Unknown historical entry unchanged');
    $check(!isset($entries($after)['never-unlocked-a']), 'No creation of inactive never-unlocked resource');
    $check($after['progressions'] === $before['progressions'], 'Long rest never resets progressions');
    $check($after['characterActions']['prepared'] === [] && $after['characterActions']['preparationPending'], 'Long rest preparation reset unchanged');
    $rest->apply($states['b'][1], RestRequest::TYPE_SHORT_REST);
    $check($entries($states['b'][1]->getState())['historical-b']['currentValue'] === 5, 'Same historical definition legitimately recharges for B');

    foreach (['a', 'b'] as $key) {
        $save(CharacterFeatureRule::forClass($save(new CharacterFeatureDefinition('level-feature-'.$key, 'Level '.$key)), $class, 4), $key);
        $save(TrackableResourceRule::forClass($resource('level-resource-'.$key), $class, 4), $key);
        $save(new CharacterActionClassRule($save(new CharacterActionDefinition('level-action-'.$key, 'Level '.$key, CharacterActionHandlerType::Aid)), $class, 4), $key);
    }
    $em->flush();
    $beforeStates = array_map(static fn ($s) => [$s->getState(), $s->getAccessToken(), $s->isParticipating()], $states['a']);
    $levelUp->levelUp($characters['a'], $class, hitPointGainMethod: HitPointGainMethod::Average);
    foreach ($states['a'] as $number => $css) {
        $resolved = $serializer->serialize($css)['character'];
        foreach (['features' => 'feature', 'resources' => 'resource', 'actions' => 'action'] as $field => $prefix) {
            $slugs = array_column($resolved[$field], 'slug');
            $check(in_array('level-'.$prefix.'-a', $slugs, true) && !in_array('level-'.$prefix.'-b', $slugs, true), 'Level-up owner-safe '.$field);
        }
        $check($css->getState()['progressions'] === $beforeStates[$number][0]['progressions'], 'Level-up preserves each progression value');
        $check($entries($css->getState())['historical-b'] === $entries($beforeStates[$number][0])['historical-b'], 'Level-up preserves foreign historical entry');
        $check($entries($css->getState())['progress-resource-a'] === $entries($beforeStates[$number][0])['progress-resource-a'], 'Level-up preserves per-state consumption and stored values');
        $check($css->getAccessToken() === $beforeStates[$number][1] && $css->isParticipating() === $beforeStates[$number][2], 'Level-up preserves token and participation');
    }
    // Historical acquisitions remain attached, even when their source is foreign.
    foreach (['a', 'b'] as $sourceScope) {
        $sourceClass = $save(new CharacterClass('source-class-'.$sourceScope, 'Source class', 8, 1), $sourceScope);
        $sourceSubclass = $save(new CharacterSubclass($sourceClass, 'source-subclass-'.$sourceScope, 'Source subclass'), $sourceScope);
        $sourceRace = $save(new CharacterRace('source-race-'.$sourceScope, 'Source race'), $sourceScope);
        $sourceFeat = $save(new Feat('source-feat-'.$sourceScope, 'Source feat'), $sourceScope);
        $sourceProgression = $save(new ProgressionDefinition('source-progress-'.$sourceScope, 'Source progress'), $sourceScope);
        $historicalCharacter = new Character($campaigns['a'], 'historical-'.$sourceScope, 'Historical', Character::TYPE_PLAYER);
        $historicalCharacter->setRace($sourceRace);
        $row = new CharacterClassLevel($historicalCharacter, $sourceClass, 1, $sourceSubclass, 5, HitPointGainMethod::Average);
        $historicalCharacter->addClassLevel($row); $em->persist($row);
        $feat = new CharacterFeat($historicalCharacter, $sourceFeat);
        $historicalCharacter->addFeat($feat); $em->persist($feat);
        $progress = new CharacterProgression($historicalCharacter, $sourceProgression);
        $historicalCharacter->addProgression($progress); $em->persist($progress);
        $em->persist($historicalCharacter);
        foreach (['Class' => $sourceClass, 'Subclass' => $sourceSubclass, 'Race' => $sourceRace, 'Feat' => $sourceFeat, 'Progression' => $sourceProgression] as $type => $source) {
            foreach (['o', 'a', 'b'] as $ruleScope) {
                $slug = strtolower('source-'.$sourceScope.'-'.$type.'-'.$ruleScope);
                $f = $save(new CharacterFeatureDefinition($slug, $slug));
                $save(CharacterFeatureRule::{'for'.$type}($f, $source, 1), $ruleScope);
                if ($type !== 'Progression') $save(TrackableResourceRule::{'for'.$type}($resource($slug), $source, 1), $ruleScope);
                if ($type === 'Class') $save(new CharacterActionClassRule($save(new CharacterActionDefinition($slug, $slug, CharacterActionHandlerType::Aid)), $sourceClass, 1), $ruleScope);
            }
        }
        $em->flush();
        foreach (['Class', 'Subclass', 'Race', 'Feat', 'Progression'] as $type) foreach (['o', 'a', 'b'] as $ruleScope) {
            $slug = strtolower('source-'.$sourceScope.'-'.$type.'-'.$ruleScope);
            $valid = $sourceScope === 'a' && $ruleScope === 'a';
            $check(isset($features->resolve($historicalCharacter, [$sourceProgression->getSlug() => 1])[$slug]) === $valid, 'Historical feature source matrix '.$slug);
            if ($type !== 'Progression') $check(isset($resources->resolve($historicalCharacter)[$slug]) === $valid, 'Historical resource source matrix '.$slug);
            if ($type === 'Class') $check(isset($actions->resolve($historicalCharacter)[$slug]) === $valid, 'Historical action source matrix '.$slug);
        }
        $check($historicalCharacter->getRace() === $sourceRace && $historicalCharacter->getClassLevels()->count() === 1 && $historicalCharacter->hasFeat($sourceFeat) && $historicalCharacter->hasProgression($sourceProgression), 'No purge or replacement of historical acquisitions');
    }

    // Official controlled actions: availability, preparation, effects and consumption.
    $wizard = $save(new CharacterClass('wizard', 'Wizard', 6, 20, SpellcastingProgressionType::Full));
    $caster = new Character($campaigns['a'], 'caster', 'Caster', Character::TYPE_PLAYER); $em->persist($caster);
    for ($level = 1; $level <= 13; ++$level) {
        $row = new CharacterClassLevel($caster, $wizard, $level, null, 4, HitPointGainMethod::Average);
        $caster->addClassLevel($row); $em->persist($row);
    }
    foreach ([CharacterActionHandlerType::Aid, CharacterActionHandlerType::HeroesFeast, CharacterActionHandlerType::ArcaneRecovery] as $handler) {
        $action = (new CharacterActionDefinition($handler->value, $handler->value, $handler))->setRequiresPreparation($handler !== CharacterActionHandlerType::ArcaneRecovery);
        $save($action); $save(new CharacterActionClassRule($action, $wizard, 1));
    }
    $recovery = $resource('arcane-recovery'); $recovery->setBaseMaximum(1);
    $save(TrackableResourceRule::forClass($recovery, $wizard, 1));
    $inactive = $save((new CharacterActionDefinition('inactive', 'Inactive', CharacterActionHandlerType::Aid))->setActive(false));
    $save(new CharacterActionClassRule($inactive, $wizard, 1));
    $locked = $save(new CharacterActionDefinition('too-high', 'Too high', CharacterActionHandlerType::Aid));
    $save(new CharacterActionClassRule($locked, $wizard, 20));
    $em->flush();
    $check($keys($actions->resolve($caster)) === ['aid', 'arcane-recovery', 'heroes-feast'], 'Controlled actions active and level conditions unchanged');
    $casterState = new CharacterSessionState($states['a'][1]->getGameSession(), $caster, $factory->create($caster));
    $em->persist($casterState); $em->flush();
    $casterToken = $casterState->getAccessToken(); $casterId = $caster->getId();
    $em->flush();
    // HTTP reads clear Doctrine's identity map; all fixture construction is finished.
    foreach (['a', 'b'] as $key) foreach ($states[$key] as $css) {
        [$status, $body] = $request('/public/characters/'.$css->getAccessToken());
        $check($status === 200, 'Anonymous token profile');
        $foreign = $key === 'a' ? 'b' : 'a';
        foreach (['features' => 'feature', 'resources' => 'resource', 'actions' => 'action'] as $field => $prefix) {
            $check(!in_array($prefix.'-'.$foreign, array_column($body['character'][$field], 'slug'), true), 'HTTP token excludes foreign '.$field);
        }
    }
    $session->set('_security_main', serialize(new UsernamePasswordToken($users['b'], 'main', ['ROLE_USER'])));
    [$status] = $request('/campaigns/'.$campaigns['b']->getId().'/dnd/reference');
    $check($status === 200, 'Authenticated visitor B');
    $managedA = $em->find(Character::class, $characters['a']->getId());
    $check(isset($features->resolve($managedA)['feature-a']) && !isset($features->resolve($managedA)['feature-b']), 'Visitor B does not replace Character owner A');
    [$status, $body] = $request('/public/characters/'.$states['a'][2]->getAccessToken());
    $check($status === 200 && in_array('feature-a', array_column($body['character']['features'], 'slug'), true) && !in_array('feature-b', array_column($body['character']['features'], 'slug'), true), 'Token A under visitor B still uses A');

    $session->remove('_security_main');
    [$status, $body] = $request('/public/characters/'.$states['a'][1]->getAccessToken());
    [$status] = $request('/public/characters/'.$states['a'][1]->getAccessToken().'/actions/prepared', 'PATCH', ['prepared' => ['action-b'], 'revision' => $body['revision']]);
    $check($status === 422, 'Foreign action cannot be prepared via token');
    [$status, $body] = $request('/public/characters/'.$casterToken);
    $actionPath = '/public/characters/'.$casterToken.'/actions/';
    [$status, $body] = $request($actionPath.'prepared', 'PATCH', ['prepared' => ['aid', 'heroes-feast'], 'revision' => $body['revision']]);
    $check($status === 200 && $body['state']['characterActions']['prepared'] === ['aid', 'heroes-feast'], 'Official actions preparation through scoped resolver');
    $before = $body['state'];
    [$status, $body] = $request($actionPath.'aid', 'POST', ['spellSlotLevel' => 2, 'targetIds' => [$casterId], 'revision' => $body['revision']]);
    $check($status === 200, 'Aid controller and handler');
    $check($entries($body['state'])['spell-slot-2']['currentValue'] === $entries($before)['spell-slot-2']['currentValue'] - 1, 'Aid consumes slot');
    $check($body['state']['hitPoints']['current'] === $before['hitPoints']['current'] + 5, 'Aid HP effect');
    $before = $body['state'];
    [$status, $body] = $request($actionPath.'heroes-feast', 'POST', ['hitPointBonus' => 10, 'targetIds' => [$casterId], 'revision' => $body['revision']]);
    $check($status === 200, 'Heroes Feast controller and handler');
    $check($entries($body['state'])['spell-slot-6']['currentValue'] === $entries($before)['spell-slot-6']['currentValue'] - 1, 'Heroes Feast consumes slot');
    $check($body['state']['hitPoints']['current'] === $before['hitPoints']['current'] + 10, 'Heroes Feast HP effect');
    $before = $body['state'];
    [$status, $body] = $request($actionPath.'arcane-recovery', 'POST', ['slots' => [2 => 1], 'revision' => $body['revision']]);
    $check($status === 200, 'Arcane Recovery controller and handler '.json_encode($body));
    $check($entries($body['state'])['spell-slot-2']['currentValue'] === $entries($before)['spell-slot-2']['currentValue'] + 1, 'Arcane Recovery restores slot');
    $check($entries($body['state'])['arcane-recovery']['currentValue'] === 0, 'Arcane Recovery consumes scoped resource');

    echo "OK: $checks runtime visibility assertions; temporary tables rolled back.\n";
} finally {
    while ($db->isTransactionActive()) $db->rollBack();
    $em->clear(); $kernel->shutdown();
}
