<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Catalogue\Spell2014TargetingImport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\{InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(name: 'app:spells:import-targeting-2014', description: 'Importe les ciblages 2014 revus ; simulation par défaut.')]
final class ImportSpellTargeting2014Command extends Command
{
    public function __construct(private readonly Spell2014TargetingImport $importer, #[Autowire('%kernel.project_dir%')] private readonly string $projectDir)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('apply', null, InputOption::VALUE_NONE, 'Appliquer après validation complète, dans une transaction.');
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Rapport JSON complet, sans formatage console.');
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Autoriser la mise à jour des ciblages déjà configurés.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $path = $this->projectDir.'/data/spells-2014-targeting.json';
            if (!is_file($path)) throw new \RuntimeException('Référence de ciblage introuvable.');
            $document = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($document)) throw new \InvalidArgumentException('Document de ciblage invalide.');
            $report = $this->importer->run($document, (bool) $input->getOption('apply'), (bool) $input->getOption('force'));
        } catch (\Throwable $error) {
            $report = ['changes'=>[], 'unchanged'=>[], 'review'=>[], 'errors'=>[$error->getMessage()], 'applied'=>0];
        }
        $report['mode'] = $input->getOption('apply') ? 'apply' : 'dry-run';
        if ($input->getOption('json')) $output->writeln(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
        else {
            $output->writeln(sprintf('%s : %d changements prévus | %d appliqués | %d inchangés | %d revues | %d erreurs', $report['mode'], count($report['changes']), $report['applied'], count($report['unchanged']), count($report['review']), count($report['errors'])));
            foreach ($report['changes'] as $change) $output->writeln(OutputFormatter::escape('CHANGEMENT '.$change['name'].' ['.$change['import_key'].'] : '.json_encode($change['after'], JSON_UNESCAPED_UNICODE)));
            foreach ($report['review'] as $entry) $output->writeln(OutputFormatter::escape('REVUE '.$entry['name'].' ['.$entry['import_key'].'] : '.$entry['reason']));
            foreach ($report['errors'] as $error) $output->writeln(OutputFormatter::escape('ERREUR '.$error));
        }
        return $report['errors'] === [] ? Command::SUCCESS : Command::FAILURE;
    }
}
