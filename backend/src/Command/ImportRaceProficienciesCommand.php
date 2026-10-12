<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\CharacterRace;
use App\Entity\ProficiencyDefinition;
use App\Entity\ProficiencyGrantRule;
use App\Service\Catalogue\RaceProficiencyImportPlanner;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputArgument, InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:dnd:import-race-proficiencies', description: 'Importe les règles de maîtrises raciales du catalogue D&D 2014.')]
final class ImportRaceProficienciesCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $entityManager, private readonly RaceProficiencyImportPlanner $planner, private readonly string $projectDir)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('path', InputArgument::OPTIONAL, 'Catalogue JSON', $this->projectDir.'/data/reference/dnd-2014-race-proficiencies.json')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Valider et comparer sans écrire')
            ->addOption('update-existing', null, InputOption::VALUE_NONE, 'Mettre à jour explicitement les règles officielles différentes');
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
            $columns = $db->createSchemaManager()->listTableColumns('proficiency_grant_rule');
            if (!isset($columns['import_key'], $columns['acquisition_mode'])) {
                throw new \RuntimeException('Migration requise : import_key et acquisition_mode doivent exister. Aucune migration ni écriture exécutée.');
            }
            $run = function () use ($catalogue, $input, $io): void {
                $races = $definitions = [];
                foreach ($this->entityManager->getRepository(CharacterRace::class)->findAll() as $race) $races[$race->getSlug()] = $race;
                foreach ($this->entityManager->getRepository(ProficiencyDefinition::class)->findAll() as $definition) $definitions[$definition->getSlug()] = $definition;
                $rules = $this->entityManager->getRepository(ProficiencyGrantRule::class)->findAll();
                $used = array_map('intval', $this->entityManager->getConnection()->fetchFirstColumn('SELECT DISTINCT grant_rule_id FROM character_proficiency_choice'));
                if ($this->entityManager->getConnection()->createSchemaManager()->tablesExist(['character_proficiency_substitution'])) {
                    $used = array_values(array_unique([...$used, ...array_map('intval', $this->entityManager->getConnection()->fetchFirstColumn('SELECT DISTINCT grant_rule_id FROM character_proficiency_substitution'))]));
                }
                $plan = $this->planner->plan($catalogue, $races, $definitions, $rules, $used, (bool) $input->getOption('update-existing'));
                foreach ($catalogue['races'] as $entry) {
                    foreach ($entry['deferred'] as $reason) $io->note($entry['slug'].' : non pris en charge — '.$reason);
                }
                $counts = ['create' => 0, 'unchanged' => 0, 'keep' => 0, 'update' => 0];
                foreach ($plan as $item) {
                    ++$counts[$item['action']];
                    $io->writeln($item['action'].' : '.$item['candidate']->getImportKey());
                    if ($input->getOption('dry-run')) continue;
                    if ($item['action'] === 'create') $this->entityManager->persist($item['candidate']);
                    if ($item['action'] === 'update') $this->planner->applyUpdate($item['existing'], $item['candidate']);
                }
                $io->table(['Action', 'Nombre'], array_map(static fn ($action, $count) => [$action, $count], array_keys($counts), array_values($counts)));
            };
            if ($input->getOption('dry-run')) {
                $run();
                $io->note('Dry-run : aucune écriture en base.');
            } else {
                $this->entityManager->wrapInTransaction(function () use ($run, $db): void {
                    // Serialize imports and prevent concurrent choices during mechanical updates.
                    $db->executeStatement('LOCK TABLE proficiency_grant_rule, proficiency_grant_rule_option, character_proficiency_choice IN SHARE ROW EXCLUSIVE MODE');
                    $run();
                });
            }
            $io->success('Catalogue de règles traité.');

            return Command::SUCCESS;
        } catch (\Throwable $error) {
            $io->error($error->getMessage());

            return Command::FAILURE;
        }
    }
}
