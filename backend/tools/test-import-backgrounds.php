<?php

declare(strict_types=1);

// In-memory fixtures only: no kernel, SQL, migration execution or persisted import.
use App\Entity\{Campaign, Character, CharacterBackground, CharacterClass, CharacterClassLevel, CharacterRace, ProficiencyDefinition, ProficiencyGrantRule, User};
use App\Enum\{ProficiencyAcquisitionMode, ProficiencyType, ReferenceOrigin};
use App\Repository\ProficiencyGrantRuleRepository;
use App\Service\{CharacterProficiencyChoiceService, CharacterProficiencyResolver, ReferenceVisibility};
use App\Service\Catalogue\BackgroundImportPlanner;

require __DIR__.'/../vendor/autoload.php';
$load = static fn (string $file) => json_decode(file_get_contents(__DIR__.'/../data/reference/'.$file), true, 512, JSON_THROW_ON_ERROR);
$catalogue = $load('dnd-2014-backgrounds.json');
$definitions = [];
foreach ($load('dnd-2014-proficiencies.json')['proficiencies'] as $entry) {
    $definitions[$entry['slug']] = new ProficiencyDefinition($entry['slug'], $entry['name'], ProficiencyType::from($entry['type']));
}
$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void {
    if (!$ok) throw new RuntimeException($message);
    ++$checks;
};
$reject = static function (callable $call, string $message) use ($check): void {
    try { $call(); } catch (LogicException $error) { $check(true, $message); return; }
    throw new RuntimeException($message);
};
$same = static function (array $actual, array $expected, string $message) use ($check): void {
    sort($actual); sort($expected); $check($actual === $expected, $message);
};
$slugs = static fn (ProficiencyGrantRule $rule) => array_map(static fn ($d) => $d->getSlug(), $rule->getOptions()->toArray());
$planner = new BackgroundImportPlanner();
$first = $planner->plan($catalogue, [], $definitions, []);
$check(count($first['backgrounds']) === 13 && count($first['rules']) === 26, '13 backgrounds and 26 rules.');
$backgrounds = $rules = $byKey = [];
foreach ($first['backgrounds'] as $item) {
    $check($item['action'] === 'create', 'New background.');
    $backgrounds[$item['candidate']->getSlug()] = $item['candidate'];
}
foreach ($first['rules'] as $index => $item) {
    $rule = $item['candidate'];
    (new ReflectionProperty($rule, 'id'))->setValue($rule, $index + 1);
    $rules[] = $rule;
    $byKey[$rule->getImportKey()] = $rule;
    $check($item['action'] === 'create' && $rule->getAcquisitionMode() === ProficiencyAcquisitionMode::Any, 'New ANY rule.');
}
$get = static fn (string $slug, string $kind = 'skills') => $byKey['phb-2014-background-'.$slug.'-phb-'.$kind];
$expectedSkills = [
    'acolyte' => ['insight', 'religion'], 'charlatan' => ['deception', 'sleight-of-hand'],
    'criminal' => ['deception', 'stealth'], 'entertainer' => ['acrobatics', 'performance'],
    'folk-hero' => ['animal-handling', 'survival'], 'guild-artisan' => ['insight', 'persuasion'],
    'hermit' => ['medicine', 'religion'], 'noble' => ['history', 'persuasion'],
    'outlander' => ['athletics', 'survival'], 'sage' => ['arcana', 'history'],
    'sailor' => ['athletics', 'perception'], 'soldier' => ['athletics', 'intimidation'],
    'urchin' => ['sleight-of-hand', 'stealth'],
];
foreach ($expectedSkills as $slug => $expected) {
    $same($slugs($get($slug)), $expected, 'Official skill pair '.$slug);
    $check($get($slug)->getChoiceCount() === 0, 'Skills are automatic.');
}
$same($slugs($get('charlatan', 'tools-automatic')), ['disguise-kit', 'forgery-kit'], 'Charlatan kits.');
$same($slugs($get('urchin', 'tools-automatic')), ['disguise-kit', 'thieves-tools'], 'Urchin tools.');
$same($slugs($get('hermit', 'tools-automatic')), ['herbalism-kit'], 'Hermit kit.');
foreach (['folk-hero' => 17, 'guild-artisan' => 17, 'entertainer' => 10, 'outlander' => 10, 'criminal' => 4, 'soldier' => 4, 'noble' => 4] as $slug => $count) {
    $check($get($slug, 'tools-choice')->getOptions()->count() === $count && $get($slug, 'tools-choice')->getChoiceCount() === 1, 'Choose one individual tool '.$slug);
}
$second = $planner->plan($catalogue, $backgrounds, $definitions, $rules);
foreach ($second as $items) $check(array_unique(array_column($items, 'action')) === ['unchanged'], 'Idempotent reimport.');
$check($second['backgrounds'][0]['existing'] === $backgrounds['acolyte-phb'] && $second['rules'][0]['existing'] === $rules[0], 'Identity retained.');

