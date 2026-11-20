<?php

declare(strict_types=1);

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** Import initial : n'écrase jamais les définitions de capacités éditées par le MJ. */
#[AsCommand(name: 'app:beasts:import-traits-2014', description: 'Associer les traits SRD 2014 aux capacités réutilisables')]
final class ImportBeastTraits2014Command extends Command
{
    public function __construct(private readonly Connection $db)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('apply', null, InputOption::VALUE_NONE, 'Appliquer les créations et associations');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $apply = (bool) $input->getOption('apply');
        $rows = $this->db->fetchAllAssociative('SELECT id, slug, stat_block FROM beast WHERE stat_block IS NOT NULL ORDER BY id');
        $planned = [];
        foreach ($rows as $row) {
            $block = is_array($row['stat_block']) ? $row['stat_block'] : json_decode((string) $row['stat_block'], true);
            foreach (($block['traits'] ?? []) as $position => $trait) {
                if (!is_array($trait) || !is_string($trait['name'] ?? null) || trim($trait['name']) === '') continue;
                $name = trim($trait['name']);
                $description = $trait['desc'] ?? $trait['description'] ?? '';
                if (is_array($description)) $description = implode("\n", array_filter($description, 'is_string'));
                if (!is_string($description)) $description = '';
                $normalized = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', mb_strtolower($name));
                $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($normalized ?: $name)), '-');
                if ($slug === '') continue;
                // Préfixe pour éviter les collisions avec des capacités de classes homonymes.
                $slug = substr('beast-trait-'.$slug, 0, 100);
                $planned[] = ['beast' => (int) $row['id'], 'name' => $name, 'slug' => $slug, 'description' => $description, 'position' => (int) $position];
            }
        }
        $output->writeln(sprintf('%d associations de traits trouvées pour %d bêtes.', count($planned), count($rows)));
        if (!$apply) {
            $output->writeln('Simulation uniquement. Ajouter --apply pour enregistrer.');
            return Command::SUCCESS;
        }
        $created = 0;
        $linked = 0;
        $this->db->transactional(function () use ($planned, &$created, &$linked): void {
            foreach ($planned as $entry) {
                $featureId = $this->db->fetchOne('SELECT id FROM character_feature_definition WHERE slug = ?', [$entry['slug']]);
                if (!$featureId) {
                    $featureId = $this->db->fetchOne(
                        "INSERT INTO character_feature_definition (slug, name, description, activation_type, visible, custom, origin, owner_id, created_at, updated_at) VALUES (?, ?, ?, 'passive', true, false, 'OFFICIAL', NULL, NOW(), NOW()) ON CONFLICT (slug) DO NOTHING RETURNING id",
                        [$entry['slug'], $entry['name'], $entry['description']],
                    );
                    if ($featureId) ++$created;
                    else $featureId = $this->db->fetchOne('SELECT id FROM character_feature_definition WHERE slug = ?', [$entry['slug']]);
                }
                $linked += $this->db->executeStatement(
                    'INSERT INTO beast_feature (beast_id, feature_definition_id, display_order) VALUES (?, ?, ?) ON CONFLICT DO NOTHING',
                    [$entry['beast'], (int) $featureId, $entry['position']],
                );
            }
        });
        $output->writeln(sprintf('Définitions créées : %d ; associations créées : %d.', $created, $linked));
        return Command::SUCCESS;
    }
}
