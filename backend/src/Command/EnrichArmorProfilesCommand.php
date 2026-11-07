<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\ArmorProfileEnricher;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:items:enrich-armor-2014', description: 'Aperçu des profils physiques sûrs du catalogue existant ; --apply pour enregistrer.')]
final class EnrichArmorProfilesCommand extends Command
{
    public function __construct(private readonly Connection $db, private readonly ArmorProfileEnricher $enricher) { parent::__construct(); }
    protected function configure(): void { $this->addOption('apply', null, InputOption::VALUE_NONE, 'Appliquer le plan si aucun conflit.'); }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $report = $this->enricher->run($this->db, (bool) $input->getOption('apply'));
        $output->writeln(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
        return $report['conflicts'] === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
