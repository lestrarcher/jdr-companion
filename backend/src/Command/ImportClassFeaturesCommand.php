<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\CharacterActionHandlerType;
use App\Service\ClassFeatureCatalogueValidator;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:dnd:import-class-features', description: 'Importe le référentiel D&D 2014 des classes et capacités.')]
final class ImportClassFeaturesCommand extends Command
{
    private const CATEGORIES = ['classes', 'subclasses', 'resources', 'features', 'featureRules', 'resourceRules', 'actions', 'actionRules'];
    private const METRICS = ['create', 'identical', 'kept', 'update', 'legacy', 'pending', 'custom', 'conflict'];
    private const SUBCLASS_LEGACY = ['warlock:genie' => ['genie-dao']];

    /** @var array<string, array<string, int>> */
    private array $report = [];
    /** @var list<string> */
    private array $details = [];

    public function __construct(
        private readonly Connection $connection,
        private readonly ClassFeatureCatalogueValidator $validator,
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('path', InputArgument::OPTIONAL, 'Chemin du catalogue JSON', $this->projectDir.'/data/reference/dnd-2014-class-features.json')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Construire le plan sans écrire')
            ->addOption('update-existing', null, InputOption::VALUE_NONE, 'Mettre à jour les données non custom différentes');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $this->resetReport();

        try {
            $catalogue = $this->loadAndValidate((string) $input->getArgument('path'));
            $write = !$input->getOption('dry-run');
            $update = (bool) $input->getOption('update-existing');
            $run = function () use ($catalogue, $write, $update): void {
                $this->process($catalogue, $write, $update);
                if (array_sum(array_column($this->report, 'conflict')) > 0) {
                    throw new \RuntimeException('Le plan contient des conflits ; aucune écriture ne peut être validée.');
                }
            };
            $write ? $this->connection->transactional($run) : $run();
            if (!$write) {
                $io->note('Dry-run : aucune écriture ni mutation d’entité Doctrine.');
            }
        } catch (\Throwable $error) {
            if (array_sum(array_column($this->report, 'conflict')) === 0) {
                ++$this->report['classes']['conflict'];
            }
            $this->details[] = 'ERREUR: '.$error->getMessage();
        }

        foreach ($this->details as $detail) {
            $io->writeln($detail);
        }
        $this->renderReport($io);

        return array_sum(array_column($this->report, 'conflict')) === 0 ? Command::SUCCESS : Command::FAILURE;
    }

