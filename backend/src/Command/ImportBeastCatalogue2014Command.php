<?php
declare(strict_types=1);
namespace App\Command;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:beasts:import-2014', description: 'Import the beast slugs from the curated 2014 XML list')]
final class ImportBeastCatalogue2014Command extends Command
{
    public function __construct(private readonly Connection $db, private readonly string $projectDir) { parent::__construct(); }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $path = $this->projectDir.'/data/beasts-2014-seed.json';
        $entries = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $count = 0;
        foreach ($entries as $entry) {
            $this->db->executeStatement('INSERT INTO beast (slug, source_url) VALUES (:slug, :url) ON CONFLICT (slug) DO UPDATE SET source_url = EXCLUDED.source_url', ['slug' => $entry['slug'], 'url' => $entry['sourceUrl']]);
            ++$count;
        }
        $output->writeln(sprintf('%d références de bêtes synchronisées (statistiques non importées).', $count));
        return Command::SUCCESS;
    }
}
