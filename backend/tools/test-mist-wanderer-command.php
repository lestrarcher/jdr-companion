<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use App\Command\CreateMistWandererBackgroundCommand;
use App\Entity\{CharacterBackground, ProficiencyDefinition, ProficiencyGrantRule, User};
use App\Enum\ProficiencyType;
use App\Service\ReferenceVisibility;
use Symfony\Component\Console\Tester\CommandTester;

(new Symfony\Component\Dotenv\Dotenv())->bootEnv(__DIR__.'/../.env');
$kernel = new App\Kernel('dev', true);
$kernel->boot();
$em = $kernel->getContainer()->get('doctrine')->getManager();
$db = $em->getConnection();
$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void {
    if (!$ok) throw new RuntimeException($message);
    ++$checks;
};
try {
    $db->beginTransaction();
    $metadata = $em->getMetadataFactory()->getAllMetadata();
    foreach ((new Doctrine\ORM\Tools\SchemaTool($em))->getCreateSchemaSql($metadata) as $sql) {
        $db->executeStatement(preg_replace(['/^CREATE TABLE /', '/^CREATE SEQUENCE /'], ['CREATE TEMP TABLE ', 'CREATE TEMP SEQUENCE '], $sql));
    }
    foreach ($metadata as $meta) {
        if ($db->fetchOne('SELECT relpersistence FROM pg_class WHERE oid = to_regclass(?)', [$meta->getTableName()]) !== 't') throw new RuntimeException('Table not isolated');
    }
    $owner = (new User())->setEmail('mj@example.invalid')->setPassword('unused');
    $other = (new User())->setEmail('other@example.invalid')->setPassword('unused');
    $em->persist($owner);
    $em->persist($other);
    $em->flush();
    $tester = new CommandTester(new CreateMistWandererBackgroundCommand($em));
    $args = ['owner-email' => $owner->getEmail(), '--dry-run' => true];
    $check($tester->execute($args) === 1, 'Missing dependencies rejected.');
    foreach (['stealth', 'survival'] as $slug) $em->persist(new ProficiencyDefinition($slug, $slug, ProficiencyType::Skill));
    $em->flush();
    $check($tester->execute(['owner-email' => 'missing@example.invalid', '--dry-run' => true]) === 1, 'Unknown owner rejected.');
    $check($tester->execute($args) === 0, 'Dry-run succeeds.');
    $check((int) $db->fetchOne('SELECT COUNT(*) FROM character_background') === 0, 'Dry-run creates no background.');
    $check((int) $db->fetchOne('SELECT COUNT(*) FROM proficiency_grant_rule') === 0, 'Dry-run creates no rule.');
    $check($tester->execute(['owner-email' => $owner->getEmail()]) === 0, 'Actual command succeeds.');
    $em->clear();
    $owner = $em->getRepository(User::class)->findOneBy(['email' => 'mj@example.invalid']);
    $other = $em->getRepository(User::class)->findOneBy(['email' => 'other@example.invalid']);
    $background = $em->getRepository(CharacterBackground::class)->findOneBy(['slug' => 'vagabond-des-brumes']);
    $rule = $em->getRepository(ProficiencyGrantRule::class)->findOneBy(['characterBackground' => $background]);
    $check($background !== null && $rule !== null, 'Background and rule survive ORM reload.');
    $check(ReferenceVisibility::allows($rule, $owner) && !ReferenceVisibility::allows($rule, $other), 'Rule private to owner.');
    $check(ReferenceVisibility::choices($em, CharacterBackground::class, $other) === [], 'Other owner cannot select background.');
    $check(count(ReferenceVisibility::choices($em, CharacterBackground::class, $owner)) === 1, 'Owner can select background.');
    $slugs = array_map(static fn ($skill) => $skill->getSlug(), $rule->getOptions()->toArray());
    sort($slugs);
    $check($slugs === ['stealth', 'survival'] && $rule->getChoiceCount() === 0 && $rule->getUnlockLevel() === 1, 'Exactly two automatic skills.');
    $ids = [$background->getId(), $rule->getId()];
    $check($tester->execute(['owner-email' => $owner->getEmail()]) === 0, 'Repeat succeeds.');
    $check(str_contains($tester->getDisplay(), 'unchanged') && [$background->getId(), $rule->getId()] === $ids, 'Repeat preserves identities.');
    $check((int) $db->fetchOne('SELECT COUNT(*) FROM proficiency_grant_rule') === 1, 'No duplicate rule.');
    $check($tester->execute(['owner-email' => $other->getEmail(), '--dry-run' => true]) === 1, 'Foreign ownership collision rejected.');
    $background->setDescription('Texte personnalisé à préserver');
    $em->flush();
    $check($tester->execute($args) === 1 && $background->getDescription() === 'Texte personnalisé à préserver', 'Existing edits never overwritten.');
    echo "OK: $checks assertions (temporary tables only).\n";
} finally {
    if ($db->isTransactionActive()) $db->rollBack();
    $em->close();
    $kernel->shutdown();
}
