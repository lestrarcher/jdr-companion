<?php
declare(strict_types=1);

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:beasts:enrich-2014', description: 'Validate and import reviewed 2014 beast stat blocks without changing observed beasts')]
final class EnrichBeastCatalogue2014Command extends Command
{
    public function __construct(private readonly Connection $db, private readonly string $projectDir)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('file', null, InputOption::VALUE_REQUIRED, 'JSON data file (relative to backend or absolute)', 'data/beasts-2014-stats.json');
        $this->addOption('apply', null, InputOption::VALUE_NONE, 'Persist the validated stat blocks');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $filename = (string) $input->getOption('file');
        $path = str_starts_with($filename, '/') ? $filename : $this->projectDir.'/'.$filename;
        if (!is_file($path)) {
            $output->writeln('<error>Fichier introuvable : '. $path .'</error>');
            return Command::FAILURE;
        }
        try {
            $entries = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $output->writeln('<error>JSON invalide : '.$e->getMessage().'</error>');
            return Command::FAILURE;
        }
        if (!is_array($entries) || !array_is_list($entries)) {
            $output->writeln('<error>Le fichier doit contenir un tableau JSON.</error>');
            return Command::FAILURE;
        }

        $changes = [];
        $slugs = [];
        $errors = [];
        foreach ($entries as $index => $entry) {
            $prefix = 'Entrée '.($index + 1);
            if (!is_array($entry) || !is_string($entry['slug'] ?? null) || !preg_match('/^[a-z0-9-]+$/', $entry['slug'])) {
                $errors[] = "$prefix : slug invalide";
                continue;
            }
            $slug = $entry['slug'];
            if (isset($slugs[$slug])) {
                $errors[] = "$prefix : doublon $slug";
                continue;
            }
            $slugs[$slug] = true;
            $name = $entry['name'] ?? null;
            $cr = $entry['challengeRating'] ?? null;
            $block = $entry['statBlock'] ?? null;
            if (!is_string($name) || trim($name) === '' || mb_strlen($name) > 160
                || !(is_string($cr) || is_int($cr)) || !preg_match('/^(?:0|[1-9][0-9]*|1\/[248])$/', (string) $cr)
                || !is_array($block) || array_is_list($block)) {
                $errors[] = "$prefix ($slug) : nom, FP ou statBlock invalide";
                continue;
            }
            $abilities = $block['abilities'] ?? null;
            $speed = $block['speed'] ?? null;
            if (!is_int($block['armorClass'] ?? null) || $block['armorClass'] < 1
                || !is_int($block['hitPoints'] ?? null) || $block['hitPoints'] < 1
                || !is_array($abilities) || !is_array($speed)
                || !is_array($block['actions'] ?? null)) {
                $errors[] = "$prefix ($slug) : CA, PV, vitesses, caractéristiques ou actions manquants";
                continue;
            }
            foreach (['str','dex','con','int','wis','cha'] as $ability) {
                if (!is_int($abilities[$ability] ?? null) || $abilities[$ability] < 1 || $abilities[$ability] > 30) {
                    $errors[] = "$prefix ($slug) : caractéristique $ability invalide";
                }
            }
            foreach ($speed as $kind => $value) {
                if (!in_array($kind, ['walk','fly','swim','climb','burrow'], true) || !is_numeric($value) || (float) $value < 0) {
                    $errors[] = "$prefix ($slug) : vitesse $kind invalide";
                }
            }
            $current = $this->db->fetchAssociative('SELECT id, name, challenge_rating, stat_block FROM beast WHERE slug = :slug', ['slug' => $slug]);
            if ($current === false) {
                $errors[] = "$prefix ($slug) : absent du catalogue initial";
                continue;
            }
            $json = json_encode($block, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
            $stored = $current['stat_block'] === null ? null : json_decode((string) $current['stat_block'], true);
            $nameDiff = $current['name'] !== $name;
            $crDiff = (string) $current['challenge_rating'] !== (string) $cr;
            $statsDiff = $this->normalizeJson($stored) !== $this->normalizeJson($block);

            if ($nameDiff || $crDiff || $statsDiff) {
                $changes[] = ['id' => (int) $current['id'], 'slug' => $slug, 'name' => $name, 'cr' => (string) $cr, 'json' => $json];
            }
        }
        if ($errors !== []) {
            foreach ($errors as $error) $output->writeln('<error>'.$error.'</error>');
            $output->writeln('<error>Aucune modification appliquée.</error>');
            return Command::FAILURE;
        }
        $output->writeln(sprintf('Fiches vérifiées : %d ; à enrichir/actualiser : %d ; déjà conformes : %d.', count($entries), count($changes), count($entries) - count($changes)));
        if (!$input->getOption('apply')) {
            $output->writeln('Simulation uniquement. Ajouter --apply pour écrire.');
            return Command::SUCCESS;
        }
        $this->db->transactional(function () use ($changes): void {
            foreach ($changes as $entry) {
                $this->db->executeStatement('UPDATE beast SET name = :name, challenge_rating = :cr, stat_block = :block WHERE id = :id', [
                    'name' => $entry['name'], 'cr' => $entry['cr'], 'block' => $entry['json'], 'id' => $entry['id'],
                ]);
            }
        });
        $output->writeln('Enrichissement appliqué. Les animaux observés ne sont pas modifiés.');
        return Command::SUCCESS;
    }

    private function normalizeJson(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (!array_is_list($value)) {
            ksort($value);
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->normalizeJson($item);
        }

        return $value;
    }

    private function findDifference(mixed $expected, mixed $actual, string $path = 'root'): ?string
{
    if (get_debug_type($expected) !== get_debug_type($actual)) {
        return sprintf(
            '%s : types différents (%s / %s)',
            $path,
            get_debug_type($expected),
            get_debug_type($actual)
        );
    }

    if (is_array($expected)) {
        foreach ($expected as $key => $value) {
            if (!array_key_exists($key, $actual)) {
                return "$path.$key : clé absente en base";
            }

            $difference = $this->findDifference($value, $actual[$key], "$path.$key");
            if ($difference !== null) {
                return $difference;
            }
        }

        foreach ($actual as $key => $_) {
            if (!array_key_exists($key, $expected)) {
                return "$path.$key : clé supplémentaire en base";
            }
        }

        return null;
    }

    return $expected === $actual
        ? null
        : sprintf(
            '%s : valeurs différentes (%s / %s)',
            $path,
            var_export($expected, true),
            var_export($actual, true)
        );
}
}
