<?php
declare(strict_types=1);

define('PERF_LIBRARY', true);
require __DIR__.'/benchmark.php';

// Private temporary HTTP listener: actual Symfony routing/security/controller/JSON stack.
// The local PHP server's request log is discarded, since its URL contains a token.
if (PHP_SAPI === 'cli-server') {
    try {
        if (!is_file('/.dockerenv') || getenv('PERF_HTTP_LOCAL') !== '1'
            || parse_url(getenv('DATABASE_URL'), PHP_URL_HOST) !== 'database'
            || getenv('PGOPTIONS') !== '-c default_transaction_read_only=on'
            || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET'
            || !preg_match('~^/public/characters/[a-f0-9]{64}(?:/spells(?:/ability-choices)?)?$~D', $_SERVER['REQUEST_URI'] ?? '')) {
            http_response_code(403); exit;
        }
        (new Symfony\Component\Dotenv\Dotenv())->bootEnv(dirname(__DIR__, 2).'/.env');
        $kernel = new class('prod', false) extends App\Kernel {
            use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait { configureContainer as private applicationContainer; }
            public function getProjectDir(): string { return dirname(__DIR__, 2); }
            public function getCacheDir(): string { return getenv('PERF_HTTP_CACHE').'/cache'; }
            public function getLogDir(): string { return getenv('PERF_HTTP_CACHE').'/log'; }
            protected function configureContainer(Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator $container): void
            {
                $this->applicationContainer($container);
                $container->services()->set('logger', Psr\Log\NullLogger::class);
            }
        };
        $kernel->boot();
        $db = $kernel->getContainer()->get('doctrine')->getConnection();
        if ($db->fetchOne('SHOW default_transaction_read_only') !== 'on') throw new RuntimeException('Read-only failed');
        // Match the normal front controller, not this router script's filesystem path.
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['PHP_SELF'] = '/index.php';
        $_SERVER['SCRIPT_FILENAME'] = dirname(__DIR__, 2).'/public/index.php';
        $request = Symfony\Component\HttpFoundation\Request::createFromGlobals();
        $response = $kernel->handle($request);
        $response->headers->set('X-Benchmark-Read-Only', 'on');
        $response->send(); $kernel->terminate($request, $response); $kernel->shutdown();
    } catch (Throwable $e) { http_response_code(500); header('X-Benchmark-Failure: '.get_class($e).':'.$e->getLine()); echo '{"error":"local_benchmark_failed"}'; }
    exit;
}

