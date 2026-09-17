<?php

declare(strict_types=1);

use App\Controller\DndReferenceController;
use App\Entity\CharacterRace;
use App\Service\CharacterRaceMetadataResolver;

require __DIR__.'/../vendor/autoload.php';

$catalogue = json_decode((string) file_get_contents(__DIR__.'/../data/reference/dnd-2014-races.json'), false, 512, JSON_THROW_ON_ERROR);
$playable = [];
foreach ($catalogue->entries as $entry) if ($entry->selectable) $playable[$entry->slug] = true;
$controller = new DndReferenceController(new CharacterRaceMetadataResolver(), dirname(__DIR__));
$method = new ReflectionMethod($controller, 'serializeRace');
$checks = 0;
$check = static function (string $slug, bool $custom, bool $expected, bool $storedSelectable = true) use ($controller, $method, $playable, &$checks): void {
    $race = (new CharacterRace($slug, $slug))->setCustom($custom)->setSelectable($storedSelectable);
    $actual = $method->invoke($controller, $race, $playable)['selectable'];
    if ($actual !== $expected) throw new RuntimeException("Unexpected builder visibility for $slug.");
    ++$checks;
};
$check('human-phb', false, true);
$check('human-phb', false, false, false);
$check('elf-phb', false, false);
$check('kobold-vgm', false, false);
$check('kobold', false, false);
$check('water-genasi', false, false);
$check('metallic-dragonborn-silver', false, false);
$check('chromatic-dragonborn-red', false, false);
$check('my-custom-race', true, true);

echo "OK ($checks checks)\n";
