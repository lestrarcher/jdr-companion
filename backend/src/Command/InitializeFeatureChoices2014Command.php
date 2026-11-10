<?php
declare(strict_types=1);
namespace App\Command;

use App\Service\Catalogue\FeatureChoiceCatalogue;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:features:initialize-choices-2014', description: 'Catalogue des choix 2014 ; aperçu par défaut, aucune sélection de personnage.')]
final class InitializeFeatureChoices2014Command extends Command
{
    public function __construct(private readonly FeatureChoiceCatalogue $catalogue) { parent::__construct(); }
    protected function configure(): void { $this->addOption('apply', null, InputOption::VALUE_NONE, 'Appliquer le plan additif sans écraser les références existantes.'); }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $report = $this->catalogue->run((bool)$input->getOption('apply'));
            $io->writeln(json_encode($report, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
            $io->success(sprintf('%s : %d créations, %d références réutilisées, %d droits historiques préservés.', $input->getOption('apply') ? 'Application' : 'Aperçu', count($report['created']), count($report['reused']), $report['legacyPreserved']));
            return Command::SUCCESS;
        } catch (\Throwable $error) { $io->error($error->getMessage()); return Command::FAILURE; }
    }
}
