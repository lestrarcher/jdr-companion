<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Character;
use App\Entity\CharacterMagicItem;
use App\Entity\CharacterSpellAcquisition;
use App\Service\SpellbookLibrary;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** One-time, explicitly targeted migration. Does not change the legacy preparation engine. */
#[AsCommand(name: 'app:spellbooks:backfill-character', description: 'Copy existing wizard spellbook acquisitions into a specified physical spellbook')]
final class BackfillCharacterSpellbookCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly SpellbookLibrary $library)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('character-id', InputArgument::REQUIRED, 'Character ID')
            ->addArgument('book-id', InputArgument::REQUIRED, 'Owned magic item ID of the target spellbook')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Persist changes (default: dry run)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $character = $this->em->find(Character::class, (int) $input->getArgument('character-id'));
        $book = $this->em->find(CharacterMagicItem::class, (int) $input->getArgument('book-id'));
        if (!$character || !$book || $book->getCharacter()->getId() !== $character->getId()
            || $book->getMagicItem()->getCategory() !== 'spellbook' || $book->getQuantity() !== 1) {
            $output->writeln('<error>Character or owned individual spellbook not found.</error>');
            return Command::FAILURE;
        }

        $acquisitions = $this->em->getRepository(CharacterSpellAcquisition::class)->findBy(['character' => $character]);
        $spells = [];
        foreach ($acquisitions as $acquisition) {
            if (!$acquisition->isInSpellbook() || $acquisition->getSpell()->getLevel() < 1
                || $acquisition->getPolicyDefinition()->policy()->learningStyle !== 'spellbook') {
                continue;
            }
            $spell = $acquisition->getSpell();
            $spells[(int) $spell->getId()] = $spell;
        }

        $existing = array_column($this->library->contents($book), 'spellId');
        $missing = array_diff(array_keys($spells), $existing);
        $output->writeln(sprintf('Character %d, book %d: %d historical spells, %d missing.',
            $character->getId(), $book->getId(), count($spells), count($missing)));
        foreach ($missing as $id) {
            $output->writeln(sprintf('  + %s (level %d)', $spells[$id]->getName(), $spells[$id]->getLevel()));
        }
        if (!$input->getOption('apply')) {
            $output->writeln('<comment>Dry run. Repeat with --apply to write.</comment>');
            return Command::SUCCESS;
        }
        $this->em->wrapInTransaction(function () use ($book, $missing, $spells): void {
            foreach ($missing as $id) {
                $this->library->inscribe($book, $spells[$id]);
            }
        });
        $output->writeln('<info>Backfill completed.</info>');
        return Command::SUCCESS;
    }
}
