<?php
declare(strict_types=1);
namespace App\Command;
use App\Service\WeaponProfileSchema;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:items:enrich-weapons-2014', description: 'Complete missing physical profiles of existing official weapons (dry-run by default).')]
final class EnrichWeaponProfilesCommand extends Command
{
    public function __construct(private readonly Connection $db, private readonly string $projectDir) { parent::__construct(); }
    protected function configure(): void { $this->addOption('apply', null, InputOption::VALUE_NONE); }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $profiles = json_decode(file_get_contents($this->projectDir.'/data/weapon-profiles-2014.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach ($profiles as $profile) WeaponProfileSchema::validate($profile);
        return $this->db->transactional(function () use ($profiles, $input, $output) {
            $count = 0;
            foreach ($profiles as $key => $profile) {
                $rows = $this->db->fetchAllAssociative("SELECT id,name,weapon_profile FROM magic_item WHERE import_key=? AND edition='2014' AND origin='OFFICIAL' AND campaign_id IS NULL AND owner_id IS NULL AND category='weapon' AND NOT magical FOR UPDATE", [$key]);
                if (count($rows) !== 1) { $output->writeln('Absent ou ambigu : '.$key); continue; }
                $row = $rows[0];
                if ($row['weapon_profile'] !== null) {
                    if (json_decode($row['weapon_profile'], true, 512, JSON_THROW_ON_ERROR) != $profile) $output->writeln('Configuration existante préservée : '.$row['name']);
                    continue;
                }
                $output->writeln('Profil : '.$row['name']); ++$count;
                if ($input->getOption('apply')) $this->db->update('magic_item', ['weapon_profile'=>json_encode($profile, JSON_THROW_ON_ERROR)], ['id'=>$row['id']]);
            }
            $output->writeln(($input->getOption('apply') ? 'Appliqués' : 'Prévus').' : '.$count);
            return Command::SUCCESS;
        });
    }
}
