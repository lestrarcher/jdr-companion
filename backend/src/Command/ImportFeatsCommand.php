<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Feat;
use App\Enum\Ability;
use App\Service\FeatCatalogueValidator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:dnd:import-feats', description: 'Importe les dons approuvés du catalogue D&D 2014.')]
final class ImportFeatsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FeatCatalogueValidator $validator,
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('path', InputArgument::OPTIONAL, 'Chemin du catalogue JSON', $this->projectDir . '/data/reference/dnd-2014-feats.json')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Valider et comparer sans écrire')
            ->addOption('update-existing', null, InputOption::VALUE_NONE, 'Appliquer les différences aux dons existants');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $counts = ['À créer' => 0, 'Existants identiques' => 0, 'Existants différents' => 0,
            'Créés' => 0, 'Mis à jour' => 0, 'Pending ignorés' => 0, 'Erreurs' => 0];
        try {
            $path = $input->getArgument('path');
            if (!is_file($path) || !is_readable($path)) {
                throw new \RuntimeException('Catalogue introuvable ou illisible : ' . $path);
            }
            $catalogue = json_decode(file_get_contents($path), false, 512, JSON_THROW_ON_ERROR);
            $errors = $this->validator->validate($catalogue);
            if ($errors !== []) {
                $counts['Erreurs'] = count($errors);
                throw new \RuntimeException(implode("\n", $errors));
            }

            // Prepare every entity before any write. Existing managed entities are not mutated here.
            $plan = [];
            foreach ($catalogue->feats as $entry) {
                if ($entry->reviewStatus === 'pending') {
                    ++$counts['Pending ignorés'];
                    continue;
                }
                $candidate = new Feat($entry->slug, $entry->name);
                $candidate->setDescription($entry->description)
                    ->setRepeatable($entry->repeatable)
                    ->setRequiresAbilityChoice($entry->requiresAbilityChoice)
                    ->setChosenAbilityIncrease($entry->chosenAbilityIncrease)
                    ->setAllowedAbilities(...array_map(Ability::from(...), $entry->allowedAbilities))
                    ->setCustom($entry->custom);
                $existing = $this->entityManager->getRepository(Feat::class)->findOneBy(['slug' => $entry->slug]);
                if ($existing === null) {
                    ++$counts['À créer'];
                    $io->writeln('À créer : ' . $entry->slug);
                    $plan[] = [$candidate, null];
                    continue;
                }
                $before = $this->values($existing);
                $after = $this->values($candidate);
                $different = false;
                foreach ($after as $field => $value) {
                    if ($before[$field] !== $value) {
                        $different = true;
                        $io->writeln(OutputFormatter::escape(sprintf('%s.%s : %s -> %s', $entry->slug, $field,
                            json_encode($before[$field], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                            json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))));
                    }
                }
                ++$counts[$different ? 'Existants différents' : 'Existants identiques'];
                if ($different && $input->getOption('update-existing')) {
                    $plan[] = [$candidate, $existing];
                }
            }

            if (!$input->getOption('dry-run') && $plan !== []) {
                $this->entityManager->wrapInTransaction(function () use ($plan): void {
                    foreach ($plan as [$candidate, $existing]) {
                        if ($existing === null) {
                            $this->entityManager->persist($candidate);
                        } else {
                            // Keep the existing identity and all relations.
                            $existing->setName($candidate->getName())
                                ->setDescription($candidate->getDescription())
                                ->setRepeatable($candidate->isRepeatable())
                                ->setRequiresAbilityChoice($candidate->requiresAbilityChoice())
                                ->setChosenAbilityIncrease($candidate->getChosenAbilityIncrease())
                                ->setAllowedAbilities(...$candidate->getAllowedAbilities())
                                ->setCustom($candidate->isCustom());
                        }
                    }
                });
                foreach ($plan as [, $existing]) {
                    ++$counts[$existing === null ? 'Créés' : 'Mis à jour'];
                }
            }
            if ($input->getOption('dry-run')) {
                $io->note('Dry-run : aucune écriture. Mises à jour prévues : ' . ($input->getOption('update-existing') ? $counts['Existants différents'] : 0));
            }
        } catch (\Throwable $error) {
            $counts['Erreurs'] = max(1, $counts['Erreurs']);
            $io->error($error->getMessage());
        }
        $io->table(['Résultat', 'Nombre'], array_map(static fn ($label, $count) => [$label, $count], array_keys($counts), $counts));

        return $counts['Erreurs'] === 0 ? Command::SUCCESS : Command::FAILURE;
    }

    private function values(Feat $feat): array
    {
        $abilities = array_map(static fn (Ability $ability) => $ability->value, $feat->getAllowedAbilities());
        sort($abilities);

        return ['slug' => $feat->getSlug(), 'name' => $feat->getName(), 'description' => $feat->getDescription(),
            'repeatable' => $feat->isRepeatable(), 'requiresAbilityChoice' => $feat->requiresAbilityChoice(),
            'chosenAbilityIncrease' => $feat->getChosenAbilityIncrease(), 'allowedAbilities' => $abilities,
            'custom' => $feat->isCustom()];
    }
}
