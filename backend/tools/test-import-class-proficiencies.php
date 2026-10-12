<?php

declare(strict_types=1);

// Run: docker compose exec -T backend php tools/test-import-class-proficiencies.php
// No kernel, SQL, migration or import writes: plan/update simulation in memory only.
use App\Entity\{Campaign, Character, CharacterClass, CharacterClassLevel, ProficiencyDefinition, ProficiencyGrantRule, User};
use App\Enum\{Ability, ProficiencyType, ProficiencyAcquisitionMode, ReferenceOrigin};
use App\Repository\ProficiencyGrantRuleRepository;
use App\Service\Catalogue\ClassProficiencyImportPlanner;
use App\Service\{CharacterProficiencyResolver, CharacterProficiencyChoiceService};

require __DIR__.'/../vendor/autoload.php';
$catalogue = json_decode(file_get_contents(__DIR__.'/../data/reference/dnd-2014-class-proficiencies.json'), true, 512, JSON_THROW_ON_ERROR);
$reference = json_decode(file_get_contents(__DIR__.'/../data/reference/dnd-2014-proficiencies.json'), true, 512, JSON_THROW_ON_ERROR);
$classes = $definitions = [];
foreach (['barbarian', 'bard', 'cleric', 'druid', 'sorcerer', 'fighter', 'wizard', 'monk', 'warlock', 'paladin', 'ranger', 'rogue'] as $slug) {
    $classes[$slug] = new CharacterClass($slug, $slug, 8, 3);
}
foreach ($reference['proficiencies'] as $entry) {
    $definitions[$entry['slug']] = (new ProficiencyDefinition($entry['slug'], $entry['name'], ProficiencyType::from($entry['type'])))
        ->setAbility($entry['ability'] === null ? null : Ability::from($entry['ability']));
}
$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) throw new RuntimeException($message);
    ++$checks;
};
$reject = static function (callable $call, string $message) use ($check): void {
    try { $call(); } catch (DomainException|LogicException $error) { $check(true, $message); return; }
    throw new RuntimeException($message);
};
$planner = new ClassProficiencyImportPlanner();
$plan = $planner->plan($catalogue, $classes, $definitions, []);
$rules = array_column($plan, 'candidate');
$check(count($plan) === 69 && array_unique(array_column($plan, 'action')) === ['create'], 'First plan creates 69 rules.');
$second = $planner->plan($catalogue, $classes, $definitions, $rules);
$check(count($second) === 69 && array_unique(array_column($second, 'action')) === ['unchanged'], 'Reimport plan is idempotent.');
$byKey = [];
foreach ($rules as $index => $rule) {
    $byKey[$rule->getImportKey()] = $rule;
    (new ReflectionProperty($rule, 'id'))->setValue($rule, $index + 1);
    $check($second[$index]['existing'] === $rule, 'Existing rule identity is retained.');
}
$rule = static fn (string $class, string $mode, string $category) => $byKey["phb-2014-$class-$mode-$category"];
$slugs = static function (ProficiencyGrantRule $rule): array {
    $values = array_map(static fn ($option) => $option->getSlug(), $rule->getOptions()->toArray());
    sort($values); return $values;
};
$same = static function (array $actual, array $expected, string $message) use ($check): void {
    sort($actual); sort($expected); $check($actual === $expected, $message);
};
$expectedCounts = ['barbarian' => [4,2], 'bard' => [5,3], 'cleric' => [4,1], 'druid' => [5,1], 'sorcerer' => [3,0], 'fighter' => [4,2], 'wizard' => [3,0], 'monk' => [4,1], 'warlock' => [4,2], 'paladin' => [4,2], 'ranger' => [4,3], 'rogue' => [5,3]];
foreach ($expectedCounts as $class => [$start, $multi]) {
    foreach (['STARTING_CLASS' => $start, 'MULTICLASS' => $multi] as $mode => $count) {
        $check(count(array_filter($rules, static fn ($r) => $r->getCharacterClass() === $classes[$class] && $r->getAcquisitionMode()->value === $mode)) === $count, "$class/$mode rule count");
    }
}
$same($slugs($rule('barbarian', 'starting', 'skills')), ['animal-handling','athletics','intimidation','nature','perception','survival'], 'Barbarian exact skill pool.');
$check($rule('barbarian','starting','skills')->getChoiceCount() === 2, 'Barbarian chooses two.');
$same($slugs($rule('barbarian','multiclass','armor')), ['shields'], 'Multiclass barbarian gets no armor.');
$check($rule('bard','starting','skills')->getOptions()->count() === 18 && $rule('bard','starting','skills')->getChoiceCount() === 3, 'Bard chooses any three skills.');
$check($rule('bard','starting','tools')->getOptions()->count() === 10 && $rule('bard','starting','tools')->getChoiceCount() === 3, 'Bard chooses three instruments.');
$check($rule('bard','multiclass','tools')->getChoiceCount() === 1 && $rule('bard','multiclass','skills')->getChoiceCount() === 1, 'Multiclass bard gets one instrument and skill.');
$same($slugs($rule('fighter','starting','skills')), ['acrobatics','animal-handling','athletics','history','insight','intimidation','perception','survival'], 'Fighter exact skill pool.');
$check(in_array('heavy-armor', $slugs($rule('fighter','starting','armor')), true) && !in_array('heavy-armor', $slugs($rule('fighter','multiclass','armor')), true), 'Heavy armor only for initial fighter.');
$same($slugs($rule('monk','starting','weapons')), ['simple-weapons','shortsword'], 'Monk proficiencies are not the monk weapon property definition.');
$check($rule('monk','starting','tools')->getOptions()->count() === 27 && $rule('monk','starting','tools')->getChoiceCount() === 1, 'Monk chooses one artisan tool OR instrument.');
$same($slugs($rule('monk','starting','skills')), ['acrobatics','athletics','history','insight','religion','stealth'], 'Monk exact skill pool.');
$same($slugs($rule('rogue','starting','skills')), ['acrobatics','athletics','deception','insight','intimidation','investigation','perception','performance','persuasion','sleight-of-hand','stealth'], 'Rogue exact skill pool.');
$check($rule('rogue','starting','skills')->getChoiceCount() === 4 && $rule('rogue','multiclass','skills')->getChoiceCount() === 1, 'Rogue chooses four initially, one on multiclassing.');
$same($slugs($rule('rogue','multiclass','tools')), ['thieves-tools'], 'Multiclass rogue gets thieves tools automatically.');
foreach ($rules as $r) {
    if ($r->getAcquisitionMode() === ProficiencyAcquisitionMode::Multiclass) {
        $check(count(array_filter($r->getOptions()->toArray(), static fn ($o) => $o->getType() === ProficiencyType::SavingThrow)) === 0, 'No multiclass saving throws.');
    }
}

