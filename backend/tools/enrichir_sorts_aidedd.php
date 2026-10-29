<?php
/** Enrichissement prudent des métadonnées AideDD. PHP 8.1+, ext-dom. Aucune écriture DB. */
declare(strict_types=1);

$root = dirname(__DIR__);
$source = $root . '/data/jdr_companion_490_sorts_2014_complet.json';
$output = $root . '/data/jdr_companion_490_sorts_2014_durees.json';
$progress = $root . '/data/aidedd_enrichissement_progression.json';
$report = $root . '/data/aidedd_enrichissement_rapport.json';
$descriptionsOutput = $root . '/data/aidedd_descriptions_travail.json';
$limit = 5;
$delayMs = 1200;
foreach ($argv as $arg) {
    if (preg_match('/^--limit=(\d+)$/', $arg, $m)) $limit = (int)$m[1];
    if (preg_match('/^--delay-ms=(\d+)$/', $arg, $m)) $delayMs = max(1000, (int)$m[1]);
}
if (!is_file($source)) { fwrite(STDERR, "Fichier introuvable: $source\n"); exit(1); }
$dataset = json_decode(file_get_contents($source), true, 512, JSON_THROW_ON_ERROR);
$spells = $dataset['spells'] ?? null;
if (!is_array($spells) || !array_is_list($spells)) { fwrite(STDERR, "Format spells invalide\n"); exit(1); }
$cache = is_file($progress) ? json_decode(file_get_contents($progress), true, 512, JSON_THROW_ON_ERROR) : [];
if (!is_array($cache)) $cache = [];
function saveJson(string $file, array $value): void {
    $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $tmp = $file . '.tmp';
    if (file_put_contents($tmp, $json . "\n", LOCK_EX) === false || !rename($tmp, $file)) throw new RuntimeException("Impossible d'écrire $file");
}
function field(DOMXPath $xp, string $class): ?string {
    $nodes = $xp->query("//div[contains(concat(' ', normalize-space(@class), ' '), ' $class ')]");
    foreach ($nodes as $node) {
        $text = trim(preg_replace('/\s+/u', ' ', $node->textContent));
        $text = preg_replace('/^[^:]+:\s*/u', '', $text, 1);
        if ($text !== '') return $text;
    }
    return null;
}
$done = 0;
foreach ($spells as $spell) {
    $key = $spell['import_key'] ?? null;
    $url = $spell['aidedd_url'] ?? null;
    if (is_string($url)) {
        $url = preg_replace_callback(
            '/[^\x00-\x7F]+/u',
            static fn($m) => rawurlencode($m[0]),
            $url
        );
    }
    if (!is_string($key) || !is_string($url) || !preg_match('~^https://www\.aidedd\.org/dnd/sorts\.php\?vf=(?:[a-z0-9-]|%[0-9A-Fa-f]{2})+$~', $url)) {
        if (is_string($key) && !isset($cache[$key])) $cache[$key] = ['status' => 'invalid-url'];
        continue;
    }
    if (
        isset($cache[$key])
        && $cache[$key]['status'] === 'ok'
        && array_key_exists('description_html', $cache[$key])
    ) continue;
    if ($done >= $limit) break;
    $done++;
    $ctx = stream_context_create(['http' => ['timeout' => 20, 'user_agent' => 'JdrCompanionMetadataResearch/1.0 (personal use)', 'ignore_errors' => true]]);
    $html = @file_get_contents($url, false, $ctx);
    $http = implode(' ', $http_response_header ?? []);
    if ($html === false || !preg_match('~HTTP/\S+ 200\b~', $http)) {
        $cache[$key] = ['status' => 'http-error', 'http' => substr($http, 0, 200)];
    } else {
        $dom = new DOMDocument();
        $old = libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML($html);
        libxml_clear_errors(); libxml_use_internal_errors($old);
        $xp = new DOMXPath($dom);
        $duration = $loaded ? field($xp, 'd') : null;
        $components = $loaded ? field($xp, 'c') : null;
        $casting = $loaded ? field($xp, 't') : null;
        $range = $loaded ? field($xp, 'r') : null;
        $descriptionNode = $loaded
            ? $xp->query("//div[contains(concat(' ', normalize-space(@class), ' '), ' description ')]")->item(0)
            : null;

        $descriptionHtml = $descriptionNode
            ? trim($dom->saveHTML($descriptionNode))
            : null;
        $cache[$key] = $duration !== null
            ? [
                'status' => 'ok',
                'duration' => $duration,
                'components_detail' => $components,
                'casting_time_detail' => $casting,
                'range_detail' => $range,
                'description_html' => $descriptionHtml,
                'url' => $url,
            ]
            : ['status' => 'parse-error', 'url' => $url];
    }
    echo sprintf("[%d] %s : %s\n", $done, $spell['name_fr'] ?? $key, $cache[$key]['status']);
    saveJson($progress, $cache);
    usleep($delayMs * 1000);
}
foreach ($dataset['spells'] as &$spell) {
    $item = $cache[$spell['import_key'] ?? ''] ?? null;
    if (($item['status'] ?? '') !== 'ok') continue;
    $spell['duration'] = $item['duration'];
    $spell['components_detail'] = $item['components_detail'];
    $spell['casting_time_detail'] = $item['casting_time_detail'];
    $spell['range_detail'] = $item['range_detail'];
    $spell['duration_source'] = 'aidedd-detail-page';
}
unset($spell);
$reportData = ['total' => count($dataset['spells']), 'durations_filled' => count(array_filter($dataset['spells'], fn($s) => !empty($s['duration']))), 'cached_successes' => count(array_filter($cache, fn($v) => ($v['status'] ?? '') === 'ok')), 'failures' => array_filter($cache, fn($v) => ($v['status'] ?? '') !== 'ok')];
$descriptions = [];

foreach ($dataset['spells'] as $spell) {
    $key = $spell['import_key'] ?? '';
    $item = $cache[$key] ?? null;

    if (
        ($item['status'] ?? '') !== 'ok'
        || empty($item['description_html'])
    ) {
        continue;
    }

    $descriptions[$key] = [
        'name_fr' => $spell['name_fr'],
        'source_url' => $item['url'],
        'description_html' => $item['description_html'],
        'usage' => 'working-copy-license-review-required',
    ];
}

saveJson($descriptionsOutput, $descriptions);
saveJson($output, $dataset);
saveJson($report, $reportData);
echo "\nDurées remplies : {$reportData['durations_filled']}/{$reportData['total']}\nSortie : $output\nRapport : $report\n";
