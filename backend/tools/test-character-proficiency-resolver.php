<?php

declare(strict_types=1);

// Run: docker compose exec -T backend php tools/test-character-proficiency-resolver.php
// Fixtures are in memory only; no migration or database write.
use App\Entity\{Campaign, Character, CharacterClass, CharacterClassLevel, CharacterSubclass, CharacterRace, CharacterFeat, Feat, User, ProficiencyDefinition, ProficiencyGrantRule, CharacterProficiencyChoice};
use App\Enum\{ProficiencyType, ProficiencyAcquisitionMode, ReferenceOrigin};
use App\Repository\ProficiencyGrantRuleRepository;
use App\Service\CharacterProficiencyResolver;

require __DIR__.'/../vendor/autoload.php';
$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void {
    if (!$ok) throw new RuntimeException($message);
    ++$checks;
};
$repository = new class extends ProficiencyGrantRuleRepository {
    public array $rules = [];
    public function __construct() {}
    public function findVisibleForOwner(User $owner): array { return $this->rules; }
};
$resolver = new CharacterProficiencyResolver($repository);
$owner = new User();
$campaign = new Campaign($owner, 'test', 'Test');
$character = new Character($campaign, 'hero', 'Hero', Character::TYPE_PLAYER);
$fighter = new CharacterClass('fighter', 'Fighter', 10, 3);
$rogue = new CharacterClass('rogue', 'Rogue', 8, 3);
$other = new CharacterClass('other', 'Other', 8, 3);
$subclass = new CharacterSubclass($rogue, 'test', 'Test');
// Deliberately not insertion-sorted. Returning to the initial class is not multiclass acquisition.
$character->addClassLevel(new CharacterClassLevel($character, $rogue, 2, $subclass));
$character->addClassLevel(new CharacterClassLevel($character, $fighter, 1));
$character->addClassLevel(new CharacterClassLevel($character, $fighter, 3));
$race = new CharacterRace('parent', 'Parent');
$character->setRace((new CharacterRace('child', 'Child'))->setParentRace($race));
$feat = new Feat('test', 'Test');
$character->addFeat(new CharacterFeat($character, $feat));
$perception = new ProficiencyDefinition('perception', 'Perception', ProficiencyType::Skill);
$stealth = new ProficiencyDefinition('stealth', 'Stealth', ProficiencyType::Skill);
$make = static fn () => (new ProficiencyGrantRule('Grant'))->addOption($perception);
$raceRule = $make()->setCharacterRace($race);
$classRule = $make()->setCharacterClass($fighter);
$repository->rules = [$raceRule, $classRule];
$result = $resolver->resolve($character);
$check(count($result) === 1 && $result['perception']['grants'] === [$raceRule, $classRule], 'One effective proficiency preserves both sources.');
$check($character->getProficiencyChoices()->isEmpty(), 'Automatic grants persist nothing.');
$classRule->addOption($stealth);
$check(count($resolver->resolve($character)) === 2, 'Automatic rule grants all options.');

foreach ([$fighter, $rogue] as $class) {
    foreach (ProficiencyAcquisitionMode::cases() as $mode) {
        $repository->rules = [$make()->setCharacterClass($class)->setAcquisitionMode($mode)];
        $expected = $mode === ProficiencyAcquisitionMode::Any
            || ($mode === ProficiencyAcquisitionMode::StartingClass && $class === $fighter)
            || ($mode === ProficiencyAcquisitionMode::Multiclass && $class === $rogue);
        $check(($resolver->resolve($character) !== []) === $expected, 'Acquisition mode respects position one, including later levels in the initial class.');
    }
}
$incomplete = new Character($campaign, 'incomplete', 'Incomplete', Character::TYPE_PLAYER);
$incomplete->addClassLevel(new CharacterClassLevel($incomplete, $fighter, 2));
foreach (ProficiencyAcquisitionMode::cases() as $mode) {
    $repository->rules = [$make()->setCharacterClass($fighter)->setAcquisitionMode($mode)];
    $check(($resolver->resolve($incomplete) !== []) === ($mode === ProficiencyAcquisitionMode::Any), 'Missing initial position is never guessed.');
}

