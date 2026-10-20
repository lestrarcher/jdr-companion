<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Service\SpellCatalogue;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputArgument, InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:user:reference-admin', description: 'Attribue ou retire explicitement le droit de gestion des sorts OFFICIAL.')]
final class ReferenceAdminCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em) { parent::__construct(); }
    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED, 'Compte existant')->addOption('revoke', null, InputOption::VALUE_NONE, 'Retirer ce droit uniquement');
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $input->getArgument('email')]);
        if (!$user) { $output->writeln('<error>Compte introuvable.</error>'); return Command::FAILURE; }
        $roles = array_values(array_diff($user->getRoles(), [SpellCatalogue::ADMIN_ROLE]));
        if (!$input->getOption('revoke')) $roles[] = SpellCatalogue::ADMIN_ROLE;
        $user->setRoles($roles); $this->em->flush();
        $output->writeln('Droit mis à jour. Reconnectez ce compte pour renouveler sa session.');
        return Command::SUCCESS;
    }
}
