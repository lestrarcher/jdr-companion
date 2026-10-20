<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Catalogue\Spell2014Catalogue;
use App\Service\Catalogue\Spell2014ClassAssignments;
use App\Service\Catalogue\Spell2014ImportPlan;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(name: 'app:spells:import-2014', description: 'Valide puis importe le catalogue de sorts 2014 (dry-run par défaut).')]
final class ImportSpells2014Command extends Command
{
public function __construct(
    private readonly EntityManagerInterface $em,
    #[Autowire('%kernel.project_dir%')]
    private readonly string $projectDir,
    #[Autowire('%kernel.environment%')]
    private readonly string $environment,
) {
    parent::__construct();
}
    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simulation explicite, identique au comportement par défaut.');
        $this->addOption('apply', null, InputOption::VALUE_NONE, 'Insérer uniquement si tout le catalogue admissible est valide.');
        $this->addOption(
            'local-unverified',
            null,
            InputOption::VALUE_NONE,
            'Autoriser les contenus aux droits non vérifiés, uniquement en environnement dev local.'
        );
        }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('apply') && $input->getOption('dry-run')) {
            $output->writeln('<error>--apply et --dry-run sont incompatibles.</error>');
            return Command::INVALID;
        }
        $path = $this->projectDir . '/data/jdr_companion_490_sorts_2014_durees.json';
        if (!is_file($path)) {
            $output->writeln('<error>Fichier introuvable : ' . $path . '</error>');
            return Command::FAILURE;
        }
        try {
            $contents = (string) file_get_contents($path);
            $data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
            $reviewPath = $this->projectDir . '/data/spells-2014-material-review.json';
            if (!is_file($reviewPath)) throw new \JsonException('Revue matérielle introuvable.');
            $review = json_decode((string) file_get_contents($reviewPath), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($review) || ($review['schemaVersion'] ?? null) !== 1 || ($review['inputSha256'] ?? null) !== hash('sha256', $contents)) {
                throw new \JsonException('Le catalogue a changé : renouveler sa revue explicite.');
            }
        } catch (\JsonException $e) {
            $output->writeln('<error>JSON invalide : ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
        if (!is_array($data) || !isset($data['spells']) || !is_array($data['spells']) || !array_is_list($data['spells']) || count($data['spells']) !== 490 || ($data['count'] ?? null) !== 490) {
            $output->writeln('<error>Clé spells absente ou invalide.</error>');
            return Command::FAILURE;
        }

        $enrichmentPath = $this->projectDir
    . '/data/aidedd_enrichissement_progression.json';

if (!is_file($enrichmentPath)) {
    $output->writeln('<error>Fichier d’enrichissement introuvable.</error>');
    return Command::FAILURE;
}

try {
    $enrichment = json_decode(
        (string) file_get_contents($enrichmentPath),
        true,
        512,
        JSON_THROW_ON_ERROR
    );

    if (!is_array($enrichment) || count($enrichment) !== 490) {
        throw new \JsonException('Corpus enrichi incomplet.');
    }
} catch (\JsonException $e) {
    $output->writeln(
        '<error>Enrichissement invalide : ' . $e->getMessage() . '</error>'
    );
    return Command::FAILURE;
}

['prepared' => $prepared, 'errors' => $errors, 'excluded' => $excluded, 'blockers' => $blockers] =
    (new Spell2014Catalogue())->prepare($data, $review, $enrichment);
        if (count($prepared) + count($errors) !== 487 || count($excluded) !== 3) $errors[] = 'Périmètre attendu : 487 candidats et les trois exclusions.';
        $output->writeln(sprintf('Total : %d | Préparés : %d | Exclus : %d | Erreurs : %d', count($data['spells']), count($prepared), count($excluded), count($errors)));
        $output->writeln(sprintf('Composantes : %d avec M | %d coûts scalaires en pc | %d consommations habituelles à l’incantation (partielles incluses)',
            count(array_filter($prepared, static fn ($spell) => $spell->isMaterial())),
            count(array_filter($prepared, static fn ($spell) => $spell->getMaterialCostCp() !== null)),
            count(array_filter($prepared, static fn ($spell) => $spell->isMaterialConsumed())),
        ));
        foreach ($excluded as $name) $output->writeln('EXCLU : ' . $name);
        foreach ($errors as $error) $output->writeln('<error>ERREUR : ' . $error . '</error>');
        // The very same identity/collision plan is evaluated in dry-run and before writing.
        $planner = new Spell2014ImportPlan($this->em);
        $associations = [];
        try {
            $assignmentPath = $this->projectDir.'/data/spells-2014-class-assignments.json';
            if (!is_file($assignmentPath)) throw new \RuntimeException('Fichier des associations introuvable.');
            $document = json_decode((string)file_get_contents($assignmentPath), true, 512, JSON_THROW_ON_ERROR);
            $associations = (new Spell2014ClassAssignments())->prepare($document, hash('sha256', $contents), $prepared);
            $tasha = count(array_filter($associations, static fn ($row) => $row['source'] === 'TCoE'));
            if (count($associations) !== 1235 || $tasha !== 65) throw new \RuntimeException('Périmètre attendu : 1170 associations base et 65 TCoE.');
            $plan = $planner->plan($prepared, $associations);
            $output->writeln(sprintf('Plan : %d créations | %d conservés | %d conflits', count($plan['create']), $plan['keep'], count($plan['conflicts'])));
            $output->writeln(sprintf('Associations : %d à créer | %d existantes | 1170 base | %d TCoE (actives par défaut)', count($plan['associationCreate']), $plan['associationKeep'], $tasha));
            foreach ($plan['conflicts'] as $conflict) $errors[] = $conflict;
        } catch (\Throwable $e) {
            $errors[] = 'Lecture du plan impossible : '.$e->getMessage();
        }
        $localUnverified = (bool) $input->getOption('local-unverified');

if ($localUnverified && $this->environment !== 'dev') {
    $errors[] = 'Le mode local-unverified est réservé à APP_ENV=dev.';
}

$effectiveBlockers = $blockers;

if ($localUnverified && $this->environment === 'dev') {
    $rightsBlocker = 'Droits de réutilisation du corpus non établis (résumés et traductions compris).';

    $effectiveBlockers = array_values(array_filter(
        $blockers,
        static fn (string $blocker): bool => $blocker !== $rightsBlocker
    ));

    $output->writeln(
        '<comment>Mode local : droits non vérifiés, aucune autorisation de publication.</comment>'
    );
}
        foreach ($blockers as $blocker) $output->writeln('<comment>BLOCAGE AVANT IMPORT : '.htmlspecialchars($blocker, ENT_NOQUOTES).'</comment>');
        foreach ($errors as $error) $output->writeln('<error>VALIDATION : '.htmlspecialchars($error, ENT_NOQUOTES).'</error>');
        if (!$input->getOption('apply')) {
            $output->writeln('<info>DRY-RUN : aucune écriture en base.</info>');
            $output->writeln($errors || $effectiveBlockers ? '<comment>NON PRÊT pour un import réel.</comment>' : '<info>Prêt techniquement pour revue avant import.</info>');
            return $errors || $effectiveBlockers ? Command::FAILURE : Command::SUCCESS;
        }
        if ($errors || $effectiveBlockers) {
            $output->writeln('<error>Import refusé : résoudre les erreurs et blocages avant --apply.</error>');
            return Command::FAILURE;
        }
        try {
            $result = $planner->insertMissing($prepared, $associations);
            $output->writeln(sprintf('Associations : %d créées, %d conservées.', $result['associationsCreated'], $result['associationsKept']));
            $output->writeln(sprintf('<info>Import terminé : %d créés, %d conservés.</info>', $result['created'], $result['kept']));
        } catch (\Throwable $e) {
            $output->writeln('<error>Transaction annulée : '.$e->getMessage().'</error>');
            return Command::FAILURE;
        }
        return Command::SUCCESS;
    }
}