    private function loadAndValidate(string $path): \stdClass
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new \RuntimeException('Catalogue introuvable ou illisible : '.$path);
        }
        $catalogue = json_decode((string) file_get_contents($path), false, 512, JSON_THROW_ON_ERROR);
        $errors = $this->validator->validate($catalogue);
        if ($errors !== []) {
            throw new \RuntimeException("Catalogue invalide avant écriture :\n".implode("\n", $errors));
        }

        return $catalogue;
    }

    private function process(\stdClass $c, bool $write, bool $update): void
    {
        $classes = $this->definitions('classes', 'character_class', $c->classes, $write, $update, fn ($e) => [
            'name' => $e->name, 'description' => $e->description, 'hit_die' => $e->hitDie,
            'subclass_selection_level' => $e->subclassSelectionLevel, 'spellcasting_progression' => $e->spellcastingProgression,
            'custom' => $e->custom,
        ], true);

        $subclasses = [];
        foreach ($c->subclasses as $entry) {
            $category = 'subclasses';
            if ($entry->reviewStatus === 'pending') { ++$this->report[$category]['pending']; continue; }
            $classId = $classes[$entry->classSlug] ?? null;
            if ($classId === null) { $this->conflict($category, "$entry->classSlug/$entry->slug : classe absente"); continue; }
            $key = "$entry->classSlug:$entry->slug";
            $existing = $this->row('SELECT * FROM character_subclass WHERE character_class_id = ? AND slug = ?', [$classId, $entry->slug]);
            if ($existing === null) {
                foreach (self::SUBCLASS_LEGACY[$key] ?? [] as $legacy) {
                    $match = $this->row('SELECT * FROM character_subclass WHERE character_class_id = ? AND slug = ?', [$classId, $legacy]);
                    if ($match !== null) {
                        ++$this->report[$category]['legacy'];
                        $this->details[] = "[$category] legacy match $entry->classSlug/$legacy -> $key (aucun renommage)";
                        $subclasses[$key] = (int) $match['id'];
                        continue 2;
                    }
                }
            }
            $values = ['character_class_id' => $classId, 'slug' => $entry->slug, 'name' => $entry->name,
                'description' => $entry->description, 'spellcasting_progression' => $entry->spellcastingProgression, 'custom' => $entry->custom];
            $id = $this->definition($category, 'character_subclass', $entry->slug, $values, $existing, $write, $update, true);
            if ($id !== null) { $subclasses[$key] = $id; }
        }
        $eldritchClassId = $classes['fighter'] ?? null;
        if ($eldritchClassId !== null
            && $this->row('SELECT id FROM character_subclass WHERE character_class_id = ? AND slug = ?', [$eldritchClassId, 'eldritch-knight']) !== null
            && $this->row('SELECT id FROM character_subclass WHERE character_class_id = ? AND slug = ?', [$eldritchClassId, 'chevalier-occulte']) !== null) {
            $this->details[] = '[subclasses] information: fighter/chevalier-occulte est présent avec fighter/eldritch-knight ; aucune fusion ni suppression.';
        }

        $resources = $this->definitions('resources', 'trackable_resource_definition', $c->resources, $write, $update, fn ($e) => [
            'name' => $e->name, 'description' => $e->description, 'recharge_type' => $e->rechargeType,
            'maximum_type' => $e->maximumType, 'base_maximum' => $e->baseMaximum, 'multiplier' => $e->multiplier,
            'minimum_maximum' => $e->minimumMaximum, 'scaling_ability' => $e->scalingAbility, 'custom' => $e->custom,
        ], true);

        $features = $this->definitions('features', 'character_feature_definition', $c->features, $write, $update, function ($e) use (&$resources) {
            $resourceId = $e->resourceSlug !== null ? ($resources[$e->resourceSlug] ?? null) : null;
            if ($e->resourceSlug !== null && $resourceId === null) { throw new \RuntimeException("Ressource absente pour la capacité $e->slug : $e->resourceSlug"); }
            return ['name' => $e->name, 'description' => $e->description, 'activation_type' => $e->activationType,
                'resource_definition_id' => $resourceId, 'visible' => $e->visible, 'custom' => $e->custom];
        }, true);

        $this->featureRules($c->featureRules, $classes, $subclasses, $features, $write, $update);
        $this->resourceRules($c->resourceRules, $classes, $subclasses, $resources, $write, $update);

        foreach ($c->controlledActions as $entry) {
            if ($entry->reviewStatus === 'approved' && CharacterActionHandlerType::tryFrom($entry->handlerType) === null) {
                $this->conflict('actions', "$entry->slug : handler inconnu $entry->handlerType");
            }
        }
        if ($this->report['actions']['conflict'] > 0) { throw new \RuntimeException('Handler d’action inconnu.'); }
        $actions = $this->definitions('actions', 'character_action_definition', $c->controlledActions, $write, $update, fn ($e) => [
            'name' => $e->name, 'description' => $e->description, 'handler_type' => $e->handlerType,
            'requires_preparation' => $e->requiresPreparation, 'active' => $e->active, 'custom' => $e->custom,
        ], false);
        $this->actionRules($c->actionClassRules, $classes, $actions, $write, $update);
    }

    /** @return array<string, int|null> */
    private function definitions(string $category, string $table, array $entries, bool $write, bool $update, callable $values, bool $timestamps): array
    {
        $result = [];
        foreach ($entries as $entry) {
            if ($entry->reviewStatus === 'pending') { ++$this->report[$category]['pending']; continue; }
            $existing = $this->row("SELECT * FROM $table WHERE slug = ?", [$entry->slug]);
            if ($existing === null) {
                foreach ($entry->legacySlugs ?? [] as $legacy) {
                    $legacyRow = $this->row("SELECT * FROM $table WHERE slug = ?", [$legacy]);
                    if ($legacyRow !== null) {
                        ++$this->report[$category]['legacy'];
                        $this->details[] = "[$category] legacy match $legacy -> $entry->slug (aucun renommage)";
                        $result[$entry->slug] = (int) $legacyRow['id'];
                        continue 2;
                    }
                }
            }
            $result[$entry->slug] = $this->definition($category, $table, $entry->slug, ['slug' => $entry->slug, ...$values($entry)], $existing, $write, $update, $timestamps);
        }
        return $result;
    }

    private function definition(string $category, string $table, string $slug, array $values, ?array $existing, bool $write, bool $update, bool $timestamps): ?int
    {
        if ($existing === null) {
            ++$this->report[$category]['create'];
            if (!$write) { return -1; }
            if ($timestamps) {
                $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s.u');
                $values += ['created_at' => $now, 'updated_at' => $now];
            }
            $this->connection->insert($table, $this->dbValues($values));
            return (int) $this->connection->lastInsertId();
        }
        // A null catalogue value must not disconnect an existing runtime relation.
        if ($table === 'character_feature_definition' && $values['resource_definition_id'] === null && $existing['resource_definition_id'] !== null) {
            $values['resource_definition_id'] = $existing['resource_definition_id'];
        }
        if ($table === 'character_subclass' && $values['spellcasting_progression'] === null && $existing['spellcasting_progression'] !== null) {
            $values['spellcasting_progression'] = $existing['spellcasting_progression'];
        }
        if ((bool) ($existing['custom'] ?? false)) { ++$this->report[$category]['custom']; $this->details[] = "[$category] custom protégé: $slug"; return (int) $existing['id']; }
        $diff = $this->diff($existing, $values);
        if ($diff === []) { ++$this->report[$category]['identical']; return (int) $existing['id']; }
        $this->details[] = "[$category] $slug: ".json_encode($diff, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        ++$this->report[$category][$update ? 'update' : 'kept'];
        if ($write && $update) {
            if ($timestamps) { $values['updated_at'] = (new \DateTimeImmutable())->format('Y-m-d H:i:s.u'); }
            $this->connection->update($table, $this->dbValues($values), ['id' => $existing['id']]);
        }
        return (int) $existing['id'];
    }

    private function featureRules(array $entries, array $classes, array $subclasses, array $features, bool $write, bool $update): void
    {
        foreach ($entries as $e) {
            if ($e->reviewStatus === 'pending') { ++$this->report['featureRules']['pending']; continue; }
            $sourceId = $e->sourceType === 'class' ? ($classes[$e->classSlug] ?? null) : ($subclasses["$e->classSlug:$e->subclassSlug"] ?? null);
            $featureId = $features[$e->featureSlug] ?? null;
            if ($sourceId === null || $featureId === null) { $this->conflict('featureRules', "$e->featureSlug : dépendance absente"); continue; }
            $column = $e->sourceType === 'class' ? 'character_class_id' : 'character_subclass_id';
            $this->rule('featureRules', 'character_feature_rule', [$column => $sourceId, 'feature_definition_id' => $featureId, 'unlock_level' => $e->unlockLevel], ['display_order' => $e->displayOrder], $write, $update);
        }
    }

    private function resourceRules(array $entries, array $classes, array $subclasses, array $resources, bool $write, bool $update): void
    {
        foreach ($entries as $e) {
            if ($e->reviewStatus === 'pending') { ++$this->report['resourceRules']['pending']; continue; }
            $sourceId = $e->sourceType === 'class' ? ($classes[$e->classSlug] ?? null) : ($subclasses["$e->classSlug:$e->subclassSlug"] ?? null);
            $resourceId = $resources[$e->resourceSlug] ?? null;
            if ($sourceId === null || $resourceId === null) { $this->conflict('resourceRules', "$e->resourceSlug : dépendance absente"); continue; }
            $column = $e->sourceType === 'class' ? 'character_class_id' : 'character_subclass_id';
            $this->rule('resourceRules', 'trackable_resource_rule', [$column => $sourceId, 'resource_definition_id' => $resourceId, 'unlock_level' => $e->unlockLevel], ['maximum_override' => $e->maximumOverride, 'maximum_bonus' => $e->maximumBonus], $write, $update);
        }
    }

    private function actionRules(array $entries, array $classes, array $actions, bool $write, bool $update): void
    {
        foreach ($entries as $e) {
            if ($e->reviewStatus === 'pending') { ++$this->report['actionRules']['pending']; continue; }
            $classId = $classes[$e->classSlug] ?? null; $actionId = $actions[$e->actionSlug] ?? null;
            if ($classId === null || $actionId === null) { $this->conflict('actionRules', "$e->actionSlug : dépendance absente"); continue; }
            $this->rule('actionRules', 'character_action_class_rule', ['character_class_id' => $classId, 'action_definition_id' => $actionId], ['unlock_level' => $e->unlockLevel], $write, $update);
        }
    }

    private function rule(string $category, string $table, array $identity, array $values, bool $write, bool $update): void
    {
        $where = implode(' AND ', array_map(static fn ($key) => "$key = ?", array_keys($identity)));
        $existing = $this->row("SELECT * FROM $table WHERE $where", array_values($identity));
        if ($existing === null) { ++$this->report[$category]['create']; if ($write) { $this->connection->insert($table, [...$identity, ...$values]); } return; }
        $diff = $this->diff($existing, $values);
        if ($diff === []) { ++$this->report[$category]['identical']; return; }
        ++$this->report[$category][$update ? 'update' : 'kept'];
        $this->details[] = "[$category] id {$existing['id']}: ".json_encode($diff, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if ($write && $update) { $this->connection->update($table, $values, ['id' => $existing['id']]); }
    }

    private function row(string $sql, array $params): ?array { $row = $this->connection->fetchAssociative($sql, $params); return $row === false ? null : $row; }
    private function diff(array $before, array $after): array
    {
        $diff = [];
        foreach ($after as $key => $value) {
            $old = $before[$key] ?? null;
            if (is_bool($value)) { $old = filter_var($old, FILTER_VALIDATE_BOOL); }
            if ($old !== $value && (string) $old !== (string) $value) { $diff[$key] = ['database' => $old, 'catalogue' => $value]; }
        }
        return $diff;
    }
    private function dbValues(array $values): array
    {
        return array_map(static fn (mixed $value): mixed => is_bool($value) ? (int) $value : $value, $values);
    }
    private function conflict(string $category, string $message): void { ++$this->report[$category]['conflict']; $this->details[] = "[$category] CONFLIT: $message"; }
    private function resetReport(): void { foreach (self::CATEGORIES as $c) { $this->report[$c] = array_fill_keys(self::METRICS, 0); } }
    private function renderReport(SymfonyStyle $io): void
    {
        $rows = [];
        foreach ($this->report as $category => $counts) { $rows[] = [$category, ...array_values($counts)]; }
        $io->table(['Catégorie', 'À créer', 'Identiques', 'Différents conservés', 'À mettre à jour', 'Legacy match', 'Pending ignorés', 'Custom protégés', 'Conflits'], $rows);
    }
}
