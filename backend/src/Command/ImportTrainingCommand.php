<?php
declare(strict_types=1);
namespace App\Command;

use App\Entity\{CharacterClass, CharacterRace, CharacterBackground, ProficiencyDefinition, LanguageDefinition, LanguageGrantRule, ExpertiseGrantRule};
use App\Service\Catalogue\TrainingImportPlanner;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:dnd:import-training', description: 'Importe les langues, expertises et complète les seules métadonnées de langues raciales manquantes.')]
final class ImportTrainingCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly TrainingImportPlanner $planner, private readonly string $projectDir) { parent::__construct(); }
    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE)->addOption('update-existing', null, InputOption::VALUE_NONE);
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $catalogue = json_decode(file_get_contents($this->projectDir.'/data/reference/dnd-2014-training.json'), true, 512, JSON_THROW_ON_ERROR);
            $races = json_decode(file_get_contents($this->projectDir.'/data/reference/dnd-2014-races.json'), true, 512, JSON_THROW_ON_ERROR);
            $db = $this->em->getConnection();
            if (!$db->createSchemaManager()->tablesExist(['language_definition', 'language_grant_rule', 'expertise_grant_rule', 'character_background'])) throw new \DomainException('Migration requise avant comparaison en base. Aucune écriture.');
            $run = function () use ($catalogue, $races, $input, $io): void {
                $existing = [];
                foreach (['languages' => LanguageDefinition::class, 'languageRules' => LanguageGrantRule::class, 'expertiseRules' => ExpertiseGrantRule::class,
                    'classes' => CharacterClass::class, 'backgrounds' => CharacterBackground::class, 'races' => CharacterRace::class, 'proficiencies' => ProficiencyDefinition::class] as $kind => $class) {
                    $existing[$kind] = [];
                    foreach ($this->em->getRepository($class)->findAll() as $entity) $existing[$kind][str_ends_with($kind, 'Rules') ? $entity->getImportKey() : $entity->getSlug()] = $entity;
                }
                $plan = $this->planner->plan($catalogue, $races, $existing, (bool) $input->getOption('update-existing'));
                foreach ($plan as $kind => $items) foreach ($items as $key => $item) {
                    $io->writeln($kind.' '.$key.' : '.$item['action']);
                    if ($input->getOption('dry-run')) continue;
                    if ($item['action'] === 'create') $this->em->persist($item['candidate']);
                    if ($item['action'] === 'update') $this->planner->applyUpdate($kind, $item);
                }
            };
            if ($input->getOption('dry-run')) $run();
            else $this->em->wrapInTransaction(function () use ($run, $db): void {
                $db->executeStatement('LOCK TABLE language_definition, language_grant_rule, expertise_grant_rule, character_race IN SHARE ROW EXCLUSIVE MODE');
                $run();
            });
            $io->success($input->getOption('dry-run') ? 'Dry-run terminé, aucune écriture.' : 'Référentiel importé.');
            return Command::SUCCESS;
        } catch (\Throwable $e) { $io->error($e->getMessage()); return Command::FAILURE; }
    }
}

