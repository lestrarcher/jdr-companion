<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\DndReferenceReadiness;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:dnd:check-reference', description: 'Vérifie les référentiels indispensables à la livraison, sans écriture.')]
final class CheckDndReferenceCommand extends Command
{
    public function __construct(private readonly DndReferenceReadiness $readiness) { parent::__construct(); }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $errors = $this->readiness->check();
            if ($errors !== []) {
                $io->listing($errors);
                $io->error('Référentiels incomplets : livraison bloquée. Suivre docs/dnd-reference-deployment.md ; aucun import automatique.');
                return Command::FAILURE;
            }
        } catch (\Throwable $error) {
            $io->error('Contrôle des référentiels impossible : '.$error->getMessage());
            return Command::FAILURE;
        }
        $io->success('Référentiels de formation présents et langues raciales visibles. Contrôle en lecture seule.');
        return Command::SUCCESS;
    }
}
