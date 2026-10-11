<?php

declare(strict_types=1);

use App\Entity\{User, Campaign, Character, CharacterClass, CharacterSubclass, CharacterRace, Feat, ProgressionDefinition, RaceAbilityModifier, CharacterClassLevel, CharacterClassLevelRule, GameSession, CharacterSessionState};
use App\Enum\{Ability, HitPointGainMethod, LevelAdvancementChoice};
use App\Service\{ReferenceVisibility, CharacterLevelUpOptionsService, CharacterAbilityCalculator, CharacterMulticlassEligibilityService};
use Symfony\Component\HttpFoundation\{Request, Session\Session, Session\Storage\MockArraySessionStorage};
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

require __DIR__.'/../vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv(__DIR__.'/../.env');
putenv('SHELL_VERBOSITY=-1');
$_SERVER['SHELL_VERBOSITY'] = $_ENV['SHELL_VERBOSITY'] = -1;
$kernel = new App\Kernel('dev', true); $kernel->boot();
$registry = $kernel->getContainer()->get('doctrine'); $em = $registry->getManager(); $db = $em->getConnection();
$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void { if (!$ok) throw new RuntimeException($message); ++$checks; };
$session = new Session(new MockArraySessionStorage()); $session->start();
$login = static function (?User $user) use ($session): void {
    if ($user === null) $session->remove('_security_main');
    else $session->set('_security_main', serialize(new UsernamePasswordToken($user, 'main', ['ROLE_USER'])));
};
$request = static function (string $path, string $method = 'GET', ?array $payload = null) use ($kernel, $session): array {
    $r = Request::create($path, $method, [], [$session->getName() => $session->getId()], [], ['CONTENT_TYPE' => 'application/json'], $payload === null ? null : json_encode($payload));
    $r->setSession($session); $response = $kernel->handle($r);
    return [$response->getStatusCode(), json_decode($response->getContent(), true)];
};
$fingerprint = static function () use ($db): array {
    $result = [];
    foreach (['character', 'character_class_level', 'character_feat', 'character_race_ability_choice', 'character_progression', 'character_session_state'] as $table) {
        $result[$table] = $db->fetchOne("SELECT md5(coalesce(string_agg(row_to_json(t)::text, '' ORDER BY id), '')) FROM $table t");
    }
    return $result;
};
try {
    $db->beginTransaction();
    $metadata = $em->getMetadataFactory()->getAllMetadata();
    foreach ((new Doctrine\ORM\Tools\SchemaTool($em))->getCreateSchemaSql($metadata) as $sql) {
        $db->executeStatement(preg_replace(['/^CREATE TABLE /', '/^CREATE SEQUENCE /'], ['CREATE TEMP TABLE ', 'CREATE TEMP SEQUENCE '], $sql));
    }
    foreach ($metadata as $meta) $check($db->fetchOne('SELECT relpersistence FROM pg_class WHERE oid = to_regclass(?)', [$meta->getTableName()]) === 't', 'Isolated table '.$meta->getTableName());
    $users = []; $campaigns = []; $definitions = []; $characters = []; $tokens = [];
    foreach (['a','b'] as $key) {
        $users[$key] = (new User())->setEmail($key.'@visibility.invalid')->setPassword('unused');
        $campaigns[$key] = new Campaign($users[$key], 'visibility-'.$key, 'Campaign '.$key);
        $em->persist($users[$key]); $em->persist($campaigns[$key]);
    }
    $em->flush();
    foreach (['o','a','b'] as $key) {
        $class = new CharacterClass('class-'.$key, 'Class '.$key, 8, $key === 'o' ? 1 : 20);
        $race = (new CharacterRace('race-'.$key, 'Race '.$key))->setCustom(true)->setFeatChoiceCount(1);
        $definitions[$key] = ['classes' => $class, 'races' => $race,
            'feats' => new Feat('feat-'.$key, 'Feat '.$key), 'progressions' => new ProgressionDefinition('progress-'.$key, 'Progress '.$key)];
        foreach ($definitions[$key] as $entity) $em->persist($entity);
        $em->persist(new CharacterClassLevelRule($class, 4, LevelAdvancementChoice::AbilityScoreImprovementOrFeat));
        $em->flush();
    }
    foreach (['o','a','b'] as $key) {
        // Private subclasses extend the same official class, as arch-hag does.
        $sub = new CharacterSubclass($definitions['o']['classes'], 'sub-'.$key, 'Subclass '.$key);
        $modifier = new RaceAbilityModifier($definitions[$key]['races'], 1, null, 'choice');
        $definitions[$key]['subclasses'] = $sub; $definitions[$key]['modifier'] = $modifier;
        $em->persist($sub); $em->persist($modifier);
    }
    $em->flush();
    foreach (['a','b'] as $key) foreach ($definitions[$key] as $type => $entity) {
        if ($type === 'modifier') continue;
        $db->update($em->getClassMetadata($entity::class)->getTableName(), ['origin' => 'CUSTOM', 'owner_id' => $users[$key]->getId()], ['id' => $entity->getId()]);
    }
    $ids = []; foreach ($definitions as $key => $items) foreach ($items as $type => $entity) $ids[$key][$type] = $entity->getId();
    $em->clear();
    foreach (['a','b'] as $key) {
        $users[$key] = $em->find(User::class, $users[$key]->getId());
        $campaigns[$key] = $em->find(Campaign::class, $campaigns[$key]->getId());
        $character = new Character($campaigns[$key], 'character-'.$key, 'Character '.$key, Character::TYPE_PLAYER);
        foreach (Ability::cases() as $ability) $character->getAbilityScore($ability)->setBaseValue(14);
        for ($level = 1; $level <= 3; ++$level) {
            $row = new CharacterClassLevel($character, $em->find(CharacterClass::class, $ids['o']['classes']), $level, $em->find(CharacterSubclass::class, $ids['o']['subclasses']), 5, HitPointGainMethod::Average);
            $character->addClassLevel($row); $em->persist($row);
        }
        $game = (new GameSession($campaigns[$key], 'session-'.$key, 'Session'))->setStatus(GameSession::STATUS_LIVE);
        $state = new CharacterSessionState($game, $character, ['hitPoints' => ['current' => 15, 'temporary' => 0], 'resources' => [], 'progressions' => [], 'hitDice' => []]);
        $state->setLevelUpAllowed(true);
        foreach ([$character,$game,$state] as $entity) $em->persist($entity);
        $em->flush(); $characters[$key] = $character; $tokens[$key] = $state->getAccessToken();
    }
    [$status,$public] = $request('/dnd/reference');
    $check($status === 200, 'Anonymous reference');
    foreach (['classes','subclasses','races','feats'] as $type) $check(array_column($public[$type], 'id') === [$ids['o'][$type]], 'Public only O '.$type);
    foreach (['a' => 'b', 'b' => 'a'] as $key => $foreign) {
        $login($users[$key]); $campaignId = $campaigns[$key]->getId(); $characterId = $characters[$key]->getId();
        [$status,$catalogue] = $request("/campaigns/$campaignId/dnd/reference?ownerId=".$users[$foreign]->getId());
        $check($status === 200, 'Campaign catalogue '.$key);
        foreach (['classes','subclasses','races','feats'] as $type) {
            $visible = array_column($catalogue[$type], 'id'); sort($visible); $expected = [$ids['o'][$type],$ids[$key][$type]]; sort($expected);
            $check($visible === $expected, 'Campaign O+owner '.$key.' '.$type);
        }
        $check($request('/campaigns/'.$campaigns[$foreign]->getId().'/dnd/reference')[0] === 403, 'Campaign authorization '.$key);
        [$status,$progressions] = $request("/campaigns/$campaignId/dnd/progressions");
        $visible = array_column($progressions['progressions'], 'id'); sort($visible); $expected = [$ids['o']['progressions'],$ids[$key]['progressions']]; sort($expected);
        $check($status === 200 && $visible === $expected, 'Progression catalogue '.$key);
        $base = ['slug' => 'build-'.$key, 'name' => 'Build', 'type' => 'player', 'raceId' => $ids['o']['races'], 'classId' => $ids['o']['classes'], 'subclassId' => $ids['o']['subclasses'],
            'abilities' => array_fill_keys(array_map(fn ($a) => $a->value, Ability::cases()), 14),
            'racialAbilityChoices' => [['modifierId' => $ids['o']['modifier'], 'ability' => 'strength']], 'racialFeatChoices' => [['featId' => $ids['o']['feats']]]];
        $forged = [
            ['raceId' => $ids[$foreign]['races']], ['classId' => $ids[$foreign]['classes']], ['subclassId' => $ids[$foreign]['subclasses']],
            ['racialAbilityChoices' => [['modifierId' => $ids[$foreign]['modifier'], 'ability' => 'strength']]], ['racialFeatChoices' => [['featId' => $ids[$foreign]['feats']]]],
        ];
        foreach ($forged as $index => $changes) {
            $before = $fingerprint(); [$status,$body] = $request("/campaigns/$campaignId/characters/build", 'POST', array_replace($base, $changes));
            $check($status === 422, 'Forged builder choice '.$key.' '.$index);
            $check(($body['message'] ?? '') === 'La référence sélectionnée est indisponible.', 'Rejected specifically by visibility');
            $check($before === $fingerprint(), 'Rejected build has no mutations');
            $check(!str_contains(json_encode($body), 'owner'), 'No owner disclosure');
        }
        foreach (['o', $key] as $choice) {
            $payload = array_replace($base, ['slug' => 'valid-'.$key.'-'.$choice, 'raceId' => $ids[$choice]['races'], 'subclassId' => $ids[$choice]['subclasses'],
                'racialAbilityChoices' => [['modifierId' => $ids[$choice]['modifier'], 'ability' => 'strength']], 'racialFeatChoices' => [['featId' => $ids[$choice]['feats']]]]);
            [$status,$body] = $request("/campaigns/$campaignId/characters/build", 'POST', $payload);
            $check($status === 201, 'Valid builder '.$key.' '.$choice.' '.json_encode($body));
        }
        [$status,$body] = $request("/campaigns/$campaignId/characters/build", 'POST', array_replace($base, ['slug'=>'custom-class-'.$key, 'classId'=>$ids[$key]['classes'], 'subclassId'=>null]));
        $check($status === 201, 'Valid CUSTOM starting class '.$key.' '.json_encode($body));
        $levelPath = "/campaigns/$campaignId/characters/$characterId/level-up";
        [$status,$options] = $request($levelPath.'/options');
        $check($status === 200 && !in_array($ids[$foreign]['classes'], array_column($options['classes'],'id'),true), 'Level options owner '.$key);
        $check(in_array($ids[$key]['feats'],array_column($options['feats'],'id'),true) && !in_array($ids[$foreign]['feats'],array_column($options['feats'],'id'),true), 'Feat choices owner '.$key);
        foreach ([$levelPath, '/public/characters/'.$tokens[$key].'/level-up'] as $path) {
            foreach ([['classId' => $ids[$foreign]['classes']], ['classId' => $ids['o']['classes'], 'subclassId' => $ids[$foreign]['subclasses']],
                ['classId' => $ids['o']['classes'], 'advancement' => ['type'=>'feat','featId'=>$ids[$foreign]['feats']]]] as $payload) {
                $before = $fingerprint(); $check($request($path,'POST',$payload)[0] === 422, 'Forged level choice '.$path);
                $check($before === $fingerprint(), 'Rejected level has no mutations');
            }
        }
        $progressPath = "/campaigns/$campaignId/characters/$characterId/progressions/";
        $before = $fingerprint(); $check($request($progressPath.$ids[$foreign]['progressions'],'POST',[])[0] === 404, 'Foreign progression rejected');
        $check($before === $fingerprint(), 'Rejected progression has no mutations');
        foreach (['o',$key] as $choice) $check($request($progressPath.$ids[$choice]['progressions'],'POST',[])[0] === 201, 'Allowed progression '.$key.' '.$choice);
        $login(null);
        [$status,$tokenOptions] = $request('/public/characters/'.$tokens[$key].'/level-up/options');
        $check($status === 200 && $tokenOptions === $options, 'Token uses same Character choices '.$key);
        foreach (['progressions','features','resources','races'] as $unused) $check(!array_key_exists($unused,$tokenOptions), 'Token no '.$unused.' catalogue');
        $check($request("/campaigns/$campaignId/dnd/reference")[0] === 401, 'Campaign requires login');
        if ($key === 'a') $login($users[$key]);
        $successPath = $key === 'a' ? $levelPath : '/public/characters/'.$tokens[$key].'/level-up';
        [$status,$body] = $request($successPath,'POST',['classId'=>$ids['o']['classes'],'advancement'=>['type'=>'feat','featId'=>$ids[$key]['feats']]]);
        $check($status === 201, 'Authorized own feat level-up '.$key.' '.json_encode($body));
    }
    $optionsService = new CharacterLevelUpOptionsService($em, new App\Repository\CharacterClassLevelRuleRepository($registry), new CharacterMulticlassEligibilityService(new CharacterAbilityCalculator()));
    $login($users['b']);
    $check($request('/campaigns/'.$campaigns['b']->getId().'/dnd/reference')[0] === 200, 'Visitor B authenticated before direct Character A service call');
    $choicesA = $optionsService->getOptions($characters['a']);
    $check(in_array($ids['a']['classes'],array_column($choicesA['classes'],'id'),true) && !in_array($ids['b']['classes'],array_column($choicesA['classes'],'id'),true), 'Visitor B does not replace Character owner A');
    $beginner = new Character($campaigns['a'],'beginner','Beginner',Character::TYPE_PLAYER);
    $beginnerChoices = $optionsService->getOptions($beginner);
    $check($beginnerChoices['feats'] === [] && $beginnerChoices['abilities'] === [], 'No feat catalogue when no ASI choice');
    $officialOption = array_values(array_filter($beginnerChoices['classes'],fn($c)=>$c['id']===$ids['o']['classes']))[0];
    $check(in_array($ids['a']['subclasses'],array_column($officialOption['subclasses'],'id'),true) && !in_array($ids['b']['subclasses'],array_column($officialOption['subclasses'],'id'),true), 'Necessary subclass choices owner A');
    $em->find(CharacterClass::class,$ids['a']['classes'])->setSlug('paladin');
    $characters['a']->getAbilityScore(Ability::Charisma)->setBaseValue(8);
    $check(!in_array($ids['a']['classes'],array_column($optionsService->getOptions($characters['a'])['classes'],'id'),true), 'Unusable personal class is not disclosed by level-up options');
    // Invalid dependency chains are excluded without modifying or deleting acquisitions.
    $db->executeStatement('UPDATE character_subclass SET character_class_id = ? WHERE id = ?',[$ids['b']['classes'],$ids['a']['subclasses']]);
    $db->executeStatement('UPDATE character_race SET parent_race_id = ? WHERE id = ?',[$ids['a']['races'],$ids['o']['races']]);
    $em->clear();
    $check(!ReferenceVisibility::allows($em->find(CharacterSubclass::class,$ids['a']['subclasses']),$users['a']), 'CUSTOM A cannot depend on CUSTOM B');
    $check(!ReferenceVisibility::allows($em->find(CharacterRace::class,$ids['o']['races']),$users['a']), 'OFFICIAL cannot inherit CUSTOM A');
    $db->executeStatement("UPDATE character_subclass SET origin = 'CUSTOM', owner_id = ? WHERE id = ?", [$users['b']->getId(),$ids['o']['subclasses']]);
    $em->clear();
    $login($users['a']);
    $before = $fingerprint();
    $check($request('/campaigns/'.$campaigns['a']->getId().'/characters/'.$characters['a']->getId().'/level-up/options')[0] === 422, 'Historical illegal subclass is reported without purge');
    $check($before === $fingerprint(), 'Historical acquisition preserved');
    echo "OK: $checks catalogue/build/level-up/token/progression assertions; temporary tables rolled back.\n";
} finally {
    while ($db->isTransactionActive()) $db->rollBack();
    $kernel->shutdown();
}
