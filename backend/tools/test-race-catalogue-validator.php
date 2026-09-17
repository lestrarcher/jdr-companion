<?php

declare(strict_types=1);

// Run: docker compose exec -T backend php tools/test-race-catalogue-validator.php

use App\Service\RaceCatalogueValidator;

require __DIR__ . '/../vendor/autoload.php';

$decode = static fn (string $path): stdClass => json_decode(file_get_contents($path), false, 512, JSON_THROW_ON_ERROR);
$clone = static fn (stdClass $value): stdClass => json_decode(json_encode($value, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
$catalogue = $decode(__DIR__ . '/../data/reference/dnd-2014-races.json');
$manifest = $decode(__DIR__ . '/../data/reference/dnd-2014-races-manifest.json');
$validator = new RaceCatalogueValidator();
$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) throw new RuntimeException($message);
    ++$checks;
};
$rejects = static function (callable $mutation, string $expected) use ($clone, $catalogue, $manifest, $validator, $check): void {
    $invalidCatalogue = $clone($catalogue);
    $invalidManifest = $clone($manifest);
    $mutation($invalidCatalogue, $invalidManifest);
    $errors = $validator->validate($invalidCatalogue, $invalidManifest);
    $check(array_filter($errors, static fn (string $error): bool => str_contains($error, $expected)) !== [], "Expected validation error containing: $expected");
};

