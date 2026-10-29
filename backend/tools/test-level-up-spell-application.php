<?php
declare(strict_types=1);

use App\Entity\{User, Campaign, Character, CharacterClass, CharacterSubclass, CharacterClassLevel, CharacterSessionState, GameSession};
use App\Service\{CharacterSpellAcquisitions, CharacterSpellAccessResolver, SpellCatalogue, SpellSubclassGrants, CharacterResourceResolver, CharacterFeatureResolver, CharacterAbilityCalculator};
use App\Repository\{CharacterFeatureRuleRepository, TrackableResourceRuleRepository};

require __DIR__.'/../vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv(__DIR__.'/../.env');
putenv('SHELL_VERBOSITY=-1'); $_SERVER['SHELL_VERBOSITY'] = $_ENV['SHELL_VERBOSITY'] = -1;
$kernel = new App\Kernel('dev', true); $kernel->boot();
$registry = $kernel->getContainer()->get('doctrine');
$em = $registry->getManager(); $db = $em->getConnection(); $checks = 0;
$check = static function(bool $ok, string $message) use (&$checks): void {
    if (!$ok) throw new RuntimeException($message);
    ++$checks;
};
$services = static function() use ($registry, $db): CharacterSpellAcquisitions {
    $catalogue = new SpellCatalogue($db);
    $resources = new CharacterResourceResolver(new TrackableResourceRuleRepository($registry), new CharacterAbilityCalculator(), new CharacterFeatureResolver(new CharacterFeatureRuleRepository($registry)));
    return new CharacterSpellAcquisitions($registry->getManager(), new CharacterSpellAccessResolver($catalogue, new SpellSubclassGrants($db, $catalogue), $resources));
};
$session = new Symfony\Component\HttpFoundation\Session\Session(new Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage());
$session->start();
$http = static function(?User $actor, string $path, array $payload, string $method = 'POST') use ($session, $kernel, $registry) {
    // A rejected ORM transaction closes its manager. Start each simulated request fresh.
    $registry->resetManager();
    if ($actor) $session->set('_security_main', serialize(new Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken($actor, 'main', $actor->getRoles())));
    else $session->remove('_security_main');
    $request = Symfony\Component\HttpFoundation\Request::create($path, $method, [], [$session->getName()=>$session->getId()], [], ['CONTENT_TYPE'=>'application/json','HTTP_ACCEPT'=>'application/json'], json_encode($payload));
    $request->setSession($session);
    return $kernel->handle($request);
};
try {
    $db->beginTransaction();
    $metadata = $em->getMetadataFactory()->getAllMetadata();
    foreach ((new Doctrine\ORM\Tools\SchemaTool($em))->getCreateSchemaSql($metadata) as $sql) {
        $db->executeStatement(preg_replace(['/^CREATE TABLE /','/^CREATE SEQUENCE /'], ['CREATE TEMP TABLE ','CREATE TEMP SEQUENCE '], $sql));
    }
    foreach ($metadata as $meta) $check($db->fetchOne('SELECT relpersistence FROM pg_class WHERE oid=to_regclass(?)', [$meta->getTableName()]) === 't', 'Temporary '.$meta->getTableName());
    $owner = (new User())->setEmail('level-spells@example.invalid')->setPassword('unused')->setRoles(['ROLE_REFERENCE_ADMIN']);
    $campaign = new Campaign($owner, 'level-spells', 'Synthetic');
    $class = new CharacterClass('level-spells-fighter', 'Synthetic fighter', 10, 3);
    $list = new CharacterClass('level-spells-list', 'Synthetic list', 6, 20);
    $sub = new CharacterSubclass($class, 'level-spells-sub', 'Synthetic third caster');
    $sub->setSpellcastingProgression(App\Enum\SpellcastingProgressionType::Third);
    foreach ([$owner,$campaign,$class,$list,$sub] as $entity) $em->persist($entity);
    $em->flush();
    $preset = App\Service\Dnd2014AdvancedSpellPolicies::thirdCaster($list, false);
    // Explicit synthetic optional replacement, to exercise confirmation independently of PHB presets.
    foreach ($preset['rules'] as &$rule) {
        $values = $rule['parameters']->values; $values['optionalReplacement'] = true;
        $rule['parameters'] = new App\Dto\SpellChoiceParameters($values);
    }
    unset($rule);
    $policy = $services()->defineAdvancedPolicy($sub, $preset['policy'], $owner, $preset['rules']);
    $rules = [];
    foreach ($em->getRepository(App\Entity\SpellPolicyChoiceRule::class)->findBy(['policy'=>$policy]) as $rule) $rules[$rule->getKey()] = $rule->getId();
    $catalogue = new SpellCatalogue($db); $spells = [];
    foreach ([0,0,1,1,1,1,2,1,1,3] as $i=>$n) {
        $spells[] = $catalogue->create(['slug'=>'level-spell-'.$i,'name'=>'Synthetic '.$i,'level'=>$n,'school'=>$i===8?'illusion':'abjuration','castingTime'=>'Fixture','range'=>'Fixture','duration'=>'Fixture','description'=>'Synthetic','classIds'=>[$list->getId()]], $owner, true);
    }
    $ids = ['campaign'=>$campaign->getId(),'class'=>$class->getId(),'sub'=>$sub->getId(),'policy'=>$policy->getId(),'owner'=>$owner->getId()];
    $fixture = static function() use ($registry,$services,$ids,$rules,$spells): array {
        static $counter = 0; ++$counter;
        $em = $registry->resetManager();
        $character = new Character($em->find(Campaign::class,$ids['campaign']), 'level-case-'.$counter, 'Synthetic', Character::TYPE_PLAYER);
        $em->persist($character); $levels=[];
        for ($i=1;$i<=6;++$i) {
            $level = new CharacterClassLevel($character,$em->find(CharacterClass::class,$ids['class']),$i,$i>=3?$em->find(CharacterSubclass::class,$ids['sub']):null, $i===1?10:6, $i===1?App\Enum\HitPointGainMethod::FirstLevel:App\Enum\HitPointGainMethod::Average);
            $character->addClassLevel($level); $em->persist($level); $levels[]=$level;
        }
        $game = new GameSession($character->getCampaign(), 'level-session-'.$counter, 'Synthetic'); $game->setStatus(GameSession::STATUS_LIVE);
        $state = new CharacterSessionState($game,$character,['resources'=>[['id'=>'spell-slot-1','currentValue'=>1]],'progressions'=>[['id'=>'synthetic','currentValue'=>5]]]);
        $state->setLevelUpAllowed(true); $em->persist($game); $em->persist($state); $em->flush();
        $choices=[];
        for ($i=0;$i<5;++$i) $choices[]=['spellId'=>$spells[$i]['id'],'classLevelId'=>$levels[2]->getId(),'ruleId'=>$rules[$i<2?'cantrips':($i===4?'unrestricted':'restricted')]];
        $old=$services()->acquire($character,$ids['policy'],$choices,'level-history','Synthetic history',$character->getCampaign()->getOwner());
        $services()->acquire($character,$ids['policy'],[['spellId'=>$spells[5]['id'],'classLevelId'=>$levels[3]->getId(),'ruleId'=>$rules['restricted']]],'level-history','Synthetic history',$character->getCampaign()->getOwner());
        return ['id'=>$character->getId(),'stateId'=>$state->getId(),'token'=>$state->getAccessToken(),'old'=>$old[2]];
    };
    $snapshot = static function() use ($db,$metadata): array {
        $out=[];
        foreach ($metadata as $meta) $out[$meta->getTableName()]=$db->fetchOne('SELECT md5(COALESCE(string_agg(row_to_json(t)::text,\'\' ORDER BY row_to_json(t)::text),\'\')) FROM '.$db->quoteIdentifier($meta->getTableName()).' t');
        return $out;
    };
    $choice = ['policyId'=>$ids['policy'],'spellId'=>$spells[6]['id'],'ruleId'=>$rules['restricted']];
    $payload = ['classId'=>$ids['class']];
    $url = static fn(array $f): string => '/campaigns/'.$ids['campaign'].'/characters/'.$f['id'].'/level-up';
    $rejected = static function(array $f, array $body, int $code, bool $player=false) use ($snapshot,$http,$owner,$url,$check): void {
        $before=$snapshot();
        $response=$http($player?null:$owner,$player?'/public/characters/'.$f['token'].'/level-up':$url($f),$body);
        $check($response->getStatusCode()===$code, 'Expected '.$code.', got '.$response->getStatusCode().': '.$response->getContent());
        $check($before===$snapshot(), 'Rejected level-up rolls back every ORM table');
    };
    // Preview and POST must agree for known/unknown origins and identical/different semantics.
    foreach ([[false,false],[true,false],[false,true]] as [$known,$sameSemantics]) {
        $f=$fixture();
        $db->update('character_spell_choice',['origin_known'=>$known?1:0,'opportunity_level_id'=>null],['acquisition_id'=>$f['old']]);
        $original=$db->fetchOne('SELECT parameters FROM spell_policy_choice_rule WHERE id=?',[$rules['unrestricted']]);
        if ($sameSemantics) {
            $parameters=json_decode($original,true);
            $parameters['replacementSchools']=['abjuration','evocation'];
            $db->update('spell_policy_choice_rule',['parameters'=>json_encode($parameters)],['id'=>$rules['unrestricted']]);
        }
        $expected=!$known && !$sameSemantics;
        $before=$snapshot();
        foreach ([$url($f),'/public/characters/'.$f['token'].'/level-up'] as $i=>$endpoint) {
            $response=$http($i===0?$owner:null,$endpoint.'/options?classId='.$ids['class'],[],'GET');
            $check($response->getStatusCode()===200,'Qualification preview succeeds');
            $preview=json_decode($response->getContent(),true)['spellPreview'];
            $route=array_column($preview['policies'][0]['choices'],null,'ruleId')[$rules['restricted']];
            $old=array_column($route['replacement']['acquisitions'],null,'acquisitionId')[$f['old']];
            $check($old['requiresQualification']===$expected,'MJ/player preview qualification matches replacement semantics');
        }
        $check($before===$snapshot(),'Qualification preview does not mutate historical choices');
        $replacement=['policyId'=>$ids['policy'],'spellId'=>$spells[7]['id'],'ruleId'=>$rules['restricted'],'replacesAcquisitionId'=>$f['old'],'replacementConfirmed'=>true];
        $response=$http($owner,$url($f),$payload+['spellChoices'=>[$choice,$replacement]]);
        $check($response->getStatusCode()===($expected?409:201),'POST agrees with preview qualification: '.$response->getContent());
        if ($expected) {
            $check(str_contains($response->getContent(),'provenance'),'Refusal identifies unqualified provenance');
            $check($before===$snapshot(),'Qualification refusal rolls back all mutations');
        }
        $db->update('spell_policy_choice_rule',['parameters'=>$original],['id'=>$rules['unrestricted']]);
    }
    $f=$fixture();
    $response=$http($owner,$url($f),$payload);
    $check($response->getStatusCode()===201, 'Legacy level-up succeeds: '.$response->getContent());
    $check((int)$db->fetchOne('SELECT COUNT(*) FROM character_spell_acquisition WHERE character_id=?',[$f['id']])===6,'Absent choices preserve six acquisitions');
    foreach ([
        [[],409],
        [[array_replace($choice,['spellId'=>$spells[8]['id']])],400],
        [[array_replace($choice,['spellId'=>$spells[2]['id']])],409],
        [[$choice,array_replace($choice,['spellId'=>$spells[7]['id']])],409],
        [[array_replace($choice,['policyId'=>999999])],400],
        [[array_replace($choice,['ruleId'=>999999])],400],
        [[array_replace($choice,['spellId'=>$spells[9]['id']])],400],
    ] as [$choices,$code]) $rejected($fixture(),$payload+['spellChoices'=>$choices],$code);
    $f=$fixture();
    $replacement=['policyId'=>$ids['policy'],'spellId'=>$spells[7]['id'],'ruleId'=>$rules['restricted'],'replacesAcquisitionId'=>$f['old']];
    $rejected($f,$payload+['spellChoices'=>[$choice,$replacement]],400,true);
    $replacement['replacementConfirmed']=true;
    // Replacement alone must not satisfy the new learning opportunity; its INSERT rolls back too.
    $rejected($f,$payload+['spellChoices'=>[$replacement]],409,true);
    $response=$http(null,'/public/characters/'.$f['token'].'/level-up',$payload+['spellChoices'=>[$choice,$replacement]]);
    $check($response->getStatusCode()===201,'Player level-up succeeds: '.$response->getContent());
    $rows=$db->fetchAllAssociative('SELECT a.* FROM character_spell_acquisition a JOIN character_class_level l ON l.id=a.class_level_id WHERE a.character_id=? AND l.position=7 ORDER BY a.id',[$f['id']]);
    $check(count($rows)===2 && (int)$rows[1]['replaces_id']===$f['old'],'New learning and replacement reference the new level and old acquisition');
    foreach ($rows as $row) $check($row['provenance']==='level-history' && (int)$row['decided_by_id']===$ids['owner'] && str_contains($row['decision_note'],'acteur technique'),'Player origin explicitly recorded');
    $check((int)$db->fetchOne('SELECT COUNT(*) FROM character_spell_choice WHERE acquisition_id IN (?,?)',array_column($rows,'id'))===2,'Advanced choice rows persisted');
    $check((int)$db->fetchOne('SELECT COUNT(*) FROM character_spell_acquisition WHERE character_id=?',[$f['id']])===8,'Original six acquisitions retained');
// The level-up creates exactly one seventh class level.
$check(
    (int) $db->fetchOne(
        'SELECT COUNT(*) FROM character_class_level
         WHERE character_id = ? AND position = 7',
        [$f['id']]
    ) === 1,
    'Exactly one level 7 persisted'
);

// The forgotten spell remains in history but is superseded by a replacement.
$check(
    (int) $db->fetchOne(
        'SELECT COUNT(*)
         FROM character_spell_acquisition old
         WHERE old.id = ?
           AND old.retirement_id IS NULL
           AND EXISTS (
               SELECT 1
               FROM character_spell_acquisition replacement
               WHERE replacement.replaces_id = old.id
                 AND replacement.retirement_id IS NULL
           )',
        [$f['old']]
    ) === 1,
    'Replaced spell remains in history and is superseded'
);

// The new learning and replacement remain active.
$check(
    (int) $db->fetchOne(
        'SELECT COUNT(*) FROM character_spell_acquisition
         WHERE id IN (?, ?) AND retirement_id IS NULL',
        array_column($rows, 'id')
    ) === 2,
    'Both new acquisitions remain active'
);
    $state=json_decode($db->fetchOne('SELECT state FROM character_session_state WHERE id=?',[$f['stateId']]),true);
    $slots=array_column($state['resources'],'currentValue','id');
    $check($slots['spell-slot-1']===2 && $slots['spell-slot-2']===2,'Slot maxima delta preserves consumed slots');
    $check($state['progressions'][0]['currentValue']===5,'Progression retained');
    $rejected($f,$payload+['spellChoices'=>[$choice]],403,true);
    $f=$fixture(); $db->executeStatement('UPDATE character_session_state SET level_up_allowed=false WHERE id=?',[$f['stateId']]);
    $rejected($f,$payload+['spellChoices'=>[$choice]],403,true);
    $f=$fixture(); $response=$http($owner,$url($f),$payload+['spellChoices'=>[$choice]]);
    $check($response->getStatusCode()===201,'MJ explicit acquisition succeeds: '.$response->getContent());
    // Classical policies use the same acquisition engine, without advanced rule IDs.
    foreach (['cleric-2014','wizard-2014','sorcerer-2014'] as $presetKey) {
        $em=$registry->resetManager();
        $actor=$em->find(User::class,$ids['owner']);
        $simpleClass=new CharacterClass('simple-'.$presetKey,'Synthetic simple',8,20); $em->persist($simpleClass);
        $simpleCharacter=new Character($em->find(Campaign::class,$ids['campaign']),'simple-'.$presetKey,'Synthetic simple',Character::TYPE_PLAYER); $em->persist($simpleCharacter);
        $simpleLevels=[];
        for ($n=1;$n<=2;++$n) {
            $simpleLevel=new CharacterClassLevel($simpleCharacter,$simpleClass,$n); $simpleCharacter->addClassLevel($simpleLevel); $em->persist($simpleLevel); $simpleLevels[]=$simpleLevel;
        }
        $em->flush();
        $simplePolicy=$services()->definePolicy($simpleClass,App\Service\Dnd2014SpellPolicies::get($presetKey),$actor);
        $simpleSpells=[];
        for ($i=0;$i<3;++$i) $simpleSpells[]=$catalogue->create(['slug'=>$presetKey.'-spell-'.$i,'name'=>'Synthetic simple '.$i,'level'=>1,'school'=>'abjuration','castingTime'=>'Fixture','range'=>'Fixture','duration'=>'Fixture','description'=>'Synthetic','classIds'=>[$simpleClass->getId()]],$actor,true);
        $sf=['id'=>$simpleCharacter->getId()]; $body=['classId'=>$simpleClass->getId(),'spellChoices'=>[]];
        if ($presetKey==='cleric-2014') {
            $response=$http($owner,$url($sf),$body);
            $check($response->getStatusCode()===201,'Prepared policy accepts empty learning choices');
            $check((int)$db->fetchOne('SELECT COUNT(*) FROM character_spell_acquisition WHERE character_id=?',[$sf['id']])===0,'Prepared list is not copied into acquisitions');
            continue;
        }
        $rejected($sf,$body,409);
        $newChoice=['policyId'=>$simplePolicy->getId(),'spellId'=>$simpleSpells[0]['id'],'ruleId'=>null];
        $body['spellChoices']=[$newChoice];
        if ($presetKey==='wizard-2014') {
            $rejected($sf,$body,409); // One book addition is insufficient, and must roll back.
            $body['spellChoices'][]=array_replace($newChoice,['spellId'=>$simpleSpells[1]['id']]);
        } else {
            $em=$registry->resetManager();
            $old=$services()->acquire($em->find(Character::class,$sf['id']),$simplePolicy->getId(),[['spellId'=>$simpleSpells[1]['id'],'classLevelId'=>$simpleLevels[1]->getId()]],'level-history','Synthetic earlier learning',$em->find(User::class,$ids['owner']))[0];
            $body['spellChoices'][]=array_replace($newChoice,['spellId'=>$simpleSpells[2]['id'],'replacesAcquisitionId'=>$old]);
        }
        $response=$http($owner,$url($sf),$body);
        $check($response->getStatusCode()===201,'Classical learning/replacement accepted: '.$response->getContent());
        $check((int)$db->fetchOne('SELECT COUNT(*) FROM character_spell_acquisition a JOIN character_class_level l ON l.id=a.class_level_id WHERE a.character_id=? AND l.position=3',[$sf['id']])===2,'Classical decisions attach to new level');
    }
// With no historical policy pin, class and subclass policies are ambiguous.
// Use dedicated references to avoid contaminating the main level-up fixture.
$em = $registry->resetManager();

$ambiguousClass = new CharacterClass(
    'ambiguous-level-class',
    'Synthetic ambiguous class',
    10,
    3
);

$ambiguousSub = new CharacterSubclass(
    $ambiguousClass,
    'ambiguous-level-sub',
    'Synthetic ambiguous subclass'
);

$ambiguous = new Character(
    $em->find(Campaign::class, $ids['campaign']),
    'ambiguous-level',
    'Synthetic ambiguous',
    Character::TYPE_PLAYER
);

foreach ([$ambiguousClass, $ambiguousSub, $ambiguous] as $entity) {
    $em->persist($entity);
}

for ($i = 1; $i <= 6; ++$i) {
    $level = new CharacterClassLevel(
        $ambiguous,
        $ambiguousClass,
        $i,
        $i >= 3 ? $ambiguousSub : null
    );

    $ambiguous->addClassLevel($level);
    $em->persist($level);
}

$em->flush();

$actor = $em->find(User::class, $ids['owner']);

$services()->definePolicy(
    $ambiguousClass,
    App\Service\Dnd2014SpellPolicies::get('sorcerer-2014'),
    $actor
);

$ambiguousPreset = App\Service\Dnd2014AdvancedSpellPolicies::thirdCaster(
    $ambiguousClass,
    false
);

$services()->defineAdvancedPolicy(
    $ambiguousSub,
    $ambiguousPreset['policy'],
    $actor,
    $ambiguousPreset['rules']
);

$rejected(
    ['id' => $ambiguous->getId()],
    ['classId' => $ambiguousClass->getId(), 'spellChoices' => []],
    409
);
    foreach (['none','full','pact','third-subclass'] as $kind) {
        $em=$registry->resetManager();
        $progression=App\Enum\SpellcastingProgressionType::tryFrom($kind) ?? App\Enum\SpellcastingProgressionType::None;
        $targetClass=new CharacterClass('applicability-'.$kind,'Synthetic applicability',10,3,$progression);
        $targetSub=new CharacterSubclass($targetClass,'applicability-'.$kind,'Synthetic subclass');
        if ($kind==='third-subclass') $targetSub->setSpellcastingProgression(App\Enum\SpellcastingProgressionType::Third);
        $target=new Character($em->find(Campaign::class,$ids['campaign']),'applicability-'.$kind,'Synthetic applicability',Character::TYPE_PLAYER);
        foreach ([$targetClass,$targetSub,$target] as $entity) $em->persist($entity);
        for ($n=1;$n<=6;++$n) {
            $l=new CharacterClassLevel($target,$targetClass,$n,$n>=3?$targetSub:null,$n===1?10:6,$n===1?App\Enum\HitPointGainMethod::FirstLevel:App\Enum\HitPointGainMethod::Average); $target->addClassLevel($l); $em->persist($l);
        }
        $game=new GameSession($target->getCampaign(),'applicability-'.$kind,'Synthetic'); $game->setStatus(GameSession::STATUS_LIVE);
        $state=new CharacterSessionState($game,$target,[]); $state->setLevelUpAllowed(true);
        $em->persist($game); $em->persist($state); $em->flush();
        $f=['id'=>$target->getId(),'token'=>$state->getAccessToken()]; $body=['classId'=>$targetClass->getId(),'spellChoices'=>[]];
        $before=$snapshot();
        foreach ([$url($f),'/public/characters/'.$f['token'].'/level-up'] as $i=>$endpoint) {
            $response=$http($i===0?$owner:null,$endpoint.'/options?classId='.$targetClass->getId(),[],'GET');
            $check($response->getStatusCode()===200,'Applicability preview succeeds');
            $data=json_decode($response->getContent(),true)['spellPreview'];
            $check($data['status']===($kind==='none'?'no-spellcasting':'policy-to-configure'),'Explicit applicability: '.$kind);
        }
        $check($before===$snapshot(),'Applicability preview is read-only');
        if ($kind==='none') {
            $rejected($f,array_replace($body,['spellChoices'=>[$choice]]),400);
            $response=$http(null,'/public/characters/'.$f['token'].'/level-up',$body);
            $check($response->getStatusCode()===201,'Nonmagical fighter level-up accepted');
            $check((int)$db->fetchOne('SELECT COUNT(*) FROM character_spell_acquisition WHERE character_id=?',[$f['id']])===0,'No acquisition invented');
        } else $rejected($f,$body,409,true);
    }
    // An indeterminate HP differential is a 422 before levels, spells or authorization change.
    $em=$registry->resetManager();
    $em->persist(new App\Entity\CharacterClassLevelRule($em->find(CharacterClass::class,$ids['class']),7,App\Enum\LevelAdvancementChoice::AbilityScoreImprovementOrFeat));
    $em->flush();
    foreach ([false,true] as $player) {
        $f=$fixture();
        $em=$registry->getManager();
        $character=$em->find(Character::class,$f['id']);
        $character->getAbilityScore(App\Enum\Ability::Constitution)->setBaseValue(6);
        $em->flush();
        $db->executeStatement('UPDATE character_class_level SET hit_point_gain=NULL, hit_point_gain_method=NULL WHERE character_id=? AND position=2',[$f['id']]);
        $before=$snapshot();
        $response=$http($player?null:$owner,$player?'/public/characters/'.$f['token'].'/level-up':$url($f),$payload+[
            'advancement'=>['type'=>'ability','increases'=>[['ability'=>'constitution','value'=>2]]],
            'spellChoices'=>[$choice],
        ]);
        $check($response->getStatusCode()===422,'Indeterminate HP gives 422 for '.($player?'player':'MJ').': '.$response->getContent());
        $check(str_contains(json_decode($response->getContent(),true)['message'],'historique des gains de PV'),'HTTP error explains required HP history regularization');
        $check($snapshot()===$before,'HP refusal preserves every ORM table including acquisitions, authorization and sessions');
    }
    echo "OK: $checks assertions, isolated temporary tables.\n";
} finally {
    while ($db->isTransactionActive()) $db->rollBack();
    $registry->resetManager(); $kernel->shutdown();
}
