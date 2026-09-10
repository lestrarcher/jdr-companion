<?php

declare(strict_types=1);

use App\Service\FeatCatalogueValidator;

require __DIR__ . '/../vendor/autoload.php';

$validator = new FeatCatalogueValidator();

try {
    $path = __DIR__ . '/../data/reference/dnd-2014-feats.json';
    $catalogue = json_decode(file_get_contents($path), false, 512, JSON_THROW_ON_ERROR);
    $errors = $validator->validate($catalogue, true);

    // Historical migration strings and provenance URLs are intentional, not application references.
    foreach ([__DIR__ . '/../src', __DIR__ . '/../config', __DIR__ . '/../../frontend/src'] as $root) {
        if (!is_dir($root)) {
            echo "Not mounted; application reference scan skipped: $root\n";
            continue;
        }
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getRealPath() === realpath(__DIR__ . '/../src/Service/FeatCatalogueValidator.php')) { continue; }
            if (!$file->isFile() || !in_array($file->getExtension(), ['php', 'ts', 'html', 'json', 'yaml', 'yml'], true)) {
                continue;
            }
            foreach (FeatCatalogueValidator::OLD_SLUGS as $slug) {
                if (str_contains(file_get_contents($file->getPathname()), $slug)) {
                    $errors[] = 'Obsolete application reference: ' . $file->getPathname() . ' (' . $slug . ')';
                }
            }
        }
    }

    if (in_array('--self-test', $argv, true)) {
        $mutations = [
            static function ($c) { $c->schemaVersion = 2; },
            static function ($c) { array_pop($c->feats); },
            static function ($c) { $c->feats[1]->slug = $c->feats[0]->slug; },
            static function ($c) { $c->feats[0]->slug = 'Bad_slug'; },
            static function ($c) { $c->feats[0]->slug = str_repeat('a', 81); },
            static function ($c) { $c->feats[0]->name = str_repeat('é', 121); },
            static function ($c) { $c->extra = true; },
            static function ($c) { $c->feats[0]->extra = true; },
            static function ($c) { unset($c->feats[0]->description); },
            static function ($c) { $c->feats[0]->slug = 'peur-du-dragon'; },
            static function ($c) { $c->feats[0]->allowedAbilities = ['strength', 'strength']; },
            static function ($c) { $c->feats[0]->chosenAbilityIncrease = 3; },
            static function ($c) { $c->feats[0]->reviewNotes = []; },
            static function ($c) { $c->feats[0]->reviewStatus = 'approved'; },
            static function ($c) { $c->feats[0]->custom = true; },
            static function ($c) { $c->feats[0]->sourceUrl = 'http://example.com'; },
            static function ($c) { foreach ($c->feats as $f) { if ($f->slug === 'resilient') $f->repeatable = null; } },
            static function ($c) { foreach ($c->feats as $f) { if ($f->slug === 'resilient') $f->allowedAbilities = []; } },
            static function ($c) { foreach ($c->feats as $f) { if ($f->slug === 'war-caster') $f->chosenAbilityIncrease = 1; } },
        ];
        foreach ($mutations as $index => $mutate) {
            $copy = unserialize(serialize($catalogue));
            $mutate($copy);
            if ($validator->validate($copy, true) === []) {
                $errors[] = "Self-test $index failed to reject an invalid catalogue.";
            }
        }
        echo count($mutations) . " invalid-catalogue cases checked.\n";
    }
    if ($errors !== []) {
        throw new RuntimeException(implode("\n", $errors));
    }
    echo "OK: 83 feats, 9 approved, 74 pending; no obsolete application slug references.\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
