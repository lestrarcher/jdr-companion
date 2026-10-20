<?php
/**
 * Extraction des attributions de classes AideDD pour les candidats D&D 2014.
 * Usage: php /app/tools/extract_spell_classes_2014.php [--limit=10] [--retry-errors]
 * Ne modifie ni le corpus original ni la base de données.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$sourcePath = $root . '/data/jdr_companion_490_sorts_2014_durees.json';
$cachePath = $root . '/data/aidedd_enrichissement_progression.json';
$outputPath = $root . '/data/spells-2014-class-assignments.json';
$limit = null;
$retryErrors = in_array('--retry-errors', $argv, true);
foreach ($argv as $arg) {
    if (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = (int) $m[1];
    }
}

function readJson(string $path): array {
    if (!is_file($path)) {
        throw new RuntimeException("Fichier introuvable : $path");
    }
    $data = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($data)) {
        throw new RuntimeException("JSON inattendu : $path");
    }
    return $data;
}

function saveJson(string $path, array $data): void {
    $temp = $path . '.tmp';
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    if (file_put_contents($temp, $json, LOCK_EX) === false || !rename($temp, $path)) {
        throw new RuntimeException("Échec d'écriture : $path");
    }
}

function classNodes(string $html): array {
    $dom = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    try {
        if (!$dom->loadHTML('<?xml encoding="UTF-8">' . $html)) {
            throw new RuntimeException('HTML illisible');
        }
        $xpath = new DOMXPath($dom);
        $nodes = $xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " classe ") or contains(concat(" ", normalize-space(@class), " "), " tcoe ")]');
        if ($nodes === false) {
            throw new RuntimeException('XPath invalide');
        }
        $result = [];
        foreach ($nodes as $node) {
            $label = trim(preg_replace('/\s+/u', ' ', $node->textContent));
            $css = ' ' . trim(preg_replace('/\s+/', ' ', $node->getAttribute('class'))) . ' ';
            $origin = str_contains($css, ' tcoe ') ? 'TCoE' : 'base';
            $name = trim(preg_replace('/\s*\[[^]]+\]\s*$/u', '', $label));
            if ($name === '') {
                continue;
            }
            $key = mb_strtolower($name, 'UTF-8') . '|' . $origin;
            $result[$key] = ['name' => $name, 'source' => $origin, 'label' => $label];
        }
        return array_values($result);
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    }
}

try {
    if (!extension_loaded('dom') || !extension_loaded('mbstring')) {
        throw new RuntimeException('Extensions PHP dom et mbstring nécessaires.');
    }
    $source = readJson($sourcePath);
    $cache = readJson($cachePath);
    $spells = $source['spells'] ?? null;
    if (!is_array($spells)) {
        throw new RuntimeException('Structure spells absente du corpus.');
    }
    $sourceHash = hash_file('sha256', $sourcePath);
    $out = is_file($outputPath) ? readJson($outputPath) : [
        'schema_version' => 1,
        'source_sha256' => $sourceHash,
        'entries' => [],
    ];
    if (($out['source_sha256'] ?? null) !== $sourceHash) {
        throw new RuntimeException('Le corpus a changé depuis le début de cette extraction. Conserver le résultat précédent et réexaminer avant reprise.');
    }
    if (!isset($out['entries']) || !is_array($out['entries'])) {
        throw new RuntimeException('Structure entries absente du résultat.');
    }
    $attempted = 0;
    $eligible = 0;
    foreach ($spells as $spell) {
        if (($spell['source_book'] ?? '') === 'Extra (AideDD)') {
            continue;
        }
        $eligible++;
        $key = $spell['import_key'] ?? null;
        if (!is_string($key) || $key === '') {
            throw new RuntimeException('Sort sans import_key dans le corpus.');
        }
        $previous = $out['entries'][$key] ?? null;
        if (is_array($previous) && ($previous['status'] ?? '') === 'ok') {
            continue;
        }
        if (is_array($previous) && !$retryErrors && ($previous['status'] ?? '') === 'error') {
            continue;
        }
        if ($limit !== null && $attempted >= $limit) {
            break;
        }
        $attempted++;
        $url = $cache[$key]['url'] ?? $spell['url'] ?? null;
        if (!is_string($url) || !preg_match('~^https://(?:www\.)?aidedd\.org/dnd/sorts\.php\?~i', $url)) {
            $out['entries'][$key] = ['status' => 'error', 'error' => 'URL AideDD absente ou non reconnue'];
            saveJson($outputPath, $out);
            echo "ERREUR $key : URL absente/invalide\n";
            continue;
        }
        $context = stream_context_create(['http' => [
            'timeout' => 20,
            'user_agent' => 'JdR-Companion class metadata research/1.0',
            'header' => "Accept: text/html\r\n",
        ]]);
        $html = @file_get_contents($url, false, $context);
        if ($html === false) {
            $out['entries'][$key] = ['status' => 'error', 'url' => $url, 'error' => 'Échec HTTP'];
            echo "ERREUR $key : HTTP\n";
        } else {
            $classes = classNodes($html);
            $out['entries'][$key] = [
                'status' => count($classes) ? 'ok' : 'review',
                'url' => $url,
                'classes' => $classes,
            ];
            echo sprintf("%s %s : %d classe(s)\n", count($classes) ? 'OK' : 'À VÉRIFIER', $key, count($classes));
        }
        saveJson($outputPath, $out);
        // Requêtes espacées pour éviter de surcharger le site.
        usleep(500000);
    }
    $counts = ['ok' => 0, 'review' => 0, 'error' => 0];
    foreach ($out['entries'] as $entry) {
        $status = $entry['status'] ?? 'error';
        $counts[$status] = ($counts[$status] ?? 0) + 1;
    }
    printf("\nÉligibles : %d | OK : %d | À vérifier : %d | Erreurs : %d | Non traités : %d\n", $eligible, $counts['ok'], $counts['review'], $counts['error'], max(0, $eligible - count($out['entries'])));
    echo "Résultat : $outputPath\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'ERREUR : ' . $e->getMessage() . "\n");
    exit(1);
}
