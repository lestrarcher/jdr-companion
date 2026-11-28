<?php
declare(strict_types=1);

// Local CLI only. Never registered as a Symfony service or route.
require dirname(__DIR__, 2).'/vendor/autoload.php';

final class PerfCounters
{
    public static array $calls = [];
}

function localOnly(): void
{
    if (PHP_SAPI !== 'cli' || !is_file('/.dockerenv')) throw new RuntimeException('Docker CLI required');
    $url = getenv('DATABASE_URL') ?: '';
    if (parse_url($url, PHP_URL_HOST) !== 'database') throw new RuntimeException('Only Compose database host allowed');
}

function json(array $data): string
{
    return json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
}

function keyMaterial(): string
{
    $path = '/tmp/jdr-performance-hmac.key';
    if (!is_file($path)) {
        $handle = fopen($path, 'x');
        if ($handle === false) throw new RuntimeException('Cannot create local key');
        chmod($path, 0600);
        fwrite($handle, random_bytes(32)); fclose($handle);
    }
    $key = file_get_contents($path);
    if ($key === false || strlen($key) !== 32) throw new RuntimeException('Invalid local key');
    return $key;
}

// Preserve list order and every scalar, including timestamps. Sort maps only.
// HMAC every string AND map key, so custom names/slugs/notes cannot leak.
function snapshot(mixed $value, string $key): mixed
{
    if (is_string($value)) return ['hmac' => hash_hmac('sha256', $value, $key)];
    if (!is_array($value)) return $value;
    if (array_is_list($value)) return array_map(fn($v) => snapshot($v, $key), $value);
    $out = [];
    foreach ($value as $k => $v) {
        if (in_array($k, ['accessToken', 'spellCastReceipts'], true)) continue;
        $out[hash_hmac('sha256', (string)$k, $key)] = snapshot($v, $key);
    }
    ksort($out);
    return $out;
}

function stats(array $values): array
{
    sort($values, SORT_NUMERIC); $n = count($values);
    return ['n'=>$n, 'median'=>$n % 2 ? $values[intdiv($n, 2)] : ($values[$n/2-1]+$values[$n/2])/2,
        'p95'=>$values[(int)ceil(.95*$n)-1], 'max'=>$values[$n-1]];
}

function instrumentResolver(): void
{
    $source = file_get_contents(dirname(__DIR__, 2).'/src/Service/CharacterFeatureResolver.php');
    if (class_exists(App\Service\CharacterFeatureResolver::class, false)) throw new RuntimeException('Resolver already loaded');
    $source = preg_replace_callback('/public function (resolve|applicableRules|acquisitionRules)\([^)]*\)\s*:\s*array\s*\{/s',
        static fn($m) => $m[0].' \\PerfCounters::$calls[\''.$m[1].'\'] = (\\PerfCounters::$calls[\''.$m[1].'\'] ?? 0) + 1;', $source, -1, $count);
    if ($count !== 3) throw new RuntimeException('Resolver signatures changed: review instrumentation');
    eval(substr($source, 5)); // Exact source plus entry counters, in this CLI process only.
}

final class BenchEnvironment
{
    public Doctrine\ORM\EntityManagerInterface $em;
    public Doctrine\DBAL\Connection $db;
    public array $services = [];

