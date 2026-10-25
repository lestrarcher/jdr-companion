<?php

declare(strict_types=1);

// Real workers, committed synthetic fixtures and an observer connection, as in
// test-player-concurrency.php. No HTTP server, public data writes or shared sequences.
use App\Entity as E;
use App\Service as S;
use App\Repository as R;
use App\Enum as V;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/training-presenter-fixture.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv(__DIR__.'/../.env');
$kernel=new App\Kernel('dev',true); $kernel->boot();
$registry=$kernel->getContainer()->get('doctrine');
$em=$registry->getManager(); $db=$em->getConnection();
$worker=($argv[1]??'')==='worker';
$schema=$worker?$argv[2]:'test_spell_concurrency_'.bin2hex(random_bytes(8));
if (!preg_match('/\Atest_spell_concurrency_[a-f0-9]{16}\z/',$schema)) throw new RuntimeException('Unsafe schema');
$quoted=$db->quoteIdentifier($schema);
$processes=[]; $created=false; $checks=0;
$check=static function(bool $ok,string $message) use (&$checks):void {
    if (!$ok) throw new RuntimeException($message);
    ++$checks;
};

function spellConcurrencyServices($registry): array {
    $em=$registry->getManager(); $db=$em->getConnection();
    $ability=new S\CharacterAbilityCalculator();
    $features=new S\CharacterFeatureResolver(new R\CharacterFeatureRuleRepository($registry));
    $resources=new S\CharacterResourceResolver(new R\TrackableResourceRuleRepository($registry),$ability,$features);
    $slots=new S\CharacterSpellSlotCalculator(); $slotState=new S\CharacterSpellSlotStateService($slots);
    $catalogue=new S\SpellCatalogue($db);
    $access=new S\CharacterSpellAccessResolver($catalogue,new S\SpellSubclassGrants($db,$catalogue),$resources);
    $acquisitions=new S\CharacterSpellAcquisitions($em,$access,$ability);
    $directory=new S\PlayerSpellDirectory($db,$em,new S\CharacterFeatureSpellService($db,$features,$ability),$ability,
        $access,$acquisitions,new S\CharacterSpellPreparation($acquisitions,$ability),$catalogue,
        new S\CharacterSpellAvailabilityContext($slots,$slotState,$resources,new R\TrackableResourceDefinitionRepository($registry)));
    $executor=new S\CharacterSpellCastExecutor($em,new S\CharacterSpellCastCommandValidator($directory));
    $hp=new S\CharacterHitPointCalculator($ability); $hpState=new S\CharacterHitPointStateService($hp);
    $states=new R\CharacterSessionStateRepository($registry);
    $sync=new S\CharacterSessionStateSynchronizer($hp,$hpState,$resources,$states,$slotState);
    $profile=new S\CharacterProfileSerializer($ability,$features,$resources,$hp,$slots,
        new S\CharacterActionResolver(new R\CharacterActionClassRuleRepository($registry)),trainingPresenterForRegistry($registry));
    $serializer=new S\CharacterSessionStateSerializer($profile,$hpState,new R\CharacterActiveEffectRepository($registry),$slotState,$directory);
    $controller=new App\Controller\CharacterSessionStateController($profile,$sync,$serializer);
    $controller->setContainer(new Symfony\Component\DependencyInjection\Container());
    return compact('executor','controller','states','sync','hpState','catalogue','directory');
}

