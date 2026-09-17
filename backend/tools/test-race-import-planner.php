<?php

declare(strict_types=1);

use App\Service\RaceImportPlanner;

require __DIR__.'/../vendor/autoload.php';

$planner = new RaceImportPlanner(); $checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void { if (!$condition) throw new RuntimeException($message); ++$checks; };
$parent = (object) ['slug' => 'parent', 'legacySlugs' => []];
$child = (object) ['slug' => 'child', 'parentSlug' => 'parent', 'legacySlugs' => []];
$ordered = $planner->parentsFirst([$child, $parent]);
$check(array_map(static fn ($e) => $e->slug, $ordered) === ['parent', 'child'], 'Parents must be planned first.');
$check($planner->match($parent, [])['status'] === 'new', 'A new race must be planned as new.');
$check($planner->match($parent, ['parent' => ['id' => 1, 'slug' => 'parent']])['status'] === 'exact', 'An exact slug must be reused.');
$legacy = (object) ['slug' => 'canonical', 'legacySlugs' => ['legacy']];
$match = $planner->match($legacy, ['legacy' => ['id' => 42, 'slug' => 'legacy']]);
$check($match['status'] === 'legacy' && $match['row']['id'] === 42, 'A legacy match must preserve its ID.');
$check($planner->match($legacy, ['legacy' => ['id' => 42, 'slug' => 'legacy'], 'canonical' => ['id' => 43, 'slug' => 'canonical']])['status'] === 'conflict', 'Canonical and legacy coexistence must conflict.');
$strategies = $planner->preservedLocalStrategies([
    (object) ['localSlug' => 'human', 'status' => 'structural-parent'],
    (object) ['localSlug' => 'kobold', 'status' => 'version-ambiguous'],
    (object) ['localSlug' => 'water-genasi', 'status' => 'version-ambiguous'],
    (object) ['localSlug' => 'silver', 'status' => 'compatibility-required'],
    (object) ['localSlug' => 'red', 'status' => 'compatibility-required'],
]);
$check($strategies['human'] === 'KEEP_STRUCTURAL', 'Structural races must be preserved.');
$check($strategies['kobold'] === 'UNRESOLVED' && $strategies['water-genasi'] === 'UNRESOLVED', 'Ambiguous races must remain unresolved.');
$check($strategies['silver'] === 'KEEP_COMPATIBILITY' && $strategies['red'] === 'KEEP_COMPATIBILITY', 'Fizban materializations must remain compatible locals.');
$check(count($strategies) === 5, 'Planning must not delete local entries.');
$outOfScope = ['id' => 90, 'slug' => 'kobold-vgm', 'selectable' => true, 'custom' => false];
$check($planner->outOfScopeAction($outOfScope, false, true) === 'disable', 'An excluded canonical version must be disabled in place.');
$check($planner->outOfScopeAction(['id' => 90, 'selectable' => 'f', 'custom' => 'f'], false, true) === 'already-disabled', 'PostgreSQL false values must remain false when planning visibility.');
$check($planner->outOfScopeAction($outOfScope, false, false) === 'kept', 'An excluded canonical version must not change without --update-existing.');
$check($planner->outOfScopeAction(['id' => 90, 'selectable' => false, 'custom' => false], false, true) === 'already-disabled', 'An excluded disabled race must be idempotent.');
$check($planner->outOfScopeAction(['id' => 8, 'selectable' => true, 'custom' => false], true, true) === 'protected', 'The unresolved local Kobold must be protected.');
$check($planner->outOfScopeAction(['id' => 13, 'selectable' => true, 'custom' => false], true, true) === 'protected', 'The unresolved local Water Genasi must be protected.');
$check($planner->outOfScopeAction(['id' => 19, 'selectable' => true, 'custom' => false], true, true) === 'protected', 'The historical Metallic Dragonborn must be protected.');
$check($planner->outOfScopeAction(['id' => 20, 'selectable' => true, 'custom' => false], true, true) === 'protected', 'The historical Chromatic Dragonborn must be protected.');
$check($planner->outOfScopeAction(['id' => 91, 'selectable' => true, 'custom' => true], false, true) === 'protected', 'Custom races must be protected.');
$check($planner->outOfScopeAction(null, false, true) === 'absent', 'Missing excluded races must not be created.');
$fixed = (object) ['kind' => 'fixed', 'ability' => 'strength', 'amount' => 2];
$choice = (object) ['kind' => 'choice', 'amount' => 2, 'choiceCount' => 1];
$check($planner->abilityModifierRepresentable($fixed), 'A fixed modifier must be representable.');
$check(!$planner->abilityModifierRepresentable($choice), 'A constrained choice must remain unsupported.');
$keyedChoice = (object) ['kind' => 'choice', 'choiceKey' => 'primary', 'amount' => 2, 'choiceCount' => 1, 'distinct' => true, 'abilities' => ['strength', 'dexterity', 'constitution', 'intelligence', 'wisdom', 'charisma']];
$check($planner->abilityModifierRepresentable($keyedChoice), 'A keyed free choice must be reconstructible on a fresh database.');
$restrictedChoice = clone $keyedChoice; $restrictedChoice->abilities = ['strength', 'dexterity'];
$check(!$planner->abilityModifierRepresentable($restrictedChoice), 'A restricted choice must remain unsupported by the current builder.');
$descriptive = (object) ['representation' => 'descriptive', 'reviewStatus' => 'approved'];
$unsupported = (object) ['representation' => 'full', 'reviewStatus' => 'runtime-unsupported'];
$full = (object) ['representation' => 'full', 'reviewStatus' => 'approved'];
$check($planner->traitIsDescriptiveOnly($descriptive), 'A descriptive trait must not invent mechanics.');
$check($planner->traitIsDescriptiveOnly($unsupported), 'A runtime unsupported trait must not invent mechanics.');
$check(!$planner->traitIsDescriptiveOnly($full), 'A fully represented approved trait is not descriptive-only.');
$multipleLegacy = (object) ['slug' => 'canonical', 'legacySlugs' => ['old-a', 'old-b']];
$check($planner->match($multipleLegacy, ['old-a' => ['id' => 1, 'slug' => 'old-a'], 'old-b' => ['id' => 2, 'slug' => 'old-b']])['status'] === 'conflict', 'Multiple legacy matches must conflict.');
$check($match['legacySlug'] === 'legacy', 'The matched legacy slug must be reported.');
$check($planner->featureSlug('elf-high-phb', 'darkvision-3') !== $planner->featureSlug('elf-drow-phb', 'darkvision-3'), 'Racial trait definitions must have global slugs.');
$check($planner->modifierAction(2, 2, true, true) === 'unchanged', 'An identical referenced modifier must be reused.');
$check($planner->modifierAction(1, 2, true, true) === 'conflict', 'An incompatible referenced modifier must conflict.');
$check($planner->modifierAction(1, 2, false, false) === 'kept', 'A differing modifier must be kept by default.');
$check($planner->modifierAction(1, 2, false, true) === 'update', 'A safe differing modifier may update explicitly.');
$check($planner->preservedModifierAction(true) === 'PRESERVE_REFERENCED', 'A referenced extra modifier must be preserved explicitly.');
$check($planner->preservedModifierAction(false) === 'PRESERVE_LOCAL', 'An unreferenced extra modifier must be preserved explicitly.');
$check($planner->featChoiceAction(1, 0, 0, true) === 'update', 'Eladrin featChoiceCount may decrease when unused.');
$check($planner->featChoiceAction(1, 0, 1, true) === 'conflict', 'A dangerous featChoiceCount decrease must conflict.');
$check($planner->featChoiceAction(0, 1, 0, true) === 'update', 'featChoiceCount may increase on update.');
$check($planner->featChoiceAction(1, 1, 0, true) === 'unchanged', 'A second featChoiceCount plan must be idempotent.');
$catalogue = json_decode(file_get_contents(__DIR__.'/../data/reference/dnd-2014-races.json'), false, 512, JSON_THROW_ON_ERROR);
$bySlug = []; foreach ($catalogue->entries as $entry) $bySlug[$entry->slug] = $entry;
$human = $bySlug['human-phb'];
$check(count($human->abilityModifiers) === 6 && array_reduce($human->abilityModifiers, static fn (bool $ok, stdClass $m): bool => $ok && $m->kind === 'fixed' && $m->amount === 1, true), 'human-phb must plan six fixed +1 modifiers on an empty database.');
$check($bySlug['human-variant-phb']->featChoiceCount === 1 && $human->featChoiceCount === 0, 'Canonical featChoiceCount values must reconstruct an empty database.');
foreach (['aasimar-mpmm', 'eladrin-mpmm', 'goliath-mpmm', 'reborn-vrgr', 'shadar-kai-mpmm'] as $slug) {
    $check(array_reduce($bySlug[$slug]->abilityModifiers, static fn (bool $ok, stdClass $modifier): bool => $ok && $planner->abilityModifierRepresentable($modifier), true), "$slug modifiers must be planned on a fresh database.");
}
$check($bySlug['aarakocra-mpmm']->metadata->walkingSpeed === 9 && $bySlug['aarakocra-mpmm']->traits !== [], 'Retained metadata and descriptive traits must be available to the plan.');
$check(array_filter($bySlug['human-variant-phb']->choices, static fn (stdClass $choice): bool => $choice->reviewStatus === 'runtime-unsupported') !== [], 'Unsupported choices must remain explicit.');
echo "OK ($checks checks)\n";
