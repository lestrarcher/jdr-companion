<?php

declare(strict_types=1);

// Run: docker compose exec -T backend php tools/test-character-race-metadata-resolver.php

use App\Entity\CharacterRace;
use App\Service\CharacterRaceMetadataResolver;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;

require __DIR__ . '/../vendor/autoload.php';

$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }

    ++$checks;
};

$resolver = new CharacterRaceMetadataResolver();
$empty = new CharacterRace('empty', 'Empty');
$check($resolver->resolve($empty) === [
    'sizeOptions' => [],
    'walkingSpeed' => null,
    'movementSpeeds' => [],
    'languages' => [],
    'languageChoiceCount' => 0,
    'senses' => [],
    'damageResistances' => [],
    'damageImmunities' => [],
    'conditionImmunities' => [],
], 'A race without metadata resolves to an empty coherent structure.');

$root = (new CharacterRace('root', 'Root'))
    ->setSizeOptions(['medium'])
    ->setWalkingSpeed(9.0)
    ->setMovementSpeeds(['swim' => 6.0, 'climb' => 3.0])
    ->setLanguages(['common', 'elvish', 'common'])
    ->setLanguageChoiceCount(1)
    ->setSenses(['darkvision' => 18.0, 'blindsight' => 3.0])
    ->setDamageResistances(['fire', 'poison', 'fire'])
    ->setDamageImmunities(['cold'])
    ->setConditionImmunities(['charmed', 'poisoned', 'charmed']);
$rootMetadata = $resolver->resolve($root);
$check($rootMetadata['sizeOptions'] === ['medium'], 'The root defines its size.');
$check($rootMetadata['walkingSpeed'] === 9.0, 'The root defines its walking speed.');
$check($rootMetadata['movementSpeeds'] === ['swim' => 6.0, 'climb' => 3.0], 'The root defines special speeds.');
$check($rootMetadata['languages'] === ['common', 'elvish'], 'The root languages are deduplicated.');
$check($rootMetadata['languageChoiceCount'] === 1, 'The root defines its language choice count.');
$check($rootMetadata['senses'] === ['darkvision' => 18.0, 'blindsight' => 3.0], 'The root defines its senses.');
$check($rootMetadata['damageResistances'] === ['fire', 'poison'], 'The root resistances are deduplicated.');
$check($rootMetadata['damageImmunities'] === ['cold'], 'The root defines its damage immunities.');
$check($rootMetadata['conditionImmunities'] === ['charmed', 'poisoned'], 'The root condition immunities are deduplicated.');

$inheritingChild = (new CharacterRace('inheriting-child', 'Inheriting child'))->setParentRace($root);
$check($resolver->resolve($inheritingChild) === $rootMetadata, 'A child inherits all metadata.');

$walkingChild = (new CharacterRace('walking-child', 'Walking child'))
    ->setParentRace($root)
    ->setWalkingSpeed(10.5);
$check($resolver->resolve($walkingChild)['walkingSpeed'] === 10.5, 'A child replaces the walking speed.');

$sizeChild = (new CharacterRace('size-child', 'Size child'))
    ->setParentRace($root)
    ->setSizeOptions(['small', 'medium']);
$check($resolver->resolve($sizeChild)['sizeOptions'] === ['small', 'medium'], 'A child replaces the size options.');

$movementChild = (new CharacterRace('movement-child', 'Movement child'))
    ->setParentRace($root)
    ->setMovementSpeeds(['swim' => 12.0, 'fly' => 9.0])
    ->setLanguages(['elvish', 'draconic'])
    ->setLanguageChoiceCount(0)
    ->setSenses(['darkvision' => 36.0, 'truesight' => 1.5])
    ->setDamageResistances(['poison', 'lightning'])
    ->setDamageImmunities(['fire', 'cold'])
    ->setConditionImmunities(['frightened', 'poisoned']);
$check($resolver->resolve($movementChild)['movementSpeeds'] === ['swim' => 12.0, 'climb' => 3.0, 'fly' => 9.0], 'A child merges and replaces special speeds.');
$childMetadata = $resolver->resolve($movementChild);
$check($childMetadata['languages'] === ['common', 'elvish', 'draconic'], 'A child adds languages and the union is deduplicated.');
$check($childMetadata['languageChoiceCount'] === 0, 'A child can replace the inherited language choice count with zero.');
$check($childMetadata['senses'] === ['darkvision' => 36.0, 'blindsight' => 3.0, 'truesight' => 1.5], 'A child adds and replaces senses.');
$check($childMetadata['damageResistances'] === ['fire', 'poison', 'lightning'], 'Damage resistances are merged and deduplicated.');
$check($childMetadata['damageImmunities'] === ['cold', 'fire'], 'Damage immunities are merged and deduplicated.');
$check($childMetadata['conditionImmunities'] === ['charmed', 'poisoned', 'frightened'], 'Condition immunities are merged and deduplicated.');

