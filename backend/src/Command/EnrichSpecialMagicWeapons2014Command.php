<?php
declare(strict_types=1);
namespace App\Command;

use App\Entity\MagicItem;
use App\Service\SpecialWeaponProfiles;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputInterface,InputOption};
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:items:enrich-special-weapons-2014', description: 'Enrich fixed special weapon profiles, dry-run by default.')]
final class EnrichSpecialMagicWeapons2014Command extends Command
{
    public function __construct(private readonly EntityManagerInterface $em) { parent::__construct(); }
    protected function configure(): void { $this->addOption('apply',null,InputOption::VALUE_NONE); }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $db=$this->em->getConnection();
        $rows=$db->fetchAllAssociative("SELECT id, import_key, weapon_profile FROM magic_item WHERE origin='OFFICIAL' AND edition='2014' AND category='weapon' AND magical=TRUE AND campaign_id IS NULL AND owner_id IS NULL AND import_key LIKE 'srd-5.1:magic-item:%'");
        $pending=[]; $corrections=[]; $existing=0; $variable=0;
        foreach ($rows as $row) {
            $item=$this->em->getRepository(MagicItem::class)->find($row['id']);
            if (!$item instanceof MagicItem) continue;
            if (SpecialWeaponProfiles::isVariable($item)) { ++$variable; continue; }
            if (!SpecialWeaponProfiles::isFixed($item)) continue;
            $profile=SpecialWeaponProfiles::profile($item,null);
            if ($row['weapon_profile'] !== null) {
                $current=json_decode($row['weapon_profile'],true,512,JSON_THROW_ON_ERROR);
                if ($current != $profile) {
                    // Exception ciblée : retirer uniquement la polyvalence héritée de l’épée longue.
                    if (str_ends_with($item->getImportKey() ?? '', ':sun-blade')) {
                        $legacy=$profile;
                        $legacy['versatile']='1d10';
                        if ($current == $legacy) { $corrections[$item->getId()]=$profile; continue; }
                    }
                    $output->writeln('<error>Profil déjà différent : '.$item->getName().' (ID '.$item->getId().')</error>');
                    return Command::FAILURE;
                }
                ++$existing;
                continue;
            }
            $pending[$item->getId()]=$profile;
        }
        $output->writeln(sprintf('Fixes à enrichir : %d ; déjà conformes : %d ; variables sans profil de référence : %d.',count($pending),$existing,$variable));
        $output->writeln(sprintf('Corrections Épée radieuse : %d.', count($corrections))); 
        if (!$input->getOption('apply')) { $output->writeln('Simulation uniquement. Ajouter --apply pour écrire.'); return Command::SUCCESS; }
        $db->transactional(function () use ($db,$pending,$corrections): void {
            foreach ($corrections as $id=>$profile) {
                $changed=$db->executeStatement('UPDATE magic_item SET weapon_profile = ?, updated_at = ? WHERE id = ?', [json_encode($profile,JSON_THROW_ON_ERROR),(new \DateTimeImmutable())->format('Y-m-d H:i:s'),$id]);
                if ($changed !== 1) throw new \RuntimeException('Correction impossible pour ID '.$id);
            }
            foreach ($pending as $id=>$profile) {
                $changed=$db->executeStatement('UPDATE magic_item SET weapon_profile = ?, updated_at = ? WHERE id = ? AND weapon_profile IS NULL',[
                    json_encode($profile,JSON_THROW_ON_ERROR),(new \DateTimeImmutable())->format('Y-m-d H:i:s'),$id,
                ]);
                if ($changed !== 1) throw new \RuntimeException('Modification concurrente détectée pour ID '.$id);
            }
        });
        $output->writeln('Enrichissement appliqué.');
        return Command::SUCCESS;
    }
}
