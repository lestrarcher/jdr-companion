<?php
declare(strict_types=1);

require __DIR__.'/character-training-fixture.php';

use App\Entity\Feat;
use App\Service\DndReferenceInitializer;

$f = trainingFixture();
$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks): void {
    if (!$ok) throw new RuntimeException($label);
    ++$checks;
};
$reject = static function (callable $call, string $label) use ($check): void {
    try { $call(); } catch (LogicException) { $check(true, $label); return; }
    throw new RuntimeException($label);
};
$plan = static fn ($existing, $update = false) => $f['planner']->plan($f['catalogue'], $f['raceCatalogue'], $existing, $update);
foreach (['backgrounds', 'classes', 'proficiencies', 'races'] as $dependency) {
    $incomplete = $f['existing'];
    $incomplete[$dependency] = [];
    $reject(fn () => $plan($incomplete), 'Missing dependency rejected: '.$dependency);
}
$snapshot = serialize($f['existing']);
foreach ([false, true, false] as $update) {
    $result = $plan($f['existing'], $update);
    foreach ($result as $items) foreach ($items as $item) $check($item['action'] === 'unchanged', 'Training replay is idempotent');
}
$check(serialize($f['existing']) === $snapshot, 'Planning never mutates IDs, relations or metadata');
$missing = $f['existing'];
$missing['languages'] = $missing['languageRules'] = $missing['expertiseRules'] = [];
$result = $plan($missing);
$check(count($result['languages']) === 18 && $result['languages']['common']['action'] === 'create', 'Missing language catalogue is explicitly planned');
$f['races']['human-phb']->setLanguages(null);
$result = $plan($f['existing']);
$check($result['races']['human-phb']['action'] === 'keep', 'Missing racial metadata requires explicit update');
$result = $plan($f['existing'], true);
$check($result['races']['human-phb']['action'] === 'update', 'Explicit update can fill missing metadata');
$f['planner']->applyUpdate('races', $result['races']['human-phb']);
$check($plan($f['existing'])['races']['human-phb']['action'] === 'unchanged', 'Metadata repair is idempotent');
$f['races']['human-phb']->setLanguages(['elvish']);
$reject(fn () => $plan($f['existing'], true), 'Different existing languages are never overwritten');
// Test the initializer guard without a kernel, ORM or SQL.
$initializer = (new ReflectionClass(DndReferenceInitializer::class))->newInstanceWithoutConstructor();
$guard = new ReflectionMethod($initializer, 'assertOfficialReference');
$custom = (new Feat('legacy-test', 'Legacy'))->setCustom(true);
$reject(fn () => $guard->invoke($initializer, $custom), 'Initializer refuses legacy custom reference');
echo "OK: $checks import dependency/idempotence assertions, memory only.\n";
