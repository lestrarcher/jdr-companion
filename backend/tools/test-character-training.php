<?php
declare(strict_types=1);
require __DIR__.'/character-training-fixture.php';
use App\Entity\{CharacterManualGrant, CharacterLanguageChoice, ExpertiseGrantRule, LanguageDefinition};
use App\Service\{CharacterTrainingPresenter, ReferenceVisibility};
$f = trainingFixture(); extract($f);
$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void { if (!$ok) throw new RuntimeException($message); ++$checks; };
$reject = static function (callable $call, string $message) use ($check): void {
    try { $call(); } catch (LogicException $e) { $check(true, $message); return; }
    throw new RuntimeException($message);
};
$character = $make();
$result = $service->apply($character, $payload, $owner);
$check(isset($result['proficiencies']['stealth'], $result['proficiencies']['dice-set']), 'Automatic and selected proficiencies.');
$check(count($result['expertises']) === 2 && isset($result['expertises']['thieves-tools']), 'Rogue expertise skill and thieves tools.');
$check(isset($result['languages']['common'], $result['languages']['elvish'], $result['languages']['thieves-cant']), 'Automatic race, chosen race and class secret languages.');
$check(count($character->getProficiencyChoices()) === 5 && count($character->getLanguageChoices()) === 1, 'Automatic grants are not stored as choices.');
$check($character->getBackground() === $backgrounds['criminal-phb'], 'Background preserved.');
$substituted = $payload;
$substituted['substitutions'] = [
    ['ruleId' => $rule('phb-2014-background-criminal-phb-skills'), 'originalProficiencyId' => $def('stealth'), 'replacementProficiencyId' => $def('perception')],
    ['ruleId' => $rule('phb-2014-background-criminal-phb-tools-automatic'), 'originalProficiencyId' => $def('thieves-tools'), 'replacementProficiencyId' => $def('herbalism-kit')],
];
$c = $make(); $after = $service->apply($c, $substituted, $owner);
$check(count($after['proficiencies']) === count($result['proficiencies']) + 2, 'Each justified substitution yields exactly one replacement.');
$check(count($after['proficiencies']['stealth']['grants']) === 1 && count($after['proficiencies']['perception']['grants']) === 1, 'Original class grant retained; historical origin moved.');
$check($after['proficiencies']['perception']['substitutions'][0]->getOriginal()->getSlug() === 'stealth', 'Substitution trace retained.');
$presenter = new CharacterTrainingPresenter($refs, $resolver, $proficiencyResolver);
$check(count($presenter->options($character)['substitutions']) >= 2, 'Preview exposes substitution options.');
$check(count($presenter->resolved($c)['expertises']) === 2, 'Serializable expertise result.');
$bad = [
    static function (&$p) { $p['proficiencyChoices'] = []; },
    static function (&$p) { $p['proficiencyChoices'][0]['proficiencyIds'] = []; },
    static function (&$p) { $p['proficiencyChoices'][0]['proficiencyIds'][0] = 99999; },
    static function (&$p) { $p['proficiencyChoices'][] = $p['proficiencyChoices'][0]; },
    static function (&$p) { $p['languageChoices'] = []; },
    static function (&$p) { $p['languageChoices'][0]['languageSlugs'] = ['common']; },
    static function (&$p) { $p['languageChoices'][0]['languageSlugs'] = ['druidic']; },
    static function (&$p) { $p['languageChoices'][0]['sourceKey'] = 'race:elf-phb'; },
    static function (&$p) { $p['expertiseChoices'] = []; },
    static function (&$p) use ($def) { $p['expertiseChoices'][0]['proficiencyIds'] = [$def('nature'), $def('stealth')]; },
    static function (&$p) use ($def) { $p['expertiseChoices'][0]['proficiencyIds'] = [$def('stealth'), $def('stealth')]; },
    static function (&$p) { $p['approvedLanguageSlugs'] = ['infernal']; },
    static function (&$p) { $p['manualGrants'] = [['kind' => 'LANGUAGE', 'languageSlug' => 'druidic', 'reason' => '']]; },
    static function (&$p) use ($def) { $p['manualGrants'] = [['kind' => 'EXPERTISE', 'proficiencyId' => $def('nature'), 'reason' => 'Test']]; },
];
foreach ($bad as $mutate) { $p = $payload; $mutate($p); $reject(fn () => $service->apply($make(), $p, $owner), 'Invalid selection rejected.'); }
$p = $payload; $p['languageChoices'][0]['languageSlugs'] = ['infernal'];
$check(isset($service->apply($make(), $p, $owner)['languages']['infernal']), 'Exotic choice requires no approval field.');
$p['manualGrants'] = [['kind' => 'LANGUAGE', 'languageSlug' => 'druidic', 'reason' => 'Enseignement autorisé par le MJ'],
    ['kind' => 'PROFICIENCY', 'proficiencyId' => $def('nature'), 'reason' => 'Apprentissage'],
    ['kind' => 'EXPERTISE', 'proficiencyId' => $def('nature'), 'reason' => 'Formation avancée']];
