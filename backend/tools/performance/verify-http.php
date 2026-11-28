<?php
declare(strict_types=1);

define('PERF_LIBRARY', true);
require __DIR__.'/benchmark.php';

try {
    localOnly();
    $o = getopt('', ['services:', 'http:']);
    $services = json_decode(file_get_contents($o['services']), true, 512, JSON_THROW_ON_ERROR);
    $http = json_decode(file_get_contents($o['http']), true, 512, JSON_THROW_ON_ERROR);
    foreach ([$services, $http] as $report) if (!($report['complete'] ?? false) || !($report['dataset_unchanged'] ?? false)
        || !($report['functional_stable_across_modes'] ?? false)) throw new RuntimeException('Incomplete report');
    if ($services['key_id'] !== $http['key_id'] || $services['key_id'] !== hash('sha256',keyMaterial())
        || $services['dataset_before'] !== $http['dataset_before']) throw new RuntimeException('Incompatible reports');
    $index = [];
    foreach ($services['results'] as $row) if ($row['mode'] === 'light') $index[$row['state'].':'.$row['operation']] = $row['snapshot'];
    $equal = true;
    foreach ($http['results'] as $row) {
        $id = $row['state'].':'.$row['operation'];
        if (!array_key_exists($id,$index)) throw new RuntimeException('Missing service sample');
        $actual = $row['snapshot'];
        if ($row['operation'] === 'ability_choices') $actual = $actual[hash_hmac('sha256','choices',keyMaterial())];
        $same = $actual === $index[$id]; $equal = $equal && $same;
        echo json(['state'=>$row['state'],'operation'=>$row['operation'],'http_matches_isolated_service'=>$same]);
    }
    exit($equal ? 0 : 2);
} catch (Throwable $e) {
    fwrite(STDERR,'Cross-stack comparison failed ('.get_class($e).'). No response data logged.'."\n"); exit(1);
}
