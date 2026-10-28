<?php
declare(strict_types=1);
namespace App\Command;

use App\Entity\CharacterProgression;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\LockMode;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputArgument, InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:progressions:correct-maximum', description: 'Preview an explicit initialization/correction; never scans session history.')]
final class CorrectProgressionMaximumCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em) { parent::__construct(); }

    protected function configure(): void
    {
        $this->addArgument('assignment', InputArgument::REQUIRED, 'CharacterProgression ID')
            ->addArgument('maximum', InputArgument::REQUIRED)
            ->addOption('reason', null, InputOption::VALUE_REQUIRED, 'Evidence/reason for this correction')
            ->addOption('expected', null, InputOption::VALUE_REQUIRED, 'Previously reviewed maximum, or null')
            ->addOption('apply', null, InputOption::VALUE_NONE);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $id = filter_var($input->getArgument('assignment'), FILTER_VALIDATE_INT);
        $value = filter_var($input->getArgument('maximum'), FILTER_VALIDATE_INT);
        $reason = trim((string)$input->getOption('reason'));
        if ($id === false || $id < 1 || $value === false || $reason === '' || strlen($reason) > 2000) {
            throw new \InvalidArgumentException('ID, integer maximum and reason (1–2000 bytes) required.');
        }
        $apply = (bool)$input->getOption('apply');
        $run = function () use ($id, $value, $reason, $apply, $input): array {
            $assignment = $this->em->find(CharacterProgression::class, $id);
            if (!$assignment) throw new \InvalidArgumentException('Assignment not found.');
            if ($apply) {
                $this->em->lock($assignment->getCharacter(), LockMode::PESSIMISTIC_WRITE);
                $this->em->lock($assignment, LockMode::PESSIMISTIC_WRITE);
                $this->em->refresh($assignment);
            }
            $definition = $assignment->getProgressionDefinition();
            if (!$definition->isIrreversible()) throw new \InvalidArgumentException('Progression is reversible.');
            if ($value < $definition->getMinimumValue()
                || ($definition->getMaximumValue() !== null && $value > $definition->getMaximumValue())) {
                throw new \InvalidArgumentException('Maximum outside progression bounds.');
            }
            $before = $assignment->getMaximumReached();
            if ($apply && $input->getOption('expected') !== ($before === null ? 'null' : (string)$before)) {
                throw new \RuntimeException('Expected maximum missing or stale; preview again.');
            }
            $changed = $before !== $value;
            if ($apply && $changed) {
                $assignment->correctMaximumReached($value, $reason);
                $this->em->flush();
            }
            return ['assignmentId'=>$id, 'characterId'=>$assignment->getCharacter()->getId(),
                'progressionId'=>$definition->getId(), 'before'=>$before, 'after'=>$value,
                'reason'=>$reason, 'apply'=>$apply, 'changed'=>$changed];
        };
        $result = $apply ? $this->em->wrapInTransaction($run) : $run();
        $output->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        return Command::SUCCESS;
    }
}
