<?php
declare(strict_types=1);

// Real HTTP / dev kernel / Doctrine backtraces / 256 MiB, against a disposable synthetic schema.
require __DIR__.'/../vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv(__DIR__.'/../.env');
putenv('SHELL_VERBOSITY=-1'); $_SERVER['SHELL_VERBOSITY']=$_ENV['SHELL_VERBOSITY']='-1';
$serving=PHP_SAPI==='cli-server';
$kernel=new App\Kernel('dev',$serving); $kernel->boot();
$registry=$kernel->getContainer()->get('doctrine'); $em=$registry->getManager(); $db=$em->getConnection();
if ($serving) {
    $schema=getenv('HISTORICAL_TEST_SCHEMA');
    if (!is_string($schema) || !preg_match('/^historical_http_[a-f0-9]{16}$/D',$schema)) throw new RuntimeException('Isolated schema required');
    $db->executeStatement('SET search_path TO '.$schema);
    $holder=null;
    foreach ($db->getConfiguration()->getMiddlewares() as $middleware) if ($middleware instanceof Doctrine\Bundle\DoctrineBundle\Middleware\DebugMiddleware) {
        $holder=(new ReflectionProperty($middleware,'debugDataHolder'))->getValue($middleware);
    }
    if (!$holder instanceof Doctrine\Bundle\DoctrineBundle\Middleware\BacktraceDebugDataHolder) throw new RuntimeException('Doctrine backtrace collector must remain enabled');
    $holder->reset(); memory_reset_peak_usage(); $reserve=str_repeat('x',1024*1024); $start=microtime(true);
    register_shutdown_function(static function() use($holder,&$reserve,$start):void {
        $reserve=null; $peak=memory_get_peak_usage(true); $used=memory_get_usage(false);
        $data=(new ReflectionProperty(Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder::class,'data'))->getValue($holder);
        $traces=(new ReflectionProperty($holder,'backtraces'))->getValue($holder);
        $count=0; $memberships=0; $frames=0; $traceCount=0;
        foreach($data as $queries) foreach($queries as $query) { ++$count; if(str_contains($query['sql'],'JOIN character_class c ON c.id = e.character_class_id')) ++$memberships; }
        foreach($traces as $list) foreach($list as $trace) { ++$traceCount; $frames+=count($trace); }
        unset($data,$traces,$queries,$query,$list,$trace); $holder->reset(); gc_collect_cycles();
        file_put_contents(getenv('HISTORICAL_TEST_METRICS'),json_encode(['method'=>$_SERVER['REQUEST_METHOD'],'endpoint'=>basename(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)),'peakMiB'=>round($peak/1048576,2),'sql'=>$count,'membershipSql'=>$memberships,'backtraces'=>$traceCount,'frames'=>$frames,'collectorReleasedMiB'=>round(($used-memory_get_usage(false))/1048576,2),'seconds'=>round(microtime(true)-$start,2),'fatal'=>(error_get_last()['type']??null)===E_ERROR ? error_get_last()['message'] : null],JSON_THROW_ON_ERROR).PHP_EOL,FILE_APPEND);
    });
    // Match the normal public/index.php front controller, not this router's tools/ path.
    $_SERVER['SCRIPT_FILENAME']=realpath(__DIR__.'/../public/index.php');
    $_SERVER['SCRIPT_NAME']=$_SERVER['PHP_SELF']='/index.php';
    $request=Symfony\Component\HttpFoundation\Request::createFromGlobals();
    $response=$kernel->handle($request); $response->send(); $kernel->terminate($request,$response);
    return;
}