// Catalogue -> planner -> real resolver/choice service, using a memory repository.
$repo = new class extends ProficiencyGrantRuleRepository {
    public array $rules = [];
    public function __construct() {}
    public function findVisibleForOwner(User $owner): array { return $this->rules; }
};
$repo->rules = $rules;
$resolver = new CharacterProficiencyResolver($repo);
$campaign = new Campaign(new User(), 'test', 'Test');
$character = new Character($campaign, 'test', 'Test', Character::TYPE_PLAYER);
$character->addClassLevel(new CharacterClassLevel($character, $classes['fighter'], 1));
$character->addClassLevel(new CharacterClassLevel($character, $classes['rogue'], 2));
$resolved = $resolver->resolve($character);
$check(isset($resolved['heavy-armor'], $resolved['saving-throw-strength'], $resolved['thieves-tools']) && !isset($resolved['saving-throw-dexterity']), 'Starting fighter and multiclass rogue resolve distinct automatic rules.');
(new CharacterProficiencyChoiceService())->replaceChoices($character, $rule('rogue','multiclass','skills'), [$definitions['stealth']]);
$check(isset($resolver->resolve($character)['stealth']), 'Imported skill choice becomes effective.');
$reject(fn () => (new CharacterProficiencyChoiceService())->replaceChoices($character, $rule('rogue','multiclass','skills'), [$definitions['nature']]), 'Skill outside rogue pool rejected.');

