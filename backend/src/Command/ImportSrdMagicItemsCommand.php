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
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:import-srd-magic-items', description: 'Importe les objets magiques du SRD 5.1 (édition 2014)')]
final class ImportSrdMagicItemsCommand extends Command
{
    private const SOURCE = 'https://raw.githubusercontent.com/5e-bits/5e-database/main/src/2014/en/5e-SRD-Magic-Items.json';

    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('file', null, InputOption::VALUE_REQUIRED, 'Fichier JSON SRD local au lieu du téléchargement');
        $this->addOption('translations-file', null, InputOption::VALUE_REQUIRED, 'Fichier JSON de traductions françaises (index SRD => name, description)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $file = $input->getOption('file');
        $source = is_string($file) && $file !== '' ? $file : self::SOURCE;
        $context = stream_context_create(['http' => ['timeout' => 30, 'follow_location' => 1, 'user_agent' => 'JdR-Companion-SRD-Importer/1.0']]);
        $contents = @file_get_contents($source, false, $context);
        if ($contents === false) {
            $output->writeln('<error>Impossible de lire le catalogue : '.$source.'</error>');
            return Command::FAILURE;
        }
        try {
            $rows = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            $output->writeln('<error>JSON invalide : '.$exception->getMessage().'</error>');
            return Command::FAILURE;
        }
        if (!is_array($rows) || !array_is_list($rows) || count($rows) < 100) {
            $output->writeln('<error>Catalogue incomplet ou format inattendu. Import annulé.</error>');
            return Command::FAILURE;
        }
        // Validation complète avant toute écriture.
        $keys = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['index'], $row['name'], $row['desc'])
                || !is_string($row['index']) || !preg_match('/^[a-z0-9-]+$/', $row['index'])
                || !is_string($row['name']) || trim($row['name']) === ''
                || !is_array($row['desc']) || !is_array($row['rarity'] ?? null)
                || !is_string($row['rarity']['name'] ?? null)
                || !is_string($row['equipment_category']['index'] ?? null)
                || isset($keys[$row['index']])) {
                $output->writeln('<error>Entrée SRD invalide ou dupliquée. Import annulé.</error>');
                return Command::FAILURE;
            }
            $keys[$row['index']] = true;
        }

        $translationPath = $input->getOption('translations-file');
        if (!is_string($translationPath) || $translationPath === '' || !is_file($translationPath)) {
            $output->writeln('<error>Indiquer un fichier de traductions françaises avec --translations-file. Aucun objet importé.</error>');
            return Command::FAILURE;
        }
        try {
            $translations = json_decode((string) file_get_contents($translationPath), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            $output->writeln('<error>Traductions JSON invalides : '.$exception->getMessage().'</error>');
            return Command::FAILURE;
        }
        if (!is_array($translations) || array_is_list($translations)) {
            $output->writeln('<error>Le fichier de traductions doit être un objet JSON indexé par identifiant SRD.</error>');
            return Command::FAILURE;
        }
        foreach ($translations as $index => $translation) {
            if (!isset($keys[$index]) || !is_array($translation)
                || !is_string($translation['name'] ?? null) || trim($translation['name']) === ''
                || !is_string($translation['description'] ?? null) || trim($translation['description']) === '') {
                $output->writeln('<error>Traduction invalide ou identifiant SRD inconnu : '.$index.'</error>');
                return Command::FAILURE;
            }
        }

        $repo = $this->em->getRepository(MagicItem::class);
        $created = 0;
        $updated = 0;
        $skipped = 0;
        foreach ($rows as $row) {
            // Ne jamais introduire de libellé anglais dans le catalogue visible.
            if (!isset($translations[$row['index']])) {
                ++$skipped;
                continue;
            }
            $translation = $translations[$row['index']];
            $key = 'srd-5.1:magic-item:'.$row['index'];
            $item = $repo->findOneBy(['importKey' => $key, 'origin' => ReferenceOrigin::Official, 'owner' => null, 'campaign' => null]);
            if ($item === null) {
                $item = new MagicItem(null, $translation['name'], $this->rarity($row['rarity']['name']), null, ReferenceOrigin::Official);
                $item->setImportMetadata($key, 'SRD 5.1 (CC BY 4.0)');
                $this->em->persist($item);
                ++$created;
            } else {
                ++$updated;
            }
            $item->setName($translation['name']);
            $rarity = $this->rarity($row['rarity']['name']);
            if ($rarity !== null) $item->setRarity($rarity);
            $item->setReferenceDetails($row['name'], null, null);
            $item->setDescription($translation['description']);
            $desc = strtolower(implode(' ', array_filter($row['desc'], 'is_string')));
            $item->setRequiresAttunement(str_contains($desc, 'requires attunement'));
            // Descriptions intégrales conservées. Ne pas inventer charges/sorts depuis du texte libre.
            $category = match ($row['equipment_category']['index']) {
                'armor' => str_contains(strtolower($row['name']), 'shield') ? 'shield' : 'armor',
                'weapon', 'weapons', 'ammunition' => 'weapon',
                'potion', 'potions' => 'potion',
                'scroll', 'scrolls' => 'scroll',
                default => 'other',
            };
            // Les munitions et objets génériques ne doivent pas être classés comme armes équipables.
            if ($row['equipment_category']['index'] === 'ammunition') $category = 'other';
            $equippable = in_array($category, ['armor', 'shield', 'weapon'], true);
            $slot = $category === 'armor' ? 'armor' : ($equippable ? 'hand' : 'free');
            $hands = $category === 'shield' || $category === 'weapon' ? 'one' : null;
            $item->classify($category, true, $equippable, $slot, $hands);
        }
        $this->em->flush();
        $output->writeln(sprintf('<info>SRD 5.1 FR : %d objets créés, %d mis à jour, %d ignorés faute de traduction (%d entrées source).</info>', $created, $updated, $skipped, count($rows)));
        $output->writeln('Les mécaniques de charges et de sorts restent descriptives tant qu’elles ne sont pas explicitement configurées.');
        return Command::SUCCESS;
    }

    private function rarity(string $value): ?MagicItemRarity
    {
        return match (strtolower($value)) {
            'common' => MagicItemRarity::Common,
            'uncommon' => MagicItemRarity::Uncommon,
            'rare' => MagicItemRarity::Rare,
            'very rare' => MagicItemRarity::VeryRare,
            'legendary' => MagicItemRarity::Legendary,
            'artifact' => MagicItemRarity::Artifact,
            default => null,
        };
    }
}
