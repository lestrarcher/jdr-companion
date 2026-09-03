<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:create-user',
    description: 'Crée un compte utilisateur pour le MJ.',
)]
final class CreateUserCommand extends Command
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(
            'email',
            InputArgument::REQUIRED,
            'Adresse email du compte MJ.',
        );
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        $email = mb_strtolower(
            trim((string) $input->getArgument('email')),
        );

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $output->writeln(
                '<error>Adresse email invalide.</error>',
            );

            return Command::INVALID;
        }

        if ($this->userRepository->findOneBy([
            'email' => $email,
        ])) {
            $output->writeln(
                '<error>Un utilisateur utilise déjà cette adresse.</error>',
            );

            return Command::FAILURE;
        }

        $passwordQuestion = new Question(
            'Mot de passe : ',
        );

        $passwordQuestion->setHidden(true);
        $passwordQuestion->setHiddenFallback(false);

        $password = $this
            ->getHelper('question')
            ->ask(
                $input,
                $output,
                $passwordQuestion,
            );

        if (
            !is_string($password) ||
            mb_strlen($password) < 12
        ) {
            $output->writeln(
                '<error>Le mot de passe doit contenir au moins 12 caractères.</error>',
            );

            return Command::INVALID;
        }

        $user = new User();

        $user->setEmail($email);
        $user->setRoles(['ROLE_USER']);

        $user->setPassword(
            $this->passwordHasher->hashPassword(
                $user,
                $password,
            ),
        );

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $output->writeln(
            sprintf(
                '<info>Compte MJ créé pour %s.</info>',
                $email,
            ),
        );

        return Command::SUCCESS;
    }
}