$realErrors = $validator->validate($catalogue, $manifest);
if ($realErrors !== []) echo implode("\n", $realErrors), "\n";
$check($realErrors === [], 'The real racial catalogue and manifest must be valid.');
$check(count($catalogue->entries) === count($manifest->expectedEntrySlugs), 'Coverage must be exhaustive.');
$check(count($catalogue->entries) === 63 && count($manifest->outOfScopeSlugs) === 87, 'Reduced scope must contain 63 active and 87 excluded entries.');
$check(count(array_filter($catalogue->entries, static fn (stdClass $entry): bool => $entry->selectable)) === 58, 'Exactly 58 player options must remain.');
$check(count(array_filter($catalogue->entries, static fn (stdClass $entry): bool => !$entry->selectable)) === 5, 'Exactly five structural parents must remain.');
$check(count(array_unique($manifest->expectedEntrySlugs)) === count($manifest->expectedEntrySlugs), 'Expected slugs must be unique.');
$bySlug = [];
foreach ($catalogue->entries as $entry) $bySlug[$entry->slug] = $entry;
$motm = array_filter($catalogue->entries, static fn (stdClass $entry): bool => $entry->sourceCode === 'MPMM');
$check(count($motm) === 34, 'MotM must contain 34 database entries.');
$check(count(array_filter($motm, static fn (stdClass $entry): bool => $entry->selectable)) === 33, 'MotM must contain 33 playable options.');
$check(!isset($bySlug['goblin-dankwood-awm']), 'Adventure with Muk must be excluded.');
$check(!isset($bySlug['bugbear-erlw']), 'The identical ERLW Bugbear reprint must be merged into Volo.');
$check(!isset($bySlug['tiefling-asmodeus-mtf']), 'The Asmodeus Tiefling reprint must be merged into PHB.');
$check($bySlug['human-phb']->selectable === true, 'The standard PHB Human must be selectable.');
$check(in_array('standard-human', $bySlug['human-phb']->legacySlugs, true), 'standard-human must reconcile to human-phb.');
$check(count($bySlug['human-phb']->abilityModifiers) === 6, 'human-phb must keep its six fixed +1 modifiers.');
$check($bySlug['human-phb']->featChoiceCount === 0 && $bySlug['human-variant-phb']->featChoiceCount === 1, 'Human feat choice counts must be canonical.');
foreach (['aasimar-mpmm', 'eladrin-mpmm', 'goliath-mpmm', 'reborn-vrgr', 'shadar-kai-mpmm'] as $slug) {
    $choices = $bySlug[$slug]->abilityModifiers;
    $check(count($choices) === 2 && $choices[0]->choiceKey === 'primary' && $choices[0]->amount === 2 && $choices[1]->choiceKey === 'secondary' && $choices[1]->amount === 1, "$slug must reconstruct both historical ability choices.");
    $check($choices[0]->distinct && $choices[1]->distinct && count($choices[0]->abilities) === 6 && count($choices[1]->abilities) === 6, "$slug choices must target distinct abilities from the six scores.");
}
$variant = $bySlug['human-variant-phb'];
$check(!isset($variant->parentSlug) && $variant->inheritanceMode === 'independent', 'Variant Human must not inherit the six standard Human bonuses.');
$check(count($variant->abilityModifiers) === 1 && $variant->abilityModifiers[0]->kind === 'choice' && $variant->abilityModifiers[0]->choiceCount === 2 && $variant->abilityModifiers[0]->amount === 1, 'Variant Human must keep exactly two +1 choices.');
$check($variant->metadata->sizeOptions === ['medium'] && $variant->metadata->walkingSpeed === 9 && $variant->metadata->languages === ['common'] && $variant->metadata->languageChoiceCount === 1, 'Variant Human must carry common metadata directly.');
$check(count($variant->traits) === 5 && count($catalogue->entries[0]->traits) > 0, 'Variant Human must include three common and two unique traits.');
foreach (['elf-wood-phb' => 'elf-phb', 'halfling-lightfoot-phb' => 'halfling-phb', 'gnome-forest-phb' => 'gnome-phb', 'dwarf-hill-phb' => 'dwarf-phb'] as $child => $parent) $check($bySlug[$child]->parentSlug === $parent, "$child must retain its additive PHB parent.");
$check(in_array('eladrin', $bySlug['eladrin-mpmm']->legacySlugs, true), 'eladrin must reconcile to eladrin-mpmm.');
$check(in_array('goliath', $bySlug['goliath-mpmm']->legacySlugs, true), 'goliath must reconcile to goliath-mpmm.');
$check(in_array('shadar-kai', $bySlug['shadar-kai-mpmm']->legacySlugs, true), 'shadar-kai must reconcile to shadar-kai-mpmm.');
$check(in_array('aasimar', $bySlug['aasimar-mpmm']->legacySlugs, true), 'aasimar must reconcile to aasimar-mpmm.');
$unsupportedChoices = array_merge(...array_map(static fn (stdClass $entry): array => array_filter($entry->choices, static fn (stdClass $choice): bool => $choice->reviewStatus === 'runtime-unsupported'), $catalogue->entries));
$check(count($unsupportedChoices) === 9, 'The nine retained unsupported racial choices must remain explicit.');
$progressiveTraits = array_filter(array_merge(...array_map(static fn (stdClass $entry): array => $entry->traits, $catalogue->entries)), static fn (stdClass $trait): bool => $trait->unlockLevel > 1);
$check($progressiveTraits !== [], 'Known progressive racial traits must not all be represented at level 1.');