try {
    if (!$worker) { $db->executeStatement('CREATE SCHEMA '.$quoted); $created=true; }
    $db->executeStatement('SET search_path TO '.$quoted);
    $db->executeStatement("SET statement_timeout = '15s'");
    $db->executeStatement("SET lock_timeout = '12s'");
    $db->executeStatement("SET idle_in_transaction_session_timeout = '30s'");
    $s=spellConcurrencyServices($registry);
    if ($worker) {
        $data=json_decode(base64_decode($argv[3]),true,512,JSON_THROW_ON_ERROR);
        $db->fetchOne("SELECT set_config('application_name', ?, false)",[$schema.'_'.$data['worker']]);
        $session=$em->find(E\CharacterSessionState::class,$data['session']);
        // Deliberately preload stale entities before the barrier.
        $session->getCharacter()->getClassLevels()->toArray(); $session->getState();
        echo 'READY '.$db->fetchOne('SELECT pg_backend_pid()')."\n"; flush();
        stream_set_timeout(STDIN,20);
        if (trim((string)fgets(STDIN))!=='GO') throw new RuntimeException('Worker barrier timeout');
        try {
            if ($data['action']==='cast') {
                $result=['status'=>200,'body'=>$s['executor']->execute($session,$data['body'])];
            } else {
                $request=Symfony\Component\HttpFoundation\Request::create('/','PATCH',[],[],[],['CONTENT_TYPE'=>'application/json'],json_encode($data['body'],JSON_THROW_ON_ERROR));
                $response=$s['controller']->updatePublic($session->getAccessToken(),$request,
                    new S\PlayerCharacterAccess($s['states']),$em,new S\PlayerCharacterStateUpdater($s['sync'],$s['hpState']),
                    new S\CharacterInspirationService($em,$s['states']));
                $result=['status'=>$response->getStatusCode(),'body'=>json_decode($response->getContent(),true,512,JSON_THROW_ON_ERROR)];
            }
        } catch (HttpExceptionInterface $error) {
            $result=['status'=>$error->getStatusCode(),'message'=>$error->getMessage()];
        } catch (Throwable $error) {
            $result=['status'=>500,'message'=>$error::class.': '.$error->getMessage()];
        }
        $result['transactionOpen']=$db->isTransactionActive();
        echo json_encode($result,JSON_THROW_ON_ERROR)."\n";
    } else {
        (new Doctrine\ORM\Tools\SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());
        // These DBAL-only tables are read by the directory. No copied rows/defaults.
        foreach (['feature_spell_grant','character_feature_spell'] as $table) {
            $db->executeStatement('CREATE TABLE '.$table.' (LIKE public.'.$table.')');
        }
        $owner=(new E\User())->setEmail('spell-concurrency@example.invalid')->setPassword('unused')->setRoles([S\SpellCatalogue::ADMIN_ROLE]);
        $campaign=new E\Campaign($owner,'spell-test','Spell test');
        $class=new E\CharacterClass('wizard','Wizard',6,20,V\SpellcastingProgressionType::Full);
        $character=new E\Character($campaign,'spell-test','Spell test',E\Character::TYPE_PLAYER);
        $level=new E\CharacterClassLevel($character,$class,1,null,6,V\HitPointGainMethod::FirstLevel);
        $character->addClassLevel($level);
        $policy=new E\SpellcastingPolicyDefinition($class,new App\Dto\SpellcastingPolicy('wizard-test',1,V\Ability::Intelligence,'spellbook',
            array_fill(0,20,['maxSpellLevel'=>1,'cantrips'=>2,'known'=>0,'bookAdditions'=>2,'replacements'=>0,'preparationBase'=>1]),'Fixture'));
        $game=(new E\GameSession($campaign,'spell-test','Spell test'))->setStatus(E\GameSession::STATUS_LIVE);
        foreach ([$owner,$campaign,$class,$character,$level,$policy,$game] as $entity) $em->persist($entity);
        $em->flush();
        $sources=[]; $spellIds=[];
        foreach (['spell'=>1,'cantrip'=>0] as $kind=>$spellLevel) {
            $spell=$s['catalogue']->create(['slug'=>'concurrent-'.$kind,'name'=>$kind,'level'=>$spellLevel,'school'=>'divination',
                'castingTime'=>'1 action','range'=>'Self','duration'=>'1 minute','description'=>'Fixture','ritual'=>$spellLevel===1,
                'classIds'=>[$class->getId()]],$owner,true);
            $acquisition=new E\CharacterSpellAcquisition($character,$em->find(E\SpellDefinition::class,$spell['id']),$policy,$level,$owner,
                'manual-reconciliation',$spellLevel===0?'cantrips':'spellbook','Fixture');
            $em->persist($acquisition); $em->flush();
            $sources[$kind]='acquisition:'.$acquisition->getId(); $spellIds[$kind]=$spell['id'];
        }
        $initial=['hitPoints'=>['current'=>6,'temporary'=>0],'hitDice'=>[['id'=>'d6','current'=>1]],'progressions'=>[],
            'resources'=>[['id'=>'spell-slot-1','currentValue'=>2,'flexibleCastingBonus'=>1]],
            'preparedSpells'=>[$policy->getId()=>[$spellIds['spell']]],'spellPreparationAllowed'=>false];
        $session=new E\CharacterSessionState($game,$character,$initial); $em->persist($session); $em->flush();
        $sessionId=$session->getId();
        $observerPid=(int)$db->fetchOne('SELECT pg_backend_pid()');
        $read=static function() use($db,$sessionId):array {
            $row=$db->fetchAssociative('SELECT state,revision,updated_at FROM character_session_state WHERE id=?',[$sessionId]);
            $row['state']=json_decode($row['state'],true,512,JSON_THROW_ON_ERROR); $row['revision']=(int)$row['revision']; return $row;
        };
        $reset=static function() use($em,$session,$initial):void { $em->refresh($session); $session->setState($initial); $em->flush(); };
        $run=static function(string $label,array $requests) use($db,$schema,$sessionId,$observerPid,&$processes,$check):array {
            $started=[];
            $db->beginTransaction();
            // First worker owns Character + shared GameSession and waits here;
            // second worker then waits on the first worker's Character lock.
            $db->fetchOne('SELECT id FROM character_session_state WHERE id=? FOR UPDATE',[$sessionId]);
            foreach ($requests as $i=>$request) {
                $data=$request+['session'=>$sessionId,'worker'=>(string)$i];
                $process=proc_open([PHP_BINARY,__FILE__,'worker',$schema,base64_encode(json_encode($data,JSON_THROW_ON_ERROR))],
                    [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
                if (!is_resource($process)) throw new RuntimeException('Worker failed to start');
                $processes[]=$process;
                stream_set_timeout($pipes[1],25); stream_set_timeout($pipes[2],25);
                $ready=trim((string)fgets($pipes[1]));
                if (!preg_match('/^READY (\d+)$/',$ready,$match)) throw new RuntimeException('Worker not ready: '.$ready.stream_get_contents($pipes[2]));
                $pid=(int)$match[1]; $check($pid!==$observerPid,'Observer is an independent third connection');
                $check(!in_array($pid,array_column($started,2),true),'Workers use distinct PostgreSQL connections');
                $started[]=[$process,$pipes,$pid];
            }
            foreach ($started as $i=>[$process,$pipes,$pid]) {
                fwrite($pipes[0],"GO\n"); fflush($pipes[0]);
                $blocker=$i===0?$observerPid:$started[0][2];
                $deadline=microtime(true)+8;
                do {
                    $db->fetchOne('SELECT pg_stat_clear_snapshot()');
                    $activity=$db->fetchAssociative("SELECT query, wait_event_type, ? = ANY(pg_blocking_pids(pid)) AS blocked FROM pg_stat_activity WHERE pid=?",[$blocker,$pid]);
                    if ($activity && $activity['wait_event_type']==='Lock' && in_array($activity['blocked'],[true,1,'t','1'],true)) break;
                    usleep(10000);
                } while (microtime(true)<$deadline);
                $check($activity && $activity['wait_event_type']==='Lock' && in_array($activity['blocked'],[true,1,'t','1'],true),$label.': worker actually blocked by expected connection');
                $table=$i===0?'character_session_state':'character';
                $check((bool)preg_match('/FROM\s+"?'.$table.'"?\s/i',$activity['query']) && str_contains(strtoupper($activity['query']),'FOR UPDATE'),$label.': observed lock target '.$table);
                if ($i===0) {
                    // RowShareLock on these relations comes from the prior SELECT
                    // FOR UPDATE / FOR SHARE, not ordinary entity hydration.
                    $lockedRelations=(int)$db->fetchOne("SELECT count(DISTINCT relation) FROM pg_locks WHERE pid=? AND granted AND mode='RowShareLock' AND relation IN ('character'::regclass,'game_session'::regclass)",[$pid]);
                    $check($lockedRelations===2,$label.': character and game locks acquired before session lock');
                }
                echo "$label: worker $pid blocked by $blocker on $table\n";
            }
            $db->commit();
            $results=[];
            foreach ($started as [$process,$pipes,$pid]) {
                fclose($pipes[0]); $out=stream_get_contents($pipes[1]); $err=stream_get_contents($pipes[2]);
                $timedOut=stream_get_meta_data($pipes[1])['timed_out'];
                fclose($pipes[1]); fclose($pipes[2]);
                if ($timedOut) proc_terminate($process);
                $exit=proc_close($process);
                $result=json_decode(trim($out),true);
                $check(!$timedOut && $exit===0 && is_array($result) && ($result['status']??500)!==500,$label.': worker completed: '.$out.' '.$err);
                $check($result['transactionOpen']===false,$label.': no transaction left open');
                $results[]=$result;
            }
            $check(!$db->isTransactionActive(),$label.': observer transaction closed');
            return $results;
        };
        $uuid='a0000000-0000-4000-8000-000000000001';
        $command=static fn(int $revision):array=>['revision'=>$revision,'requestId'=>$uuid,'sourceKey'=>$sources['spell'],'method'=>'standard-slot','castingLevel'=>1];
        $cast=static fn(array $body):array=>['action'=>'cast','body'=>$body];
        $patch=static fn(int $revision):array=>['action'=>'patch','body'=>['revision'=>$revision,'state'=>['hitPoints'=>['current'=>3]]]];
        foreach (['same','different-id','conflict','cantrip','ritual'] as $scenario) {
            $reset(); $before=$read(); $cmd=$command($before['revision']);
            if ($scenario==='cantrip') $cmd=array_replace($cmd,['sourceKey'=>$sources['cantrip'],'method'=>'cantrip','castingLevel'=>0]);
            if ($scenario==='ritual') $cmd['method']='ritual';
            $other=$cmd;
            if ($scenario==='different-id') $other['requestId']='a0000000-0000-4000-8000-000000000002';
            if ($scenario==='conflict') $other['method']='ritual'; // Valid but different content.
            $results=$run($scenario,[$cast($cmd),$cast($other)]); $after=$read();
            $check($results[0]['status']===200 && $results[0]['body']['replayed']===false,$scenario.': first acceptance');
            if (in_array($scenario,['different-id','conflict'],true)) {
                $check($results[1]['status']===409 && $results[1]['message']===($scenario==='conflict'?'request_id_conflict':'stale_revision'),$scenario.': exact conflict');
            } else {
                $check($results[1]['status']===200 && $results[1]['body']['replayed']===true && $results[1]['body']['cast']===$results[0]['body']['cast'],$scenario.': historical replay');
                $check($results[1]['body']['revision']===$after['revision'],$scenario.': replay revision current');
            }
            $expected=$before['state']['resources'];
            if (!in_array($scenario,['cantrip','ritual'],true)) --$expected[0]['currentValue'];
            $check($after['state']['resources']===$expected,$scenario.': exact counters and metadata');
            $check($after['revision']===$before['revision']+1,$scenario.': exactly one revision');
            $receipts=S\SpellCastReceipts::validateState($after['state']);
            $check(count($receipts)===1 && $receipts[$uuid]['cast']===$results[0]['body']['cast'],$scenario.': one persisted receipt');
        }
        // Existing PATCH controller, directly invoked (no new HTTP endpoint).
        foreach (['cast-first','patch-first'] as $order) {
            $reset(); $before=$read(); $cmd=$command($before['revision']);
            $requests=[$cast($cmd),$patch($before['revision'])];
            if ($order==='patch-first') $requests=array_reverse($requests);
            $results=$run($order,$requests); $after=$read();
            $check(array_column($results,'status')===[200,409],$order.': one accepted update');
            $check($after['revision']===$before['revision']+1,$order.': one revision');
            $check($after['state']['resources'][0]['currentValue']===($order==='cast-first'?1:2),$order.': no lost resource update');
            $check($after['state']['hitPoints']['current']===($order==='cast-first'?6:3),$order.': no lost HP update');
            $check(count(S\SpellCastReceipts::validateState($after['state']))===($order==='cast-first'?1:0),$order.': receipt matches accepted cast');
            if ($order==='cast-first') {
                $run('patch-after-cast',[$patch($after['revision'])]);
                $mutated=$read();
                $replay=$run('replay-after-patch',[$cast($cmd)]);
                $check($replay[0]['body']['replayed']===true && $replay[0]['body']['cast']===$results[0]['body']['cast'],'Replay after PATCH retains historical cast');
                $check($read()===$mutated,'Replay after PATCH preserves current state, revision and timestamp');
                $check($replay[0]['body']['revision']===$mutated['revision'] && $replay[0]['body']['state']['hitPoints']['current']===3,'Replay response reflects later PATCH');
            }
        }
        echo "PASS: $checks spell concurrency assertions; persisted results checked by observer.\n";
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