$c = $make(); $manual = $service->apply($c, $p, $owner);
$check(isset($manual['languages']['druidic'], $manual['expertises']['nature'], $manual['proficiencies']['nature']), 'Manual language, proficiency and expertise supported.');
$check($c->getManualGrants()->first()->getGrantedBy() === $owner && $c->getManualGrants()->first()->getReason() !== '', 'Manual grant actor, reason and timestamp retained.');
$reject(fn () => $service->apply($make(), $payload, new App\Entity\User()), 'Foreign actor rejected.');
foreach (['wrong-type','no-duplicate','non-background','duplicate-target'] as $case) {
    $p = $substituted;
    if ($case === 'wrong-type') $p['substitutions'][0]['replacementProficiencyId'] = $def('lute');
    if ($case === 'no-duplicate') $p['substitutions'][0]['originalProficiencyId'] = $def('insight');
    if ($case === 'non-background') $p['substitutions'][0]['ruleId'] = $rule('phb-2014-rogue-starting-skills');
    if ($case === 'duplicate-target') $p['substitutions'][] = $p['substitutions'][0];
    $reject(fn () => $service->apply($make(), $p, $owner), 'Invalid substitution rejected.');
}
$preview = $make('bard', 'human-phb', null, 1);
$options = $presenter->options($preview);
$check($options['expertiseRules'] === [], 'Bard expertise unavailable at level one.');
$preview = $make('bard', 'human-phb', null, 3);
$service->apply($preview, ['manualGrants' => [
    ['kind' => 'PROFICIENCY','proficiencyId' => $def('arcana'),'reason' => 'Test'],
    ['kind' => 'PROFICIENCY','proficiencyId' => $def('religion'),'reason' => 'Test'],
]], $owner, false);
$check(count($presenter->options($preview)['expertiseRules']) === 1, 'Bard level three choices available.');
$refs->data['expertiseRules'][] = (new ExpertiseGrantRule('custom-auto-test', 'Automatic expertise', 1, 0))->setCharacterClass($classes['rogue'])->addOption($definitions['insight']);
$id(end($refs->data['expertiseRules']), 77);
$automatic = $resolver->resolve($character);
$check(isset($automatic['expertises']['insight']), 'Automatic expertise requires and uses an existing proficiency.');
$refs->data['expertiseRules'][array_key_last($refs->data['expertiseRules'])]->addOption($definitions['nature']);
$check(!isset($resolver->resolve($character)['expertises']['nature']), 'Automatic expertise does not invent proficiency.');
array_pop($refs->data['expertiseRules']);
$check(count($presenter->options($make('rogue','human-phb',null,6))['expertiseRules']) === 2, 'Rogue level six second expertise batch.');
$check(count($presenter->options($make('bard','human-phb',null,10))['expertiseRules']) === 2, 'Bard level ten second expertise batch.');
$check($presenter->options($make('ranger','human-phb',null))['notImplemented'] !== [], 'Favored enemy language explicitly manual.');
$mpmm = $make('fighter','aarakocra-mpmm',null);
$requirements = $resolver->languageRequirements($mpmm, $refs->load($mpmm));
$check($requirements['race:aarakocra-mpmm']['choiceCount'] === 1 && $requirements['race:aarakocra-mpmm']['automatic'][0]->getSlug() === 'common', 'MPMM metadata common plus one.');
$elf = $make('fighter','elf-high-phb',null);
$requirements = $resolver->languageRequirements($elf, $refs->load($elf));
$check(count($requirements['race:elf-high-phb']['automatic']) === 2 && $requirements['race:elf-high-phb']['choiceCount'] === 1, 'Inherited elf languages are not doubled.');
$foreign = LanguageDefinition::forOwner(new App\Entity\User(), 'private-language', 'Privée'); $id($foreign, 90);
$refs->data['languages'][] = $foreign;
$p = $payload; $p['languageChoices'][0]['languageSlugs'] = ['private-language'];
$reject(fn () => $service->apply($make(), $p, $owner), 'Foreign custom language cannot be selected.');
array_pop($refs->data['languages']);
$again = $planner->plan($catalogue, $raceCatalogue, $existing, true);
foreach ($again as $items) $check(array_unique(array_column(array_values($items), 'action')) === ['unchanged'], 'Training import idempotent.');
$check(count($plan['languages']) === 18 && count($plan['languageRules']) === 8 && count($plan['expertiseRules']) === 4, 'Catalogue totals.');
$invalid = $catalogue; $invalid['expertiseRules'][0]['options'][] = 'unknown';
$reject(fn () => $planner->plan($invalid, $raceCatalogue, $existing, true), 'Unknown import reference rejected.');
$invalid = $catalogue; $invalid['languageRules'][0]['choiceCount'] = 1;
$reject(fn () => $planner->plan($invalid, $raceCatalogue, $existing, true), 'Mechanical update of an existing rule rejected.');
$invalid = $catalogue; $invalid['languages'][] = $invalid['languages'][0];
$reject(fn () => $planner->plan($invalid, $raceCatalogue, $existing), 'Duplicate catalogue slug rejected.');
$invalid = $existing; $invalid['languages']['common'] = LanguageDefinition::forOwner($owner,'common','Personnalisé');
$reject(fn () => $planner->plan($catalogue, $raceCatalogue, $invalid), 'Custom reference protected.');