try {
    localOnly();
    $o = getopt('', ['states:', 'iterations:', 'warmup:', 'output:']);
    $states = array_map('intval', explode(',', $o['states'] ?? '27,34,35'));
    $n = (int)($o['iterations'] ?? 5); $warmup = (int)($o['warmup'] ?? 1);
    if ($n < 1 || $n > 100 || $warmup < 1 || min($states) < 1) throw new RuntimeException('Invalid counts');
    $out = $o['output'] ?? dirname(__DIR__, 2).'/var/performance/http-baseline.json';
    if (file_exists($out)) throw new RuntimeException('Output exists');
    if (!is_dir(dirname($out))) mkdir(dirname($out), 0700, true);
    $key = keyMaterial();
    $report = ['schema'=>1, 'kind'=>'http', 'key_id'=>hash('sha256',$key), 'states'=>$states,
        'operations'=>['full','management','ability_choices'], 'modes'=>['http'], 'sources'=>sourceFingerprint(),
        'created_utc'=>gmdate(DATE_ATOM), 'php'=>PHP_VERSION, 'dataset_before'=>datasetFingerprint(),
        'method'=>'Local loopback PHP built-in server; Symfony prod debug=false; PGOPTIONS read-only; no SQL instrumentation; CPU/memory not measured on server', 'results'=>[]];
    $params = (new Doctrine\DBAL\Tools\DsnParser(['postgresql'=>'pdo_pgsql']))->parse(getenv('DATABASE_URL'));
    $db = Doctrine\DBAL\DriverManager::getConnection($params);
    $db->beginTransaction(); $db->executeStatement('SET TRANSACTION READ ONLY');
    $tokens = [];
    foreach ($states as $id) {
        $token = $db->fetchOne('SELECT access_token FROM character_session_state WHERE id=? AND participating', [$id]);
        if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/D', $token)) throw new RuntimeException('Invalid state');
        $tokens[$id] = $token;
    }
    $db->rollBack(); $db->close();
    // Reserve a free loopback port; listener is never exposed outside the container.
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if (!$socket) throw new RuntimeException('Port allocation failed');
    $address = stream_socket_get_name($socket, false); fclose($socket);
    $env = getenv();
    unset($env['PHP_CLI_SERVER_WORKERS']);
    $env = array_merge($env, ['PERF_HTTP_LOCAL'=>'1', 'PGOPTIONS'=>'-c default_transaction_read_only=on',
        'APP_ENV'=>'prod', 'APP_DEBUG'=>'0', 'PERF_HTTP_CACHE'=>'/tmp/jdr-perf-http-'.bin2hex(random_bytes(6))]);
    $server = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=0', '-S', $address, __FILE__],
        [0=>['file','/dev/null','r'], 1=>['file','/dev/null','w'], 2=>['file','/dev/null','w']], $pipes, dirname(__DIR__, 2), $env);
    if (!is_resource($server)) throw new RuntimeException('Server unavailable');
    try {
        $ready = false;
        for ($i=0; $i<50; ++$i) {
            $probe = @stream_socket_client('tcp://'.$address, $errno, $error, .1);
            if ($probe) { fclose($probe); $ready=true; break; } usleep(100000);
        }
        if (!$ready) throw new RuntimeException('Server not ready');
        foreach ($tokens as $id=>$token) foreach (['full'=>'', 'management'=>'/spells', 'ability_choices'=>'/spells/ability-choices'] as $op=>$suffix) {
            fwrite(STDERR, "HTTP state=$id operation=$op\n");
            $samples=[]; $baseline=null; $stable=true;
            for ($i=-$warmup; $i<$n; ++$i) {
                $context = stream_context_create(['http'=>['method'=>'GET', 'timeout'=>120, 'ignore_errors'=>true,
                    'follow_location'=>0, 'header'=>"Accept: application/json\r\nConnection: close\r\n"]]);
                $start=hrtime(true);
                $body=@file_get_contents('http://'.$address.'/public/characters/'.$token.$suffix, false, $context);
                $elapsed=(hrtime(true)-$start)/1e6;
                $headers=$http_response_header ?? [];
                if ($body === false || !preg_match('~^HTTP/\S+ 200\b~', $headers[0] ?? '')
                    || !in_array('x-benchmark-read-only: on', array_map('strtolower',$headers), true)) {
                    foreach ($headers as $header) if (str_starts_with(strtolower($header), 'x-benchmark-failure:') || str_starts_with($header, 'HTTP/')) fwrite(STDERR,$header."\n");
                    throw new RuntimeException('HTTP failure');
                }
                $decoded=json_decode($body,true,512,JSON_THROW_ON_ERROR);
                $current=snapshot($decoded,$key);
                if ($baseline===null) $baseline=$current; elseif ($baseline!==$current) $stable=false;
                if ($i>=0) $samples[]=['wall_ms'=>$elapsed,'json_bytes'=>strlen($body)];
            }
            $summary=[];
            foreach (array_keys($samples[0]) as $metric) $summary[$metric]=stats(array_column($samples,$metric));
            $report['results'][]=['state'=>$id,'operation'=>$op,'mode'=>'http','warmup'=>$warmup,'stable'=>$stable,
                'summary'=>$summary,'samples'=>$samples,'snapshot'=>$baseline];
            file_put_contents($out,json($report),LOCK_EX);
        }
    } finally { proc_terminate($server); proc_close($server); }
    $report['dataset_after']=datasetFingerprint();
    $report['dataset_unchanged']=$report['dataset_before']===$report['dataset_after'];
    $report['complete']=true;
    $report['functional_stable_across_modes']=$report['dataset_unchanged'] && !in_array(false,array_column($report['results'],'stable'),true);
    file_put_contents($out,json($report),LOCK_EX);
    echo json(['complete'=>true,'groups'=>count($report['results']),'stable'=>$report['functional_stable_across_modes']]);
    exit($report['functional_stable_across_modes'] ? 0 : 2);
} catch (Throwable $e) {
    fwrite(STDERR,'HTTP benchmark failed ('.get_class($e).', line '.$e->getLine().'); no URL or response logged.'."\n"); exit(1);
}