$repository = new class extends ProficiencyGrantRuleRepository {
    public array $rules = [];
    public function __construct() {}
    public function findVisibleForOwner(User $owner): array { return $this->rules; }
};
$repository->rules = $rules;
$resolver = new CharacterProficiencyResolver($repository);
$owner = new User();
$character = new Character(new Campaign($owner, 'test', 'Test'), 'hero', 'Hero', Character::TYPE_PLAYER);
$class = new CharacterClass('fighter', 'Fighter', 10, 3);
$character->addClassLevel(new CharacterClassLevel($character, $class, 1));
$check($resolver->resolve($character) === [], 'No background grants nothing.');
$character->setBackground($backgrounds['acolyte-phb']);
$same(array_keys($resolver->resolve($character)), ['insight', 'religion'], 'Two automatic skills.');
$check($character->getProficiencyChoices()->isEmpty(), 'Automatic grants are not persisted.');
$character->setBackground($backgrounds['entertainer-phb']);
$same(array_keys($resolver->resolve($character)), ['acrobatics', 'performance', 'disguise-kit'], 'Missing choice does not grant every instrument.');
$choices = new CharacterProficiencyChoiceService();
$choiceRule = $get('entertainer', 'tools-choice');
$choices->replaceChoices($character, $choiceRule, [$definitions['lute']]);
$check(isset($resolver->resolve($character)['lute']) && $character->getProficiencyChoices()->count() === 1, 'Persisted instrument choice resolves.');
$reject(fn () => $choices->replaceChoices($character, $choiceRule, [$definitions['smiths-tools']]), 'Reject wrong tool.');
$reject(fn () => $choices->replaceChoices($character, $choiceRule, []), 'Reject missing choice.');
$reject(fn () => $choices->replaceChoices($character, $choiceRule, [$definitions['lute'], $definitions['flute']]), 'Reject excess choices.');
$character->setBackground(null);
$check($resolver->resolve($character) === [] && $character->getProficiencyChoices()->count() === 1, 'Unselected background choices retained but inactive.');
$character->setBackground($backgrounds['sailor-phb']);
$race = new CharacterRace('elf', 'Elf');
$character->setRace($race);
$raceRule = (new ProficiencyGrantRule('Race perception'))->setCharacterRace($race)->addOption($definitions['perception']);
$classRule = (new ProficiencyGrantRule('Class perception'))->setCharacterClass($class)->addOption($definitions['perception']);
$repository->rules = [...$rules, $raceRule, $classRule];
$result = $resolver->resolve($character);
$check(count($result) === 3 && count($result['perception']['grants']) === 3, 'One effective proficiency, three origins.');
$same(array_map(static fn ($r) => $r->getLabel(), $result['perception']['grants']), [$get('sailor')->getLabel(), 'Race perception', 'Class perception'], 'Origins identifiable.');
$check(!isset($result['lute']), 'Old background choice does not leak.');
$get('sailor')->setUnlockLevel(2);
$check(count($resolver->resolve($character)['perception']['grants']) === 2, 'Background respects unlock level.');
$get('sailor')->setUnlockLevel(1)->setAcquisitionMode(ProficiencyAcquisitionMode::StartingClass);
$reject(fn () => $resolver->resolve($character), 'Background cannot use class acquisition mode.');
$get('sailor')->setAcquisitionMode(ProficiencyAcquisitionMode::Any);
$probe = (new ProficiencyGrantRule('Probe'))->setCharacterBackground($backgrounds['acolyte-phb'])->addOption($definitions['religion']);
$probe->setCharacterRace($race);
$check($probe->getCharacterBackground() === null, 'Changing source clears background.');
$probe->setCharacterBackground($backgrounds['acolyte-phb']);
$check($probe->getCharacterRace() === null, 'Background clears other sources.');
(new ReflectionProperty($probe, 'characterRace'))->setValue($probe, $race);
$reject(fn () => $probe->validateRule(), 'Exactly one source including background.');

