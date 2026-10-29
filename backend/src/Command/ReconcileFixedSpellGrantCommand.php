<?php
declare(strict_types=1);
namespace App\Command;

use App\Repository\CharacterRepository;
use App\Service\CharacterFeatureSpellService;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputArgument, InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:spells:reconcile-fixed-grant', description: 'Preview one missing fixed acquisition; --apply explicitly reconciles it.')]
final class ReconcileFixedSpellGrantCommand extends Command
{
    public function __construct(private readonly Connection $db, private readonly CharacterRepository $characters,
        private readonly CharacterFeatureSpellService $spells) { parent::__construct(); }

    protected function configure(): void
    {
        $this->addArgument('character', InputArgument::REQUIRED)
            ->addArgument('grant', InputArgument::REQUIRED)
            ->addOption('apply', null, InputOption::VALUE_NONE)
            ->addOption('dry-run', null, InputOption::VALUE_NONE);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('apply') && $input->getOption('dry-run')) return Command::INVALID;
        foreach (['character', 'grant'] as $key) {
            if (!ctype_digit((string)$input->getArgument($key)) || (int)$input->getArgument($key) < 1) {
                throw new \InvalidArgumentException('Positive IDs required.');
            }
        }
        $character = $this->characters->find((int)$input->getArgument('character'));
        if (!$character) throw new \InvalidArgumentException('Character not found.');
        $id = (int)$input->getArgument('grant');
        $grant = $this->db->fetchAssociative('SELECT mode,spell_id,managed_resource_id FROM feature_spell_grant WHERE id=?', [$id]);
        if (!$grant || $grant['mode'] !== 'fixed') throw new \InvalidArgumentException('An existing fixed grant is required.');
        $apply = (bool)$input->getOption('apply');
        $result = $this->spells->propagateFixedGrant($id, [$character], !$apply);
        $output->writeln(json_encode(['characterId'=>$character->getId(), 'grantId'=>$id,
            'spellId'=>(int)$grant['spell_id'], 'managedResourceId'=>$grant['managed_resource_id'],
            'apply'=>$apply, ...$result], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        return $result['eligible'] === 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
