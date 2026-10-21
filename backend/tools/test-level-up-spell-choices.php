<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

use App\Dto\LevelUpSpellChoices;
use App\Entity\Character;
use App\Entity\CharacterClass;
use App\Service\CharacterLevelUpRequestResolver;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\Decorator\EntityManagerDecorator;
use Doctrine\ORM\EntityRepository;

// Pure structural tests: no kernel, connection, level or acquisition is created.
$count = 0;
$check = static function (bool $condition, string $message) use (&$count): void {
    if (!$condition) throw new RuntimeException($message);
    ++$count;
};
$valid = ['policyId'=>1, 'spellId'=>2, 'ruleId'=>3];
$check(LevelUpSpellChoices::fromPayload([]) === null, 'Absent remains absent');
$check(LevelUpSpellChoices::fromPayload(['spellChoices'=>[]]) === [], 'Empty remains empty');
$accepted = [
    [$valid],
    [array_replace($valid, ['ruleId'=>null])],
    [$valid + ['replacesAcquisitionId'=>4]],
    [$valid + ['replacesAcquisitionId'=>4, 'replacementConfirmed'=>true]],
    [$valid + ['replacesAcquisitionId'=>4, 'replacementConfirmed'=>false]],
    [$valid, array_replace($valid, ['policyId'=>2])],
    array_map(static fn(int $id): array => array_replace($valid, ['spellId'=>$id]), range(1, 100)),
];
foreach ($accepted as $choices) {
    $check(LevelUpSpellChoices::fromPayload(['spellChoices'=>$choices]) === $choices, 'Accepted without coercion');
}
$invalid = [null, false, '[]', 1, ['choice'=>$valid], [[]], [1], [null],
    [$valid + ['unknown'=>1]], [$valid + ['classLevelId'=>1]],
    [$valid, $valid], [$valid, array_replace($valid, ['ruleId'=>5])],
    [$valid + ['replacesAcquisitionId'=>4], ['policyId'=>2,'spellId'=>5,'ruleId'=>6,'replacesAcquisitionId'=>4]],
    [$valid + ['replacementConfirmed'=>true]], [$valid + ['replacementConfirmed'=>false]],
    array_map(static fn(int $id): array => array_replace($valid, ['spellId'=>$id]), range(1, 101)),
];
foreach (['policyId', 'spellId', 'ruleId'] as $field) {
    $missing = $valid;
    unset($missing[$field]);
    $invalid[] = [$missing];
    foreach ([0, -1, '1', 1.0, true, false, [], new stdClass()] as $value) {
        $invalid[] = [array_replace($valid, [$field=>$value])];
    }
    if ($field !== 'ruleId') $invalid[] = [array_replace($valid, [$field=>null])];
}
foreach ([null, 0, -1, '4', 4.0, true, []] as $value) {
    $invalid[] = [$valid + ['replacesAcquisitionId'=>$value]];
}
foreach ([null, 1, 'true', []] as $value) {
    $invalid[] = [$valid + ['replacesAcquisitionId'=>4, 'replacementConfirmed'=>$value]];
}
foreach ($invalid as $choices) {
    try {
        LevelUpSpellChoices::fromPayload(['spellChoices'=>$choices]);
        throw new RuntimeException('Invalid payload accepted');
    } catch (InvalidArgumentException) {
        $check(true, 'Invalid payload rejected');
    }
}

// Stub only the pre-existing class reads to exercise the actual resolver without SQL.
$class = new CharacterClass('structural-fixture', 'Structural fixture', 10, 3);
$repository = new class($class) extends EntityRepository {
    public function __construct(private CharacterClass $fixture) {}
    public function find(mixed $id, LockMode|int|null $lockMode = null, ?int $lockVersion = null): ?object { return $this->fixture; }
};
$em = new class($repository) extends EntityManagerDecorator {
    public function __construct(private EntityRepository $fixture) {}
    public function find(string $className, mixed $id, LockMode|int|null $lockMode = null, ?int $lockVersion = null): ?object { return null; }
    public function getRepository(string $className): EntityRepository { return $this->fixture; }
};
$resolver = new CharacterLevelUpRequestResolver($em);
$character = (new ReflectionClass(Character::class))->newInstanceWithoutConstructor();
$legacy = $resolver->resolve(['classId'=>1], $character);
$check($legacy['spellChoices'] === null, 'Legacy request accepted');
foreach ([[], [$valid], [array_replace($valid, ['ruleId'=>null])]] as $choices) {
    $resolved = $resolver->resolve(['classId'=>1, 'spellChoices'=>$choices], $character);
    $check($resolved['spellChoices'] === $choices, 'Resolver preserves parsed choices');
    unset($resolved['spellChoices']);
    $expected = $legacy;
    unset($expected['spellChoices']);
    $check($resolved === $expected, 'Existing selections unchanged');
}
try {
    $resolver->resolve(['classId'=>1, 'spellChoices'=>[$valid + ['forged'=>true]]], $character);
    throw new RuntimeException('Resolver bypassed validation');
} catch (InvalidArgumentException) {
    $check(true, 'Resolver rejects malformed choices');
}
echo "OK: $count assertions; no database connection.\n";
