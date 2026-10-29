<?php
declare(strict_types=1);
namespace App\Command;

use App\Service\FeyTouchedCastingRepair;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputArgument,InputInterface,InputOption};
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name:'app:spells:repair-fey-touched',description:'Preview a scoped Fey Touched configuration repair; --apply explicitly writes it.')]
final class RepairFeyTouchedCastingCommand extends Command
{
    public function __construct(private readonly FeyTouchedCastingRepair $repair) { parent::__construct(); }
    protected function configure(): void
    {
        $this->addArgument('character',InputArgument::REQUIRED)->addArgument('state',InputArgument::REQUIRED)
            ->addOption('apply',null,InputOption::VALUE_NONE,'Apply the reviewed configuration and initialize only its missing counters.');
    }
    protected function execute(InputInterface $input,OutputInterface $output): int
    {
        foreach (['character','state'] as $key) if (!ctype_digit((string)$input->getArgument($key)) || (int)$input->getArgument($key)<1) throw new \InvalidArgumentException('Positive IDs required.');
        $output->writeln(json_encode($this->repair->run((int)$input->getArgument('character'),(int)$input->getArgument('state'),(bool)$input->getOption('apply')),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
        return Command::SUCCESS;
    }
}