    public function __construct(Doctrine\ORM\Configuration $config, ?Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder $holder)
    {
        $dc = new Doctrine\DBAL\Configuration();
        if ($holder) $dc->setMiddlewares([new Symfony\Bridge\Doctrine\Middleware\Debug\Middleware($holder, null)]);
        $params = (new Doctrine\DBAL\Tools\DsnParser(['postgresql'=>'pdo_pgsql']))->parse(getenv('DATABASE_URL'));
        $this->db = Doctrine\DBAL\DriverManager::getConnection($params, $dc);
        $factory = new class($this) implements Doctrine\ORM\Repository\RepositoryFactory {
            private array $repos = [];
            public function __construct(private BenchEnvironment $env) {}
            public function getRepository(Doctrine\ORM\EntityManagerInterface $em, string $name): Doctrine\ORM\EntityRepository
            {
                $m = $em->getClassMetadata($name);
                return $this->repos[$name] ??= $m->customRepositoryClassName
                    ? $this->env->service($m->customRepositoryClassName) : new Doctrine\ORM\EntityRepository($em, $m);
            }
        };
        $config = clone $config; $config->setRepositoryFactory($factory);
        $this->em = new Doctrine\ORM\EntityManager($this->db, $config);
        $registry = new class($this->em, $this->db) extends Doctrine\Persistence\AbstractManagerRegistry {
            public function __construct(private object $em, private object $db) { parent::__construct('benchmark', ['default'=>'db'], ['default'=>'em'], 'default', 'default'); }
            protected function getService(string $name): object { return $name === 'em' ? $this->em : $this->db; }
            protected function resetService(string $name): void { throw new RuntimeException('Reset forbidden'); }
        };
        $this->services = [Doctrine\ORM\EntityManagerInterface::class=>$this->em,
            Doctrine\DBAL\Connection::class=>$this->db, Doctrine\Persistence\ManagerRegistry::class=>$registry];
        $this->db->beginTransaction();
        $this->db->executeStatement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
        $this->db->executeStatement("SET LOCAL statement_timeout = '15s'");
        if ($this->db->fetchOne('SHOW transaction_read_only') !== 'on') throw new RuntimeException('Read-only required');
    }

    public function service(string $class): object
    {
        if (isset($this->services[$class])) return $this->services[$class];
        $r = new ReflectionClass($class); $args = [];
        foreach ($r->getConstructor()?->getParameters() ?? [] as $p) {
            $t = $p->getType();
            if ($t instanceof ReflectionNamedType && !$t->isBuiltin()) $args[] = $this->service($t->getName());
            elseif ($p->isDefaultValueAvailable()) $args[] = $p->getDefaultValue();
            else throw new RuntimeException('Unsupported constructor: '.$class);
        }
        return $this->services[$class] = $r->newInstanceArgs($args);
    }

    public function close(): void { $this->db->rollBack(); $this->db->close(); $this->em->clear(); $this->services = []; }
}

function operation(BenchEnvironment $env, string $operation, int $id): array
{
    $state = $env->em->find(App\Entity\CharacterSessionState::class, $id);
    if (!$state || !$state->isParticipating()) throw new RuntimeException('State missing or not participating: '.$id);
    $c = $state->getCharacter();
    $pv = App\Service\CharacterSessionStateSynchronizer::extractProgressionValues($state->getState());
    return match ($operation) {
        'full' => $env->service(App\Service\CharacterSessionStateSerializer::class)->serialize($state),
        'features' => array_map(static fn($r) => ['id'=>$r->getId(), 'featureId'=>$r->getFeatureDefinition()->getId(),
            'slug'=>$r->getFeatureDefinition()->getSlug(), 'sourceType'=>$r->sourceType(), 'sourceId'=>$r->sourceId(),
            'level'=>$r->getUnlockLevel(), 'threshold'=>$r->getProgressionThreshold(), 'order'=>$r->getDisplayOrder(),
            'choice'=>$r->getChoiceProvenance()], $env->service(App\Service\CharacterFeatureResolver::class)->resolve($c, $pv)),
        'abilities' => array_map(static fn($a) => $a->toArray(), $env->service(App\Service\CharacterAbilityCalculator::class)->calculateAll($c, $pv)),
        'training' => $env->service(App\Service\CharacterTrainingPresenter::class)->resolved($c, false, $pv),
        'resources' => array_map(static fn($r) => ['id'=>$r->getDefinition()->getId(), 'slug'=>$r->getSlug(),
            'name'=>$r->getName(), 'maximum'=>$r->getMaximum(), 'recharge'=>$r->getRechargeType()->value],
            $env->service(App\Service\CharacterResourceResolver::class)->resolve($c, $pv)),
        'profile' => $env->service(App\Service\CharacterProfileSerializer::class)->serialize($c, $pv),
        'spells' => $env->service(App\Service\PlayerSpellDirectory::class)->forCharacter($c, $state->getState()),
        'management' => $env->service(App\Service\PlayerSpellManagement::class)->view($state),
        'ability_choices' => $env->service(App\Service\CharacterFeatureSpellAbilityChoice::class)->list($c, $pv),
        // Extra functional coverage: the profile does not describe all owned instances.
        'inventory' => array_map(static fn($i) => ['id'=>$i->getId(), 'definitionId'=>$i->getMagicItem()?->getId(),
            'quantity'=>$i->getQuantity(), 'equipped'=>$i->isEquipped(), 'attuned'=>$i->isAttuned(),
            'slot'=>$i->getEquippedSlot(), 'hands'=>$i->getHandsUsed(), 'charges'=>$i->getCurrentCharges(),
            'spellId'=>$i->getInscribedSpell()?->getId(), 'active'=>$i->isEffectActive()], $c->getMagicItems()->toArray()),
        default => throw new RuntimeException('Unknown operation'),
    };
}

