<?php

declare(strict_types=1);

// Local integration cases run against TEMP copies, including independent sequences.
// Original character data and sequences are never written, even on failure.
use App\Entity\{Character, CharacterSessionState, GameSession};
use App\Entity\RestRequest;
use App\Repository\{CharacterActiveEffectRepository, CharacterFeatureRuleRepository, CharacterSessionStateRepository, TrackableResourceDefinitionRepository, TrackableResourceRuleRepository};
use App\Service\{CharacterAbilityCalculator, CharacterActiveEffectService, CharacterFeatureResolver, CharacterHitPointCalculator, CharacterHitPointStateService, CharacterResourceResolver, CharacterRestService, CharacterSessionStateFactory, CharacterSessionStateSynchronizer, CharacterSpellSlotCalculator, CharacterSpellSlotStateService};
use Symfony\Component\HttpFoundation\Request;

require __DIR__.'/../vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv(__DIR__.'/../.env');
putenv('SHELL_VERBOSITY=-1');
$_ENV['SHELL_VERBOSITY'] = $_SERVER['SHELL_VERBOSITY'] = '-1';
$kernel = new App\Kernel('dev', true);
$kernel->boot();
$em = $kernel->getContainer()->get('doctrine')->getManager();
$db = $em->getConnection();
$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void {
    if (!$ok) throw new RuntimeException($message);
    ++$checks;
};
$tables = array_unique([...array_map(static fn($meta) => $meta->getTableName(), $em->getMetadataFactory()->getAllMetadata()), 'feature_spell_grant', 'character_feature_spell']);
$fingerprint = static function () use ($db, $tables): array {
    $result = [];
    foreach ($tables as $table) $result[$table] = $db->fetchOne('SELECT md5(COALESCE(string_agg(row_to_json(t)::text,\'\' ORDER BY row_to_json(t)::text),\'\')) FROM public.'.$db->quoteIdentifier($table).' t');
    $result['sequences'] = $db->fetchAllAssociative("SELECT sequencename,last_value FROM pg_sequences WHERE schemaname='public' ORDER BY sequencename");
    return $result;
};
$before = $fingerprint();
$db->beginTransaction();
try {
    foreach ($tables as $table) {
        $name = $db->quoteIdentifier($table);
        $db->executeStatement('CREATE TEMP TABLE '.$name.' (LIKE public.'.$name.' INCLUDING DEFAULTS INCLUDING CONSTRAINTS INCLUDING INDEXES)');
        $db->executeStatement('INSERT INTO pg_temp.'.$name.' SELECT * FROM public.'.$name);
        $id = $db->fetchOne("SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name=? AND column_name='id' AND data_type IN ('integer','bigint')", [$table]);
        if ($id) {
            $start = (int)$db->fetchOne('SELECT COALESCE(max(id),0)+1 FROM pg_temp.'.$name);
            $sequence = 'test_player_spells_'.$table;
            $db->executeStatement('CREATE TEMP SEQUENCE '.$db->quoteIdentifier($sequence).' START '.$start);
            $db->executeStatement('ALTER TABLE pg_temp.'.$name.' ALTER COLUMN id SET DEFAULT nextval('.$db->quote('pg_temp.'.$sequence).')');
        }
        $check($db->fetchOne('SELECT relpersistence FROM pg_class WHERE oid=to_regclass(?)', [$table]) === 't', 'Temporary isolation: '.$table);
    }
    $sessions = [];
    foreach (['Test initié'=>40, 'Sylveon'=>36, 'Nikita'=>28] as $label => $id) {
        $character = $em->find(Character::class, $id);
        $check($character !== null && $character->getName() === $label, 'Expected local validation character: '.$label);
        $game = (new GameSession($character->getCampaign(), 'lot-3l-'.$id, 'Temporary 3L test'))->setStatus(GameSession::STATUS_LIVE);
        $session = new CharacterSessionState($game, $character, ['hitPoints'=>['current'=>1,'temporary'=>0], 'resources'=>[], 'progressions'=>[]]);
        $em->persist($game); $em->persist($session); $em->flush();
        $sessions[$label] = $session;
    }
    $request = static function (string $method, string $path, ?array $body = null) use ($kernel): array {
        $request = Request::create($path, $method, server:['CONTENT_TYPE'=>'application/json', 'HTTP_ACCEPT'=>'application/json'], content:$body === null ? null : json_encode($body, JSON_THROW_ON_ERROR));
        $request->setRequestFormat('json');
        $response = $kernel->handle($request);
        return [$response->getStatusCode(), json_decode($response->getContent(), true) ?? []];
    };
    $url = static fn(CharacterSessionState $session): string => '/public/characters/'.$session->getAccessToken().'/spells';
    $get = static function (CharacterSessionState $session) use ($request, $url, $check): array {
        [$code, $body] = $request('GET', $url($session));
        $check($code === 200, 'Public read succeeds: '.json_encode($body));
        return $body;
    };
    $post = static function (CharacterSessionState $session, string $operation, array $payload, int $expected = 200) use ($request, $url, $get, $check): array {
        $view = $get($session);
        $before = $view;
        [$code, $body] = $request('POST', $url($session).'/'.$operation, $payload + ['revision'=>$view['revision']]);
        $check($code === $expected, 'Expected '.$expected.' for '.$operation.', got '.$code.': '.json_encode($body));
        if ($expected !== 200) $check($get($session) === $before, 'Rejected command leaves the public state unchanged');
        return $body;
    };
    $initiated = $sessions['Test initié'];
    $view = $get($initiated);
    $pending = array_values(array_filter($view['choices'], static fn($r) => $r['kind'] === 'feature'));
    $check(count($pending) === 2, 'Magic Initiate: two independent remaining grants');
    $check(count(array_filter($view['spells'], static fn($s) => $s['name'] === 'Décharge occulte')) === 1, 'Eldritch Blast is already selected');
    $quotaBefore = $db->fetchOne('SELECT count(*) FROM character_spell_acquisition WHERE character_id=?', [40]);
    foreach ($pending as $choice) {
        $check($choice['available'] !== [], 'Grant has eligible choices');
        $grant = $db->fetchAssociative('SELECT * FROM feature_spell_grant WHERE id=?', [$choice['grantId']]);
        foreach ($choice['available'] as $spell) {
            $check($spell['level'] === (int)$grant['spell_level'], 'Grant level restriction');
            $check((bool)$db->fetchOne('SELECT 1 FROM spell_class_list_entry WHERE spell_id=? AND character_class_id=?', [$spell['id'],$grant['class_id']]), 'Warlock list restriction');
        }
        $invalid = (int)$db->fetchOne('SELECT id FROM spell_definition WHERE level=9 LIMIT 1');
        $post($initiated, 'choose', ['grantId'=>$choice['grantId'],'spellId'=>$invalid], 400);
        $payload = ['grantId'=>$choice['grantId'],'spellId'=>$choice['available'][0]['id']];
        $post($initiated, 'choose', $payload);
        $post($initiated, 'choose', $payload, 400);
    }
    $check($db->fetchOne('SELECT count(*) FROM character_spell_acquisition WHERE character_id=?', [40]) === $quotaBefore, 'Feature grants spend no class quota');
    $view = $get($initiated);
    $classChoice = array_values(array_filter($view['choices'], static fn($r) => $r['kind'] === 'class'))[0];
    $post($initiated, 'learn', ['policyId'=>$classChoice['policyId'], 'ruleId'=>$classChoice['ruleId'], 'spellId'=>$classChoice['available'][0]['id']]);
    $check((int)$db->fetchOne('SELECT count(*) FROM character_spell_acquisition WHERE character_id=?', [40]) === (int)$quotaBefore + 1, 'Wizard acquisitions remain independent');
    $sylveon = $sessions['Sylveon'];
    $view = $get($sylveon);
    // HTTP commands may detach entities; mutate the currently managed instance.
    $sylveon = $em->find(CharacterSessionState::class, $sylveon->getId());
    $check(!array_key_exists('spellPreparationAllowed', $sylveon->getState()), 'Legacy fixture has no preparation flag');
    $check($view['spellPreparationAllowed'] === true, 'GET allows first preparation for a legacy session');
    $registry = $kernel->getContainer()->get('doctrine');
    $ability = new CharacterAbilityCalculator();
    $hp = new CharacterHitPointCalculator($ability);
    $hpState = new CharacterHitPointStateService($hp);
    $slots = new CharacterSpellSlotCalculator();
    $effects = new CharacterActiveEffectRepository($registry);
    $resources = new CharacterResourceResolver(new TrackableResourceRuleRepository($registry), $ability,
        new CharacterFeatureResolver(new CharacterFeatureRuleRepository($registry)));
    $sync = new CharacterSessionStateSynchronizer($hp, $hpState, $resources,
        new CharacterSessionStateRepository($registry), new CharacterSpellSlotStateService($slots));
    $factory = new CharacterSessionStateFactory($hp, $resources, $slots, $effects);
    $rest = new CharacterRestService($hp, $hpState, $resources, $sync, $slots,
        new TrackableResourceDefinitionRepository($registry), $ability, $effects, new CharacterActiveEffectService($hpState), $em);
    $initial = $factory->create($sylveon->getCharacter());
    $check($initial['spellPreparationAllowed'] === true, 'New session state explicitly allows preparation');
    $sylveon->setState($initial);
    $em->flush();
    $check($get($sylveon)['spellPreparationAllowed'] === true, 'GET exposes initial permission');
    // Include multiple policies and an empty selection; rest must preserve the entire map verbatim.
    $selections = ['101'=>[301, 302], '102'=>[], '103'=>[303]];
    foreach ([null, false, true] as $allowed) {
        $sylveon = $em->find(CharacterSessionState::class, $sylveon->getId());
        $state = $initial;
        $state['preparedSpells'] = $selections;
        $state['characterActions'] = ['prepared'=>['independent-action'], 'preparationPending'=>false];
        if ($allowed === null) unset($state['spellPreparationAllowed']);
        else $state['spellPreparationAllowed'] = $allowed;
        $sylveon->setState($state);
        $rest->apply($sylveon, RestRequest::TYPE_SHORT_REST);
        $afterShort = $sylveon->getState();
        $check(array_key_exists('spellPreparationAllowed', $afterShort) === ($allowed !== null)
            && ($afterShort['spellPreparationAllowed'] ?? null) === $allowed, 'Short rest preserves missing/open/locked permission');
        $check($afterShort['preparedSpells'] === $selections, 'Short rest preserves every selection');
        $check($afterShort['characterActions'] === $state['characterActions'], 'Short rest preserves independent actions');
        $em->flush();
        $check($get($sylveon)['spellPreparationAllowed'] === ($allowed ?? true), 'GET exposes permission after short rest');
        $sylveon = $em->find(CharacterSessionState::class, $sylveon->getId());
        $rest->apply($sylveon, RestRequest::TYPE_LONG_REST);
        $afterLong = $sylveon->getState();
        $check($afterLong['spellPreparationAllowed'] === true, 'Long rest opens legacy/open/locked preparation');
        $check($afterLong['preparedSpells'] === $selections, 'Long rest preserves every selection without confirmation');
        $check($afterLong['characterActions'] === ['prepared'=>[], 'preparationPending'=>true], 'Existing action preparation cycle remains independent');
        $em->flush();
        $check($get($sylveon)['spellPreparationAllowed'] === true, 'GET exposes reopened preparation');
    }
    $sylveon = $em->find(CharacterSessionState::class, $sylveon->getId());
    $sylveon->setState($initial);
    $em->flush();
    $view = $get($sylveon);
    $check(count(array_filter($view['spells'], static fn($s) => $s['level'] === 0)) === 4, 'Sylveon has four cantrips');
    $racial = array_values(array_filter($view['spells'], static fn($s) => $s['sourceType'] === 'feature'))[0];
    $check($racial['castingAbility'] === 'intelligence' && is_int($racial['spellSaveDc']), 'Stored INT grant uses the intelligence calculator');
    $check(count(array_filter($view['spells'], static fn($s) => $s['inSpellbook'] && $s['level'] === 1)) === 6, 'Sylveon has six level-one book spells');
    $plan = $view['preparations'][0];
    $spellId = $plan['available'][0]['id'];
    $view = $post($sylveon, 'prepare', ['policyId'=>$plan['policyId'],'spellIds'=>[$spellId]]);
    $check(count(array_filter($view['spells'], static fn($s) => $s['prepared'] === true && $s['inSpellbook'] && !$s['alwaysPrepared'])) === 1, 'Prepared and book views overlap without an acquisition');
    $check($view['preparations'][0]['selected'] === [$spellId], 'Preparation returned immediately');
    $check($view['spellPreparationAllowed'] === true, 'Current prepare POST does not lock preparation');
    $sylveon = $em->find(CharacterSessionState::class, $sylveon->getId());
    $state = $sylveon->getState();
    $state['spellPreparationAllowed'] = false;
    $sylveon->setState($state);
    $em->flush();
    $post($sylveon, 'prepare', ['policyId'=>$plan['policyId'],'spellIds'=>[$spellId]], 409);
    $sylveon = $em->find(CharacterSessionState::class, $sylveon->getId());
    $rest->apply($sylveon, RestRequest::TYPE_LONG_REST);
    $em->flush();
    $post($sylveon, 'prepare', ['policyId'=>$plan['policyId'],'spellIds'=>[$spellId,$spellId]], 422);
    $post($sylveon, 'prepare', ['policyId'=>$plan['policyId'],'spellIds'=>[2147483647]], 422);
    if (count($plan['available']) > $plan['maximum']) $post($sylveon, 'prepare', ['policyId'=>$plan['policyId'],'spellIds'=>array_column($plan['available'],'id')], 422);
    $post($sylveon, 'prepare', ['policyId'=>$plan['policyId'],'spellIds'=>[]]);
    $post($sylveon, 'prepare', ['revision'=>1,'policyId'=>$plan['policyId'],'spellIds'=>[]], 409);
    $post($sylveon, 'learn', ['policyId'=>$plan['policyId'],'ruleId'=>null,'spellId'=>$spellId,'overrideRules'=>true], 400);
    $check($get($sessions['Nikita'])['spells'] === [], 'Nikita empty state');
    $check($request('GET', '/public/characters/'.str_repeat('0',64).'/spells')[0] === 404, 'Invalid token rejected');
    require __DIR__.'/test-spell-preparation-confirmation-cases.php';
    $db->update('game_session', ['status'=>GameSession::STATUS_CLOSED], ['id'=>$sylveon->getGameSession()->getId()]);
    $em->clear();
    $post($sylveon, 'prepare', ['policyId'=>$plan['policyId'],'spellIds'=>[]], 409);
    $db->update('character_session_state', ['participating'=>false], ['id'=>$sylveon->getId()], ['participating'=>\Doctrine\DBAL\ParameterType::BOOLEAN]);
    $em->clear();
    $check($request('GET', $url($sylveon))[0] === 404, 'Removed participant rejected');
    $check($fingerprint() === $before, 'Original tables AND sequences unchanged');
    echo "PASS: $checks assertions; temporary tables and sequences only.\n";
} finally {
    if ($db->isTransactionActive()) $db->rollBack();
    $kernel->shutdown();
}
