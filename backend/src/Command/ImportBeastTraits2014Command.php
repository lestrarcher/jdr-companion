<?php

declare(strict_types=1);

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** Synchronise les traits SRD, sans écraser les définitions personnalisées du MJ. */
#[AsCommand(name: 'app:beasts:import-traits-2014', description: 'Synchroniser les traits SRD 2014 et leurs variantes par bête')]
final class ImportBeastTraits2014Command extends Command
{
    public function __construct(private readonly Connection $db)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('apply', null, InputOption::VALUE_NONE, 'Appliquer les changements');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $rows = $this->db->fetchAllAssociative('SELECT id, slug, stat_block FROM beast WHERE stat_block IS NOT NULL ORDER BY id');
        $planned = [];
        $variants = [];
        $englishDescriptions = [];
        foreach ($rows as $row) {
            $block = is_array($row['stat_block']) ? $row['stat_block'] : json_decode((string) $row['stat_block'], true);
            foreach (($block['traits'] ?? []) as $position => $trait) {
                if (!is_array($trait) || !is_string($trait['name'] ?? null) || trim($trait['name']) === '') continue;
                $name = trim($trait['name']);
                $description = $trait['desc'] ?? $trait['description'] ?? '';
                if (is_array($description)) $description = implode("\n", array_filter($description, 'is_string'));
                if (!is_string($description)) $description = '';
                $translatedDescription = $trait['translatedDescription'] ?? $description;
                if (!is_string($translatedDescription) || $translatedDescription === '') $translatedDescription = $description;
                $normalized = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', mb_strtolower($name));
                $baseSlug = substr('beast-trait-'.trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($normalized ?: $name)), '-'), 0, 80);
                if ($baseSlug === 'beast-trait-') continue;
                $variants[$baseSlug][$translatedDescription] = true;
                $englishDescriptions[$baseSlug][$description] = true;
                $planned[] = [
                    'beast' => (int) $row['id'], 'name' => trim((string) ($trait['translatedName'] ?? $name)),
                    'originalName' => $name, 'baseSlug' => $baseSlug, 'description' => $translatedDescription,
                    'originalDescription' => $description, 'position' => (int) $position,
                ];
            }
        }
        foreach ($planned as &$entry) {
            // Le suffixe ne dépend que du texte français : des règles identiques restent partagées.
            $entry['slug'] = count($variants[$entry['baseSlug']]) > 1
                ? $entry['baseSlug'].'-'.substr(hash('sha256', $entry['description']), 0, 12)
                : $entry['baseSlug'];
        }
        unset($entry);

        $output->writeln(sprintf('%d associations de traits trouvées pour %d bêtes.', count($planned), count($rows)));
        $output->writeln(sprintf('%d définitions distinctes nécessaires (variantes mécaniques incluses).', count(array_unique(array_column($planned, 'slug')))));
        if (!$input->getOption('apply')) {
            $output->writeln('Simulation uniquement. Ajouter --apply pour enregistrer.');
            return Command::SUCCESS;
        }

        $created = $linked = $updated = $migrated = $preserved = 0;
        $this->db->transactional(function () use ($planned, $englishDescriptions, &$created, &$linked, &$updated, &$migrated, &$preserved): void {
            foreach ($planned as $entry) {
                $feature = $this->db->fetchAssociative('SELECT id, name, description, origin, custom FROM character_feature_definition WHERE slug = ?', [$entry['slug']]);
                if (!$feature) {
                    $id = $this->db->fetchOne(
                        "INSERT INTO character_feature_definition (slug, name, description, activation_type, visible, custom, origin, owner_id, created_at, updated_at) VALUES (?, ?, ?, 'passive', true, false, 'OFFICIAL', NULL, NOW(), NOW()) ON CONFLICT (slug) DO NOTHING RETURNING id",
                        [$entry['slug'], $entry['name'], $entry['description']],
                    );
                    if ($id) ++$created;
                    $feature = $this->db->fetchAssociative('SELECT id, name, description, origin, custom FROM character_feature_definition WHERE slug = ?', [$entry['slug']]);
                }
                if (!$feature) throw new \RuntimeException('Définition introuvable : '.$entry['slug']);
                $featureId = (int) $feature['id'];
                $official = $feature['origin'] === 'OFFICIAL' && !$feature['custom'];
                $oldDescriptions = array_keys($englishDescriptions[$entry['baseSlug']]);
                $safeDescription = $feature['description'] === $entry['description'] || in_array((string) $feature['description'], $oldDescriptions, true);
                $safeName = $feature['name'] === $entry['originalName'] || $feature['name'] === $entry['name'];
                if ($official && $safeDescription && $safeName && ($feature['description'] !== $entry['description'] || $feature['name'] !== $entry['name'])) {
                    $updated += $this->db->executeStatement('UPDATE character_feature_definition SET name = ?, description = ?, updated_at = NOW() WHERE id = ?', [$entry['name'], $entry['description'], $featureId]);
                } elseif (!$official || !$safeDescription || !$safeName) {
                    ++$preserved;
                }
                // Migrer uniquement l'ancien lien automatique, jamais les autres capacités attribuées par le MJ.
                if ($entry['slug'] !== $entry['baseSlug']) {
                    $old = $this->db->fetchAssociative('SELECT id, description, origin, custom FROM character_feature_definition WHERE slug = ?', [$entry['baseSlug']]);
                    if ($old && $old['origin'] === 'OFFICIAL' && !$old['custom']
                        && in_array((string) $old['description'], $oldDescriptions, true)) {
                        $migrated += $this->db->executeStatement('DELETE FROM beast_feature WHERE beast_id = ? AND feature_definition_id = ?', [$entry['beast'], (int) $old['id']]);
                    }
                }
                $linked += $this->db->executeStatement(
                    'INSERT INTO beast_feature (beast_id, feature_definition_id, display_order) VALUES (?, ?, ?) ON CONFLICT DO NOTHING',
                    [$entry['beast'], $featureId, $entry['position']],
                );
            }
        });
        $output->writeln(sprintf('Définitions créées : %d ; descriptions/libellés actualisés : %d ; anciens liens migrés : %d ; associations créées : %d ; définitions préservées : %d.', $created, $updated, $migrated, $linked, $preserved));
        return Command::SUCCESS;
    }
}