$custom = CharacterBackground::forOwner($owner, 'custom', 'Custom');
$custom->validateReferenceOwnership();
$check(ReferenceVisibility::allows($custom, $owner) && !ReferenceVisibility::allows($custom, new User()), 'Custom background is owner scoped.');
$customRule = (new ProficiencyGrantRule('Custom'))->setCharacterBackground($custom)->addOption($definitions['perception']);
$check(!ReferenceVisibility::allows($customRule, $owner), 'Official rule cannot depend on custom background.');
(new ReflectionProperty($customRule, 'origin'))->setValue($customRule, ReferenceOrigin::Custom);
(new ReflectionProperty($customRule, 'owner'))->setValue($customRule, $owner);
$customRule->validateRule();
$repository->rules = [$customRule];
$character->setBackground($custom);
$check(isset($resolver->resolve($character)['perception']), 'Custom owned background can resolve.');
$check(!ReferenceVisibility::allows($customRule, new User()), 'Foreign custom rule is hidden.');

$edited = $catalogue;
$edited['backgrounds'][0]['name'] = 'Acolyte édité';
$edited['backgrounds'][0]['rules'][0]['label'] = 'Compétences éditées';
$plan = $planner->plan($edited, $backgrounds, $definitions, $rules);
$check($plan['backgrounds'][0]['action'] === 'keep' && $plan['rules'][0]['action'] === 'keep', 'Updates require explicit flag.');
$plan = $planner->plan($edited, $backgrounds, $definitions, $rules, [$rules[0]->getId()], true);
$check($plan['backgrounds'][0]['action'] === 'update' && $plan['rules'][0]['action'] === 'update', 'Editorial updates allowed even with choices.');
$check($backgrounds['acolyte-phb']->getName() === 'Acolyte' && $rules[0]->getLabel() !== 'Compétences éditées', 'Planning has no side effects.');
$planner->applyBackgroundUpdate($plan['backgrounds'][0]['existing'], $plan['backgrounds'][0]['candidate']);
$planner->applyRuleUpdate($plan['rules'][0]['existing'], $plan['rules'][0]['candidate']);
$again = $planner->plan($edited, $backgrounds, $definitions, $rules, [], true);
$check($again['backgrounds'][0]['action'] === 'unchanged' && $again['rules'][0]['action'] === 'unchanged', 'Updated import is idempotent.');
$mechanical = $catalogue;
// Entertainer is entry 3; choice is its third rule.
array_pop($mechanical['backgrounds'][3]['rules'][2]['options']);
$reject(fn () => $planner->plan($mechanical, $backgrounds, $definitions, $rules, [$choiceRule->getId()], true), 'Used choice rules cannot change options.');
$mechanical['backgrounds'][3]['rules'][2]['options'] = $catalogue['backgrounds'][3]['rules'][2]['options'];
$mechanical['backgrounds'][3]['rules'][2]['choiceCount'] = 2;
$reject(fn () => $planner->plan($mechanical, $backgrounds, $definitions, $rules, [$choiceRule->getId()], true), 'Used choice count cannot change.');
$plan = $planner->plan($mechanical, $backgrounds, $definitions, $rules, [], true);
$updates = array_values(array_filter($plan['rules'], static fn ($i) => $i['existing'] === $choiceRule));
$check($updates[0]['action'] === 'update', 'Unused mechanical update is explicit.');
$planner->applyRuleUpdate($choiceRule, $updates[0]['candidate']);
$check($choiceRule->getChoiceCount() === 2, 'Unused mechanical update applied in memory.');
$choiceRule->setChoiceCount(1);
$check($planner->plan($catalogue, $backgrounds, $definitions, [...$rules, $raceRule, $classRule])['rules'][0]['existing'] === $rules[0], 'Other source rules ignored.');