function cpu(): float
{
    $r = getrusage();
    return ($r['ru_utime.tv_sec']+$r['ru_stime.tv_sec'])*1000+($r['ru_utime.tv_usec']+$r['ru_stime.tv_usec'])/1000;
}

function datasetFingerprint(): string
{
    $params = (new Doctrine\DBAL\Tools\DsnParser(['postgresql'=>'pdo_pgsql']))->parse(getenv('DATABASE_URL'));
    $db = Doctrine\DBAL\DriverManager::getConnection($params);
    $db->beginTransaction(); $db->executeStatement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
    $hash = hash_init('sha256', HASH_HMAC, keyMaterial());
    try {
        foreach ($db->fetchFirstColumn("SELECT tablename FROM pg_tables WHERE schemaname='public' ORDER BY tablename") as $table) {
            hash_update($hash, $table."\0");
            // Read only; raw rows (including sensitive values) never leave this process.
            foreach ($db->executeQuery('SELECT row_to_json(t)::text AS row FROM '.$db->quoteIdentifier($table).' t ORDER BY row_to_json(t)::text')->iterateColumn() as $row) hash_update($hash, $row."\0");
        }
    } finally { $db->rollBack(); $db->close(); }
    return hash_final($hash);
}

function sourceFingerprint(): array
{
    $root = dirname(__DIR__, 2); $files = [];
    foreach (['src', 'config'] as $dir) foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$dir, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isFile()) $files[substr($file->getPathname(), strlen($root)+1)] = hash_file('sha256', $file->getPathname());
    }
    ksort($files);
    return ['application_sha256'=>hash('sha256', json($files)), 'composer_lock_sha256'=>hash_file('sha256', $root.'/composer.lock'),
        'benchmark_sha256'=>hash_file('sha256', __FILE__)];
}

function worker(array $o): array
{
    $instrumented = $o['mode'] === 'instrumented';
    if ($instrumented) instrumentResolver();
    $holder = $instrumented ? new Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder() : null;
    $config = Doctrine\ORM\ORMSetup::createAttributeMetadataConfig([dirname(__DIR__, 2).'/src/Entity'], true, cache: new Symfony\Component\Cache\Adapter\ArrayAdapter());
    $config->enableNativeLazyObjects(true);
    $config->setNamingStrategy(new Doctrine\ORM\Mapping\UnderscoreNamingStrategy());
    $key = keyMaterial(); $samples = []; $baseline = null; $stable = true; $queries = [];
    for ($i = -(int)$o['warmup']; $i < (int)$o['iterations']; ++$i) {
        gc_collect_cycles(); $env = new BenchEnvironment($config, $holder);
        $holder?->reset(); PerfCounters::$calls = []; memory_reset_peak_usage();
        $memory = memory_get_usage(true); $start = hrtime(true); $cpu = cpu();
        try {
            $result = operation($env, $o['operation'], (int)$o['state']);
            $encoded = json_encode($result, JSON_THROW_ON_ERROR);
            $sample = ['wall_ms'=>(hrtime(true)-$start)/1e6, 'cpu_ms'=>cpu()-$cpu,
                'memory_peak_bytes'=>memory_get_peak_usage(true), 'memory_peak_delta_bytes'=>memory_get_peak_usage(true)-$memory,
                'json_bytes'=>strlen($encoded)];
            if ($holder) {
                $data = $holder->getData()['default'] ?? []; $shapes = []; $exact = [];
                foreach ($data as $q) {
                    $shape = hash('sha256', preg_replace('/\s+/', ' ', trim($q['sql'])));
                    $same = hash_hmac('sha256', serialize([$q['sql'], $q['params'], $q['types']]), $key);
                    $shapes[$shape] = ($shapes[$shape] ?? 0)+1; $exact[$same] = ($exact[$same] ?? 0)+1;
                }
                $sample += ['sql_count'=>count($data), 'sql_shape_repeats'=>count($data)-count($shapes),
                    'sql_exact_repeats'=>count($data)-count($exact), 'dbal_ms'=>1000*array_sum(array_column($data, 'executionMS')),
                    'resolver_resolve_calls'=>PerfCounters::$calls['resolve'] ?? 0,
                    'resolver_applicable_calls'=>PerfCounters::$calls['applicableRules'] ?? 0,
                    'resolver_acquisition_calls'=>PerfCounters::$calls['acquisitionRules'] ?? 0];
                arsort($shapes); $queries = array_slice($shapes, 0, 12, true);
            }
            // Snapshot overhead is intentionally outside the timed region.
            $current = snapshot($result, $key);
            if ($baseline === null) $baseline = $current;
            elseif ($baseline !== $current) $stable = false;
            if ($i >= 0) $samples[] = $sample;
        } finally { $env->close(); }
        unset($env, $result, $encoded, $data, $current);
    }
    $summary = [];
    foreach (array_keys($samples[0]) as $metric) $summary[$metric] = stats(array_column($samples, $metric));
    return ['state'=>(int)$o['state'], 'operation'=>$o['operation'], 'mode'=>$o['mode'], 'warmup'=>(int)$o['warmup'],
        'stable'=>$stable, 'summary'=>$summary, 'samples'=>$samples, 'sql_shapes_last_iteration'=>$queries, 'snapshot'=>$baseline];
}

