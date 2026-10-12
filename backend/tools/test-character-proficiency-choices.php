<?php

declare(strict_types=1);

// Run: docker compose exec -T backend php tools/test-character-proficiency-choices.php
// In-memory domain tests: no database writes and no migration execution.

use App\Entity\Campaign;
use App\Entity\Character;
use App\Entity\CharacterProficiencyChoice;
use App\Entity\CharacterRace;
use App\Entity\ProficiencyDefinition;
use App\Entity\ProficiencyGrantRule;
use App\Entity\User;
use App\Enum\ProficiencyType;
use App\Service\CharacterProficiencyChoiceService;

require __DIR__.'/../vendor/autoload.php';

$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    ++$checks;
};
$reject = static function (callable $operation, string $message, string $exception = DomainException::class) use ($check): void {
    try {
        $operation();
    } catch (Throwable $error) {
        if (!$error instanceof $exception) {
            throw $error;
        }
        $check(true, $message);
        return;
    }
    throw new RuntimeException($message);
};

$campaign = new Campaign(new User(), 'test', 'Test');
$character = new Character($campaign, 'hero', 'Hero', Character::TYPE_PLAYER);
$other = new Character($campaign, 'other', 'Other', Character::TYPE_PLAYER);
$race = new CharacterRace('test-race', 'Test race');
$athletics = new ProficiencyDefinition('athletics', 'Athletics', ProficiencyType::Skill);
$stealth = new ProficiencyDefinition('stealth', 'Stealth', ProficiencyType::Skill);
$arcana = new ProficiencyDefinition('arcana', 'Arcana', ProficiencyType::Skill);
$outside = new ProficiencyDefinition('outside', 'Outside', ProficiencyType::Tool);
$rule = (new ProficiencyGrantRule('Two skills'))->setCharacterRace($race)->setChoiceCount(2)
    ->addOption($athletics)->addOption($stealth)->addOption($arcana);
$secondRule = (new ProficiencyGrantRule('Another origin'))->setCharacterRace($race)->setChoiceCount(1)
    ->addOption($athletics)->addOption($arcana);
$automatic = (new ProficiencyGrantRule('Automatic'))->setCharacterRace($race)->addOption($athletics);
$service = new CharacterProficiencyChoiceService();

$service->replaceChoices($character, $automatic, []);
$check($character->getProficiencyChoices()->isEmpty(), 'Automatic grants create no choices.');
$reject(fn () => $service->replaceChoices($character, $automatic, [$athletics]), 'Automatic grant rejects a stored selection.');
$service->replaceChoices($character, $rule, [$athletics, $stealth]);
$rows = $character->getProficiencyChoices()->toArray();
$check(count($rows) === 2, 'Exactly two choices are stored.');
$check($rows[0]->getCharacter() === $character && $rows[0]->getGrantRule() === $rule
    && $rows[0]->getProficiencyDefinition() === $athletics, 'Choice retains its character, rule and definition.');
$service->replaceChoices($character, $rule, [$stealth, $athletics]);
$check($character->getProficiencyChoices()->toArray() === $rows, 'Resubmission preserves existing rows, irrespective of input order.');

foreach ([[], [$athletics], [$athletics, $stealth, $arcana], [$athletics, $athletics], [$athletics, $outside], [$athletics, null], [1 => $athletics, 2 => $stealth]] as $invalid) {
    $reject(fn () => $service->replaceChoices($character, $rule, $invalid), 'Invalid count, duplicate, option, type or list rejected.');
    $check($character->getProficiencyChoices()->toArray() === $rows, 'Failed validation leaves all existing choices untouched.');
}

$service->replaceChoices($character, $secondRule, [$athletics]);
$check($character->getProficiencyChoices()->count() === 3, 'Same proficiency from another rule preserves both origins.');
$secondRow = $character->getProficiencyChoices()->last();
$service->replaceChoices($character, $rule, [$athletics, $arcana]);
$choices = $character->getProficiencyChoices();
$check($choices->count() === 3 && $choices->contains($rows[0]) && !$choices->contains($rows[1]), 'Replacement retains unchanged choices and removes deselected ones.');
$check($choices->contains($secondRow), 'Replacement leaves other rules untouched.');
$service->replaceChoices($other, $rule, [$stealth, $arcana]);
$check($other->getProficiencyChoices()->count() === 2 && $choices->count() === 3, 'Characters have independent selections.');
$reject(fn () => $character->addProficiencyChoice($other->getProficiencyChoices()->first()), 'Cannot attach another character choice.', InvalidArgumentException::class);
$character->addProficiencyChoice($rows[0]);
$check($choices->count() === 3, 'Adding the same row twice is idempotent.');

$rule->setChoiceCount(0);
$service->replaceChoices($character, $rule, []);
$check($choices->count() === 1 && $choices->contains($secondRow), 'Explicit reconciliation of an automatic rule removes only its former choices.');
$invalidRule = (new ProficiencyGrantRule('Missing source'))->addOption($athletics)->setChoiceCount(1);
$reject(fn () => $service->replaceChoices($character, $invalidRule, [$athletics]), 'Malformed rules are rejected before mutation.', LogicException::class);
$check($choices->count() === 1, 'Invalid rule leaves character unchanged.');

echo "OK: $checks assertions.\n";