$schema='historical_http_'.bin2hex(random_bytes(8)); $server=null;
$metrics=tempnam(sys_get_temp_dir(),'historical-metrics-'); $log=tempnam(sys_get_temp_dir(),'historical-server-');
$check=static function(bool $ok,string $message):void { if(!$ok) throw new RuntimeException($message); };
$publicSnapshot=static function() use($db):array {
    $snapshot=['sequences'=>$db->fetchAllAssociative("SELECT sequencename,last_value FROM pg_sequences WHERE schemaname='public' ORDER BY sequencename")];
    foreach(['character_spell_acquisition','character_spell_choice','character_session_state'] as $table) {
        $snapshot[$table]=$db->fetchOne("SELECT md5(COALESCE(string_agg(row_to_json(t)::text,'' ORDER BY row_to_json(t)::text),'')) FROM public.".$table.' t');
    }
    return $snapshot;
};
$before=$publicSnapshot();
try {
    $db->executeStatement('CREATE SCHEMA '.$schema); $db->executeStatement('SET search_path TO '.$schema);
    foreach((new Doctrine\ORM\Tools\SchemaTool($em))->getCreateSchemaSql($em->getMetadataFactory()->getAllMetadata()) as $sql) $db->executeStatement($sql);
    foreach(['feature_spell_grant','character_feature_spell'] as $table) $db->executeStatement('CREATE TABLE '.$table.' (LIKE public.'.$table.' INCLUDING IDENTITY INCLUDING CONSTRAINTS INCLUDING INDEXES)');
    $owner=(new App\Entity\User())->setEmail('historical-http@example.invalid')->setPassword('unused')->setRoles(['ROLE_REFERENCE_ADMIN']);
    $campaign=new App\Entity\Campaign($owner,'historical-http','Fixture');
    $sorcerer=new App\Entity\CharacterClass('http-sorcerer','HTTP Sorcerer',6,20);
    $warlock=new App\Entity\CharacterClass('http-warlock','HTTP Warlock',8,20);
    foreach([$owner,$campaign,$sorcerer,$warlock] as $entity) $em->persist($entity); $em->flush();
    $catalogue=new App\Service\SpellCatalogue($db);
    $resources=new App\Service\CharacterResourceResolver(new App\Repository\TrackableResourceRuleRepository($registry),new App\Service\CharacterAbilityCalculator(),new App\Service\CharacterFeatureResolver(new App\Repository\CharacterFeatureRuleRepository($registry)));
    $service=new App\Service\CharacterSpellAcquisitions($em,new App\Service\CharacterSpellAccessResolver($catalogue,new App\Service\SpellSubclassGrants($db,$catalogue),$resources));
    $spec=App\Service\Dnd2014AdvancedSpellPolicies::ordinary($sorcerer,App\Service\Dnd2014SpellPolicies::get('sorcerer-2014'));
    $policy=$service->defineAdvancedPolicy($sorcerer,$spec['policy'],$owner,$spec['rules']);
    $spec=App\Service\Dnd2014AdvancedSpellPolicies::warlock($warlock); $wp=$service->defineAdvancedPolicy($warlock,$spec['policy'],$owner,$spec['rules']);
    $seed=$catalogue->create(['slug'=>'http-spell-0','name'=>'HTTP spell 000','level'=>0,'school'=>'abjuration','castingTime'=>'Fixture','range'=>'Fixture','duration'=>'Fixture','description'=>str_repeat('Synthetic description. ',40),'classIds'=>[$sorcerer->getId(),$warlock->getId()]],$owner,true);
    $columns=array_values(array_diff(array_keys($db->fetchAssociative('SELECT * FROM spell_definition WHERE id=?',[$seed['id']])),['id']));
    $expressions=array_map(static fn($column)=>match($column) {'slug'=>"'http-spell-' || n",'name'=>"'HTTP spell ' || lpad(n::text,3,'0')",'level'=>'n % 10',default=>'s.'.$column},$columns);
    $db->executeStatement('INSERT INTO spell_definition ('.implode(',',$columns).') SELECT '.implode(',',$expressions).' FROM spell_definition s CROSS JOIN generate_series(1,499) n WHERE s.id=?',[$seed['id']]);
    $db->executeStatement('INSERT INTO spell_class_list_entry (spell_id,character_class_id) SELECT s.id,c.id FROM spell_definition s CROSS JOIN character_class c WHERE s.id<>?',[$seed['id']]);
    $pool=[]; foreach($db->fetchAllAssociative('SELECT id,level FROM spell_definition ORDER BY id') as $spell) $pool[$spell['level']][]=$spell;
    $character=new App\Entity\Character($campaign,'historical-http','Historical HTTP',App\Entity\Character::TYPE_PLAYER); $em->persist($character); $levels=[];
    for($i=1;$i<=13;++$i) { $l=new App\Entity\CharacterClassLevel($character,$i<=2?$warlock:$sorcerer,$i); $character->addClassLevel($l); $em->persist($l); $levels[]=$l; } $em->flush();
    $rules=$db->fetchAllKeyValue('SELECT rule_key,id FROM spell_policy_choice_rule WHERE policy_id=?',[$policy->getId()]); $rule=(int)$rules['ordinary'];
    foreach([$pool[3][0],...array_slice($pool[0],0,6)] as $i=>$spell) {
        $note=$i===0?'Pinned level eleven':App\Service\CharacterSpellHistoricalChoices::NOTE.json_encode(['command'=>str_repeat('a',64),'witness'=>['originLevel'=>[10,4,1,1,1,1][$i-1],'learnedAt'=>[10,4,1,1,1,1][$i-1],'placeholderSpellId'=>null,'replacementBudget'=>null]]);
        $acquisition=new App\Entity\CharacterSpellAcquisition($character,$em->getReference(App\Entity\SpellDefinition::class,$spell['id']),$policy,$levels[12],$owner,$i===0?'level-history':'manual-reconciliation',$i===0?'known':'cantrips',$note);
        $em->persist($acquisition); $em->flush();
        $db->executeStatement('INSERT INTO character_spell_choice (acquisition_id,rule_id,opportunity_level_id,decided_by_id,reason,origin_known,decided_at) VALUES (?,?,?,?,?,?,CURRENT_TIMESTAMP)',[$acquisition->getId(),$i===0?$rule:$rules['cantrips'],$i===0?$levels[12]->getId():null,$owner->getId(),$note,$i===0?'true':'false']);
    }
    $game=(new App\Entity\GameSession($campaign,'historical-http','Fixture'))->setStatus('live'); $session=new App\Entity\CharacterSessionState($game,$character,['resources'=>[],'progressions'=>[]]); $em->persist($game); $em->persist($session); $em->flush();
    $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error); $check($socket!==false,'Allocate HTTP port'); $address=stream_socket_get_name($socket,false); fclose($socket);
    $env=getenv(); $env['HISTORICAL_TEST_SCHEMA']=$schema; $env['HISTORICAL_TEST_METRICS']=$metrics;
    $server=proc_open([PHP_BINARY,'-d','memory_limit=256M','-S',$address,__FILE__],[0=>['pipe','r'],1=>['file',$log,'a'],2=>['file',$log,'a']],$pipes,__DIR__.'/..',$env); $check(is_resource($server),'Start HTTP server'); fclose($pipes[0]);
    for($i=0;$i<100;++$i) { $ready=@stream_socket_client('tcp://'.$address,$errno,$error,0.1); if($ready) { fclose($ready); break; } usleep(100000); } $check((bool)$ready,'Server ready');
    $path='/public/characters/'.$session->getAccessToken().'/spells';
    $request=static function(string $method,string $suffix,?array $payload=null) use($address,$path,$metrics):array {
        $context=stream_context_create(['http'=>['method'=>$method,'header'=>"Content-Type: application/json\r\nAccept: application/json",'content'=>$payload===null?'':json_encode($payload),'ignore_errors'=>true,'timeout'=>120]]);
        $body=file_get_contents('http://'.$address.$path.$suffix,false,$context); $status=$http_response_header[0]??'no response';
        echo $method.' '.($suffix?:'/spells').' '.$status.PHP_EOL;
        // Each response is closed before the next request, so metrics remain request-specific.
        $decoded=json_decode($body?:'',true);
        if (!is_array($decoded)) echo substr($body?:'',0,1800).PHP_EOL;
        return [str_contains($status,' 200 '),$decoded];
    };
    [$ok,$view]=$request('GET',''); $check($ok && is_array($view),'Directory loads');
    [$ok,$preview]=$request('GET','/historical'); $check($ok && is_array($preview),'Historical preview loads');
    $source=array_values(array_filter($preview['classes'],static fn($c)=>$c['policyId']===$policy->getId()))[0];
    $selected=[$pool[1][0],$pool[1][1],$pool[2][0],$pool[3][1],$pool[3][2],...array_slice($pool[4],0,3),$pool[5][0],$pool[5][1],$pool[6][0]];
    $command=['policyId'=>$policy->getId(),'revision'=>$preview['revision'],'confirmed'=>true,'expectedAcquisitionIds'=>$source['expectedAcquisitionIds'],'choices'=>array_map(static fn($s)=>['spellId'=>$s['id'],'ruleId'=>$rule],$selected)];
    [$ok,$result]=$request('POST','/historical-completion',$command); $check($ok && is_array($result),'Historical POST completes without OOM');
    $check($result['revision']===$preview['revision']+1,'One revision increment');
    $check((int)$db->fetchOne('SELECT count(*) FROM character_spell_acquisition WHERE character_id=? AND policy_definition_id=?',[$character->getId(),$policy->getId()])===18,'Exactly eleven new acquisitions');
    $command['revision']=$result['revision']; [$ok,$replay]=$request('POST','/historical-completion',$command); $check($ok && $replay['revision']===$result['revision'],'Replay is idempotent');
    [$ok,$after]=$request('GET','/historical'); $check($ok,'Preview after completion');
    $source=array_values(array_filter($after['classes'],static fn($c)=>$c['policyId']===$policy->getId()))[0]; $check(array_sum(array_column($source['categories'],'remaining'))===0,'No remaining sorcerer choices');
    $samples=array_map(static fn($line)=>json_decode($line,true,512,JSON_THROW_ON_ERROR),file($metrics,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES));
    $check(count($samples)===5,'Every HTTP request has memory and SQL metrics');
    foreach($samples as $sample) {
        $check($sample['fatal']===null && $sample['peakMiB']<128,'HTTP request stays safely below 256 MiB');
        $check($sample['sql']<2000,'No catalogue N+1 amplification in HTTP');
        $check($sample['backtraces']===$sample['sql'] && $sample['frames']>0,'Backtraces remain enabled for every measured query');
    }
    echo "PASS: real HTTP, dev/debug, 256M; synthetic sorcerer 11 / warlock 2, 500 spells.\n";
} finally {
    if(is_resource($server)) { proc_terminate($server); proc_close($server); }
    echo file_get_contents($metrics);
    $db->executeStatement('SET search_path TO public'); $db->executeStatement('DROP SCHEMA IF EXISTS '.$schema.' CASCADE');
    $check($publicSnapshot()===$before,'Public acquisitions, choices, session states and sequences unchanged');
    unlink($metrics); unlink($log); $kernel->shutdown();
}
