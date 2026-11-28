<?php
declare(strict_types=1);

define('PERF_LIBRARY', true);
require __DIR__.'/benchmark.php';
localOnly();

// Synthetic reports only: no application data, database or access tokens.
$dir = sys_get_temp_dir().'/jdr-benchmark-test-'.bin2hex(random_bytes(6));
mkdir($dir, 0700);
$base = ['schema'=>1, 'complete'=>true, 'dataset_unchanged'=>true,
    'key_id'=>'synthetic', 'states'=>[1], 'operations'=>['test'], 'modes'=>['light'],
    'dataset_before'=>'synthetic', 'results'=>[['state'=>1, 'operation'=>'test',
        'mode'=>'light', 'stable'=>true, 'snapshot'=>['hp'=>5],
        'summary'=>['wall_ms'=>stats([1,2,3,4,5])]]]];
try {
    file_put_contents($dir.'/before.json', json($base));
    $cases = ['equal'=>[0, $base]];
    $changed = $base; $changed['results'][0]['snapshot']['hp'] = 4;
    $cases['business-change'] = [2, $changed];
    $unstable = $base; $unstable['results'][0]['stable'] = false;
    $cases['unstable-output'] = [2, $unstable];
    $different = $base; $different['dataset_before'] = 'changed';
    $cases['different-input'] = [1, $different];
    $partial = $base; unset($partial['complete']);
    $cases['partial-report'] = [1, $partial];
    foreach ($cases as $name => [$expected, $report]) {
        file_put_contents($dir.'/after.json', json($report));
        ob_start();
        try { $actual = compare($dir.'/before.json', $dir.'/after.json'); }
        catch (RuntimeException) { $actual = 1; }
        finally { ob_end_clean(); }
        if ($actual !== $expected) throw new RuntimeException('Failed: '.$name);
        echo $name, ": passed\n";
    }
} finally {
    foreach (['before.json', 'after.json'] as $file) if (is_file($dir.'/'.$file)) unlink($dir.'/'.$file);
    rmdir($dir);
}
