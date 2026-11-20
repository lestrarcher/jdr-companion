<?php

declare(strict_types=1);

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** Synchronise les actions françaises du stat_block sans écraser les adaptations MJ. */
#[AsCommand(name: 'app:beasts:import-natural-weapons-2014', description: 'Synchroniser les attaques et actions naturelles des bêtes')]
final class ImportBeastNaturalWeapons2014Command extends Command
{
    public function __construct(private readonly Connection $db)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('apply', null, InputOption::VALUE_NONE, 'Enregistrer les modifications');
    }

    /**
     * Conserve uniquement les effets supplémentaires dans les notes stockées en BDD.
     * La ligne d'attaque, le toucher et les dégâts principaux sont déjà structurés.
     * Les actions spéciales sans jet d'attaque conservent leur description complète.
     */
    private static function effectNotes(string $description): string
    {
        // La portée n'est pas encore structurée dans cette table : la conserver.
        $range = null;
        if (preg_match("/^Attaque d[’'\\']arme à distance\\s*:\\s*[^.]*?portée\\s+([^,\\.]+)/iu", $description, $match)) {
            $range = 'Portée : '.trim($match[1]);
        }

        // Retirer uniquement l'en-tête d'attaque, pas la règle qui suit « Touché : ».
        $description = preg_replace(
            '/^Attaque d[’\']arme (?:au corps à corps|à distance)\s*:\s*[^.]*\.\s*/iu',
            '',
            $description
        ) ?? $description;

        // Retirer le préfixe « Touché : » lorsqu'il existe.
        $description = preg_replace('/^Touché\s*:\s*/iu', '', $description) ?? $description;

        // Retirer les dégâts principaux (déjà affichés dans la colonne Dégâts).
        // Ne jamais retirer les dégâts additionnels : poison, feu, etc.
        $description = preg_replace(
            '/^\d+(?:\s*\([^)]*\))?\s+dégâts?\s+(?:de\s+)?(?:perforants?|tranchants?|contondants?)\s*(?:,?\s*et\s+|,?\s*plus\s+|\.\s*|$)/iu',
            '',
            $description
        ) ?? $description;

        $description = trim($description, " \t\n\r\0\x0B,;.");
        return $range !== null ? $range.($description !== '' ? '. '.$description : '') : $description;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $beasts = $this->db->fetchAllAssociative('SELECT id, slug, stat_block FROM beast WHERE stat_block IS NOT NULL ORDER BY id');
        $insert = [];
        $update = [];
        $preserved = 0;
        $actionsCount = 0;
        foreach ($beasts as $beast) {
            $block = is_array($beast['stat_block']) ? $beast['stat_block'] : json_decode((string) $beast['stat_block'], true, 512, JSON_THROW_ON_ERROR);
            foreach (($block['actions'] ?? []) as $position => $action) {
                if (!is_array($action) || !is_string($action['name'] ?? null) || trim($action['name']) === '') {
                    throw new \RuntimeException(sprintf('Action invalide : %s, position %d', $beast['slug'], $position));
                }
                ++$actionsCount;
                $key = 'action-'.($position + 1);
                $description = self::effectNotes(trim((string) ($action['description'] ?? '')));
                $damage = [];
                foreach (($action['damage'] ?? []) as $component) {
                    if (!is_array($component)) continue;
                    $dice = $component['damage_dice'] ?? null;
                    $type = $component['damage_type']['index'] ?? null;
                    if (is_string($dice) && $dice !== '') {
                        $damage[] = ['dice' => $dice, 'type' => is_string($type) ? $type : null];
                    }
                }
                $name = trim($action['name']);
                $bonus = is_int($action['attackBonus'] ?? null) ? $action['attackBonus'] : null;
                $kind = $bonus === null ? 'special' : (preg_match('/\b(?:à distance|distance)\b/iu', $description) ? 'ranged' : 'melee');
                $componentsJson = json_encode($damage, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                $sourceHash = hash('sha256', json_encode([$name, $kind, $bonus, $damage, $description], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
                $values = ['beast' => (int) $beast['id'], 'key' => $key, 'name' => $name, 'kind' => $kind, 'bonus' => $bonus, 'damage' => $componentsJson, 'description' => $description, 'position' => (int) $position, 'hash' => $sourceHash];
                $existing = $this->db->fetchAssociative('SELECT source_hash, customized FROM beast_natural_weapon WHERE beast_id = :beast AND source_key = :key', ['beast' => $values['beast'], 'key' => $key]);
                if ($existing === false) $insert[] = $values;
                elseif (filter_var($existing['customized'], FILTER_VALIDATE_BOOLEAN)) ++$preserved;
                elseif ($existing['source_hash'] !== $sourceHash) $update[] = $values;
            }
        }
        $output->writeln(sprintf('%d bêtes ; %d actions ; à créer : %d ; à actualiser : %d ; personnalisées préservées : %d.', count($beasts), $actionsCount, count($insert), count($update), $preserved));
        if (!$input->getOption('apply')) {
            $output->writeln('Simulation uniquement. Ajouter --apply pour écrire.');
            return Command::SUCCESS;
        }
        $this->db->transactional(function () use ($insert, $update): void {
            foreach ($insert as $row) {
                $this->db->executeStatement('INSERT INTO beast_natural_weapon (beast_id, source_key, name, attack_type, attack_bonus, damage_components, description, sort_order, source_hash) VALUES (:beast, :key, :name, :kind, :bonus, :damage, :description, :position, :hash)', $row);
            }
            foreach ($update as $row) {
                $this->db->executeStatement('UPDATE beast_natural_weapon SET name = :name, attack_type = :kind, attack_bonus = :bonus, damage_components = :damage, description = :description, sort_order = :position, source_hash = :hash WHERE beast_id = :beast AND source_key = :key AND customized = FALSE', $row);
            }
        });
        $output->writeln('Synchronisation appliquée. Aucune attaque personnalisée supprimée.');
        return Command::SUCCESS;
    }
}
