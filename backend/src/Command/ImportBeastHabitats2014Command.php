<?php
declare(strict_types=1);
namespace App\Command;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:beasts:import-habitats-2014', description: 'Synchronize reviewed beast-habitat associations')]
final class ImportBeastHabitats2014Command extends Command
{
    public function __construct(private readonly Connection $db, private readonly string $projectDir) { parent::__construct(); }
    protected function configure(): void { $this->addOption('apply', null, InputOption::VALUE_NONE, 'Persist associations'); }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $rows = json_decode((string) file_get_contents($this->projectDir.'/data/beasts-2014-habitats.json'), true, 512, JSON_THROW_ON_ERROR);
        $valid = ['aquatique','arctique','collines','desert','foret','littoral','marais','montagne','plaine','outreterre'];
        $desired = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !in_array($row['habitat'] ?? null, $valid, true) || !is_string($row['slug'] ?? null)) throw new \RuntimeException('Association invalide.');
            $id = $this->db->fetchOne('SELECT id FROM beast WHERE slug = :slug', ['slug' => $row['slug']]);
            if ($id === false) throw new \RuntimeException('Bête inconnue : '.$row['slug']);
            $desired[(int)$id.'|'.$row['habitat']] = ['id' => (int)$id, 'habitat' => $row['habitat']];
        }
        $existing = [];
        foreach ($this->db->fetchAllAssociative('SELECT beast_id, habitat FROM beast_habitat') as $row) $existing[(int)$row['beast_id'].'|'.$row['habitat']] = $row;
        $add = array_diff_key($desired, $existing);
        $remove = array_diff_key($existing, $desired);
        $output->writeln(sprintf('Associations : %d ; à ajouter : %d ; à retirer : %d.', count($desired), count($add), count($remove)));
        if (!$input->getOption('apply')) { $output->writeln('Simulation uniquement. Ajouter --apply pour écrire.'); return Command::SUCCESS; }
        $this->db->transactional(function () use ($add, $remove): void {
            foreach ($add as $row) $this->db->executeStatement('INSERT INTO beast_habitat (beast_id, habitat) VALUES (:id, :habitat) ON CONFLICT DO NOTHING', $row);
            foreach ($remove as $row) $this->db->executeStatement('DELETE FROM beast_habitat WHERE beast_id = :id AND habitat = :habitat', ['id' => $row['beast_id'], 'habitat' => $row['habitat']]);
        });
        $output->writeln('Habitats synchronisés. Les animaux observés ne sont pas modifiés.');
        return Command::SUCCESS;
    }
}
