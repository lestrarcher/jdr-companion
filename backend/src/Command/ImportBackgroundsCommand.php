<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\{CharacterBackground, LanguageDefinition, LanguageGrantRule, ProficiencyDefinition, ProficiencyGrantRule};
use App\Service\Catalogue\BackgroundImportPlanner;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputArgument, InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:dnd:import-backgrounds', description: 'Importe les historiques D&D 2014, leurs maîtrises et leurs langues.')]
final class ImportBackgroundsCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $entityManager, private readonly BackgroundImportPlanner $planner, private readonly string $projectDir)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('path', InputArgument::OPTIONAL, 'Catalogue JSON', $this->projectDir.'/data/reference/dnd-2014-backgrounds.json')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Valider et comparer sans écrire')
            ->addOption('update-existing', null, InputOption::VALUE_NONE, 'Mettre à jour explicitement les références officielles');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $path = (string) $input->getArgument('path');
            if (!is_file($path) || !is_readable($path)) throw new \RuntimeException('Catalogue introuvable : '.$path);
            $catalogue = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($catalogue)) throw new \DomainException('Catalogue invalide.');
            $db = $this->entityManager->getConnection();
            $schema = $db->createSchemaManager();
            $columns = $schema->listTableColumns('proficiency_grant_rule');
            if (!$schema->tablesExist(['character_background']) || !isset($columns['character_background_id'])) {
                throw new \RuntimeException('Migration des historiques requise, y compris pour comparer en dry-run. Aucune écriture exécutée.');
            }
            $run = function () use ($catalogue, $input, $io, $db): void {
                $backgrounds = $definitions = [];
                foreach ($this->entityManager->getRepository(CharacterBackground::class)->findAll() as $background) $backgrounds[$background->getSlug()] = $background;
                foreach ($this->entityManager->getRepository(ProficiencyDefinition::class)->findAll() as $definition) $definitions[$definition->getSlug()] = $definition;
                $rules = $this->entityManager->getRepository(ProficiencyGrantRule::class)->findAll();
                $used = array_map('intval', $db->fetchFirstColumn('SELECT DISTINCT grant_rule_id FROM character_proficiency_choice'));
                if ($this->entityManager->getConnection()->createSchemaManager()->tablesExist(['character_proficiency_substitution'])) {
                    $used = array_values(array_unique([...$used, ...array_map('intval', $this->entityManager->getConnection()->fetchFirstColumn('SELECT DISTINCT grant_rule_id FROM character_proficiency_substitution'))]));
                }
                $languages = $languageRules = [];
                if (array_filter($catalogue['backgrounds'] ?? [], static fn ($entry) => is_array($entry) && !empty($entry['languageRules']))) {
                    foreach ($this->entityManager->getRepository(LanguageDefinition::class)->findAll() as $language) $languages[$language->getSlug()] = $language;
                    $languageRules = $this->entityManager->getRepository(LanguageGrantRule::class)->findAll();
                }
                $plan = $this->planner->plan($catalogue, $backgrounds, $definitions, $rules, $used, (bool) $input->getOption('update-existing'), $languages, $languageRules);
                foreach ($catalogue['backgrounds'] as $entry) {
                    foreach ($entry['deferred'] as $reason) $io->note($entry['slug'].' : non pris en charge — '.$reason);
                }
                $rows = [];
                foreach ($plan as $kind => $items) {
                    $counts = ['create' => 0, 'unchanged' => 0, 'keep' => 0, 'update' => 0];
                    foreach ($items as $item) {
                        ++$counts[$item['action']];
                        $io->writeln($item['action'].' : '.($kind === 'backgrounds' ? $item['candidate']->getSlug() : $item['candidate']->getImportKey()));
                        if ($input->getOption('dry-run')) continue;
                        if ($item['action'] === 'create') $this->entityManager->persist($item['candidate']);
                        if ($item['action'] === 'update') {
                            if ($kind === 'backgrounds') $this->planner->applyBackgroundUpdate($item['existing'], $item['candidate']);
                            elseif ($kind === 'languageRules') $item['existing']->setLabel($item['candidate']->getLabel());
                            else $this->planner->applyRuleUpdate($item['existing'], $item['candidate']);
                        }
                    }
                    foreach ($counts as $action => $count) $rows[] = [$kind, $action, $count];
                }
                $io->table(['Références', 'Action', 'Nombre'], $rows);
            };
            if ($input->getOption('dry-run')) {
                $run();
                $io->note('Dry-run : aucune écriture en base.');
            } else {
                $this->entityManager->wrapInTransaction(function () use ($run, $db, $catalogue): void {
                    $db->executeStatement('LOCK TABLE character_background, proficiency_grant_rule, proficiency_grant_rule_option, character_proficiency_choice IN SHARE ROW EXCLUSIVE MODE');
                    // Lock only when this catalogue needs the language subsystem (legacy PHB imports remain compatible).
                    if (array_filter($catalogue['backgrounds'] ?? [], static fn ($entry) => is_array($entry) && !empty($entry['languageRules']))) {
                        $db->executeStatement('LOCK TABLE language_grant_rule IN SHARE ROW EXCLUSIVE MODE');
                    }
                    $run();
                });
            }
            $io->success('Catalogue d’historiques traité.');

            return Command::SUCCESS;
        } catch (\Throwable $error) {
            $io->error($error->getMessage());

            return Command::FAILURE;
        }
    }
}