$mutations = [
    static function (&$c) { $c['edition'] = '2024'; },
    static function (&$c) { $c['backgrounds'] = []; },
    static function (&$c) { $c['backgrounds'][] = $c['backgrounds'][0]; },
    static function (&$c) { $c['backgrounds'][0]['slug'] = 'Acolyte-phb'; },
    static function (&$c) { $c['backgrounds'][0]['name'] = ''; },
    static function (&$c) { $c['backgrounds'][0]['description'] = []; },
    static function (&$c) { $c['backgrounds'][0]['deferred'] = [null]; },
    static function (&$c) { $c['backgrounds'][0]['rules'] = []; },
    static function (&$c) { $c['backgrounds'][0]['rules'][] = $c['backgrounds'][0]['rules'][0]; },
    static function (&$c) { $c['backgrounds'][0]['rules'][0]['key'] = 'bad'; },
    static function (&$c) { $c['backgrounds'][0]['rules'][0]['options'] = ['unknown', 'religion']; },
    static function (&$c) { $c['backgrounds'][0]['rules'][0]['options'] = ['religion', 'religion']; },
    static function (&$c) { $c['backgrounds'][0]['rules'][0]['options'] = ['lute', 'religion']; },
    static function (&$c) { $c['backgrounds'][0]['rules'][0]['options'] = ['religion']; },
    static function (&$c) { $c['backgrounds'][0]['rules'][0]['acquisitionMode'] = 'STARTING_CLASS'; },
    static function (&$c) { $c['backgrounds'][0]['rules'][0]['unlockLevel'] = 2; },
    static function (&$c) { $c['backgrounds'][0]['rules'][0]['choiceCount'] = 1; },
    static function (&$c) { $c['backgrounds'][3]['rules'][2]['choiceCount'] = 11; },
    static function (&$c) { $c['backgrounds'][3]['rules'][2]['choiceCount'] = '1'; },
];
foreach ($mutations as $mutate) {
    $invalid = $catalogue; $mutate($invalid);
    $reject(fn () => $planner->plan($invalid, $backgrounds, $definitions, $rules, [], true), 'Invalid catalogue rejected before updates.');
}
$missing = $definitions; unset($missing['religion']);
$reject(fn () => $planner->plan($catalogue, [], $missing, []), 'Unknown definition rejected.');
$protected = $backgrounds; $protected['acolyte-phb'] = CharacterBackground::forOwner($owner, 'acolyte-phb', 'Custom');
$reject(fn () => $planner->plan($catalogue, $protected, $definitions, $rules), 'Custom background collision protected.');
$cloned = clone $rules[0];
(new ReflectionProperty($cloned, 'origin'))->setValue($cloned, ReferenceOrigin::Custom);
(new ReflectionProperty($cloned, 'owner'))->setValue($cloned, $owner);
$reject(fn () => $planner->plan($catalogue, $backgrounds, $definitions, [$cloned]), 'Custom rule protected.');
$cloned = clone $rules[0]; $cloned->setCharacterRace($race);
$reject(fn () => $planner->plan($catalogue, $backgrounds, $definitions, [$cloned]), 'Stable key cannot change source.');
$cloned = clone $rules[0]; (new ReflectionProperty($cloned, 'importKey'))->setValue($cloned, null);
$reject(fn () => $planner->plan($catalogue, $backgrounds, $definitions, [$cloned]), 'Unkeyed matching rule requires manual reconciliation.');
$protectedDefinitions = $definitions; $protectedDefinitions['religion'] = clone $definitions['religion'];
(new ReflectionProperty($protectedDefinitions['religion'], 'origin'))->setValue($protectedDefinitions['religion'], ReferenceOrigin::Custom);
(new ReflectionProperty($protectedDefinitions['religion'], 'owner'))->setValue($protectedDefinitions['religion'], $owner);
$reject(fn () => $planner->plan($catalogue, [], $protectedDefinitions, []), 'Custom proficiency protected.');

echo "OK: $checks assertions (background import plans, catalogue and resolver).\n";
