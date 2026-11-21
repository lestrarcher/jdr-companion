<?php

declare(strict_types=1);

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:beasts:import-dinosaurs-pdf', description: 'Ajouter les neuf bêtes du PDF fourni sans écraser les fiches existantes')]
final class ImportDinosaursPdfCommand extends Command
{
    public function __construct(private readonly Connection $db, private readonly string $projectDir)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('apply', null, InputOption::VALUE_NONE, 'Créer les bêtes absentes (simulation par défaut)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $path = $this->projectDir.'/data/beasts-dinosaurs-pdf.json';
        $entries = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($entries) || !array_is_list($entries) || count($entries) !== 9) {
            throw new \RuntimeException('Le fichier doit contenir les neuf fiches du PDF.');
        }
        $missing = [];
        $existing = [];
        foreach ($entries as $entry) {
            $slug = $entry['slug'] ?? null;
            if (!is_string($slug) || !preg_match('/^[a-z0-9-]+$/', $slug)
                || !is_string($entry['name'] ?? null)
                || !is_array($entry['statBlock'] ?? null)
                || !is_array($entry['statBlock']['actions'] ?? null)) {
                throw new \RuntimeException('Fiche invalide : '.(string) $slug);
            }
            $id = $this->db->fetchOne('SELECT id FROM beast WHERE slug = :slug', ['slug' => $slug]);
            if ($id !== false) {
                $existing[] = $slug;
            } else {
                $missing[] = $entry;
            }
        }
        $output->writeln(sprintf('Fiches : %d ; à créer : %d ; déjà présentes (préservées) : %d.', count($entries), count($missing), count($existing)));
        foreach ($existing as $slug) $output->writeln('  = '.$slug);
        foreach ($missing as $entry) $output->writeln('  + '.$entry['name'].' (FP '.$entry['challengeRating'].')');
        if (!$input->getOption('apply')) {
            $output->writeln('Simulation uniquement. Ajouter --apply pour créer.');
            return Command::SUCCESS;
        }
        $this->db->transactional(function () use ($missing): void {
            foreach ($missing as $entry) {
                $this->db->executeStatement(
                    'INSERT INTO beast (slug, name, challenge_rating, stat_block) VALUES (:slug, :name, :cr, :block) ON CONFLICT (slug) DO NOTHING',
                    [
                        'slug' => $entry['slug'],
                        'name' => $entry['name'],
                        'cr' => (string) $entry['challengeRating'],
                        'block' => json_encode($entry['statBlock'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    ],
                );
            }
        });
        $output->writeln('Import terminé. Fiches déjà présentes intactes.');
        return Command::SUCCESS;
    }
}
