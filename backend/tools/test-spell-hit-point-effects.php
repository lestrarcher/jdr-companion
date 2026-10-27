<?php

declare(strict_types=1);

// Committed synthetic fixtures in a disposable schema; never reads/writes player rows.
use App\Entity as E;
use App\Enum as V;
use App\Service as S;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/spell-cast-services-fixture.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv(__DIR__.'/../.env');
putenv('SHELL_VERBOSITY=-1');
$_ENV['SHELL_VERBOSITY'] = $_SERVER['SHELL_VERBOSITY'] = '-1';
$kernel = new App\Kernel('dev', true); $kernel->boot();
$registry = $kernel->getContainer()->get('doctrine');
$em = $registry->getManager(); $db = $em->getConnection();
$worker = ($argv[1] ?? '') === 'worker';
$schema = $worker ? $argv[2] : 'test_spell_hp_'.bin2hex(random_bytes(8));
if (!preg_match('/\Atest_spell_hp_[a-f0-9]{16}\z/', $schema)) throw new RuntimeException('Unsafe schema');
$quoted = $db->quoteIdentifier($schema); $created = false; $processes = []; $checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void {
    if (!$ok) throw new RuntimeException($message);
    ++$checks;
};
try {
    if (!$worker) { $db->executeStatement('CREATE SCHEMA '.$quoted); $created = true; }
    $db->executeStatement('SET search_path TO '.$quoted);
    $db->executeStatement("SET statement_timeout = '15s'");
    $db->executeStatement("SET lock_timeout = '12s'");
    if ($worker) {
        $s = spellConcurrencyServices($registry);
        $data = json_decode(base64_decode($argv[3]), true, 512, JSON_THROW_ON_ERROR);
        $session = $em->find(E\CharacterSessionState::class, $data['session']);
        // Intentionally hydrate targets/effects before the concurrency barrier.
        $em->getRepository(E\CharacterSessionState::class)->findAll();
        $em->getRepository(E\CharacterActiveEffect::class)->findAll();
        echo 'READY '.$db->fetchOne('SELECT pg_backend_pid()')."\n"; flush();
        stream_set_timeout(STDIN, 20);
        if (trim((string) fgets(STDIN)) !== 'GO') throw new RuntimeException('Barrier timeout');
        try {
            $result = ['status'=>200, 'body'=>$s['executor']->execute($session, $data['command'])];
        } catch (HttpExceptionInterface $error) {
            $result = ['status'=>$error->getStatusCode(), 'message'=>$error->getMessage()];
        }
        $result['transactionOpen'] = $db->isTransactionActive();
        echo json_encode($result, JSON_THROW_ON_ERROR)."\n";
    } else {
        (new Doctrine\ORM\Tools\SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());
        foreach (['feature_spell_grant','character_feature_spell'] as $table) {
            $db->executeStatement('CREATE TABLE '.$table.' (LIKE public.'.$table.')');
        }
        foreach ($db->fetchFirstColumn('SELECT tablename FROM pg_tables WHERE schemaname=?', [$schema]) as $table) {
            $check($db->fetchOne('SELECT n.nspname FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE c.oid=to_regclass(?)', [$table]) === $schema, 'Fixture table isolated: '.$table);
        }
        $s = spellConcurrencyServices($registry);
        $owner = (new E\User())->setEmail('hp@example.invalid')->setPassword('unused')->setRoles([S\SpellCatalogue::ADMIN_ROLE]);
        $campaign = new E\Campaign($owner, 'hp', 'HP');
        $class = new E\CharacterClass('cleric', 'Cleric', 6, 1, V\SpellcastingProgressionType::Full);
        $policy = new E\SpellcastingPolicyDefinition($class, new App\Dto\SpellcastingPolicy('hp', 1, V\Ability::Wisdom, 'known',
            array_fill(0, 20, ['maxSpellLevel'=>9,'cantrips'=>2,'known'=>10,'bookAdditions'=>0,'replacements'=>0,'preparationBase'=>null]), 'Synthetic'));
        $game = (new E\GameSession($campaign, 'hp', 'HP'))->setStatus(E\GameSession::STATUS_LIVE);
        $otherGame = (new E\GameSession($campaign, 'other', 'Other'))->setStatus(E\GameSession::STATUS_LIVE);
        foreach ([$owner,$campaign,$class,$policy,$game,$otherGame] as $entity) $em->persist($entity);
        $em->flush();
        $characters = []; $sessions = []; $initial = []; $lastLevels = [];
        for ($i = 0; $i < 15; ++$i) {
            $character = new E\Character($campaign, 'hp-'.$i, 'HP '.$i, E\Character::TYPE_PLAYER);
            for ($position = 1; $position <= ($i < 2 ? 17 : 1); ++$position) {
                $level = new E\CharacterClassLevel($character, $class, $position, null, $i === 14 ? null : 4, $i === 14 ? null : V\HitPointGainMethod::Average);
                $character->addClassLevel($level); $em->persist($level);
            }
            $lastLevels[$i] = $level;
            $state = ['hitPoints'=>['current'=>10,'temporary'=>3,'maximumAdjustment'=>7],
                'resources'=>array_map(static fn($level,$maximum)=>['id'=>'spell-slot-'.$level,'currentValue'=>$maximum,'note'=>'preserve'],
                    array_keys($maxima=(new S\CharacterSpellSlotCalculator())->calculate($character)), array_values($maxima)),
                'characterActions'=>['prepared'=>['aid','heroes-feast'],'preparationPending'=>false],
                'spellPreparationAllowed'=>true,'progressions'=>[['id'=>'narrative','currentValue'=>4]]];
            $session = new E\CharacterSessionState($i === 13 ? $otherGame : $game, $character, $state);
            if ($i === 12) $session->setParticipating(false);
            $em->persist($character); $em->persist($session);
            $characters[$i]=$character; $sessions[$i]=$session; $initial[$i]=$state;
        }
        $em->flush(); $sources = [];
        foreach (['aid'=>2,'feast'=>6,'ordinary'=>1] as $kind=>$level) {
            $data = $s['catalogue']->create(['slug'=>'hp-'.$kind,'name'=>'Identical translated name','level'=>$level,'school'=>'abjuration',
                'castingTime'=>'1 action','range'=>'30 feet','duration'=>'8 hours','description'=>'Synthetic','classIds'=>[$class->getId()]], $owner, true);
            $spell = $em->find(E\SpellDefinition::class, $data['id']);
            if ($kind !== 'ordinary') $spell->setImportKey($kind === 'aid' ? 'aidedd-2014:aide' : 'aidedd-2014:festin-des-heros');
            foreach ([0,1] as $i) {
                $acquisition = new E\CharacterSpellAcquisition($characters[$i], $spell, $policy, $lastLevels[$i], $owner, 'manual-reconciliation', 'known', 'Synthetic');
                $em->persist($acquisition); $em->flush();
                $sources[$i][$kind] = 'acquisition:'.$acquisition->getId();
            }
        }
        $em->flush();
        $ids = array_map(static fn($c)=>$c->getId(), $characters);
        $sessionIds = array_map(static fn($c)=>$c->getId(), $sessions);
        $read = static function(int $i) use ($db,$sessionIds): array {
            $row=$db->fetchAssociative('SELECT state,revision,updated_at FROM character_session_state WHERE id=?',[$sessionIds[$i]]);
            $row['state']=json_decode($row['state'],true,512,JSON_THROW_ON_ERROR); $row['revision']=(int)$row['revision']; return $row;
        };
        $snapshot = static fn()=>[
            $db->fetchAllAssociative('SELECT * FROM character_session_state ORDER BY id'),
            $db->fetchAllAssociative('SELECT * FROM character_active_effect ORDER BY id'),
        ];
        $reset = static function() use ($db,$sessionIds,$initial,$em): void {
            $db->executeStatement('DELETE FROM character_active_effect');
            foreach ($sessionIds as $i=>$id) $db->executeStatement('UPDATE character_session_state SET state=?,revision=revision+1 WHERE id=?',[json_encode($initial[$i]),$id]);
            $em->clear();
        };
        $sequence=0;
        $command = static function(string $kind,int $level,array $targets=[],?int $roll=null,int $caster=0) use (&$sequence,$sources,$read): array {
            $cmd=['revision'=>$read($caster)['revision'],'requestId'=>sprintf('b0000000-0000-4000-8000-%012d',++$sequence),
                'sourceKey'=>$sources[$caster][$kind],'method'=>'standard-slot','castingLevel'=>$level];
            if ($targets !== []) $cmd['targetCharacterIds']=$targets;
            if ($roll !== null) $cmd['hitPointRoll']=$roll;
            return $cmd;
        };
        $execute = static fn(array $cmd,int $caster=0)=>$s['executor']->execute($em->find(E\CharacterSessionState::class,$sessionIds[$caster]),$cmd);
        $refuse = static function(array $cmd,int $status,string $label) use ($execute,$snapshot,$check): void {
            $before=$snapshot();
            try { $execute($cmd); throw new RuntimeException('Accepted invalid '.$label); }
            catch (HttpExceptionInterface $error) { $check($error->getStatusCode()===$status,$label.': '.$error->getMessage()); }
            $check($snapshot()===$before,$label.': no cost, HP, effect, revision or receipt changes');
        };
        foreach (range(2,9) as $level) {
            $reset(); $cmd=$command('aid',$level,[$ids[0],$ids[2],$ids[3]]); $before=$read(0);
            $result=$execute($cmd); $bonus=5*($level-1);
            foreach ([0,2,3] as $i) {
                $hp=$read($i)['state']['hitPoints'];
                $check($hp===['current'=>10+$bonus,'temporary'=>3,'maximumAdjustment'=>7+$bonus], 'Aid '.$level.' target '.$i.' preserves manual adjustment');
            }
            $expected=$before['state']['resources']; --$expected[$level-1]['currentValue'];
            $check($read(0)['state']['resources']===$expected,'Aid consumes chosen slot exactly once including self-target');
            // getState() projects character-wide inspiration; it is deliberately not in session JSON.
            $persistedState=$read(0)['state'];
            $check(!array_key_exists('inspiration',$persistedState),'Inspiration is not duplicated in session storage');
            $persistedState['inspiration']=$db->fetchOne('SELECT inspiration FROM character WHERE id=?',[$ids[0]]);
            $check($result['state']===$persistedState,'Self-target response matches persisted state and character inspiration');
            $check((int)$db->fetchOne('SELECT count(*) FROM character_active_effect')===3,'One independent effect per target');
            $snapshotAfter=$snapshot(); $replay=$execute($cmd);
            $check($replay['replayed'] && $snapshot()===$snapshotAfter,'Aid replay no mutations');
            $reordered=$cmd; $reordered['targetCharacterIds']=array_reverse($cmd['targetCharacterIds']);
            $check($execute($reordered)['replayed'] && $snapshot()===$snapshotAfter,'Target order is irrelevant');
            $different=$cmd; $different['targetCharacterIds']=[$ids[4]]; $refuse($different,409,'Changed targets');
            unset($different['targetCharacterIds']); $refuse($different,409,'Omitted targets conflict');
        }
        foreach (range(6,9) as $level) {
            $reset(); $cmd=$command('feast',$level,array_slice($ids,0,12),13); $result=$execute($cmd);
            foreach (range(0,11) as $i) $check($read($i)['state']['hitPoints']['maximumAdjustment']===20,'Feast '.$level.' target '.$i.' fixed roll');
            $expected=$initial[0]['resources']; --$expected[$level-1]['currentValue'];
            $check($read(0)['state']['resources']===$expected,'Feast '.$level.' one slot');
            $before=$snapshot(); $check($execute($cmd)['replayed'] && $snapshot()===$before,'Feast replay');
            $refuse(array_replace($cmd,['hitPointRoll'=>14]),409,'Changed roll');
        }
        $reset();
        $execute($command('aid',2,[$ids[2]]));
        $execute($command('feast',6,[$ids[2]],10));
        $execute($command('aid',3,[$ids[2]]));
        $check($read(2)['state']['hitPoints']===['current'=>30,'temporary'=>3,'maximumAdjustment'=>27],'Aid + feast stack, stronger aid adds difference only');
        $hpBefore=$read(2); $execute($command('aid',2,[$ids[2]]));
        $check($read(2)===$hpBefore,'Weaker aid does not heal or replace');
        $execute($command('feast',7,[$ids[2]],14));
        $check($read(2)['state']['hitPoints']['maximumAdjustment']===31 && $read(2)['state']['hitPoints']['current']===34,'Stronger feast adds difference');
        $execute($command('feast',8,[$ids[2]],14));
        $check($read(2)['state']['hitPoints']['current']===34,'Equal feast does not heal');
        $check((int)$db->fetchOne('SELECT count(*) FROM character_active_effect')===2,'Stack retains exactly two effects');
        $reset();
        foreach ([[],[$ids[2],$ids[2]],[$ids[2],$ids[3],$ids[4],$ids[5]],[$ids[12]],[$ids[13]],[2147483647],[$ids[2],$ids[14]]] as $targets) {
            $refuse($command('aid',2,$targets),422,'Invalid aid targets '.json_encode($targets));
        }
        foreach ([['targetCharacterIds'=>['1']],['targetCharacterIds'=>[0]],['targetCharacterIds'=>null],['hitPointRoll'=>2]] as $patch) {
            $refuse(array_replace($command('aid',2,[$ids[2]]),$patch),422,'Malformed aid parameters');
        }
        $refuse($command('aid',1,[$ids[2]]),422,'Aid level below minimum');
        $refuse($command('feast',5,[$ids[2]],10),422,'Feast level below minimum');
        foreach ([null,1,21] as $roll) $refuse($command('feast',6,[$ids[2]],$roll),422,'Missing/out of range roll');
        foreach (['10',10.5,true] as $roll) $refuse(array_replace($command('feast',6,[$ids[2]],10),['hitPointRoll'=>$roll]),422,'Noninteger roll');
        $refuse($command('feast',6,array_slice($ids,0,13),10),422,'Too many feast targets');
        $refuse($command('ordinary',1,[$ids[2]]),422,'Ordinary spell refuses effect parameters despite identical name');
        $ordinary=$command('ordinary',1); $execute($ordinary);
        $check((int)$db->fetchOne('SELECT count(*) FROM character_active_effect')===0,'Ordinary spell creates no effects');
        $receipt=$read(0)['state']['spellCastReceipts'][$ordinary['requestId']];
        $check($receipt['version']===1 && $execute($ordinary)['replayed'],'Ordinary v1 receipt remains compatible');
        // Historical aid v1 acceptance must replay without retroactively applying HP.
        $legacy=$command('aid',2); $legacyState=$read(0)['state'];
        $legacyCast=$receipt['cast']; $legacyCast['source']['key']=$legacy['sourceKey'];
        $legacyCast['source']['spellId']=(int)$db->fetchOne("SELECT id FROM spell_definition WHERE import_key='aidedd-2014:aide'");
        $legacyCast['castingLevel']=2; $legacyCast['poolSlug']='spell-slot-2';
        $legacyState['spellCastReceipts'][$legacy['requestId']]=S\SpellCastReceipts::create($legacy,$legacyCast);
        $db->executeStatement('UPDATE character_session_state SET state=? WHERE id=?',[json_encode($legacyState),$sessionIds[0]]);
        $before=$snapshot(); $check($execute($legacy)['replayed'] && $snapshot()===$before,'Historical aid v1 is not re-executed');
        $refuse($legacy+['targetCharacterIds'=>[$ids[2]]],409,'Cannot retrofit targets onto historical receipt');

        // Existing public endpoint accepts the extended contract; receipts remain private.
        $http = static function(array $cmd,int $expectedStatus=200,string $suffix='spells/cast') use ($kernel,$sessions,$check,$snapshot): array {
            $before=$snapshot();
            $request=Symfony\Component\HttpFoundation\Request::create(
                '/public/characters/'.$sessions[0]->getAccessToken().'/'.$suffix,'POST',
                server:['CONTENT_TYPE'=>'application/json','HTTP_ACCEPT'=>'application/json'],content:json_encode($cmd));
            $response=$kernel->handle($request);
            $body=json_decode($response->getContent(),true,512,JSON_THROW_ON_ERROR);
            $check($response->getStatusCode()===$expectedStatus,'HP HTTP '.$expectedStatus.': '.$response->getContent());
            $check(!str_contains($response->getContent(),'spellCastReceipts'),'HTTP hides internal receipts');
            if ($expectedStatus!==200) $check($snapshot()===$before,'HTTP refusal preserves all states/effects');
            return $body;
        };
        $reset();
        $http($command('aid',2),422);
        $http($command('feast',6,[$ids[2]]),422);
        $http($command('aid',2,[$ids[2],$ids[2]]),400);
        foreach (['aid'=>2,'feast'=>9] as $kind=>$level) {
            $cmd=$command($kind,$level,[$ids[0],$ids[2]],$kind==='feast'?20:null);
            $body=$http($cmd); $before=$snapshot();
            $expectedHp=$read(0)['state']['hitPoints'];
            // Fixture caster: 17 levels with 4 HP each and Constitution 10.
            $expectedHp['effectiveMaximum']=68+$expectedHp['maximumAdjustment'];
            $check($body['state']['hitPoints']===$expectedHp,'HTTP returns self-target HP and derived effective maximum');
            $check($http($cmd)['replayed'] && $snapshot()===$before,'HTTP retry does not repeat effect or cost');
        }

        $reset();
        $http(['revision'=>$read(0)['revision'],'spellSlotLevel'=>2,'targetIds'=>[$ids[2]]],200,'actions/aid');
        $http(['revision'=>$read(0)['revision'],'hitPointBonus'=>10,'targetIds'=>[$ids[2]]],200,'actions/heroes-feast');
        $check($read(2)['state']['hitPoints']===['current'=>25,'temporary'=>3,'maximumAdjustment'=>22],'Legacy endpoints still stack the same HP effects');
        $legacyResources=$initial[0]['resources']; --$legacyResources[1]['currentValue']; --$legacyResources[5]['currentValue'];
        $check($read(0)['state']['resources']===$legacyResources,'Legacy endpoints retain their own single consumption');

        // Long rests consume the same effect records as the new executor; no second effect engine.
        $ability=new S\CharacterAbilityCalculator(); $hp=new S\CharacterHitPointCalculator($ability);
        $resources=new S\CharacterResourceResolver(new App\Repository\TrackableResourceRuleRepository($registry),$ability,new S\CharacterFeatureResolver(new App\Repository\CharacterFeatureRuleRepository($registry)));
        $effects=new App\Repository\CharacterActiveEffectRepository($registry);
        $rest=new S\CharacterRestService($hp,$s['hpState'],$resources,$s['sync'],new S\CharacterSpellSlotCalculator(),new App\Repository\TrackableResourceDefinitionRepository($registry),$ability,$effects,new S\CharacterActiveEffectService($s['hpState']),$em);
        foreach (['arcane-recovery'=>V\ResourceRechargeType::LongRest,'rest-short'=>V\ResourceRechargeType::ShortRest,'rest-persistent'=>V\ResourceRechargeType::None] as $slug=>$recharge) {
            $em->persist(new E\TrackableResourceDefinition($slug,$slug,$recharge,V\ResourceMaximumType::Fixed,1));
        }
        $em->flush();
        foreach (['none','aid','feast','both'] as $case) {
            $reset(); $commands=[];
            if (in_array($case,['aid','both'],true)) { $commands[]=$command('aid',3,[$ids[0]]); $execute($commands[array_key_last($commands)]); }
            if (in_array($case,['feast','both'],true)) { $commands[]=$command('feast',6,[$ids[0]],13); $execute($commands[array_key_last($commands)]); }
            $session=$em->find(E\CharacterSessionState::class,$sessionIds[0]);
            // Unrelated persistent effect: the rest must not infer an expiry from its age or amount.
            $persistent=null;
            if ($case==='both') {
                $persistent=new E\CharacterActiveEffect($session->getCharacter(),$session->getCharacter(),'fixture-persistent',0);
                (new ReflectionProperty($persistent,'createdAt'))->setValue($persistent,new DateTimeImmutable('-30 days'));
                $em->persist($persistent);
            }
            $state=$session->getState(); $state['hitPoints']['current']=1; $state['hitPoints']['temporary']=9;
            $state['hitDice']=[['id'=>'d6','current'=>0,'note'=>'preserve']];
            $state['resources'][0]['currentValue']=0; $state['resources'][0]['flexibleCastingBonus']=2;
            foreach (['arcane-recovery','rest-short','rest-persistent'] as $slug) $state['resources'][]=['id'=>$slug,'currentValue'=>0,'note'=>'preserve'];
            $session->setState($state); $em->flush();
            $persistentId=$persistent?->getId()??0;
            $persistentRow=$db->fetchAssociative('SELECT * FROM character_active_effect WHERE id=?',[$persistentId]);
            $feastRow=$db->fetchAssociative("SELECT * FROM character_active_effect WHERE target_character_id=? AND effect_type='heroes-feast'",[$ids[0]]);
            $casterBefore=$read(1); $receipts=$read(0)['state']['spellCastReceipts']??null;
            $check($commands===[] || count($receipts)===count($commands),$case.': cast receipts exist before rest');
            $expectedAdjustment=7+(in_array($case,['feast','both'],true)?13:0);
            foreach ([1,2] as $pass) {
                $db->transactional(function() use($rest,$session,$em):void { $rest->apply($session,E\RestRequest::TYPE_LONG_REST); $em->flush(); });
                $em->refresh($session); $after=$read(0)['state'];
                $check($after['hitPoints']===['current'=>68+$expectedAdjustment,'temporary'=>0,'maximumAdjustment'=>$expectedAdjustment],$case.' rest '.$pass.': removes Aid once, preserves manual adjustment/Feast, heals and clears temporary HP');
                $check($effects->findEffectFor($session->getCharacter(),E\CharacterActiveEffect::TYPE_AID)===null,$case.' rest '.$pass.': Aid record removed');
                $check($db->fetchAssociative("SELECT * FROM character_active_effect WHERE target_character_id=? AND effect_type='heroes-feast'",[$ids[0]])===$feastRow,$case.' rest '.$pass.': Feast amount, source and date unchanged');
                $check($db->fetchAssociative('SELECT * FROM character_active_effect WHERE id=?',[$persistentId])===$persistentRow,$case.' rest '.$pass.': unrelated persistent effect unchanged');
                $pools=array_column($after['resources'],null,'id');
                $check($pools['spell-slot-1']===['id'=>'spell-slot-1','currentValue'=>4,'note'=>'preserve'],$case.' rest '.$pass.': flexible bonus removed and natural slots restored');
                foreach ($initial[0]['resources'] as $pool) $check($pools[$pool['id']]===$pool,$case.' rest '.$pass.': restores '.$pool['id'].' exactly');
                $check($pools['arcane-recovery']['currentValue']===1 && $pools['rest-short']['currentValue']===1 && $pools['rest-persistent']['currentValue']===0,$case.' rest '.$pass.': recharge periods respected');
                $check($after['hitDice']===[['id'=>'d6','current'=>8*$pass,'note'=>'preserve']],$case.' rest '.$pass.': hit dice restore normally');
                $check($after['progressions']===$initial[0]['progressions'] && $read(1)===$casterBefore,$case.' rest '.$pass.': progression and other character unchanged');
                $check(($after['spellCastReceipts']??null)===$receipts,$case.' rest '.$pass.': casting receipts preserved');
                $check($after['characterActions']===['prepared'=>[],'preparationPending'=>true] && $after['spellPreparationAllowed']===true,$case.' rest '.$pass.': preparation lifecycle unchanged without recasting prepared actions');
            }
            foreach ($commands as $cmd) { $before=$snapshot(); $check($execute($cmd)['replayed'] && $snapshot()===$before,$case.': old cast receipt cannot reapply an expired effect after rest'); }
        }

        // Arcane Recovery still consumes its own recharged pool after an ordinary long rest.
        $wizard=new E\CharacterClass('wizard','Rest wizard',6,20,V\SpellcastingProgressionType::Full);
        $mage=new E\Character($session->getCharacter()->getCampaign(),'rest-mage','Rest mage',E\Character::TYPE_PLAYER);
        $em->persist($wizard); $em->persist($mage);
        foreach ([1,2] as $position) { $level=new E\CharacterClassLevel($mage,$wizard,$position,null,4,V\HitPointGainMethod::Average); $mage->addClassLevel($level); $em->persist($level); }
        $definition=$em->getRepository(E\TrackableResourceDefinition::class)->findOneBy(['slug'=>'arcane-recovery']);
        $em->persist(E\TrackableResourceRule::forClass($definition,$wizard,1));
        $mageState=new E\CharacterSessionState($session->getGameSession(),$mage,['hitPoints'=>['current'=>1,'temporary'=>2],'resources'=>[['id'=>'arcane-recovery','currentValue'=>0],['id'=>'spell-slot-1','currentValue'=>0]]]);
        $em->persist($mageState); $em->flush();
        $rest->apply($mageState,E\RestRequest::TYPE_LONG_REST); $em->flush();
        $state=$mageState->getState(); foreach ($state['resources'] as &$pool) if ($pool['id']==='spell-slot-1') --$pool['currentValue']; unset($pool); $mageState->setState($state);
        $recovery=new S\CharacterAction\CharacterArcaneRecoveryActionService($resources,new S\CharacterSpellSlotStateService(new S\CharacterSpellSlotCalculator()),$s['sync']);
        $recovery->recoverSpellSlots($mageState,[1=>1]); $em->flush();
        $values=array_column($mageState->getState()['resources'],'currentValue','id');
        $check($values['spell-slot-1']===3 && $values['arcane-recovery']===0,'Arcane Recovery after long rest restores one slot and consumes one independent use');
        $rest->apply($mageState,E\RestRequest::TYPE_LONG_REST); $em->flush();
        $check(array_column($mageState->getState()['resources'],'currentValue','id')['arcane-recovery']===1,'Next long rest restores Arcane Recovery');

        // Two real worker connections, observer holds the lowest Character lock.
        $run = static function(array $requests, ?int $lockId=null) use ($db,$schema,$ids,&$processes,$check): array {
            $started=[]; $db->beginTransaction();
            $db->fetchOne('SELECT id FROM character WHERE id=? FOR UPDATE',[$lockId ?? $ids[0]]);
            foreach ($requests as $request) {
                $process=proc_open([PHP_BINARY,__FILE__,'worker',$schema,base64_encode(json_encode($request))],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
                if (!is_resource($process)) throw new RuntimeException('Cannot spawn worker');
                $processes[]=$process; stream_set_timeout($pipes[1],25); stream_set_timeout($pipes[2],25);
                $ready=trim((string)fgets($pipes[1]));
                if (!preg_match('/^READY (\d+)$/',$ready,$match)) throw new RuntimeException('Worker not ready: '.$ready.stream_get_contents($pipes[2]));
                $pid=(int)$match[1]; $started[]=[$process,$pipes];
                fwrite($pipes[0],"GO\n"); fflush($pipes[0]);
                $deadline=microtime(true)+8;
                do {
                    $db->fetchOne('SELECT pg_stat_clear_snapshot()');
                    $waiting=$db->fetchOne("SELECT wait_event_type='Lock' FROM pg_stat_activity WHERE pid=?",[$pid]);
                    if ($waiting) break;
                    usleep(10000);
                } while(microtime(true)<$deadline);
                $check((bool)$waiting,'Worker actually waits on shared character lock');
            }
            $db->commit(); $results=[];
            foreach ($started as [$process,$pipes]) {
                fclose($pipes[0]); $out=stream_get_contents($pipes[1]); $err=stream_get_contents($pipes[2]);
                fclose($pipes[1]); fclose($pipes[2]); $exit=proc_close($process);
                $result=json_decode(trim($out),true);
                $check($exit===0 && is_array($result),'Worker completed: '.$out.' '.$err);
                $check($result['transactionOpen']===false,'Worker closed transaction'); $results[]=$result;
            }
            return $results;
        };
        foreach (['same','changed-targets','changed-roll','different-id','crossed'] as $case) {
            $reset(); $cmd=$command('feast',6,[$ids[0],$ids[1],$ids[2]],10); $other=$cmd;
            if ($case==='changed-targets') $other['targetCharacterIds']=[$ids[0],$ids[3]];
            if ($case==='changed-roll') $other['hitPointRoll']=11;
            if ($case==='different-id') $other['requestId']=$command('feast',6)['requestId'];
            if ($case==='crossed') $other=$command('feast',6,[$ids[2],$ids[1],$ids[0]],10,1);
            $results=$run([['session'=>$sessionIds[0],'command'=>$cmd],['session'=>$sessionIds[$case==='crossed'?1:0],'command'=>$other]]);
            $statuses=array_column($results,'status'); sort($statuses);
            $check($statuses===($case==='same'?[200,200]:[200,409]),$case.' expected results without deadlock');
            if ($case==='same') {
                $replays=array_column(array_column($results,'body'),'replayed'); sort($replays);
                $check($replays===[false,true],'Concurrent replay applies once');
            }
            $spent=0; foreach ([0,1] as $i) $spent+=$initial[$i]['resources'][5]['currentValue']-$read($i)['state']['resources'][5]['currentValue'];
            $check($spent===1,$case.' exactly one slot consumed');
            $acceptedIndex=$results[0]['status']===200 ? 0 : 1;
            $acceptedCommand=$acceptedIndex===0 ? $cmd : $other;
            foreach ($ids as $i=>$id) {
                $bonus=in_array($id,$acceptedCommand['targetCharacterIds'],true) ? $acceptedCommand['hitPointRoll'] : 0;
                $check($read($i)['state']['hitPoints']===['current'=>10+$bonus,'temporary'=>3,'maximumAdjustment'=>7+$bonus],$case.' exact HP on target '.$i);
            }
        }

        foreach (['stack','replace'] as $case) {
            $reset();
            $first=$command('aid',2,[$ids[2]]);
            $second=$case==='stack' ? $command('feast',6,[$ids[2]],10,1) : $command('aid',3,[$ids[2]],null,1);
            $results=$run([['session'=>$sessionIds[0],'command'=>$first],['session'=>$sessionIds[1],'command'=>$second]],$ids[2]);
            $check(array_column($results,'status')===[200,200],$case.' concurrent independent casters both accepted');
            $bonus=$case==='stack'?15:10;
            $check($read(2)['state']['hitPoints']===['current'=>10+$bonus,'temporary'=>3,'maximumAdjustment'=>7+$bonus],$case.' no lost target update');
            $check((int)$db->fetchOne('SELECT count(*) FROM character_active_effect')===($case==='stack'?2:1),$case.' no duplicate effects');
            foreach ([$first,$second] as $i=>$cmd) {
                $expected=$initial[$i]['resources']; --$expected[$cmd['castingLevel']-1]['currentValue'];
                $check($read($i)['state']['resources']===$expected,$case.' exact caster cost '.$i);
            }
        }

        // Commit failure after cost, all targets and effects have been flushed.
        $reset(); $cmd=$command('aid',3,[$ids[0],$ids[2],$ids[3]]); $before=$snapshot();
        $db->executeStatement("CREATE FUNCTION reject_hp_commit() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN RAISE EXCEPTION ''synthetic HP commit failure''; RETURN NEW; END;'");
        $db->executeStatement('CREATE CONSTRAINT TRIGGER reject_hp_commit AFTER INSERT ON character_active_effect DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION reject_hp_commit()');
        try { $execute($cmd); throw new RuntimeException('Expected commit failure'); }
        catch (Doctrine\DBAL\Exception $error) { $check(str_contains($error->getMessage(),'synthetic HP commit failure'),'Injected late failure'); }
        $check($snapshot()===$before,'Commit failure rolls back all targets, effects, resource and receipt');
        $check(!$em->isOpen(),'Failed mutated manager is not reusable');
        echo "PASS: $checks HP spell assertions (synthetic schema, execution + real concurrency).\n";
    }
} finally {
    while ($db->isTransactionActive()) $db->rollBack();
    foreach ($processes as $process) if (is_resource($process)) { proc_terminate($process); proc_close($process); }
    if ($created) {
        $db->executeStatement('SET search_path TO public');
        $db->executeStatement('DROP SCHEMA '.$quoted.' CASCADE');
        echo "CLEANUP: disposable schema removed.\n";
    }
    $kernel->shutdown();
}
