<?php

declare(strict_types=1);

// Run: docker compose exec -T backend php tools/test-import-race-proficiencies.php
// All fixtures and import/update simulations stay in memory. No SQL or migration.
use App\Entity\{Campaign, Character, CharacterRace, CharacterClass, CharacterClassLevel, ProficiencyDefinition, ProficiencyGrantRule, User};
use App\Enum\{Ability, ProficiencyType, ProficiencyAcquisitionMode, ReferenceOrigin};
use App\Repository\ProficiencyGrantRuleRepository;
use App\Service\Catalogue\RaceProficiencyImportPlanner;
use App\Service\{CharacterProficiencyResolver, CharacterProficiencyChoiceService};

require __DIR__.'/../vendor/autoload.php';
$load = static fn (string $file) => json_decode(file_get_contents(__DIR__.'/../data/reference/'.$file), true, 512, JSON_THROW_ON_ERROR);
$catalogue = $load('dnd-2014-race-proficiencies.json');
$canonical = $load('dnd-2014-races.json');
$definitions = $races = [];
foreach ($canonical['entries'] as $entry) $races[$entry['slug']] = new CharacterRace($entry['slug'], $entry['name']);
foreach ($canonical['entries'] as $entry) {
    if (isset($entry['parentSlug'])) $races[$entry['slug']]->setParentRace($races[$entry['parentSlug']]);
}
foreach ($load('dnd-2014-proficiencies.json')['proficiencies'] as $entry) {
    $definitions[$entry['slug']] = (new ProficiencyDefinition($entry['slug'], $entry['name'], ProficiencyType::from($entry['type'])))
        ->setAbility($entry['ability'] === null ? null : Ability::from($entry['ability']));
}
$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void {
    if (!$ok) throw new RuntimeException($message);
    ++$checks;
};
$reject = static function (callable $call, string $message) use ($check): void {
    try { $call(); } catch (DomainException|LogicException $error) { $check(true, $message); return; }
    throw new RuntimeException($message);
};
$same = static function (array $actual, array $expected, string $message) use ($check): void {
    sort($actual); sort($expected); $check($actual === $expected, $message);
};
$same(array_column($catalogue['races'], 'slug'), array_keys($races), 'Coverage is exactly the 63 canonical entries, not historical aliases.');
$planner = new RaceProficiencyImportPlanner();
$plan = $planner->plan($catalogue, $races, $definitions, []);
$rules = array_column($plan, 'candidate');
$check(count($rules) === 29 && array_unique(array_column($plan, 'action')) === ['create'], '29 new rules.');
$class = new CharacterClass('test', 'Test', 8, 1);
$classRule = (new ProficiencyGrantRule('Class perception'))->setCharacterClass($class)->addOption($definitions['perception']);
$second = $planner->plan($catalogue, $races, $definitions, [...$rules, $classRule]);
$check(count($second) === 29 && array_unique(array_column($second, 'action')) === ['unchanged'], 'Reimport is idempotent and ignores class rules.');
$check($classRule->getCharacterClass() === $class && $classRule->getOptions()->count() === 1, 'Class rule unchanged.');
$byKey = [];
foreach ($rules as $index => $rule) {
    (new ReflectionProperty($rule, 'id'))->setValue($rule, $index + 1);
    $byKey[$rule->getImportKey()] = $rule;
    $check($second[$index]['existing'] === $rule && $rule->getAcquisitionMode() === ProficiencyAcquisitionMode::Any, 'Reimport preserves identity; all racial rules use ANY.');
}
$get = static fn ($race, $category) => $byKey['dnd-2014-race-'.$race.'-'.$category];
$repo = new class extends ProficiencyGrantRuleRepository {
    public array $rules = [];
    public function __construct() {}
    public function findVisibleForOwner(User $owner): array { return $this->rules; }
};
$repo->rules = $rules;
$resolver = new CharacterProficiencyResolver($repo);
$choices = new CharacterProficiencyChoiceService();
$campaign = new Campaign(new User(), 'test', 'Test');
$hero = static function (string $race) use ($campaign, $races, $class): Character {
    $c = (new Character($campaign, $race, $race, Character::TYPE_PLAYER))->setRace($races[$race]);
    $c->addClassLevel(new CharacterClassLevel($c, $class, 1));
    return $c;
};
$same(array_keys($resolver->resolve($hero('elf-phb'))), ['perception'], 'Parent elf grants Perception.');
$high = $hero('elf-high-phb');
$same(array_keys($resolver->resolve($high)), ['perception','longsword','shortsword','shortbow','longbow'], 'High elf inherits Perception and gains its own four weapons.');
$check($resolver->resolve($high)['perception']['grants'] === [$get('elf-phb','skills')], 'Perception provenance stays on parent.');
$same(array_keys($resolver->resolve($hero('elf-drow-phb'))), ['perception','rapier','shortsword','hand-crossbow'], 'Drow inherits parent, not high elf weapon training.');
$same(array_keys($resolver->resolve($hero('elf-wood-phb'))), array_keys($resolver->resolve($high)), 'Wood elf inherits correctly.');
$dwarfWeapons = ['battleaxe','handaxe','light-hammer','warhammer'];
foreach (['dwarf-phb','dwarf-hill-phb','dwarf-mountain-phb'] as $slug) {
    $c = $hero($slug);
    $expected = $slug === 'dwarf-mountain-phb' ? [...$dwarfWeapons,'light-armor','medium-armor'] : $dwarfWeapons;
    $same(array_keys($resolver->resolve($c)), $expected, 'Dwarf inherited weapons and local armor.');
    $choices->replaceChoices($c, $get('dwarf-phb','tools'), [$definitions['smiths-tools']]);
    $same(array_keys($resolver->resolve($c)), [...$expected,'smiths-tools'], 'Child can select a tool on its parent rule.');
    $reject(fn () => $choices->replaceChoices($c, $get('dwarf-phb','tools'), [$definitions['thieves-tools']]), 'Dwarf rejects tool outside its three options.');
}
$check(!isset($byKey['dnd-2014-race-dwarf-hill-phb-weapons']), 'No duplicate parent rule on hill dwarf.');
foreach (['human-phb','tiefling-phb','halfling-lightfoot-phb','halfling-stout-phb','dragonborn-phb','gnome-forest-phb','duergar-mpmm'] as $slug) {
    $check($resolver->resolve($hero($slug)) === [], 'Race without permanent mastery grants none: '.$slug);
}
foreach (['custom-lineage-tce','dhampir-vrgr','hexblood-vrgr','reborn-vrgr','githyanki-mpmm','kobold-mpmm'] as $slug) {
    $check($resolver->resolve($hero($slug)) === [], 'Conditional/temporary mastery is not invented: '.$slug);
}
$half = $hero('half-elf-phb');
$check($resolver->resolve($half) === [], 'Unfilled half elf choice grants nothing.');
$choices->replaceChoices($half, $get('half-elf-phb','skills'), [$definitions['perception'],$definitions['stealth']]);
$same(array_keys($resolver->resolve($half)), ['perception','stealth'], 'Half elf chooses exactly two from all skills.');
$reject(fn () => $choices->replaceChoices($half, $get('half-elf-phb','skills'), [$definitions['stealth']]), 'Incomplete choices rejected.');
$reject(fn () => $choices->replaceChoices($half, $get('half-elf-phb','skills'), [$definitions['stealth'],$definitions['stealth']]), 'Duplicate choices rejected.');
$changeling = $get('changeling-mpmm','skills');
$same(array_map(static fn ($d) => $d->getSlug(), $changeling->getOptions()->toArray()), ['deception','insight','intimidation','performance','persuasion'], 'MPMM changeling includes Deception despite old local summary.');
$check($changeling->getChoiceCount() === 2 && $get('tortle-mpmm','skills')->getChoiceCount() === 1, 'MPMM choice quantities.');
$same(array_keys($resolver->resolve($hero('gnome-rock-phb'))), ['tinkers-tools'], 'Rock gnome only grants tool, not generic History.');
$repo->rules = [...$rules, $classRule];
$check(count($resolver->resolve($high)['perception']['grants']) === 2, 'Race and class origins both retained.');
$childRule = (new ProficiencyGrantRule('Test overlapping child'))->setCharacterRace($races['elf-high-phb'])->addOption($definitions['perception']);
$repo->rules[] = $childRule;
$check(count($resolver->resolve($high)) === 5 && count($resolver->resolve($high)['perception']['grants']) === 3, 'Parent, child and class remain distinct grants of one effective proficiency.');