function compare(string $before, string $after): int
{
    $a = json_decode(file_get_contents($before), true, 512, JSON_THROW_ON_ERROR);
    $b = json_decode(file_get_contents($after), true, 512, JSON_THROW_ON_ERROR);
    if (!($a['complete'] ?? false) || !($b['complete'] ?? false) || !($a['dataset_unchanged'] ?? false) || !($b['dataset_unchanged'] ?? false)) throw new RuntimeException('Incomplete or unstable dataset');
    foreach (['schema', 'key_id', 'states', 'operations', 'modes'] as $field) {
        if (($a[$field] ?? null) !== ($b[$field] ?? null)) throw new RuntimeException('Incompatible report: '.$field);
    }
    if ($a['dataset_before'] !== $b['dataset_before']) throw new RuntimeException('Different source data');
    $failed = false;
    foreach ($a['results'] as $i => $row) {
        $other = $b['results'][$i] ?? null;
        if (!$other || [$row['state'],$row['operation'],$row['mode']] !== [$other['state'],$other['operation'],$other['mode']]) throw new RuntimeException('Different measurement matrix');
        $equal = $row['snapshot'] === $other['snapshot'] && $row['stable'] && $other['stable']; $failed |= !$equal;
        $metrics = [];
        foreach ($row['summary'] as $name => $stat) {
            $next = $other['summary'][$name];
            $metrics[$name] = ['before'=>$stat, 'after'=>$next, 'median_delta'=>$next['median']-$stat['median']];
        }
        echo json(['state'=>$row['state'], 'operation'=>$row['operation'], 'mode'=>$row['mode'], 'equivalent'=>$equal, 'metrics'=>$metrics]);
    }
    return $failed ? 2 : 0;
}

if (defined('PERF_LIBRARY')) return;