$plain = [
    'proficiencyChoices' => [['ruleId' => $rule('phb-2014-fighter-starting-skills'), 'proficiencyIds' => [$def('athletics'),$def('survival')]]],
    'languageChoices' => [
        ['sourceKey' => 'race:human-phb', 'languageSlugs' => ['infernal']],
        ['sourceKey' => 'phb-2014-background-acolyte-languages', 'languageSlugs' => ['elvish','draconic']],
    ],
];
$r = $service->apply($make('fighter','human-phb','acolyte-phb'), $plain, $owner);
$check(isset($r['proficiencies']['religion'], $r['proficiencies']['insight']) && count($r['languages']) === 4, 'Acolyte automatic skills and two background languages.');
$p = $plain; $p['languageChoices'][1]['languageSlugs'] = ['infernal','draconic'];
$reject(fn () => $service->apply($make('fighter','human-phb','acolyte-phb'), $p, $owner), 'Duplicate languages across sources rejected.');
$plain['languageChoices'] = [$plain['languageChoices'][0]];
$r = $service->apply($make('fighter','human-phb',null), $plain, $owner);
$check(!isset($r['proficiencies']['religion']) && count($r['languages']) === 2, 'Creation without background.');
$r = $service->apply($make('druid','human-phb',null), [], $owner, false);
$check(isset($r['languages']['druidic']), 'Druidic automatically granted by the class capability.');
$p = ['proficiencyChoices' => [['ruleId' => $rule('phb-2014-fighter-starting-skills'), 'proficiencyIds' => [$def('athletics'), $def('perception')]]]];
$r = $service->apply($make('fighter','elf-wood-phb','sailor-phb'), $p, $owner);
$check(count($r['proficiencies']['perception']['grants']) === 3, 'Race class and background preserve all three origins.');
$p['substitutions'] = [['ruleId' => $rule('phb-2014-background-sailor-phb-skills'), 'originalProficiencyId' => $def('perception'), 'replacementProficiencyId' => $def('survival')]];
$r = $service->apply($make('fighter','elf-wood-phb','sailor-phb'), $p, $owner);
$check(count($r['proficiencies']['perception']['grants']) === 2 && isset($r['proficiencies']['survival']), 'Substitution preserves both non-background origins.');

echo "OK: $checks training assertions.\n";