$changed = $catalogue; $changed['rules'][0]['label'] = 'Correction';
$check($planner->plan($changed, $races, $definitions, $rules)[0]['action'] === 'keep', 'Update must be explicit.');
$update = $planner->plan($changed, $races, $definitions, $rules, [], true)[0];
$check($update['action'] === 'update' && $rules[0]->getLabel() !== 'Correction', 'Plan has no mutations.');
$id = $rules[0]->getId();
$planner->applyUpdate($rules[0], $update['candidate']);
$check($rules[0]->getId() === $id && $planner->plan($changed, $races, $definitions, $rules)[0]['action'] === 'unchanged', 'Update preserves id and is idempotent.');
$mechanical = $changed; $mechanical['rules'][0]['options'] = ['dagger'];
$reject(fn () => $planner->plan($mechanical, $races, $definitions, $rules, [$id], true), 'Mechanical change of used rule rejected.');
$check($planner->plan($mechanical, $races, $definitions, $rules, [$id])[0]['action'] === 'keep', 'Default preserves used rule.');
$update = $planner->plan($mechanical, $races, $definitions, $rules, [], true)[0];
$planner->applyUpdate($rules[0], $update['candidate']);
$check($rules[0]->getOptions()->count() === 1 && $rules[0]->getOptions()->first() === $definitions['dagger'], 'Unused rule options update explicitly.');
$check($planner->plan($mechanical, $races, $definitions, $rules)[0]['action'] === 'unchanged', 'Mechanical update is idempotent.');
$mutations = [
    static function (&$c) { $c['edition'] = '2024'; },
    static function (&$c) { $c['rules'][] = $c['rules'][0]; },
    static function (&$c) { $c['rules'][0]['options'][] = $c['rules'][0]['options'][0]; },
    static function (&$c) { $c['rules'][0]['options'] = ['absent']; },
    static function (&$c) { $c['rules'][0]['options'] = ['stealth']; },
    static function (&$c) { $c['rules'][0]['choiceCount'] = -1; },
    static function (&$c) { $c['rules'][0]['choiceCount'] = 99; },
    static function (&$c) { $c['rules'][0]['acquisitionMode'] = 'STARTING_CLASS'; },
    static function (&$c) { $c['rules'][0]['raceSlug'] = 'absent'; },
    static function (&$c) { $c['rules'][0]['options'] = []; },
    static function (&$c) { $c['races'][0]['parentSlug'] = 'elf-phb'; },
    static function (&$c) { $c['races'][] = $c['races'][0]; },
    static function (&$c) { array_pop($c['rules']); },
];
foreach ($mutations as $mutate) {
    $invalid = $catalogue; $mutate($invalid);
    $reject(fn () => $planner->plan($invalid, $races, $definitions, []), 'Invalid catalogue rejected.');
}
$missing = $races; unset($missing['elf-phb']);
$reject(fn () => $planner->plan($catalogue, $missing, $definitions, []), 'Missing parent race rejected.');
$missing = $definitions; unset($missing['perception']);
$reject(fn () => $planner->plan($catalogue, $races, $missing, []), 'Unknown definition rejected.');
$legacy = (new ProficiencyGrantRule('Legacy'))->setCharacterRace($races['elf-phb'])->addOption($definitions['perception']);
$reject(fn () => $planner->plan($catalogue, $races, $definitions, [$legacy]), 'Unkeyed duplicate requires manual reconciliation.');
$protected = $planner->plan($catalogue, $races, $definitions, [])[0]['candidate'];
(new ReflectionProperty($protected, 'origin'))->setValue($protected, ReferenceOrigin::Custom);
(new ReflectionProperty($protected, 'owner'))->setValue($protected, new User());
$reject(fn () => $planner->plan($catalogue, $races, $definitions, [$protected], [], true), 'Custom rule never overwritten.');
$races['elf-phb']->setCustom(true);
$reject(fn () => $planner->plan($catalogue, $races, $definitions, []), 'Custom parent race protected.');
$races['elf-phb']->setCustom(false);
(new ReflectionProperty($definitions['perception'], 'origin'))->setValue($definitions['perception'], ReferenceOrigin::Custom);
$reject(fn () => $planner->plan($catalogue, $races, $definitions, []), 'Custom proficiency protected.');
echo "OK: $checks assertions (racial catalogue, inheritance, choices and import plans).\n";
