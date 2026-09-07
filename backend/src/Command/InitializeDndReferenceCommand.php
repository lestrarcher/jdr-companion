<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\DndReferenceInitializer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:dnd:initialize-reference',
    description: 'Initialise le référentiel D&D 2014.',
)]
final class InitializeDndReferenceCommand extends Command
{
    public function __construct(
        private readonly DndReferenceInitializer $initializer,
    ) {
        parent::__construct();
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        $this->initializer->initialize();

        $io = new SymfonyStyle($input, $output);
        $io->success('Le référentiel D&D 2014 est initialisé.');

        return Command::SUCCESS;
    }
}
