<?php
declare(strict_types=1);
// Exercise the real builder and Doctrine wrapInTransaction with an in-memory
// connection. No SQL, schema creation, database or migration is executed.
require __DIR__.'/character-training-fixture.php';

class CreatorRepositoryDouble
{
    public function findOneBy(array $criteria): ?object { return null; }
}
class_alias(CreatorRepositoryDouble::class, App\Repository\CharacterRepository::class);
class CreatorLevelUpDouble
{
    public function __construct(private Doctrine\ORM\EntityManagerInterface $em) {}
    public function levelUp(App\Entity\Character $character, App\Entity\CharacterClass $characterClass, ?App\Entity\CharacterSubclass $subclass = null): void
    {
        // Same nested transaction/flush boundary as CharacterLevelUpService.
        $this->em->wrapInTransaction(function () use ($character, $characterClass, $subclass): void {
            $level = new App\Entity\CharacterClassLevel($character, $characterClass, 1, $subclass);
            $character->addClassLevel($level);
            $this->em->persist($level);
            $this->em->flush();
        });
    }
}
class_alias(CreatorLevelUpDouble::class, App\Service\CharacterLevelUpService::class);
class CreatorConnectionDouble extends Doctrine\DBAL\Connection
{
    public array $rows = [];
    public array $snapshots = [];
    public int $commits = 0;
    public int $rollbacks = 0;
    public function __construct() {}
    public function beginTransaction(): void { $this->snapshots[] = $this->rows; }
    public function commit(): void { array_pop($this->snapshots); if ($this->snapshots === []) ++$this->commits; }
    public function rollBack(): void { $this->rows = array_pop($this->snapshots); ++$this->rollbacks; }
    public function isTransactionActive(): bool { return $this->snapshots !== []; }
}
class CreatorEntityManagerDouble extends Doctrine\ORM\EntityManager
{
    public array $pending = [];
    public bool $wasClosed = false;
    public function __construct(public CreatorConnectionDouble $recordingConnection)
    {
        (new ReflectionProperty(Doctrine\ORM\EntityManager::class, 'conn'))->setValue($this, $recordingConnection);
    }
    public function persist(object $object): void { $this->pending[] = $object; }
    public function flush(): void { $this->recordingConnection->rows = $this->pending; }
    public function close(): void { $this->wasClosed = true; }
}
$f = trainingFixture(); extract($f);
$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void { if (!$ok) throw new RuntimeException($message); ++$checks; };
$abilities = [];
foreach (App\Enum\Ability::cases() as $ability) $abilities[$ability->value] = 12;
$run = static function (array $training) use ($f, $abilities): array {
    $db = new CreatorConnectionDouble();
    $em = new CreatorEntityManagerDouble($db);
    $builder = new App\Service\CharacterBuilderService($em, new CreatorRepositoryDouble(), new CreatorLevelUpDouble($em), $f['service']);
    $character = null; $error = null;
    try {
        $character = $builder->create($f['campaign'], 'hero', 'Hero', 'Player', 'player', $f['races']['human-phb'], $abilities, [], [], $f['classes']['rogue'], null, $f['backgrounds']['criminal-phb'], $training);
    } catch (DomainException $e) { $error = $e; }
    return [$db, $em, $character, $error];
};
[$db, $em, $character, $error] = $run($payload);
$check($error === null && $character !== null, 'Full builder creation succeeds.');
$check($db->commits === 1 && $db->rollbacks === 0 && count($db->rows) === 2, 'One final commit includes character and first level.');
$check($character->getBackground() === $backgrounds['criminal-phb'] && count($character->getExpertiseChoices()) === 2, 'Background and all training applied in the builder.');
$check(count($character->getLanguageChoices()) === 1 && count($character->getProficiencyChoices()) === 5, 'Choices attached for Doctrine cascade persist.');
$invalid = $payload; $invalid['expertiseChoices'][0]['proficiencyIds'][0] = $def('nature');
[$db, $em, $character, $error] = $run($invalid);
$check($error instanceof DomainException, 'Late invalid expertise rejects creation.');
$check($db->rows === [] && $db->commits === 0 && $db->rollbacks === 1, 'Outer rollback removes first level and character after nested flush.');
$check($em->wasClosed, 'Doctrine closes entity manager after rollback.');
[$db, $em, $character, $error] = $run([]);
$check($error instanceof DomainException && $db->rows === [], 'Missing page-two choices cannot create a partial character.');
echo "OK: $checks builder transaction assertions (recording connection, no SQL).\n";