try {
    localOnly();
    $o = getopt('', ['worker', 'self-test', 'state:', 'operation:', 'mode:', 'states:', 'operations:', 'modes:', 'iterations:', 'warmup:', 'output:', 'compare:', 'against:']);
    if (isset($o['self-test'])) {
        $s = stats(range(1, 20));
        if ($s !== ['n'=>20,'median'=>10.5,'p95'=>19,'max'=>20]) throw new RuntimeException('Quantiles');
        $k = str_repeat('x',32);
        if (snapshot(['b'=>2,'a'=>1],$k) !== snapshot(['a'=>1,'b'=>2],$k)
            || snapshot([1,2],$k) === snapshot([2,1],$k)
            || snapshot(['hp'=>3],$k) === snapshot(['hp'=>4],$k)
            || str_contains(json(snapshot(['private name'=>'private name','accessToken'=>'secret'], $k)), 'private name')) throw new RuntimeException('Snapshot');
        echo "Quantiles, map normalization, list order, mutation sensitivity and privacy checks passed.\n"; exit;
    }
    if (isset($o['compare'])) exit(compare($o['compare'], $o['against'] ?? ''));
    $o += ['iterations'=>'5', 'warmup'=>'1'];
    if (!ctype_digit($o['iterations']) || (int)$o['iterations'] < 1 || (int)$o['iterations'] > 100 || !ctype_digit($o['warmup']) || (int)$o['warmup'] < 1) throw new RuntimeException('Invalid iteration counts');
    if (isset($o['worker'])) { echo json(worker($o)); exit; }
    $states = array_map('intval', explode(',', $o['states'] ?? '27,34,35'));
    $operations = explode(',', $o['operations'] ?? 'full,features,abilities,training,resources,profile,spells,management,ability_choices,inventory');
    $modes = explode(',', $o['modes'] ?? 'instrumented,light');
    if (array_diff($modes, ['instrumented','light']) || min($states) < 1) throw new RuntimeException('Invalid matrix');
    $key = keyMaterial();
    $report = ['schema'=>1, 'key_id'=>hash('sha256', $key), 'states'=>$states, 'operations'=>$operations, 'modes'=>$modes,
        'created_utc'=>gmdate(DATE_ATOM), 'php'=>PHP_VERSION, 'opcache_cli'=>ini_get('opcache.enable_cli'), 'sources'=>sourceFingerprint(),
        'dataset_before'=>datasetFingerprint(),
        'method'=>'Fresh EM, services and connection each iteration; warm in-memory metadata/query cache; nearest-rank p95; arithmetic middle-pair median; no HTTP; snapshot excluded from timing', 'results'=>[]];
    $out = $o['output'] ?? dirname(__DIR__, 2).'/var/performance/baseline.json';
    if (file_exists($out)) throw new RuntimeException('Output already exists; choose a new filename');
    if (!is_dir(dirname($out)) && !mkdir(dirname($out), 0700, true)) throw new RuntimeException('Cannot create output directory');
    foreach ($states as $state) foreach ($operations as $op) foreach ($modes as $mode) {
        fwrite(STDERR, "state=$state operation=$op mode=$mode\n");
        $cmd = [PHP_BINARY, __FILE__, '--worker', '--state='.$state, '--operation='.$op, '--mode='.$mode, '--iterations='.$o['iterations'], '--warmup='.$o['warmup']];
        $p = proc_open($cmd, [0=>['file','/dev/null','r'], 1=>['pipe','w'], 2=>['file','/dev/null','w']], $pipes);
        if (!is_resource($p)) throw new RuntimeException('Worker unavailable');
        $raw = stream_get_contents($pipes[1]); fclose($pipes[1]); $status = proc_close($p);
        if ($status !== 0) throw new RuntimeException("Worker failed: state=$state operation=$op mode=$mode (details suppressed)");
        $report['results'][] = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        file_put_contents($out, json($report), LOCK_EX);
    }
    $stable = true; $pairs = [];
    foreach ($report['results'] as $row) {
        $id = $row['state'].':'.$row['operation'];
        $stable = $stable && $row['stable'] && (!isset($pairs[$id]) || $pairs[$id] === $row['snapshot']);
        $pairs[$id] = $row['snapshot'];
    }
    $report['dataset_after'] = datasetFingerprint();
    $report['dataset_unchanged'] = $report['dataset_before'] === $report['dataset_after'];
    $stable = $stable && $report['dataset_unchanged'];
    $report['complete'] = true; $report['functional_stable_across_modes'] = $stable;
    file_put_contents($out, json($report), LOCK_EX);
    echo json(['complete'=>true, 'groups'=>count($report['results']), 'functional_stable_across_modes'=>$stable]);
    exit($stable ? 0 : 2);
} catch (Throwable $e) {
    // Never print raw driver errors, SQL parameters, URLs or stack traces.
    fwrite(STDERR, 'Benchmark failed ('.get_class($e).', line '.$e->getLine().'). Check local prerequisites and selected IDs.'."\n"); exit(1);
}
