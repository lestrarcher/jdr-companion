<?php

declare(strict_types=1);

use App\Entity\{Character, CharacterMagicItem, MagicItem};
use App\Enum\Ability;
use App\Service\{ArmorClassCatalogueSynchronizer, CharacterAbilityCalculator, CharacterArmorClassCalculator, CharacterEquipmentService};

require dirname(__DIR__).'/vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv(dirname(__DIR__).'/.env');
$kernel = new App\Kernel('dev', true);
$kernel->boot();
$em = $kernel->getContainer()->get('doctrine')->getManager();
$db = $em->getConnection();
if (!is_file('/.dockerenv') || ($_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? '') !== 'dev' || ($db->getParams()['host'] ?? null) !== 'database') {
    throw new RuntimeException('Vérification réservée à Docker DEV.');
}
$id = filter_var($argv[1] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$id) throw new InvalidArgumentException('ID explicite du personnage requis.');
$sync = new ArmorClassCatalogueSynchronizer();
$db->beginTransaction();
$db->executeStatement('SET TRANSACTION READ ONLY');
try {
    $before = $sync->fingerprint($db);
    $character = $em->find(Character::class, $id) ?? throw new RuntimeException('Personnage introuvable.');
    $abilities = new CharacterAbilityCalculator();
    $equipment = new CharacterEquipmentService();
    $calculator = new CharacterArmorClassCalculator($abilities, $equipment);
    $report = ['characterId' => $id, 'name' => $character->getName(), 'persistedInventoryCount' => $character->getMagicItems()->count(),
        'effectiveDexterity' => $abilities->calculate($character, Ability::Dexterity)->toArray(), 'actual' => $calculator->calculate($character)];
    // These are transient PHP objects only: no persist(), flush(), SQL INSERT or inventory mutation endpoint.
    $temporary = [];
    $add = function (string $key) use ($em, $character, $equipment, &$temporary): CharacterMagicItem {
        $definition = $em->getRepository(MagicItem::class)->findOneBy(['importKey' => $key, 'owner' => null, 'campaign' => null])
            ?? throw new RuntimeException('Définition absente : '.$key);
        $owned = new CharacterMagicItem($character, $definition);
        $temporary[] = $owned;
        $character->addMagicItem($owned);
        if ($definition->requiresAttunement()) $owned->setAttuned(true);
        $equipment->equip($character, $owned, true);
        return $owned;
    };
    try {
        $tattoo = $add('tcoe:barrier-tattoo-rare');
        $bracers = $add('srd-5.1:magic-item:bracers-of-defense');
        $report['simulation']['bothEquippedAndAttuned'] = $calculator->calculate($character);
        $equipment->equip($character, $bracers, false);
        $report['simulation']['bracersUnequipped'] = $calculator->calculate($character);
        $equipment->equip($character, $bracers, true);
        $bracers->setAttuned(false);
        $report['simulation']['bracersUnattuned'] = $calculator->calculate($character);
        $bracers->setAttuned(true);
        $tattoo->setAttuned(false);
        $report['simulation']['tattooUnattuned'] = $calculator->calculate($character);
        $tattoo->setAttuned(true);
        $equipment->equip($character, $tattoo, false);
        $report['simulation']['tattooUnequipped'] = $calculator->calculate($character);
        $equipment->equip($character, $tattoo, true);
        $shield = $add('phb2014-magic-shield-plus-1');
        $report['simulation']['withShieldPlusOne'] = $calculator->calculate($character);
    } finally {
        foreach ($temporary as $owned) $character->removeMagicItem($owned);
    }
    $report['possessionsBefore'] = $before;
    $report['possessionsAfter'] = $sync->fingerprint($db);
    if ($report['possessionsBefore'] !== $report['possessionsAfter']) throw new RuntimeException('Possessions modifiées.');
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
} finally {
    $em->clear();
    $db->rollBack();
    $kernel->shutdown();
}