foreach ([$make()->setCharacterClass($other), $make()->setCharacterRace(new CharacterRace('absent', 'Absent')),
    $make()->setFeat(new Feat('absent', 'Absent')), $make()->setCharacterSubclass(new CharacterSubclass($rogue, 'absent', 'Absent')),
    $make()->setCharacterClass($rogue)->setUnlockLevel(2), $make()->setCharacterSubclass($subclass)->setUnlockLevel(2),
    $make()->setCharacterRace($race)->setUnlockLevel(4), $make()->setFeat($feat)->setUnlockLevel(4)] as $rule) {
    $repository->rules = [$rule];
    $check($resolver->resolve($character) === [], 'Absent source or insufficient source level grants nothing.');
}
foreach ([$make()->setCharacterSubclass($subclass), $make()->setFeat($feat), $raceRule] as $rule) {
    $repository->rules = [$rule];
    $check(isset($resolver->resolve($character)['perception']), 'Owned subclass, feat and ancestor race apply.');
    $rule->setAcquisitionMode(ProficiencyAcquisitionMode::Multiclass);
    try {
        $resolver->resolve($character);
        throw new RuntimeException('Incoherent source/mode was accepted.');
    } catch (LogicException) { $check(true, 'Non-class acquisition mode is explicitly rejected.'); }
    $rule->setAcquisitionMode(ProficiencyAcquisitionMode::Any);
}

$choiceRule = $make()->setCharacterClass($fighter)->addOption($stealth)->setChoiceCount(1);
$repository->rules = [$choiceRule];
$check($resolver->resolve($character) === [], 'Unfilled choice grants nothing.');
$choice = new CharacterProficiencyChoice($character, $choiceRule, $stealth);
$character->addProficiencyChoice($choice);
$check(array_keys($resolver->resolve($character)) === ['stealth'], 'Only selected options are granted.');
$repository->rules[] = $raceRule;
$check(count($resolver->resolve($character)) === 2, 'Choices and automatic grants coexist.');
$character->addProficiencyChoice(new CharacterProficiencyChoice($character, $choiceRule, $stealth));
$check(array_keys($resolver->resolve($character)) === ['perception'], 'Duplicate choice invalidates only its rule.');
$character->getProficiencyChoices()->clear();
$character->addProficiencyChoice($choice);
$choiceRule->removeOption($stealth);
$check(array_keys($resolver->resolve($character)) === ['perception'], 'Obsolete option is ignored without deleting history.');
$check($character->getProficiencyChoices()->count() === 1, 'Resolver never removes stored choices.');
$choiceRule->addOption($stealth)->setChoiceCount(2);
$check(array_keys($resolver->resolve($character)) === ['perception'], 'Incomplete batch grants nothing.');

$choiceRule->setChoiceCount(1)->setCharacterClass($other);
$check(array_keys($resolver->resolve($character)) === ['perception'], 'Persisted choices cannot activate an unowned source.');
$choiceRule->setCharacterClass($fighter)->setAcquisitionMode(ProficiencyAcquisitionMode::Multiclass);
$check(array_keys($resolver->resolve($character)) === ['perception'], 'Persisted choices cannot bypass acquisition mode.');
$choiceRule->setAcquisitionMode(ProficiencyAcquisitionMode::Any)->setUnlockLevel(3);
$check(array_keys($resolver->resolve($character)) === ['perception'], 'Persisted choices cannot bypass unlock level.');

$empty = new Character($campaign, 'empty', 'Empty', Character::TYPE_PLAYER);
$repository->rules = [$classRule, $raceRule];
$check($resolver->resolve($empty) === [], 'A character without sources receives nothing.');

// Existing custom-reference fields lack setters: reflection is confined to fixtures.
$customClass = new CharacterClass('custom', 'Custom', 8, 1);
$customRule = $make()->setCharacterClass($customClass);
foreach ([$customClass, $customRule] as $reference) {
    (new ReflectionProperty($reference, 'origin'))->setValue($reference, ReferenceOrigin::Custom);
    (new ReflectionProperty($reference, 'owner'))->setValue($reference, $owner);
}
$character->addClassLevel(new CharacterClassLevel($character, $customClass, 4));
$repository->rules = [$customRule];
$check(isset($resolver->resolve($character)['perception']), 'Custom ANY rule works for a later class.');
(new ReflectionProperty($customRule, 'owner'))->setValue($customRule, new User());
$check($resolver->resolve($character) === [], 'Foreign custom rule is excluded.');
$repository->rules = [$make()->setCharacterClass($customClass)];
$check($resolver->resolve($character) === [], 'Official rule cannot depend on a custom source.');
echo "OK: $checks resolver assertions.\n";