$removingChild = (new CharacterRace('removing-child', 'Removing child'))
    ->setParentRace($movementChild)
    ->setMovementSpeeds(['swim' => null])
    ->setSenses(['blindsight' => null]);
$check($resolver->resolve($removingChild)['movementSpeeds'] === ['climb' => 3.0, 'fly' => 9.0], 'An explicit null removes an inherited special speed.');
$check($removingChild->getMovementSpeeds() === ['swim' => null], 'Resolution preserves the raw explicit null.');
$check($resolver->resolve($removingChild)['senses'] === ['darkvision' => 36.0, 'truesight' => 1.5], 'An explicit null removes an inherited sense.');
$check($removingChild->getSenses() === ['blindsight' => null], 'Resolution preserves the raw explicit null sense.');
$check($root->getMovementSpeeds() === ['swim' => 6.0, 'climb' => 3.0], 'Resolution preserves the parent raw values.');
$check(json_decode(json_encode($removingChild->getMovementSpeeds(), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR) === ['swim' => null], 'JSON preserves an explicitly null movement key.');
$jsonType = Type::getType(Types::JSON);
$platform = new PostgreSQLPlatform();
$storedMovements = $jsonType->convertToDatabaseValue($removingChild->getMovementSpeeds(), $platform);
$check($jsonType->convertToPHPValue($storedMovements, $platform) === ['swim' => null], 'Doctrine JSON preserves an explicitly null movement key.');
$storedMissingKey = $jsonType->convertToDatabaseValue(['fly' => 7.5], $platform);
$check($jsonType->convertToPHPValue($storedMissingKey, $platform) === ['fly' => 7.5], 'Doctrine JSON does not add absent movement keys.');
$check($movementChild->getMovementSpeeds() === ['swim' => 12.0, 'fly' => 9.0], 'Resolution preserves child raw values.');

$grandchild = (new CharacterRace('grandchild', 'Grandchild'))
    ->setParentRace($removingChild)
    ->setWalkingSpeed(7.5)
    ->setMovementSpeeds(['climb' => 4.5]);
$check($resolver->resolve($grandchild) === [
    'sizeOptions' => ['medium'],
    'walkingSpeed' => 7.5,
    'movementSpeeds' => ['climb' => 4.5, 'fly' => 9.0],
    'languages' => ['common', 'elvish', 'draconic'],
    'languageChoiceCount' => 0,
    'senses' => ['darkvision' => 36.0, 'truesight' => 1.5],
    'damageResistances' => ['fire', 'poison', 'lightning'],
    'damageImmunities' => ['cold', 'fire'],
    'conditionImmunities' => ['charmed', 'poisoned', 'frightened'],
], 'Multiple inheritance levels resolve deterministically.');
$check($root->getLanguages() === ['common', 'elvish'], 'Resolution does not mutate raw languages.');
$check($movementChild->getSenses() === ['darkvision' => 36.0, 'truesight' => 1.5], 'Resolution does not mutate raw senses.');

$invalidChecks = [
    static fn () => (new CharacterRace('invalid-language', 'Invalid'))->setLanguages(['']),
    static fn () => (new CharacterRace('invalid-choice', 'Invalid'))->setLanguageChoiceCount(-1),
    static fn () => (new CharacterRace('invalid-sense', 'Invalid'))->setSenses(['darkvision' => 0]),
    static fn () => (new CharacterRace('invalid-damage', 'Invalid'))->setDamageResistances(['unknown']),
    static fn () => (new CharacterRace('invalid-condition', 'Invalid'))->setConditionImmunities(['unknown']),
];

foreach ($invalidChecks as $invalidCheck) {
    try {
        $invalidCheck();
        throw new RuntimeException('Invalid racial metadata should be rejected.');
    } catch (InvalidArgumentException) {
        ++$checks;
    }
}

echo sprintf("OK (%d checks)\n", $checks);
