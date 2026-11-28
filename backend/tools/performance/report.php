<?php
declare(strict_types=1);

define('PERF_LIBRARY', true);
require __DIR__.'/benchmark.php';

try {
    localOnly();
    $o = getopt('', ['services:', 'http:', 'output:']);
    $reports = [];
    foreach (['services','http'] as $kind) {
        $r = json_decode(file_get_contents($o[$kind]), true, 512, JSON_THROW_ON_ERROR);
        if (!($r['complete'] ?? false) || !($r['dataset_unchanged'] ?? false)
            || !($r['functional_stable_across_modes'] ?? false)) throw new RuntimeException('Incomplete or unstable report');
        $reports[$kind] = $r;
    }
    if ($reports['services']['key_id'] !== $reports['http']['key_id']
        || $reports['services']['dataset_before'] !== $reports['http']['dataset_before']) throw new RuntimeException('Different inputs');
    if (file_exists($o['output'])) throw new RuntimeException('Output exists');
    $out = "# Lot 0 — résultats locaux\n\n";
    $out .= "Chaque cellule numérique présente **médiane / p95 / maximum**. Temps en ms, mémoire en octets, JSON non compressé. p95 au rang ceil(0,95 × n) ; avec n=5, p95=max.\n\n";
    $out .= "Les compteurs SQL et resolver proviennent exclusivement du mode instrumenté. Ne pas additionner les opérations ni attribuer au serveur HTTP les métriques des services isolés.\n\n";
    foreach ($reports as $kind => $r) {
        $out .= "## $kind\n\nDate UTC : ".$r['created_utc'].". PHP : ".$r['php'].". Jeu de données inchangé et sorties stables : oui.\n\n";
        $out .= "| État | Opération | Mode | n / échauffements | Écoulé ms | CPU ms | Pic mémoire | Delta pic mémoire | JSON octets |\n|---|---|---|---|---|---|---|---|---|\n";
        $cell = static function (array $row, string $metric): string {
            if (!isset($row['summary'][$metric])) return 'non mesuré';
            $s = $row['summary'][$metric];
            return implode(' / ', array_map(static fn($v) => number_format((float)$v, 2, '.', ''), [$s['median'],$s['p95'],$s['max']]));
        };
        foreach ($r['results'] as $row) {
            $out .= '| '.$row['state'].' | '.$row['operation'].' | '.$row['mode'].' | '.$row['summary']['wall_ms']['n'].' / '.$row['warmup'].' | ';
            $out .= implode(' | ', array_map(static fn($m) => $cell($row,$m), ['wall_ms','cpu_ms','memory_peak_bytes','memory_peak_delta_bytes','json_bytes']))." |\n";
        }
        if ($kind === 'services') {
            $out .= "\n### Instrumentation SQL et resolver\n\n| État | Opération | SQL total | Répétitions exactes | Répétitions de forme | DBAL ms | resolve | applicableRules | acquisitionRules |\n|---|---|---|---|---|---|---|---|---|\n";
            foreach ($r['results'] as $row) if ($row['mode'] === 'instrumented') {
                $out .= '| '.$row['state'].' | '.$row['operation'].' | '.implode(' | ', array_map(static fn($m) => $cell($row,$m),
                    ['sql_count','sql_exact_repeats','sql_shape_repeats','dbal_ms','resolver_resolve_calls','resolver_applicable_calls','resolver_acquisition_calls']))." |\n";
            }
        }
        $out .= "\n";
    }
    file_put_contents($o['output'], $out, LOCK_EX);
    echo "Local Markdown report generated.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Report failed ('.get_class($e).").\n"); exit(1);
}
