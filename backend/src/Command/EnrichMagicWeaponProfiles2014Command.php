<?php
declare(strict_types=1);
namespace App\Command;

use App\Service\WeaponProfileSchema;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:items:enrich-magic-weapons-2014', description: 'Complete missing physical profiles for +1/+2/+3 weapons; dry-run by default.')]
final class EnrichMagicWeaponProfiles2014Command extends Command
{
    public function __construct(private readonly Connection $db, private readonly string $projectDir) { parent::__construct(); }
    protected function configure(): void { $this->addOption('apply', null, InputOption::VALUE_NONE); }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $base = json_decode(file_get_contents($this->projectDir.'/data/weapon-profiles-2014.json'), true, 512, JSON_THROW_ON_ERROR);
        $catalogue = json_decode(file_get_contents($this->projectDir.'/data/reference/magic-weapons-2014-reference.json'), true, 512, JSON_THROW_ON_ERROR);
        if (count($catalogue) !== 111) throw new \RuntimeException('Catalogue incomplet.');
        $targets = [];
        foreach ($catalogue as $item) {
            $key = $item['import_key'];
            if (!preg_match('/^phb2014-magic-weapon-([a-z-]+)-plus-([123])$/D', $key, $m)) throw new \RuntimeException('Clé invalide : '.$key);
            if ($m[1] === 'net') {
                $source = [
                    'proficiency'=>'net', 'group'=>'martial-weapons', 'mode'=>'ranged',
                    'dice'=>null, 'damageType'=>null, 'properties'=>['thrown','special'],
                    'attackBonus'=>(int)$m[2], 'damageBonus'=>0, 'range'=>[1.5,4.5],
                    'notes'=>'Une créature de taille G ou inférieure touchée est entravée. Évasion : action, test de Force DD 10. Le filet peut être détruit en infligeant 5 dégâts tranchants (CA 10). Une attaque au filet ne permet qu’une seule attaque, même avec Attaque supplémentaire. Portée 1,50/4,50 m : désavantage habituel au contact ou à longue portée.'
                ];
            } else {
                $source = $base['phb2014-weapon-'.$m[1]] ?? null;
            }
            if ($source === null) throw new \RuntimeException('Profil ordinaire absent : '.$m[1]);
            if ($m[1] !== 'net') {
                $source['attackBonus'] = (int)$m[2];
                $source['damageBonus'] = (int)$m[2];
            }
            $targets[$key] = WeaponProfileSchema::validate($source);
        }
        if (count($targets) !== 111) throw new \RuntimeException('111 profils attendus, filets inclus.');
        return $this->db->transactional(function () use ($targets, $input, $output): int {
            $updated = 0; $already = 0;
            foreach ($targets as $key => $profile) {
                $rows = $this->db->fetchAllAssociative("SELECT id,name,weapon_profile FROM magic_item WHERE import_key=? AND edition='2014' AND origin='OFFICIAL' AND campaign_id IS NULL AND owner_id IS NULL AND category='weapon' AND magical=TRUE FOR UPDATE", [$key]);
                if (count($rows) !== 1) throw new \RuntimeException('Référence absente ou ambiguë : '.$key);
                $row = $rows[0];
                if ($row['weapon_profile'] !== null) {
                    $existing = json_decode($row['weapon_profile'], true, 512, JSON_THROW_ON_ERROR);
                    if ($existing != $profile) throw new \RuntimeException('Profil déjà configuré différemment : '.$key);
                    ++$already; continue;
                }
                ++$updated;
                if ($input->getOption('apply')) $this->db->update('magic_item', ['weapon_profile'=>json_encode($profile, JSON_THROW_ON_ERROR), 'updated_at'=>(new \DateTimeImmutable())->format('Y-m-d H:i:s')], ['id'=>$row['id']]);
            }
            $output->writeln(sprintf('%s : %d ; déjà conformes : %d ; filets inclus : 3', $input->getOption('apply') ? 'Enrichies' : 'À enrichir', $updated, $already));
            return Command::SUCCESS;
        });
    }
}
