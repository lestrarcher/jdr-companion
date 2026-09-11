<?php

declare(strict_types=1);

// Run: docker compose exec backend php tools/test-character-lifecycle.php
// All ORM tables are shadowed by transaction-local PostgreSQL temporary tables.
// No campaign records or public identity sequences are changed.
use App\Dto\LevelAdvancementSelection as ASI;
use App\Entity\{Campaign, Character, CharacterClass, CharacterClassLevel, CharacterClassLevelRule, CharacterFeatureDefinition, CharacterFeatureRule, CharacterProgression, CharacterRace, CharacterSessionState, GameSession, ProgressionDefinition, TrackableResourceDefinition, TrackableResourceRule, User};
use App\Enum\{Ability, HitPointGainMethod, LevelAdvancementChoice, ResourceMaximumType, ResourceRechargeType, SpellcastingProgressionType};
use App\Repository\{CharacterClassLevelRuleRepository, CharacterFeatureRuleRepository, CharacterRepository, CharacterSessionStateRepository, TrackableResourceRuleRepository};
use App\Service\{CharacterAbilityCalculator, CharacterBuilderService, CharacterFeatureResolver, CharacterHitPointCalculator, CharacterLevelUpService, CharacterMulticlassEligibilityService, CharacterResourceResolver, CharacterSessionStateFactory, CharacterSessionStateSynchronizer, CharacterSpellSlotCalculator};
use App\Kernel;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\Dotenv\Dotenv;

require __DIR__ . '/../vendor/autoload.php';
(new Dotenv())->bootEnv(__DIR__ . '/../.env');
$kernel = new Kernel('dev', true);
$kernel->boot();
$registry = $kernel->getContainer()->get('doctrine');
$em = $registry->getManager();
$db = $em->getConnection();
$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) throw new RuntimeException($message);
    ++$checks;
};

// Each scenario gets fresh services, including after a rejected transaction closes the EM.
$services = static function () use ($registry): array {
    $em = $registry->resetManager();
    $ability = new CharacterAbilityCalculator();
    $hp = new CharacterHitPointCalculator($ability);
    $slots = new CharacterSpellSlotCalculator();
    $features = new CharacterFeatureResolver(new CharacterFeatureRuleRepository($registry));
    $resources = new CharacterResourceResolver(new TrackableResourceRuleRepository($registry), $ability, $features);
    $sync = new CharacterSessionStateSynchronizer($hp, $resources, new CharacterSessionStateRepository($registry), $slots);
    $eligibility = new CharacterMulticlassEligibilityService($ability);
    $levelUp = new CharacterLevelUpService($em, new CharacterClassLevelRuleRepository($registry), $sync, $eligibility, $ability);
    return compact('em', 'ability', 'hp', 'slots', 'features', 'resources', 'sync', 'eligibility', 'levelUp');
};
$fixture = static function (array $s, int $levels = 0): array {
    static $number = 0;
    ++$number;
    $em = $s['em'];
    $user = (new User())->setEmail("lifecycle-$number@example.invalid")->setPassword('unused');
    $campaign = new Campaign($user, "audit-$number", 'Lifecycle test');
    $character = new Character($campaign, 'test', 'Test', Character::TYPE_PLAYER);
    $wizard = $em->getRepository(CharacterClass::class)->findOneBy(['slug' => 'wizard']);
    foreach ([$user, $campaign, $character] as $entity) $em->persist($entity);
    for ($position = 1; $position <= $levels; ++$position) {
        $level = new CharacterClassLevel($character, $wizard, $position, null, $position === 1 ? 6 : 4, $position === 1 ? HitPointGainMethod::FirstLevel : HitPointGainMethod::Average);
        $character->addClassLevel($level);
        $em->persist($level);
    }
    $em->flush();
    return [$character, $wizard, $campaign];
};
$reject = static function (callable $operation, Character $character, string $label) use ($check, $db): void {
    $levels = $character->getTotalLevel();
    $adjustments = $character->getAbilityAdjustments()->count();
    $persisted = $db->fetchOne('SELECT COUNT(*) FROM character_ability_adjustment');
    try {
        $operation();
    } catch (DomainException) {
        $check($character->getTotalLevel() === $levels, "$label: no level mutation");
        $check($character->getAbilityAdjustments()->count() === $adjustments, "$label: no partial ASI in memory");
        $check($db->fetchOne('SELECT COUNT(*) FROM character_ability_adjustment') === $persisted, "$label: no persisted ASI");
        return;
    }
    throw new RuntimeException("$label: expected rejection");
};

