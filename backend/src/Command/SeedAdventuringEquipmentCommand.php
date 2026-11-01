<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\MagicItem;
use App\Enum\MagicItemRarity;
use App\Enum\ReferenceOrigin;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:seed-adventuring-equipment', description: 'Importe les équipements d’aventurier D&D 2014 dans le référentiel officiel')]
final class SeedAdventuringEquipmentCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $path = dirname(__DIR__, 2).'/data/adventuring-equipment-2014.json';
        $rows = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $repository = $this->em->getRepository(MagicItem::class);
        $created = 0;
        $updated = 0;
        foreach ($rows as $row) {
            // Only global OFFICIAL definitions; never touch user-created or campaign-specific items.
            $existing = $repository->findOneBy([
                'name' => $row['name'],
                'origin' => ReferenceOrigin::Official,
                'campaign' => null,
                'owner' => null,
            ]);
            if ($existing !== null) {
                $existing->setReferenceDetails(
                    $row['englishName'] ?: null,
                    $this->parsePrice($row['price']),
                    $this->parseWeight($row['weight']),
                );
                ++$updated;
                continue;
            }
            $rarity = $row['rarity'] === null ? null : MagicItemRarity::tryFrom($row['rarity']);
            if ($row['rarity'] !== null && $rarity === null) {
                throw new \RuntimeException('Rareté inconnue : '.$row['rarity']);
            }
            $item = new MagicItem(null, $row['name'], $rarity, null, ReferenceOrigin::Official);
            $item->classify($row['category'], $row['magical'], $row['equippable']);
            $item->setDescription($row['description']);
            $item->setReferenceDetails(
                $row['englishName'] ?: null,
                $this->parsePrice($row['price']),
                $this->parseWeight($row['weight']),
            );
            $this->em->persist($item);
            ++$created;
        }
        $this->em->flush();
        $output->writeln(sprintf('Objets créés : %d ; mis à jour : %d.', $created, $updated));
        return Command::SUCCESS;
    }
    private function parsePrice(string $raw): ?int
    {
        if (trim($raw) === '-' || trim($raw) === '') return null;
        if (!preg_match('/^(\\d+)\\s+(po|pa|pc)$/u', trim($raw), $m)) {
            throw new \RuntimeException('Prix non reconnu : '.$raw);
        }
        return (int) $m[1] * match ($m[2]) { 'po' => 100, 'pa' => 10, 'pc' => 1 };
    }

    private function parseWeight(string $raw): ?int
    {
        if (trim($raw) === '-' || trim($raw) === '') return null;
        if (!preg_match('/^(\\d+(?:[,.]\\d+)?)\\s*(g|kg)$/u', trim($raw), $m)) {
            throw new \RuntimeException('Poids non reconnu : '.$raw);
        }
        return (int) round((float) str_replace(',', '.', $m[1]) * ($m[2] === 'kg' ? 1000 : 1));
    }

}
