<?php
declare(strict_types=1);
require __DIR__.'/character-training-fixture.php';

use App\Entity\ExpertiseGrantRule;

extract(trainingFixture());
$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void {
    if (!$ok) throw new RuntimeException($message);
    ++$checks;
};
$c = $make();
$service->apply($c, $payload, $owner);
$manual = ['manualGrants' => [
    ['kind' => 'PROFICIENCY', 'proficiencyId' => $def('stealth'), 'reason' => 'Training'],
    ['kind' => 'EXPERTISE', 'proficiencyId' => $def('stealth'), 'reason' => 'Training'],
    ['kind' => 'LANGUAGE', 'languageSlug' => 'druidic', 'reason' => 'Training'],
]];
$service->apply($c, $manual, $owner, false);
$originalGrants = $c->getManualGrants()->toArray();
$service->apply($c, $manual, $owner, false);
$check($originalGrants === $c->getManualGrants()->toArray(), 'Identical retry retains grant identity and date.');
$check(count($resolver->resolve($c)['expertises']) === 2, 'Manual overlap preserves other expertise.');
$manual['manualGrants'][0]['reason'] = 'Distinct award';
$service->apply($c, $manual, $owner, false);
$check($c->getManualGrants()->count() === 4, 'Distinct reason preserved, other retries ignored.');
$check(count($resolver->resolve($c)['proficiencies']['stealth']['grants']) === 2, 'Automatic origins preserved.');

$auto = (new ExpertiseGrantRule('aaa-overlap', 'Automatic overlap'))->setCharacterClass($classes['rogue'])->addOption($definitions['stealth']);
$id($auto, 900);
$refs->data['expertiseRules'][] = $auto;
$resolved = $resolver->resolve($c);
$check(isset($resolved['expertises']['thieves-tools']), 'Automatic overlap cannot discard thieves tools expertise.');
$check(count($resolved['expertises']['stealth']['sources']) === 3, 'Automatic, choice and manual origins retained.');
$choices = $c->getExpertiseChoices()->toArray();
$service->applyChoices($c, ['expertiseChoices' => $payload['expertiseChoices']]);
$check($choices === $c->getExpertiseChoices()->toArray(), 'Historical overlapping choices can be resubmitted unchanged.');

// A chosen skill with no other source: losing it must conflict even for automatic expertise.
$autoInsight = (new ExpertiseGrantRule('aaa-insight', 'Insight expertise'))->setCharacterClass($classes['rogue'])->addOption($definitions['insight']);
$id($autoInsight, 901);
$refs->data['expertiseRules'][] = $autoInsight;
$replacement = $payload['proficiencyChoices'][0];
$replacement['proficiencyIds'] = array_map($def, ['stealth', 'deception', 'acrobatics', 'investigation']);
try {
    $service->applyChoices($c, ['proficiencyChoices' => [$replacement]]);
    throw new RuntimeException('Required mastery removal accepted.');
} catch (DomainException $exception) {
    $check(str_contains($exception->getMessage(), 'Conflit de formation'), 'Explicit prerequisite conflict.');
}
// This fixture has no transaction: resolution must still never activate an unmastered expertise.
$check(!isset($resolver->resolve($c)['expertises']['insight']), 'Resolver never activates expertise without proficiency.');
$check(isset($resolver->resolve($c)['expertises']['thieves-tools']), 'Other expertise survives missing prerequisite.');

// Removing one origin is allowed when another still grants the proficiency.
$refs->data['expertiseRules'] = array_values(array_filter($refs->data['expertiseRules'], static fn ($r) => $r !== $auto));
$c = $make();
$service->apply($c, $payload, $owner);
$service->apply($c, ['manualGrants' => [['kind' => 'PROFICIENCY', 'proficiencyId' => $def('insight'), 'reason' => 'Backup source']]], $owner, false);
$result = $service->applyChoices($c, ['proficiencyChoices' => [$replacement]]);
$check(isset($result['expertises']['insight']), 'Manual proficiency retains prerequisite after losing class choice.');

foreach (['choice', 'manual'] as $source) {
    extract(trainingFixture());
    $c = $make();
    $service->apply($c, $payload, $owner);
    if ($source === 'choice') {
        $service->applyChoices($c, ['expertiseChoices' => [[
            'ruleId' => $payload['expertiseChoices'][0]['ruleId'],
            'proficiencyIds' => [$def('stealth'), $def('insight')],
        ]]]);
    } else {
        $service->apply($c, ['manualGrants' => [['kind' => 'EXPERTISE', 'proficiencyId' => $def('insight'), 'reason' => 'Advanced training']]], $owner, false);
    }
    try {
        $service->applyChoices($c, ['proficiencyChoices' => [$replacement]]);
        throw new RuntimeException('Required mastery removal accepted: '.$source);
    } catch (DomainException $exception) {
        $check(str_contains($exception->getMessage(), 'Conflit de formation'), $source.' expertise prevents prerequisite removal.');
    }
}
echo "OK: $checks training reliability assertions.\n";
