<?php
declare(strict_types=1);
namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:items:initialize-magic-weapons-2014', description: 'Import missing official +1/+2/+3 weapon references; dry-run by default.')]
final class InitializeMagicWeapons2014Command extends Command
{
    public function __construct(private readonly Connection $db, private readonly string $projectDir) { parent::__construct(); }
    protected function configure(): void { $this->addOption('apply', null, InputOption::VALUE_NONE); }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $path = $this->projectDir.'/data/reference/magic-weapons-2014-reference.json';
        $rows = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($rows) || !array_is_list($rows) || count($rows) !== 111) throw new \RuntimeException('Catalogue attendu : exactement 111 variantes.');
        $seen = []; $prepared = [];
        foreach ($rows as $r) {
            $key = $r['import_key'] ?? null;
            if (!is_string($key) || !preg_match('/^phb2014-magic-weapon-[a-z-]+-plus-[123]$/D', $key) || isset($seen[$key])) throw new \RuntimeException('Clé invalide ou dupliquée.');
            $seen[$key] = true;
            $level = (int) substr($key, -1);
            $rarity = [1=>'uncommon', 2=>'rare', 3=>'very-rare'][$level];
            if (($r['rarity'] ?? null) !== $rarity || ($r['category'] ?? null) !== 'weapon'
                || ($r['magical'] ?? null) !== '1' || ($r['equippable'] ?? null) !== '1'
                || ($r['quantity_mode'] ?? null) !== 'individual' || ($r['equipment_slot'] ?? null) !== 'hand'
                || !in_array($r['hand_usage'] ?? null, ['one','two'], true)
                || ($r['edition'] ?? null) !== '2014' || ($r['source_reference'] ?? null) !== 'DMG 2014'
                || !in_array($r['requires_attunement'] ?? null, ['', '0'], true)
                || ($r['weapon_profile'] ?? null) !== ''
                || !is_string($r['name'] ?? null) || trim($r['name']) === ''
                || !is_string($r['english_name'] ?? null) || trim($r['english_name']) === '') {
                throw new \RuntimeException('Référence inattendue : '.$key);
            }
            $weight = $r['weight_grams'] ?? '';
            $price = $r['price_copper'] ?? '';
            foreach ([$weight, $price] as $number) if ($number !== '' && (!ctype_digit((string)$number) || (int)$number < 0)) throw new \RuntimeException('Prix/poids invalide : '.$key);
            $prepared[] = [
                'import_key'=>$key, 'name'=>$r['name'], 'english_name'=>$r['english_name'],
                'description'=>$r['description'] === '' ? null : $r['description'],
                'rarity'=>$rarity, 'requires_attunement'=>0,
                'maximum_charges'=>null, 'recharge_type'=>'none', 'recharge_formula'=>null,
                'campaign_id'=>null, 'origin'=>'OFFICIAL', 'owner_id'=>null,
                'category'=>'weapon', 'magical'=>1, 'equippable'=>1,
                'quantity_mode'=>'individual', 'equipment_slot'=>'hand', 'hand_usage'=>$r['hand_usage'],
                'edition'=>'2014', 'source_reference'=>'DMG 2014', 'archived_at'=>null,
                'price_copper'=>$price === '' ? null : (int)$price,
                'weight_grams'=>$weight === '' ? null : (int)$weight,
                'weapon_profile'=>null,
            ];
        }
        return $this->db->transactional(function () use ($prepared, $input, $output): int {
            $created = 0; $existing = 0;
            foreach ($prepared as $row) {
                $matches = $this->db->fetchAllAssociative('SELECT id,origin,edition,category,magical,campaign_id,owner_id FROM magic_item WHERE import_key=? FOR UPDATE', [$row['import_key']]);
                if (count($matches) > 1) throw new \RuntimeException('Import key dupliquée en base : '.$row['import_key']);
                if ($matches) {
                    $m = $matches[0];
                    if ($m['origin'] !== 'OFFICIAL' || $m['edition'] !== '2014' || $m['category'] !== 'weapon'
                        || !filter_var($m['magical'], FILTER_VALIDATE_BOOLEAN) || $m['campaign_id'] !== null || $m['owner_id'] !== null) {
                        throw new \RuntimeException('Conflit de référence : '.$row['import_key']);
                    }
                    ++$existing; continue;
                }
                ++$created;
                if ($input->getOption('apply')) {
                    $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
                    $this->db->insert('magic_item', $row + ['created_at'=>$now, 'updated_at'=>$now]);
                }
            }
            $output->writeln(sprintf('%s : %d ; déjà présentes : %d', $input->getOption('apply') ? 'Créées' : 'À créer', $created, $existing));
            return Command::SUCCESS;
        });
    }
}