$rejects(static function ($catalogue): void { $catalogue->entries[0]->parentSlug = 'unknown-parent'; }, 'unknown parent');
$rejects(static function ($catalogue): void { $catalogue->entries[0]->parentSlug = $catalogue->entries[1]->slug; $catalogue->entries[1]->parentSlug = $catalogue->entries[0]->slug; }, 'Parent cycle');
$rejects(static function ($catalogue): void { $catalogue->entries[0]->sourceCode = 'UNKNOWN'; }, 'unknown or inconsistent publication');
$rejects(static function ($catalogue): void { $catalogue->entries[0]->metadata->unknown = true; }, 'unsupported');
$rejects(static function ($catalogue): void { $catalogue->entries[0]->metadata->walkingSpeed = 0; }, 'walkingSpeed');
$rejects(static function ($catalogue): void { $catalogue->entries[0]->metadata->damageResistances = ['unknown']; }, 'damageResistances');
$rejects(static function ($catalogue): void { $catalogue->entries[0]->metadata->conditionImmunities = ['unknown']; }, 'conditionImmunities');
$rejects(static function ($catalogue): void { $catalogue->entries[0]->legacySlugs = ['shared-legacy']; $catalogue->entries[1]->legacySlugs = ['shared-legacy']; }, 'ambiguous slug');
$rejects(static function ($catalogue): void { $catalogue->entries[0]->legacySlugs = [$catalogue->entries[1]->slug]; }, 'already used as an ambiguous slug');
$rejects(static function ($catalogue, $manifest): void { array_pop($catalogue->entries); }, 'missing from catalogue');
$rejects(static function ($catalogue): void { $extra = clone $catalogue->entries[0]; $extra->slug = 'unexpected-extra-entry'; $catalogue->entries[] = $extra; }, 'not declared by manifest');
$rejects(static function ($catalogue): void { $catalogue->entries[0]->choices = [(object) ['slug' => 'broken']]; }, 'choices[0] is malformed');
$rejects(static function ($catalogue): void { $catalogue->entries[0]->abilityModifiers = [(object) ['kind' => 'choice', 'amount' => 1]]; }, 'abilityModifiers[0]');
$rejects(static function ($catalogue): void { $catalogue->entries[0]->featChoiceCount = -1; }, 'featChoiceCount');
$rejects(static function ($catalogue): void { foreach ($catalogue->entries as $entry) if ($entry->slug === 'human-phb') { array_pop($entry->abilityModifiers); break; } }, 'six fixed +1');
$rejects(static function ($catalogue): void { $catalogue->entries[0]->selectable = false; unset($catalogue->entries[0]->structuralReason); }, 'requires structuralReason');
$rejects(static function ($catalogue, $manifest): void { ++$manifest->expectedCounts->playableOptionCount; }, 'expectedCounts.playableOptionCount');
$rejects(static function ($catalogue, $manifest): void { $manifest->outOfScopeSlugs[] = $catalogue->entries[0]->slug; }, 'collides with active slug');
$rejects(static function ($catalogue, $manifest): void { array_pop($manifest->outOfScopeSlugs); }, 'exactly 63 active and 87 out-of-scope');
$rejects(static function ($catalogue): void { foreach ($catalogue->entries as $entry) if ($entry->slug === 'human-variant-phb') { $entry->parentSlug = 'human-phb'; break; } }, 'Variant Human must be independent');
$rejects(static function ($catalogue): void { foreach ($catalogue->entries as $entry) if ($entry->slug === 'human-variant-phb') { $entry->abilityModifiers[0]->choiceCount = 3; break; } }, 'two distinct +1 ability choices');
$rejects(static function ($catalogue): void { foreach ($catalogue->entries as $entry) if ($entry->slug === 'aasimar-mpmm') { $entry->abilityModifiers[0]->amount = 1; break; } }, 'primary +2 and secondary +1');
$rejects(static function ($catalogue): void { foreach ($catalogue->entries as $entry) if ($entry->slug === 'reborn-vrgr') { $entry->abilityModifiers[1]->choiceKey = 'primary'; break; } }, 'invalid or duplicated');
$rejects(static function ($catalogue): void { $catalogue->entries[0]->traits[0]->slug = 'racial-properties'; }, 'traits[0] is malformed');
$rejects(static function ($catalogue): void { $catalogue->entries[0]->reviewStatus = 'approved'; $catalogue->entries[0]->functionalLimitations = ['Mécanique à valider dans la source.']; }, 'cannot be mechanically approved');
$rejects(static function ($catalogue): void {
    $target = $catalogue->localReconciliation[0]->localSlug;
    $catalogue->localReconciliation[0]->status = 'version-ambiguous';
    $catalogue->localReconciliation[0]->catalogueSlug = null;
    $catalogue->entries[0]->legacySlugs[] = $target;
}, 'ambiguous local slug cannot also be a legacySlug');
$rejects(static function ($catalogue): void { $catalogue->entries[0]->metadataByLevel = [(object) ['level' => 0, 'metadata' => (object) [], 'reviewStatus' => 'approved']]; }, 'metadataByLevel[0] is malformed');

echo sprintf("OK (%d checks)\n", $checks);
