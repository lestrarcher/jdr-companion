<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\RaceCatalogueValidator;
use App\Service\RaceImportPlanner;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:dnd:import-races', description: 'Importe le référentiel racial D&D 2014.')]
final class ImportRacesCommand extends Command
{
    private const CATEGORIES = ['races', 'modifiers', 'traitDefinitions', 'traitRules'];
    private const METRICS = ['create', 'unchanged', 'kept', 'update', 'remove', 'preserve', 'outOfScopeDisable', 'outOfScopeAlreadyDisabled', 'protectedHistorical', 'reuseCanonical', 'preserveReferenced', 'preserveLocal', 'structural', 'compatibility', 'unresolved', 'unsupported', 'conflict'];    private array $report = [];
    private array $details = [];
    private int $virtualId = -1;

    public function __construct(private readonly Connection $connection, private readonly RaceCatalogueValidator $validator,
        private readonly RaceImportPlanner $planner, private readonly string $projectDir) { parent::__construct(); }

    protected function configure(): void
    {
        $this->addArgument('catalogue', InputArgument::OPTIONAL, 'Catalogue JSON', $this->projectDir.'/data/reference/dnd-2014-races.json')
            ->addOption('manifest', null, InputOption::VALUE_REQUIRED, 'Manifeste JSON', $this->projectDir.'/data/reference/dnd-2014-races-manifest.json')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Construire le plan sans écrire')
            ->addOption('update-existing', null, InputOption::VALUE_NONE, 'Mettre à jour les rapprochements certains');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output); $this->resetReport();
        try {
            [$catalogue, $manifest] = $this->load((string) $input->getArgument('catalogue'), (string) $input->getOption('manifest'));
            $errors = $this->validator->validate($catalogue, $manifest);
            if ($errors !== []) throw new \RuntimeException("Catalogue invalide avant traitement :\n".implode("\n", $errors));
            $write = !$input->getOption('dry-run'); $update = (bool) $input->getOption('update-existing');
            $run = function () use ($catalogue, $manifest, $write, $update): void {
                $this->process($catalogue, $manifest, $write, $update);
                if (array_sum(array_column($this->report, 'conflict')) > 0) throw new \RuntimeException('Le plan contient des conflits ; transaction annulée.');
            };
            $write ? $this->connection->transactional($run) : $run();
            if (!$write) $io->note('Dry-run strict : aucune écriture et aucune mutation Doctrine.');
        } catch (\Throwable $error) { ++$this->report['races']['conflict']; $this->details[] = 'ERREUR: '.$error->getMessage(); }
        foreach ($this->details as $detail) $io->writeln($detail);
        $this->render($io);
        return array_sum(array_column($this->report, 'conflict')) === 0 ? Command::SUCCESS : Command::FAILURE;
    }

    private function load(string $cataloguePath, string $manifestPath): array
    {
        foreach ([$cataloguePath, $manifestPath] as $path) if (!is_readable($path)) throw new \RuntimeException("Fichier illisible : $path");
        return [json_decode((string) file_get_contents($cataloguePath), false, 512, JSON_THROW_ON_ERROR),
            json_decode((string) file_get_contents($manifestPath), false, 512, JSON_THROW_ON_ERROR)];
    }

    private function process(\stdClass $catalogue, \stdClass $manifest, bool $write, bool $update): void
    {
        $columns = $this->connection->createSchemaManager()->listTableColumns('character_race');
        $required = ['selectable', 'size_options', 'walking_speed', 'movement_speeds', 'languages', 'language_choice_count', 'senses', 'damage_resistances', 'damage_immunities', 'condition_immunities'];
        $missing = array_diff($required, array_keys($columns));
        if ($missing !== []) {
            $this->details[] = '[schema] Migrations raciales non appliquées : '.implode(', ', $missing);
            if ($write) throw new \RuntimeException('Import réel impossible avant les migrations raciales.');
        }
        $rows = $this->connection->fetchAllAssociative('SELECT * FROM character_race');
        $bySlug = []; foreach ($rows as $row) $bySlug[$row['slug']] = $row;
        $strategies = $this->planner->preservedLocalStrategies($catalogue->localReconciliation);
        foreach ($catalogue->localReconciliation as $item) {
            if (!isset($bySlug[$item->localSlug])) continue;
            $strategy = $strategies[$item->localSlug];
            if ($strategy === 'KEEP_STRUCTURAL') ++$this->report['races']['structural'];
            if ($strategy === 'KEEP_COMPATIBILITY') ++$this->report['races']['compatibility'];
            if ($strategy === 'UNRESOLVED') ++$this->report['races']['unresolved'];
            if ($strategy !== 'KEEP_UPDATE') { ++$this->report['races']['protectedHistorical']; $this->details[] = "[races] PROTECTED HISTORICAL $strategy / preserved unchanged: {$item->localSlug} (ID {$bySlug[$item->localSlug]['id']})"; }
        }

        $raceIds = [];
        foreach ($this->planner->parentsFirst($catalogue->entries) as $entry) {
            $match = $this->planner->match($entry, $bySlug);
            if ($match['status'] === 'conflict') { $this->conflict('races', "$entry->slug : slug canonique et legacy coexistent ou plusieurs legacy existent"); continue; }
            $parentId = isset($entry->parentSlug) ? ($raceIds[$entry->parentSlug] ?? null) : null;
            if (isset($entry->parentSlug) && $parentId === null) { $this->conflict('races', "$entry->slug : parent $entry->parentSlug indisponible"); continue; }
            $values = $this->raceValues($entry, $parentId);
            $existing = $match['row'];
            if ($existing === null) {
                ++$this->report['races']['create'];
                $id = $write ? $this->insertRace($values) : $this->virtualId--;
                $raceIds[$entry->slug] = $id; continue;
            }
            $id = (int) $existing['id']; $raceIds[$entry->slug] = $id;
            if ($match['status'] === 'legacy') $this->details[] = "[races] {$match['legacySlug']} -> $entry->slug (ID $id conservé)";
            if ((bool) ($existing['custom'] ?? false)) { ++$this->report['races']['kept']; unset($raceIds[$entry->slug]); $this->details[] = "[races] custom protégé : $entry->slug (ID $id)"; continue; }
            if ((int) $existing['feat_choice_count'] > $entry->featChoiceCount) {
                $dependent = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM character_feat cf JOIN character c ON c.id = cf.character_id WHERE c.race_id = ? AND (cf.acquired_at_level = 1 OR cf.acquired_at_level IS NULL)', [$id]);
                if ($dependent > $entry->featChoiceCount) { $this->conflict('races', "$entry->slug : diminution featChoiceCount incompatible avec $dependent don(s) persisté(s)"); unset($values['feat_choice_count']); }
            }
            $diff = $this->diff($existing, $values);
            if ($diff === []) { ++$this->report['races']['unchanged']; }
            else { ++$this->report['races'][$update ? 'update' : 'kept']; $this->details[] = '[races] ACTIVE UPDATE '.$entry->slug.': '.json_encode($diff, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR); if ($write && $update) $this->connection->update('character_race', $values, ['id' => $id], $this->dbTypes($values)); }
        }
        foreach ($manifest->outOfScopeSlugs as $slug) {
            $row = $bySlug[$slug] ?? null;
            $action = $this->planner->outOfScopeAction($row, isset($strategies[$slug]) && $strategies[$slug] !== 'KEEP_UPDATE', $update);
            if ($action === 'absent') continue;
            if ($action === 'protected') { ++$this->report['races']['protectedHistorical']; $this->details[] = "[races] PROTECTED HISTORICAL $slug"; continue; }
            if ($action === 'already-disabled') { ++$this->report['races']['outOfScopeAlreadyDisabled']; continue; }
            if ($action === 'kept') { ++$this->report['races']['kept']; continue; }
            ++$this->report['races']['outOfScopeDisable'];
            $this->details[] = "[races] OUT_OF_SCOPE DISABLE $slug (ID {$row['id']})";
            if ($write) $this->connection->update('character_race', ['selectable' => false], ['id' => $row['id']], ['selectable' => ParameterType::BOOLEAN]);
        }
        $this->modifiers($catalogue->entries, $raceIds, $write, $update);
        $this->traits($catalogue->entries, $raceIds, $write, $update);
    }

    private function raceValues(\stdClass $entry, ?int $parentId): array
    {
        $m = $entry->metadata;
        return ['slug' => $entry->slug, 'name' => $entry->name, 'parent_race_id' => $parentId, 'custom' => false,
            'selectable' => $entry->selectable, 'feat_choice_count' => $entry->featChoiceCount, 'size_options' => $this->json($m->sizeOptions ?? null),
            'walking_speed' => $m->walkingSpeed ?? null, 'movement_speeds' => $this->json($m->movementSpeeds ?? null),
            'languages' => $this->json($m->languages ?? null), 'language_choice_count' => $m->languageChoiceCount ?? null,
            'senses' => $this->json($m->senses ?? null), 'damage_resistances' => $this->json($m->damageResistances ?? null),
            'damage_immunities' => $this->json($m->damageImmunities ?? null), 'condition_immunities' => $this->json($m->conditionImmunities ?? null)];
    }

    private function insertRace(array $values): int
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s.u'); $values += ['description' => null, 'created_at' => $now, 'updated_at' => $now];
        $this->connection->insert('character_race', $values, $this->dbTypes($values)); return (int) $this->connection->lastInsertId();
    }

    private function modifiers(array $entries, array $raceIds, bool $write, bool $update): void
    {
        foreach ($entries as $entry) {
            $raceId = $raceIds[$entry->slug] ?? null; if ($raceId === null) continue;
            $classified = [];
            foreach ($entry->abilityModifiers as $modifier) {
                if (!$this->planner->abilityModifierRepresentable($modifier)) { ++$this->report['modifiers']['unsupported']; continue; }
                $choice = $modifier->kind === 'choice';
                $label = $choice ? $modifier->choiceKey : $modifier->ability;
                $existing = ($choice
                    ? $this->connection->fetchAssociative('SELECT * FROM race_ability_modifier WHERE race_id = ? AND choice_key = ?', [$raceId, $modifier->choiceKey])
                    : $this->connection->fetchAssociative('SELECT * FROM race_ability_modifier WHERE race_id = ? AND ability = ? AND choice_key IS NULL', [$raceId, $modifier->ability])) ?: null;
                if ($existing === null) {
                    ++$this->report['modifiers']['create'];
                    if ($write) {
                        $this->connection->insert('race_ability_modifier', ['race_id' => $raceId, 'ability' => $choice ? null : $modifier->ability, 'value' => $modifier->amount, 'choice_key' => $choice ? $modifier->choiceKey : null]);
                        $classified[(int) $this->connection->lastInsertId()] = true;
                    }
                    continue;
                }
                $classified[(int) $existing['id']] = true;
                if ($choice && $existing['ability'] !== null) { $this->conflict('modifiers', "$entry->slug/$label : clé de choix occupée par un bonus fixe"); continue; }
                $referenced = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM character_race_ability_choice WHERE modifier_id = ?', [$existing['id']]) > 0;
                $action = $this->planner->modifierAction((int) $existing['value'], $modifier->amount, $referenced, $update);
                if ($action === 'conflict') { $this->conflict('modifiers', "$entry->slug/$label : modificateur référencé incompatible"); continue; }
                if ($action === 'unchanged') { ++$this->report['modifiers']['reuseCanonical']; $this->details[] = "[modifiers] REUSE_CANONICAL ID {$existing['id']} $entry->slug/$label"; }
                else { ++$this->report['modifiers'][$action]; if ($write && $action === 'update') $this->connection->update('race_ability_modifier', ['value' => $modifier->amount], ['id' => $existing['id']]); }
            }
            $existingModifiers = $this->connection->fetchAllAssociative('SELECT m.*, COUNT(c.id) AS reference_count FROM race_ability_modifier m LEFT JOIN character_race_ability_choice c ON c.modifier_id = m.id WHERE m.race_id = ? GROUP BY m.id ORDER BY m.id', [$raceId]);
            foreach ($existingModifiers as $existing) if (!isset($classified[(int) $existing['id']])) {
                $metric = (int) $existing['reference_count'] > 0 ? 'preserveReferenced' : 'preserveLocal';
                ++$this->report['modifiers'][$metric];
                $label = $metric === 'preserveReferenced' ? 'PRESERVE_REFERENCED' : 'PRESERVE_LOCAL';
                $this->details[] = "[modifiers] $label ID {$existing['id']} $entry->slug ({$existing['ability']}, {$existing['value']}, {$existing['choice_key']})";
            }
        }
    }

    private function traits(array $entries, array $raceIds, bool $write, bool $update): void
    {
        $plannedFeatures = []; $plannedRules = [];
        foreach ($entries as $entry) foreach ($entry->traits as $trait) {
            $raceId = $raceIds[$entry->slug] ?? null; if ($raceId === null) continue;
            $featureSlug = $this->planner->featureSlug($entry->slug, $trait->slug);
            $values = ['slug' => $featureSlug, 'name' => $trait->name, 'description' => $trait->summary, 'activation_type' => 'passive', 'visible' => true, 'custom' => false];
            if (isset($plannedFeatures[$featureSlug])) {
                $featureId = $plannedFeatures[$featureSlug]['id'];
                if ($this->diff($plannedFeatures[$featureSlug]['values'], $values) === []) ++$this->report['traitDefinitions']['reuseCanonical'];
                else $this->conflict('traitDefinitions', "$featureSlug : définitions catalogue incompatibles");
            } else {
                $existing = $this->connection->fetchAssociative('SELECT * FROM character_feature_definition WHERE slug = ?', [$featureSlug]) ?: null;
                if ($existing === null) { ++$this->report['traitDefinitions']['create']; $featureId = $write ? $this->insertFeature($values) : $this->virtualId--; }
                else { $featureId = (int) $existing['id']; $diff = $this->diff($existing, $values); if ($diff === []) ++$this->report['traitDefinitions']['reuseCanonical']; else { ++$this->report['traitDefinitions'][$update ? 'update' : 'kept']; if ($write && $update && !(bool) $existing['custom']) $this->connection->update('character_feature_definition', $values, ['id' => $featureId], $this->dbTypes($values)); } }
                $plannedFeatures[$featureSlug] = ['id' => $featureId, 'values' => $values];
            }
            if ($this->planner->traitIsDescriptiveOnly($trait)) ++$this->report['traitDefinitions']['unsupported'];
            $rule = $this->connection->fetchAssociative('SELECT * FROM character_feature_rule WHERE character_race_id = ? AND feature_definition_id = ? AND unlock_level = ?', [$raceId, $featureId, $trait->unlockLevel]) ?: null;
            if ($rule === null) { ++$this->report['traitRules']['create']; if ($write) $this->connection->insert('character_feature_rule', ['character_race_id' => $raceId, 'feature_definition_id' => $featureId, 'unlock_level' => $trait->unlockLevel, 'display_order' => 0]); }
            else ++$this->report['traitRules']['reuseCanonical'];
            $plannedRules[$raceId][$featureSlug][$trait->unlockLevel] = true;
        }

        foreach ($raceIds as $raceSlug => $raceId) {
            $existingRules = $this->connection->fetchAllAssociative('SELECT r.id, r.unlock_level, f.slug AS feature_slug, f.custom FROM character_feature_rule r JOIN character_feature_definition f ON f.id = r.feature_definition_id WHERE r.character_race_id = ?', [$raceId]);
            foreach ($existingRules as $rule) {
                $managedCanonical = str_starts_with($rule['feature_slug'], 'racial-'.$raceSlug.'-');
                $planned = isset($plannedRules[$raceId][$rule['feature_slug']][(int) $rule['unlock_level']]);
                if ($planned) continue;
                $action = $this->planner->staleTraitRuleAction($managedCanonical, filter_var($rule['custom'], FILTER_VALIDATE_BOOL), $update);
                ++$this->report['traitRules'][$action];
                if ($action === 'remove') {
                    $this->details[] = "[traitRules] STALE REMOVE $raceSlug/{$rule['feature_slug']} niveau {$rule['unlock_level']} (rule ID {$rule['id']})";
                    if ($write) $this->connection->delete('character_feature_rule', ['id' => $rule['id']]);
                }
            }
        }
    }

    private function insertFeature(array $values): int
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s.u'); $values += ['resource_definition_id' => null, 'created_at' => $now, 'updated_at' => $now];
        $this->connection->insert('character_feature_definition', $values, $this->dbTypes($values)); return (int) $this->connection->lastInsertId();
    }

    private function diff(array $before, array $after): array
    {
        $diff = []; foreach ($after as $key => $value) { $old = $before[$key] ?? null; if (is_bool($value)) $old = filter_var($old, FILTER_VALIDATE_BOOL); if ($old !== $value && (string) $old !== (string) $value) $diff[$key] = ['database' => $old, 'catalogue' => $value]; } return $diff;
    }
    private function json(mixed $value): ?string { return $value === null ? null : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR); }
    private function dbTypes(array $values): array
    {
        $types = [];
        foreach ($values as $column => $value) if (is_bool($value)) $types[$column] = ParameterType::BOOLEAN;
        return $types;
    }
    private function conflict(string $category, string $message): void { ++$this->report[$category]['conflict']; $this->details[] = "[$category] CONFLICT: $message"; }
    private function resetReport(): void { foreach (self::CATEGORIES as $category) $this->report[$category] = array_fill_keys(self::METRICS, 0); }
    private function render(SymfonyStyle $io): void { $rows = []; foreach ($this->report as $category => $counts) $rows[] = [$category, ...array_values($counts)]; $io->table(['Catégorie', ...self::METRICS], $rows); }
}
