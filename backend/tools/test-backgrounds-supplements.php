<?php

declare(strict_types=1);

// Pure planner and runtime tests. No SQL, migration or import into the application database.
require __DIR__.'/character-training-fixture.php';

use App\Entity\{CharacterBackground, LanguageDefinition, LanguageGrantRule, User};
use App\Enum\ReferenceOrigin;
use App\Service\Catalogue\BackgroundImportPlanner;
use App\Service\CharacterTrainingPresenter;

extract(trainingFixture());
$catalogue = json_decode(file_get_contents(__DIR__.'/../data/reference/dnd-2014-backgrounds-supplements.json'), true, 512, JSON_THROW_ON_ERROR);
$planner = new BackgroundImportPlanner();
$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void { if (!$ok) throw new RuntimeException($message); ++$checks; };
$reject = static function (callable $call, string $message) use ($check): void {
    try { $call(); } catch (LogicException) { $check(true, $message); return; }
    throw new RuntimeException($message);
};
$plan = $planner->plan($catalogue, $backgrounds, $definitions, $repo->rules, [], false, $existing['languages'], $existing['languageRules']);
$check(count($plan['backgrounds']) === 19, 'Exactly 19 supplements, including the City Watch variant.');
$check(count($plan['rules']) === 34 && count($plan['languageRules']) === 18, 'Expected structured proficiency and language rule totals.');
$oldBackgrounds = $backgrounds;
$oldRules = $repo->rules;
foreach ($plan['backgrounds'] as $i => $item) {
    $check($item['action'] === 'create', 'Supplement is new and not a PHB duplicate.');
    $id($item['candidate'], 100 + $i);
    $backgrounds[$item['candidate']->getSlug()] = $item['candidate'];
}
$byKey = [];
foreach ($plan['rules'] as $i => $item) {
    $id($item['candidate'], 1000 + $i);
    $repo->rules[] = $item['candidate'];
    $byKey[$item['candidate']->getImportKey()] = $item['candidate'];
}
$refs->data['proficiencyRules'] = $repo->rules;
foreach ($plan['languageRules'] as $i => $item) {
    $id($item['candidate'], 100 + $i);
    $existing['languageRules'][$item['candidate']->getImportKey()] = $item['candidate'];
}
$refs->data['languageRules'] = array_values($existing['languageRules']);
$again = $planner->plan($catalogue, $backgrounds, $definitions, $repo->rules, [], true, $existing['languages'], $existing['languageRules']);
foreach ($again as $items) foreach ($items as $item) {
    $check($item['action'] === 'unchanged' && $item['existing']->getId() !== null, 'Reimport preserves existing references and IDs.');
}
foreach ($oldBackgrounds as $slug => $background) $check($backgrounds[$slug] === $background, 'PHB identity unchanged.');
$presenter = new CharacterTrainingPresenter($refs, $resolver, $proficiencyResolver);
$build = static function (string $slug) use ($make, $backgrounds) { return $make('fighter', 'human-phb', null)->setBackground($backgrounds[$slug]); };
$choicesFor = static function ($c) use ($presenter, $def): array {
    $options = $presenter->options($c);
    $payload = ['proficiencyChoices' => [], 'languageChoices' => []];
    foreach ($options['proficiencyRules'] as $r) if ($r['choiceCount'] > 0) {
        $payload['proficiencyChoices'][] = ['ruleId' => $r['ruleId'], 'proficiencyIds' => array_slice($r['optionIds'], 0, $r['choiceCount'])];
    }
    $known = [];
    foreach ($options['languageRules'] as $r) $known = [...$known, ...$r['automaticSlugs']];
    foreach ($options['languageRules'] as $r) if ($r['choiceCount'] > 0) {
        $selected = array_slice(array_values(array_diff($r['optionSlugs'], $known)), 0, $r['choiceCount']);
        $payload['languageChoices'][] = ['sourceKey' => $r['sourceKey'], 'languageSlugs' => $selected];
        $known = [...$known, ...$selected];
    }
    return $payload;
};
foreach ($catalogue['backgrounds'] as $row) {
    $c = $build($row['slug']);
    $p = $choicesFor($c); // Test fixture chooses options; production never selects on the player's behalf.
    $result = $service->apply($c, $p, $owner);
    $skills = [];
    foreach ($result['proficiencies'] as $slug => $entry) foreach ($entry['grants'] as $r) {
        if ($r->getCharacterBackground() === $c->getBackground() && $entry['definition']->getType()->value === 'SKILL') $skills[$slug] = true;
    }
    $check(count($skills) === 2, 'Two actual background skills: '.$row['slug']);
    foreach ($presenter->choices($c) as $choice) $check($choice['status'] === 'complete', 'All choices resolve: '.$row['slug']);
    $counts = [$c->getProficiencyChoices()->count(), $c->getLanguageChoices()->count()];
    $service->apply($c, $p, $owner);
    $check($counts === [$c->getProficiencyChoices()->count(), $c->getLanguageChoices()->count()], 'Repeated choices do not duplicate rows.');
    $check($c->getFeats()->isEmpty(), 'No incomplete or 2024 origin feat assigned.');
}
$haunted = $build('haunted-one-cos');
$hp = $choicesFor($haunted);
$before = $resolver->resolve($haunted);
$check(!isset($before['proficiencies']['arcana']), 'Unmade Haunted One choices grant no arbitrary skill.');
$resolved = $service->apply($haunted, $hp, $owner);
$check(isset($resolved['proficiencies']['arcana'], $resolved['proficiencies']['investigation']), 'Haunted One chosen skills resolve.');
$invalid = $hp;
foreach ($invalid['languageChoices'] as &$choice) if (str_ends_with($choice['sourceKey'], 'exotic-choice')) $choice['languageSlugs'] = ['halfling'];
unset($choice);
$reject(fn () => $service->apply($build('haunted-one-cos'), $invalid, $owner), 'Haunted One needs at least one exotic language.');
$invalid = $hp;
foreach ($invalid['proficiencyChoices'] as &$choice) if ($choice['ruleId'] === $byKey['cos-2014-background-haunted-one-cos-skills-choice']->getId()) $choice['proficiencyIds'] = [$def('arcana'), $def('perception')];
unset($choice);
$reject(fn () => $service->apply($build('haunted-one-cos'), $invalid, $owner), 'Haunted One rejects a skill outside its list.');
$fey = $existing['languageRules']['wbtw-2014-background-feylost-wbtw-languages-choice'];
$check($fey->getChoiceCount() === 1 && array_map(static fn ($l) => $l->getSlug(), $fey->getOptions()->toArray()) === ['elvish','gnomish','goblin','sylvan'], 'Feylost has exactly one restricted language choice.');
$giant = $build('giant-foundling-bgg');
$g = $service->apply($giant, $choicesFor($giant), $owner);
$check(isset($g['languages']['giant']) && $giant->getFeats()->isEmpty() && str_contains($giant->getBackground()->getDescription(), 'NON attribué'), 'Giant language is effective and incomplete feat is explicitly deferred.');
$check(count(array_filter($catalogue['backgrounds'], static fn ($e) => $e['englishName'] === 'Haunted One')) === 1, 'One Haunted One reference shared by CoS and Ravenloft.');
$badCases = [
    static function (&$c) { $c['edition'] = '2024'; },
    static function (&$c) { $c['backgrounds'][0]['source'] = 'XPHB'; },
    static function (&$c) { $c['backgrounds'][0]['edition'] = '2024'; },
    static function (&$c) { $c['backgrounds'][] = $c['backgrounds'][0]; },
    static function (&$c) { $c['backgrounds'][0]['rules'][0]['options'][0] = 'unknown'; },
    static function (&$c) { $c['backgrounds'][0]['languageRules'][0]['options'] = ['unknown']; },
    static function (&$c) { $c['backgrounds'][0]['languageRules'][0]['options'] = ['druidic','elvish']; },
    static function (&$c) { $c['backgrounds'][0]['languageRules'][0]['options'] = ['elvish','elvish']; },
    static function (&$c) { $c['backgrounds'][0]['languageRules'][0]['choiceCount'] = 17; },
    static function (&$c) { $c['backgrounds'][0]['languageRules'][] = $c['backgrounds'][0]['languageRules'][0]; },
    static function (&$c) { $c['backgrounds'][0]['languageRules'][0]['key'] = 'wrong-source'; },
    static function (&$c) { $c['backgrounds'][3]['rules'][1]['options'][] = 'history'; },
];
foreach ($badCases as $mutate) {
    $bad = $catalogue; $mutate($bad);
    $reject(fn () => $planner->plan($bad, [], $definitions, [], [], true, $existing['languages']), 'Invalid catalogue rejected before writes.');
}
$custom = $backgrounds;
$custom['haunted-one-cos'] = CharacterBackground::forOwner($owner, 'haunted-one-cos', 'Privé');
$reject(fn () => $planner->plan($catalogue, $custom, $definitions, [], [], true, $existing['languages']), 'Private background cannot be overwritten.');
$privateLanguages = $existing['languages'];
$privateLanguages['elvish'] = LanguageDefinition::forOwner($owner, 'elvish', 'Privée');
$reject(fn () => $planner->plan($catalogue, [], $definitions, [], [], true, $privateLanguages), 'Private language cannot be adopted.');
$bad = $catalogue; array_pop($bad['backgrounds'][0]['languageRules'][0]['options']);
$reject(fn () => $planner->plan($bad, $backgrounds, $definitions, $repo->rules, [], true, $existing['languages'], $existing['languageRules']), 'Existing language mechanics cannot change, even with update-existing.');
$bad = $catalogue; array_pop($bad['backgrounds'][13]['rules'][0]['options']);
$reject(fn () => $planner->plan($bad, $backgrounds, $definitions, $repo->rules, [$byKey['cos-2014-background-haunted-one-cos-skills-choice']->getId()], true, $existing['languages'], $existing['languageRules']), 'Used skill choices protected on reimport.');
$bad = $catalogue; $bad['backgrounds'][0]['languageRules'][0]['label'] = 'Langues renommées';
$editorial = $planner->plan($bad, $backgrounds, $definitions, $repo->rules, [], true, $existing['languages'], $existing['languageRules']);
$check($editorial['languageRules'][0]['action'] === 'update', 'Explicit editorial language update allowed.');
$check($existing['languageRules'][$bad['backgrounds'][0]['languageRules'][0]['key']]->getLabel() !== 'Langues renommées', 'Planning never mutates live references.');
echo "OK: $checks supplement catalogue/import/runtime assertions.\n";
