<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\ArmorClassCatalogueSynchronizer;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpKernel\KernelInterface;

#[AsCommand(name: 'app:items:sync-ac-2014', description: 'Aperçu/import/configuration ciblés de CA, Docker DEV uniquement.')]
final class SynchronizeArmorClassCatalogueCommand extends Command
{
    public function __construct(private readonly Connection $db, private readonly ArmorClassCatalogueSynchronizer $sync, private readonly KernelInterface $kernel)
    {
        parent::__construct();
    }

    protected function configure(): void { $this->addOption('apply', null, InputOption::VALUE_NONE, 'Appliquer après vérification du plan.'); }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->kernel->getEnvironment() !== 'dev' || !is_file('/.dockerenv') || ($this->db->getParams()['host'] ?? null) !== 'database') {
            $output->writeln('<error>Commande réservée à la base du service database dans Docker DEV.</error>');
            return Command::FAILURE;
        }
        $seeds = $this->sync->loadSeeds($this->kernel->getProjectDir().'/data/armor-class-import-2014.json');
        $report = $this->sync->run($this->db, $seeds, (bool) $input->getOption('apply'));
        $output->writeln(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
        return $report['conflicts'] === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