// Explicit updates preserve identity, default imports preserve differences.
$changed = $catalogue;
$changed['rules'][0]['label'] = 'Libellé corrigé';
$check($planner->plan($changed, $classes, $definitions, $rules)[0]['action'] === 'keep', 'No implicit update.');
$update = $planner->plan($changed, $classes, $definitions, $rules, [], true)[0];
$check($update['action'] === 'update' && $rules[0]->getLabel() !== 'Libellé corrigé', 'Planning never mutates the existing rule.');
$id = $rules[0]->getId();
$planner->applyUpdate($rules[0], $update['candidate']);
$check($rules[0]->getId() === $id && $planner->plan($changed, $classes, $definitions, $rules)[0]['action'] === 'unchanged', 'Update keeps id and is idempotent.');
$mechanical = $changed;
$mechanical['rules'][0]['options'] = ['saving-throw-wisdom'];
$reject(fn () => $planner->plan($mechanical, $classes, $definitions, $rules, [$id], true), 'Used rule mechanical changes rejected.');
$check($planner->plan($mechanical, $classes, $definitions, $rules, [$id])[0]['action'] === 'keep', 'Without update, used rule remains intact.');
$update = $planner->plan($mechanical, $classes, $definitions, $rules, [], true)[0];
$planner->applyUpdate($rules[0], $update['candidate']);
$same($slugs($rules[0]), ['saving-throw-wisdom'], 'Unused rule options can be updated explicitly.');
$check($planner->plan($mechanical, $classes, $definitions, $rules)[0]['action'] === 'unchanged', 'Mechanical update is idempotent.');

$mutations = [
    static function (&$c) { $c['edition'] = '2024'; },
    static function (&$c) { $c['rules'][] = $c['rules'][0]; },
    static function (&$c) { $c['rules'][0]['options'][] = $c['rules'][0]['options'][0]; },
    static function (&$c) { $c['rules'][0]['options'] = ['missing']; },
    static function (&$c) { $c['rules'][0]['options'] = ['athletics']; },
    static function (&$c) { $c['rules'][0]['choiceCount'] = -1; },
    static function (&$c) { $c['rules'][0]['choiceCount'] = '0'; },
    static function (&$c) { $c['rules'][0]['unlockLevel'] = 2; },
    static function (&$c) { $c['rules'][0]['acquisitionMode'] = 'ANY'; },
    static function (&$c) { $c['rules'][0]['classSlug'] = 'unknown'; },
    static function (&$c) { $c['rules'][0]['options'] = []; },
    static function (&$c) { $c['rules'][0]['label'] = str_repeat('a', 121); },
    static function (&$c) { $c['rules'] = array_values(array_filter($c['rules'], static fn ($r) => $r['classSlug'] !== 'wizard')); },
    static function (&$c) { $c['rules'][3]['choiceCount'] = 99; },
];
foreach ($mutations as $mutate) {
    $invalid = $catalogue; $mutate($invalid);
    $reject(fn () => $planner->plan($invalid, $classes, $definitions, []), 'Invalid catalogue rejected.');
}
$missingClasses = $classes; unset($missingClasses['bard']);
$reject(fn () => $planner->plan($catalogue, $missingClasses, $definitions, []), 'Unknown database class rejected.');
$missingDefinitions = $definitions; unset($missingDefinitions['stealth']);
$reject(fn () => $planner->plan($catalogue, $classes, $missingDefinitions, []), 'Unknown database definition rejected.');
$legacy = (new ProficiencyGrantRule('Legacy'))->setCharacterClass($classes['barbarian'])->setAcquisitionMode(ProficiencyAcquisitionMode::StartingClass)
    ->addOption($definitions['saving-throw-strength'])->addOption($definitions['saving-throw-constitution']);
$reject(fn () => $planner->plan($catalogue, $classes, $definitions, [$legacy]), 'Unkeyed duplicate requires manual reconciliation.');
$protected = $planner->plan($catalogue, $classes, $definitions, [])[0]['candidate'];
(new ReflectionProperty($protected, 'origin'))->setValue($protected, ReferenceOrigin::Custom);
(new ReflectionProperty($protected, 'owner'))->setValue($protected, new User());
$reject(fn () => $planner->plan($catalogue, $classes, $definitions, [$protected], [], true), 'Custom rule protected even with explicit update.');
$classes['bard']->setCustom(true);
$reject(fn () => $planner->plan($catalogue, $classes, $definitions, []), 'Legacy custom class protected.');
$classes['bard']->setCustom(false);
(new ReflectionProperty($definitions['stealth'], 'origin'))->setValue($definitions['stealth'], ReferenceOrigin::Custom);
$reject(fn () => $planner->plan($catalogue, $classes, $definitions, []), 'Custom definition protected.');
echo "OK: $checks assertions (in-memory import plans and resolver).\n";