try {
    $db->beginTransaction();
    $metadata = $em->getMetadataFactory()->getAllMetadata();
    foreach ((new SchemaTool($em))->getCreateSchemaSql($metadata) as $sql) {
        $sql = preg_replace('/^CREATE TABLE /', 'CREATE TEMP TABLE ', $sql);
        $sql = preg_replace('/^CREATE SEQUENCE /', 'CREATE TEMP SEQUENCE ', $sql);
        $db->executeStatement($sql);
    }
    foreach ($metadata as $class) {
        $table = $db->getDatabasePlatform()->quoteIdentifier($class->getTableName());
        $check($db->fetchOne('SELECT relpersistence FROM pg_class WHERE oid = to_regclass(?)', [$table]) === 't', "Temporary table: $table");
    }
    // Use real class slugs for eligibility, but defer subclass selection in these focused fixtures.
    $wizard = new CharacterClass('wizard', 'Wizard', 6, 20, SpellcastingProgressionType::Full);
    $fighter = new CharacterClass('fighter', 'Fighter', 10, 20);
    $warlock = new CharacterClass('warlock', 'Warlock', 8, 20, SpellcastingProgressionType::Pact);
    $race = new CharacterRace('test-race', 'Test race');
    foreach ([$wizard, $fighter, $warlock, $race, new CharacterClassLevelRule($wizard, 4, LevelAdvancementChoice::AbilityScoreImprovementOrFeat)] as $entity) $em->persist($entity);
    $em->flush();

    $s = $services();
    [$character, $wizard, $campaign] = $fixture($s);
    $check($s['eligibility']->canTakeLevel($character, $wizard), 'Initial wizard INT 10 eligible');
    $builder = new CharacterBuilderService($s['em'], new CharacterRepository($registry), $s['levelUp']);
    $created = $builder->create($campaign, 'built', 'Built', null, Character::TYPE_PLAYER,
        $s['em']->getRepository(CharacterRace::class)->findOneBy(['slug' => 'test-race']),
        array_fill_keys(array_map(static fn (Ability $a) => $a->value, Ability::cases()), 10), [], [], $wizard);
    $check($created->getTotalLevel() === 1, 'Builder creates initial wizard below multiclass prerequisite');
    $s['levelUp']->levelUp($created, $wizard);
    $check($created->getTotalLevel() === 2, 'Same-class level-up below prerequisite');

    foreach ([[10, 13, false], [13, 10, false], [13, 13, true]] as [$intelligence, $strength, $allowed]) {
        $s = $services();
        [$character, $wizard] = $fixture($s, 1);
        $character->getAbilityScore(Ability::Intelligence)->setBaseValue($intelligence);
        $character->getAbilityScore(Ability::Strength)->setBaseValue($strength);
        $target = $s['em']->getRepository(CharacterClass::class)->findOneBy(['slug' => 'fighter']);
        $s['em']->flush();
        $check($s['eligibility']->canTakeLevel($character, $target) === $allowed, "Source INT $intelligence / target STR $strength eligibility");
        if (!$allowed) {
            $reject(fn () => $s['levelUp']->levelUp($character, $target), $character, 'Multiclass prerequisites');
        } else {
            $s['levelUp']->levelUp($character, $target);
            $check($character->getLevelInClass($target) === 1, 'Both prerequisites met');
            $character->getAbilityScore(Ability::Intelligence)->setBaseValue(10);
            $third = $s['em']->getRepository(CharacterClass::class)->findOneBy(['slug' => 'warlock']);
            $character->getAbilityScore(Ability::Charisma)->setBaseValue(13);
            $check(!$s['eligibility']->canTakeLevel($character, $third), 'Third-class entry checks all existing classes');
        }
    }

    foreach ([[18, 10, false, true], [19, 19, true, true], [20, 10, true, false], [20, 10, false, false], [19, 20, true, false], [18, 17, true, true]] as [$str, $con, $split, $allowed]) {
        $s = $services();
        [$character, $wizard] = $fixture($s, 3);
        $character->getAbilityScore(Ability::Strength)->setBaseValue($str);
        $character->getAbilityScore(Ability::Constitution)->setBaseValue($con);
        $s['em']->flush();
        $choice = $split ? ASI::increaseTwoAbilities(Ability::Strength, Ability::Constitution) : ASI::increaseOneAbility(Ability::Strength);
        if (!$allowed) {
            $reject(fn () => $s['levelUp']->levelUp($character, $wizard, advancement: $choice), $character, "ASI $str/$con split=$split");
        } else {
            $s['levelUp']->levelUp($character, $wizard, advancement: $choice);
            $check($s['ability']->calculate($character, Ability::Strength)->effectiveValue === $str + ($split ? 1 : 2), 'Legal STR ASI');
            $check($s['ability']->calculate($character, Ability::Constitution)->effectiveValue === $con + ($split ? 1 : 0), 'Legal split CON ASI');
            $check($s['hp']->calculate($character)->maximumValue === 18 + 4 * (int) floor(($con + ($split ? 1 : 0) - 10) / 2), 'HP uses legal CON after ASI');
        }
    }
    $s = $services();
    [$character, $wizard] = $fixture($s, 3);
    $character->getAbilityScore(Ability::Strength)->setMaximumValue(22)->setBaseValue(20);
    $s['levelUp']->levelUp($character, $wizard, advancement: ASI::increaseOneAbility(Ability::Strength));
    $check($s['ability']->calculate($character, Ability::Strength)->effectiveValue === 22, 'Explicit raised ceiling permits ASI');

    // The ceiling applies to the permanent score, including race and earlier ASIs.
    foreach (['race', 'previous-asi'] as $source) {
        $s = $services();
        [$character, $wizard] = $fixture($s, 3);
        $character->getAbilityScore(Ability::Strength)->setBaseValue(18);
        if ($source === 'race') {
            $race = new CharacterRace('strength-race', 'Strength race');
            $bonus = new App\Entity\RaceAbilityModifier($race, 2, Ability::Strength);
            $race->addAbilityModifier($bonus);
            $character->setRace($race);
            $s['em']->persist($race);
        } else {
            $bonus = new App\Entity\CharacterAbilityAdjustment($character, Ability::Strength,
                App\Enum\AbilityAdjustmentOperation::Increase, 2,
                App\Enum\AbilityAdjustmentSource::AbilityScoreImprovement, 'Earlier ASI', 1);
            $character->addAbilityAdjustment($bonus);
        }
        $s['em']->persist($bonus);
        $s['em']->flush();
        $reject(fn () => $s['levelUp']->levelUp($character, $wizard, advancement: ASI::increaseOneAbility(Ability::Strength)), $character, "$source included in ceiling validation");
    }
    $s = $services();
    [$character, $wizard, $campaign] = $fixture($s, 3);
    $character->getAbilityScore(Ability::Strength)->setBaseValue(18);
    $item = new App\Entity\MagicItem($campaign, 'Conditional strength', App\Enum\MagicItemRarity::Rare);
    $item->addAbilityEffect(new App\Entity\MagicItemAbilityEffect($item, Ability::Strength, App\Enum\AbilityEffectOperation::Minimum, 23));
    $owned = (new App\Entity\CharacterMagicItem($character, $item))->setEquipped(true);
    $character->addMagicItem($owned);
    $s['em']->persist($item); $s['em']->persist($owned); $s['em']->flush();
    $s['levelUp']->levelUp($character, $wizard, advancement: ASI::increaseOneAbility(Ability::Strength));
    $check($s['ability']->calculatePermanentValue($character, Ability::Strength) === 20, 'Conditional equipment does not block legal permanent ASI');
    $check($s['ability']->calculate($character, Ability::Strength)->effectiveValue === 23, 'Equipment effect remains active after ASI');

    // Simultaneous session contexts: active consumed, active missing, inactive missing,
    // inactive historical (including zero), and absent progression context.
    $s = $services();
    [$character, $wizard, $campaign] = $fixture($s, 3);
    $character->getAbilityScore(Ability::Constitution)->setBaseValue(16);
    $progression = new ProgressionDefinition('lifecycle-progress', 'Lifecycle progress');
    $assignment = new CharacterProgression($character, $progression);
    $character->addProgression($assignment);
    $resource = new TrackableResourceDefinition('progress-con', 'Progress CON', ResourceRechargeType::ShortRest, ResourceMaximumType::AbilityModifier);
    $resource->setScalingAbility(Ability::Constitution);
    $feature = (new CharacterFeatureDefinition('progress-feature', 'Progress feature'))->setResourceDefinition($resource);
    $rule = CharacterFeatureRule::forProgression($feature, $progression, 3);
    $pb = new TrackableResourceDefinition('progress-pb', 'Progress PB', ResourceRechargeType::LongRest, ResourceMaximumType::ProficiencyBonus);
    $pbFeature = (new CharacterFeatureDefinition('progress-pb-feature', 'Progress PB feature'))->setResourceDefinition($pb);
    foreach ([$progression, $assignment, $resource, $feature, $rule, $pb, $pbFeature, CharacterFeatureRule::forProgression($pbFeature, $progression, 3)] as $entity) $s['em']->persist($entity);
    $s['em']->flush();
    $sessions = [];
    foreach (['active' => [3, 1], 'missing' => [5, null], 'below' => [2, null], 'historical' => [2, 1], 'zero' => [2, 0], 'no-context' => [null, null]] as $name => [$value, $current]) {
        $game = new GameSession($campaign, $name, $name);
        $state = ['hitPoints' => ['current' => 10, 'temporary' => 2], 'hitDice' => [['id' => 'd6', 'current' => 1]], 'resources' => [['id' => 'spell-slot-2', 'currentValue' => 1]]];
        if ($value !== null) $state['progressions'] = [['id' => $progression->getSlug(), 'currentValue' => $value]];
        if ($current !== null) {
            $state['resources'][] = ['id' => 'progress-con', 'currentValue' => $current];
            $state['resources'][] = ['id' => 'progress-pb', 'currentValue' => $current];
        }
        $session = new CharacterSessionState($game, $character, $state);
        $s['em']->persist($game); $s['em']->persist($session);
        $sessions[$name] = [$session, $state];
    }
    $s['em']->flush();
    $check(!isset($s['sync']->snapshot($character)['resources']['progress-con']), 'Context-free snapshot cannot activate progression');
    $s['levelUp']->levelUp($character, $wizard, advancement: ASI::increaseOneAbility(Ability::Constitution));
    foreach ($sessions as $name => [$session, $before]) {
        $state = $session->getState();
        $pools = array_column($state['resources'], 'currentValue', 'id');
        $expected = ['active' => 2, 'missing' => 4, 'below' => null, 'historical' => 1, 'zero' => 0, 'no-context' => null][$name];
        $check(($pools['progress-con'] ?? null) === $expected, "$name: CON resource initialization/delta/history");
        $check(($state['progressions'] ?? null) === ($before['progressions'] ?? null), "$name: progression unchanged, including absent context");
        $check($pools['spell-slot-2'] === 2, "$name: standard spell slot gains only delta");
        $check($state['hitDice'][0]['current'] === 2, "$name: hit dice gain only delta");
        $check($state['hitPoints']['current'] === 21 && $state['hitPoints']['temporary'] === 2, "$name: HP delta and temporary HP preserved");
    }
    $s['levelUp']->levelUp($character, $wizard); // Total level 5 raises PB from 2 to 3.
    foreach ($sessions as $name => [$session]) {
        $pools = array_column($session->getState()['resources'], 'currentValue', 'id');
        $expected = ['active' => 2, 'missing' => 3, 'below' => null, 'historical' => 1, 'zero' => 0, 'no-context' => null][$name];
        $check(($pools['progress-pb'] ?? null) === $expected, "$name: PB maximum delta is session-specific");
    }
    $s['em']->clear();
    $stored = $s['em']->find(CharacterSessionState::class, $sessions['active'][0]->getId())->getState();
    $check(array_column($stored['resources'], 'currentValue', 'id')['progress-con'] === 2, 'Consumed active resource persisted after both level-ups');

    // Pact Magic still follows normal resource rules and remains separate from standard slots.
    $s = $services();
    [$character, $wizard, $campaign] = $fixture($s, 1);
    $character->getAbilityScore(Ability::Intelligence)->setBaseValue(13);
    $character->getAbilityScore(Ability::Charisma)->setBaseValue(13);
    $warlock = $s['em']->getRepository(CharacterClass::class)->findOneBy(['slug' => 'warlock']);
    $pact = new TrackableResourceDefinition('test-pact', 'Pact', ResourceRechargeType::ShortRest);
    foreach ([$pact, TrackableResourceRule::forClass($pact, $warlock, 1, 1), TrackableResourceRule::forClass($pact, $warlock, 2, 2)] as $entity) $s['em']->persist($entity);
    $s['em']->flush();
    $s['levelUp']->levelUp($character, $warlock);
    $factory = new CharacterSessionStateFactory($s['hp'], $s['resources'], $s['slots']);
    $state = $factory->create($character);
    foreach ($state['resources'] as &$pool) $pool['currentValue'] = 0;
    unset($pool);
    $game = new GameSession($campaign, 'pact', 'Pact');
    $session = new CharacterSessionState($game, $character, $state);
    $s['em']->persist($game); $s['em']->persist($session); $s['em']->flush();
    $s['levelUp']->levelUp($character, $warlock);
    $pools = array_column($session->getState()['resources'], 'currentValue', 'id');
    $check($pools['test-pact'] === 1, 'Consumed Pact Magic gains only maximum delta');
    $check($pools['spell-slot-1'] === 0, 'Pact level-up does not refill standard slots');

    // HP uses each level's recorded die contribution, retaining incomplete histories.
    $makeCharacter = static function (int $constitution, array $levels): Character {
        $character = new Character(new Campaign(new User(), 'transient', 'Transient'), 'test', 'Test', Character::TYPE_PLAYER);
        $character->getAbilityScore(Ability::Constitution)->setBaseValue($constitution);
        foreach ($levels as $index => [$die, $gain]) {
            $class = new CharacterClass('die-' . $die, 'Die ' . $die, $die, 20);
            $level = new CharacterClassLevel($character, $class, $index + 1, null, $gain,
                $gain === null ? null : ($index === 0 ? HitPointGainMethod::FirstLevel : HitPointGainMethod::Rolled));
            $character->addClassLevel($level);
        }
        return $character;
    };
    foreach ([
        [6, [[6, 6], [6, 1]], 5],
        [6, [[6, 6], [6, 1], [6, 2], [6, 4]], 8],
        [6, [[10, 10], [6, 1], [8, 5]], 12],
        [14, [[6, 6], [6, 4], [6, 4]], 20],
        [14, [[10, 10], [6, 4], [8, 5]], 25],
        [1, [[6, 6], [6, 1], [6, 2]], 3],
        [10, [[6, 6], [6, null]], null],
    ] as [$constitution, $levels, $expected]) {
        $result = $s['hp']->calculate($makeCharacter($constitution, $levels));
        $check($result->maximumValue === $expected, 'HP per-level minimum: ' . json_encode([$constitution, $levels]));
        $check($result->baseValue === array_sum(array_column($levels, 1)), 'Raw HP contributions remain available');
        $check($result->isComplete() === ($expected !== null), 'Incomplete HP history remains incomplete');
    }

    // Exercise public rest service behavior, not just its private pool helper.
    $s = $services();
    $rest = new App\Service\CharacterRestService($s['hp'], $s['resources'], $s['sync'], $s['slots'],
        new App\Repository\TrackableResourceDefinitionRepository($registry), $s['ability']);
    foreach ([
        ['single-level', [6], ['d6' => 0], ['d6' => 1]],
        ['single-class', [6, 6, 6, 6, 6], ['d6' => 0], ['d6' => 2]],
        ['three-exhausted', [6, 8, 10], ['d6' => 0, 'd8' => 0, 'd10' => 0], ['d6' => 0, 'd8' => 0, 'd10' => 1]],
        ['reversed-order', [6, 8, 10], ['d10' => 0, 'd8' => 0, 'd6' => 0], ['d10' => 1, 'd8' => 0, 'd6' => 0]],
        ['spill-to-next', [6, 6, 8, 10, 10, 10], ['d6' => 0, 'd8' => 0, 'd10' => 2], ['d6' => 1, 'd8' => 1, 'd10' => 3]],
        ['full-largest', [6, 6, 8, 10], ['d6' => 0, 'd8' => 0, 'd10' => 1], ['d6' => 1, 'd8' => 1, 'd10' => 1]],
        ['all-full', [6, 8, 10], ['d6' => 1, 'd8' => 1, 'd10' => 1], ['d6' => 1, 'd8' => 1, 'd10' => 1]],
        ['less-spent-than-budget', [6, 6, 8, 10, 10, 10], ['d6' => 2, 'd8' => 1, 'd10' => 2], ['d6' => 2, 'd8' => 1, 'd10' => 3]],
    ] as [$label, $dice, $current, $expected]) {
        $character = $makeCharacter(6, array_map(static fn ($die) => [$die, $die], $dice));
        $hitDice = [];
        foreach ($current as $id => $value) $hitDice[] = ['id' => $id, 'current' => $value, 'note' => 'retained'];
        $state = ['hitPoints' => ['current' => 1, 'temporary' => 2], 'hitDice' => $hitDice,
            'resources' => [], 'progressions' => [['id' => 'narrative', 'currentValue' => 7]]];
        $session = new CharacterSessionState(new GameSession($character->getCampaign(), 'rest', 'Rest'), $character, $state);
        $rest->apply($session, App\Entity\RestRequest::TYPE_SHORT_REST);
        $check($session->getState() === $state, "$label: short rest leaves HP, hit dice and narrative state untouched");
        $rest->apply($session, App\Entity\RestRequest::TYPE_LONG_REST);
        $after = $session->getState();
        $check(array_column($after['hitDice'], 'current', 'id') === $expected, "$label: deterministic character-wide recovery");
        $check(array_sum($expected) - array_sum($current) <= max(1, intdiv(count($dice), 2)), "$label: allowance not exceeded");
        $check(array_column($after['hitDice'], 'id') === array_keys($current) && array_column($after['hitDice'], 'note') === array_fill(0, count($current), 'retained'), "$label: pool order and metadata retained");
        $check($after['progressions'] === $state['progressions'], "$label: rest preserves progressions");
        $check($after['hitPoints'] === ['current' => $s['hp']->calculate($character)->maximumValue, 'temporary' => 0], "$label: long rest uses corrected HP maximum");
        unset($state['progressions']);
        $session->setState($state);
        $rest->apply($session, App\Entity\RestRequest::TYPE_LONG_REST);
        $check(!array_key_exists('progressions', $session->getState()), "$label: rest never initializes progressions");
    }

    // Real assignment controller + multiple persisted sessions, including old history.
    foreach ([2, 3, 5] as $minimum) {
        $s = $services();
        [$character, $wizard, $campaign] = $fixture($s, 1);
        $progression = (new ProgressionDefinition("assigned-$minimum", 'Assigned'))->setMinimumValue($minimum);
        $resource = new TrackableResourceDefinition("assigned-resource-$minimum", 'Assigned resource', ResourceRechargeType::ShortRest, ResourceMaximumType::Fixed, 4);
        $feature = (new CharacterFeatureDefinition("assigned-feature-$minimum", 'Assigned feature'))->setResourceDefinition($resource);
        $unrelated = new TrackableResourceDefinition("unrelated-$minimum", 'Unrelated', ResourceRechargeType::LongRest, ResourceMaximumType::Fixed, 2);
        $other = new ProgressionDefinition("other-$minimum", 'Other');
        $missingOther = new ProgressionDefinition("missing-other-$minimum", 'Other absent gauge');
        foreach ([$other, $missingOther] as $definition) {
            $assignment = new CharacterProgression($character, $definition);
            $character->addProgression($assignment);
            $s['em']->persist($definition); $s['em']->persist($assignment);
        }
        foreach ([$progression, $resource, $feature, CharacterFeatureRule::forProgression($feature, $progression, 3),
            $unrelated, TrackableResourceRule::forClass($unrelated, $wizard, 1)] as $entity) $s['em']->persist($entity);
        $sessions = [];
        foreach (['default' => [null, null], 'below' => [2, null], 'at' => [3, null], 'above' => [5, null],
            'active-history' => [5, 1], 'inactive-history' => [2, 1], 'zero-history' => [5, 0]] as $name => [$value, $historical]) {
            $state = ['hitPoints' => ['current' => 1, 'temporary' => 3], 'hitDice' => [['id' => 'd6', 'current' => 0]],
                'progressions' => [['id' => $other->getSlug(), 'currentValue' => 7]],
                'resources' => [['id' => $unrelated->getSlug(), 'currentValue' => 9, 'notes' => 'do not clamp unrelated state']]];
            if ($value !== null) $state['progressions'][] = ['id' => $progression->getSlug(), 'currentValue' => $value];
            if ($historical !== null) $state['resources'][] = ['id' => $resource->getSlug(), 'currentValue' => $historical, 'notes' => 'history'];
            $game = new GameSession($campaign, $name, $name);
            $session = new CharacterSessionState($game, $character, $state);
            if ($name === 'inactive-history') $session->setParticipating(false);
            $s['em']->persist($game); $s['em']->persist($session);
            $sessions[] = [$session, $state, $value, $historical];
        }
        $s['em']->flush();
        $tokens = new Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage();
        $tokens->setToken(new Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken($campaign->getOwner(), 'main', ['ROLE_USER']));
        $container = new Symfony\Component\DependencyInjection\Container();
        $container->set('security.authorization_checker', new Symfony\Component\Security\Core\Authorization\AuthorizationChecker($tokens,
            new Symfony\Component\Security\Core\Authorization\AccessDecisionManager([new App\Security\Voter\CampaignVoter()])));
        $controller = new App\Controller\CharacterProgressionController();
        $controller->setContainer($container);
        $response = $controller->add($campaign, $character, $progression, $s['em'], $s['sync']);
        $check($response->getStatusCode() === 201, "Assignment minimum $minimum succeeds");
        foreach ($sessions as [$session, $expected, $value, $historical]) {
            if ($value === null) $expected['progressions'][] = ['id' => $progression->getSlug(), 'currentValue' => $minimum];
            if ($historical === null && ($value ?? $minimum) >= 3) $expected['resources'][] = ['id' => $resource->getSlug(), 'currentValue' => 4];
            $check($session->getState() === $expected, "Assignment minimum $minimum: only its missing active resource/gauge changes");
            $s['em']->refresh($session);
            $check($session->getState() === $expected, 'Assignment state persisted for each session');
        }
        $before = array_map(static fn ($entry) => $entry[0]->getState(), $sessions);
        $s['sync']->synchronizeProgressionAssignment($character, $progression);
        $check(array_map(static fn ($entry) => $entry[0]->getState(), $sessions) === $before, 'Assignment synchronization is idempotent');
    }

    echo "OK: $checks character lifecycle assertions; temporary tables rolled back.\n";
} finally {
    while ($db->isTransactionActive()) $db->rollBack();
    $kernel->shutdown();
}
