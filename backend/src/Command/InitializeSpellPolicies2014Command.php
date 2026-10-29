<?php
declare(strict_types=1);
namespace App\Command;

use App\Service\Dnd2014SpellPolicyInstaller;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:spells:initialize-policies-2014', description: 'Complète les politiques et sorts fixes de sous-classe 2014 ; simulation par défaut.')]
final class InitializeSpellPolicies2014Command extends Command
{
    public function __construct(private readonly Dnd2014SpellPolicyInstaller $installer) { parent::__construct(); }
    protected function configure(): void
    {
        $this->addOption('apply', null, InputOption::VALUE_NONE, 'Enregistrer les références manquantes, sans modifier les acquisitions.');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simuler sans écriture.');
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('apply') && $input->getOption('dry-run')) return Command::INVALID;
        $report = $this->installer->run((bool) $input->getOption('apply'));
        $output->writeln(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        return $report['missing'] || $report['conflicts'] ? Command::FAILURE : Command::SUCCESS;
    }
}
