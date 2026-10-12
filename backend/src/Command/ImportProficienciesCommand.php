<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\ProficiencyDefinition;
use App\Enum\Ability;
use App\Enum\ProficiencyType;
use App\Enum\ReferenceOrigin;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:dnd:import-proficiencies',
    description: 'Importe les maîtrises officielles D&D 2014.',
)]
final class ImportProficienciesCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'path',
                InputArgument::OPTIONAL,
                'Chemin du catalogue JSON',
                $this->projectDir.'/data/reference/dnd-2014-proficiencies.json',
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Comparer sans écrire en base',
            )
            ->addOption(
                'update-existing',
                null,
                InputOption::VALUE_NONE,
                'Mettre à jour les définitions officielles existantes',
            );
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        $io = new SymfonyStyle($input, $output);

        try {
            $path = (string) $input->getArgument('path');

            if (!is_file($path) || !is_readable($path)) {
                throw new \RuntimeException(
                    'Catalogue introuvable ou illisible : '.$path,
                );
            }

            $catalogue = json_decode(
                (string) file_get_contents($path),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );

            if (
                !is_array($catalogue)
                || ($catalogue['edition'] ?? null) !== '2014'
                || !isset($catalogue['proficiencies'])
                || !is_array($catalogue['proficiencies'])
                || !array_is_list($catalogue['proficiencies'])
            ) {
                throw new \RuntimeException(
                    'Structure du catalogue de maîtrises invalide.',
                );
            }

            $repository = $this->entityManager
                ->getRepository(ProficiencyDefinition::class);

            $plan = [];
            $seen = [];
            $counts = [
                'À créer' => 0,
                'Identiques' => 0,
                'Différents conservés' => 0,
                'À mettre à jour' => 0,
            ];

            // Construire et valider le plan avant toute écriture.
            foreach ($catalogue['proficiencies'] as $entry) {
                if (
                    !is_array($entry)
                    || !isset(
                        $entry['slug'],
                        $entry['name'],
                        $entry['type'],
                    )
                    || !is_string($entry['slug'])
                    || !is_string($entry['name'])
                    || !is_string($entry['type'])
                    || (
                        array_key_exists('ability', $entry)
                        && $entry['ability'] !== null
                        && !is_string($entry['ability'])
                    )
                    || (
                        array_key_exists('description', $entry)
                        && $entry['description'] !== null
                        && !is_string($entry['description'])
                    )
                ) {
                    throw new \RuntimeException(
                        'Une entrée du catalogue est invalide.',
                    );
                }

                $candidate = new ProficiencyDefinition(
                    $entry['slug'],
                    $entry['name'],
                    ProficiencyType::from($entry['type']),
                );

                $candidate
                    ->setAbility(
                        isset($entry['ability'])
                            ? Ability::from($entry['ability'])
                            : null,
                    )
                    ->setDescription($entry['description'] ?? null);

                $slug = $candidate->getSlug();

                if (isset($seen[$slug])) {
                    throw new \RuntimeException(
                        'Slug présent plusieurs fois : '.$slug,
                    );
                }

                $seen[$slug] = true;

                $type = $candidate->getType();

                if (
                    in_array(
                        $type,
                        [ProficiencyType::Skill, ProficiencyType::SavingThrow],
                        true,
                    )
                    && $candidate->getAbility() === null
                ) {
                    throw new \RuntimeException(
                        'Caractéristique obligatoire pour : '.$slug,
                    );
                }

                if (
                    !in_array(
                        $type,
                        [ProficiencyType::Skill, ProficiencyType::SavingThrow],
                        true,
                    )
                    && $candidate->getAbility() !== null
                ) {
                    throw new \RuntimeException(
                        'Caractéristique inattendue pour : '.$slug,
                    );
                }

                $existing = $repository->findOneBy([
                    'slug' => $slug,
                ]);

                if (
                    $existing !== null
                    && $existing->getOrigin() === ReferenceOrigin::Custom
                ) {
                    throw new \RuntimeException(
                        'Une référence CUSTOM utilise déjà le slug : '.$slug,
                    );
                }

                if ($existing === null) {
                    ++$counts['À créer'];
                    $io->writeln('À créer : '.$slug);
                    $plan[] = [$candidate, null];
                    continue;
                }

                // Le type identifie la nature de la maîtrise.
                // On ne le modifie pas silencieusement.
                if ($existing->getType() !== $candidate->getType()) {
                    throw new \RuntimeException(
                        'Conflit de type pour : '.$slug,
                    );
                }

                $different =
                    $existing->getName() !== $candidate->getName()
                    || $existing->getDescription() !== $candidate->getDescription()
                    || $existing->getAbility() !== $candidate->getAbility();

                if (!$different) {
                    ++$counts['Identiques'];
                    continue;
                }

                if (!$input->getOption('update-existing')) {
                    ++$counts['Différents conservés'];
                    $io->writeln('Différence conservée : '.$slug);
                    continue;
                }

                ++$counts['À mettre à jour'];
                $io->writeln('À mettre à jour : '.$slug);
                $plan[] = [$candidate, $existing];
            }

            if (!$input->getOption('dry-run') && $plan !== []) {
                $this->entityManager->wrapInTransaction(
                    function () use ($plan): void {
                        foreach ($plan as [$candidate, $existing]) {
                            if ($existing === null) {
                                $this->entityManager->persist($candidate);
                                continue;
                            }

                            // Conserver l'identité et les relations existantes.
                            $existing
                                ->setName($candidate->getName())
                                ->setDescription($candidate->getDescription())
                                ->setAbility($candidate->getAbility());
                        }
                    },
                );
            }

            if ($input->getOption('dry-run')) {
                $io->note('Dry-run : aucune écriture en base.');
            }

            $io->table(
                ['Résultat', 'Nombre'],
                array_map(
                    static fn (string $label, int $count): array => [
                        $label,
                        $count,
                    ],
                    array_keys($counts),
                    array_values($counts),
                ),
            );

            $io->success('Traitement du catalogue terminé.');

            return Command::SUCCESS;
        } catch (\Throwable $error) {
            $io->error($error->getMessage());

            return Command::FAILURE;
        }
    }
}
