<?php
declare(strict_types=1);

/**
 * Integration smoke tests for feature spell synchronization.
 * Runs in an outer transaction, always rolled back.
 * Usage: php tools/test-character-feature-spells.php CHARACTER_ID
 */
use App\Entity\Character;
use App\Service\CharacterFeatureSpellService;
use App\Service\CharacterFeatureResolver;
use App\Repository\CharacterFeatureRuleRepository;

require __DIR__.'/../vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv(__DIR__.'/../.env');
$characterId = filter_var($argv[1] ?? null, FILTER_VALIDATE_INT);
if (!$characterId || $characterId < 1) {
    fwrite(STDERR, "Usage: php tools/test-character-feature-spells.php CHARACTER_ID\n");
    exit(2);
}
$kernel = new App\Kernel('dev', true);
$kernel->boot();
$registry = $kernel->getContainer()->get('doctrine');
$em = $registry->getManager();
$db = $em->getConnection();
$character = $em->find(Character::class, $characterId);
if (!$character) {
    fwrite(STDERR, "Character not found.\n");
    exit(2);
}
$resolver = new CharacterFeatureResolver(new CharacterFeatureRuleRepository($registry));
$service = new CharacterFeatureSpellService($db, $resolver);
$checks = 0;
$check = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) throw new RuntimeException("FAIL: ".$label);
    echo "OK: ".$label."\n";
    ++$checks;
};
$rows = static fn() => $db->fetchAllAssociative(
    'SELECT grant_id, spell_id, active FROM character_feature_spell WHERE character_id=? ORDER BY grant_id',
    [$characterId]
);
$db->beginTransaction();
try {
    $service->synchronize($character);
    $first = $rows();
    $service->synchronize($character);
    $second = $rows();
    $check($first === $second, 'Two synchronizations are idempotent');
    $check(count($second) === count(array_unique(array_column($second, 'grant_id'))),
        'At most one acquisition per grant');
    $status = $service->status($character);
    $check(isset($status['known'], $status['pending']), 'Status exposes known and pending spells');

    // Source independence: two grants for the same spell must remain two rows.
    $active = array_values(array_filter($second, static fn($r) => (bool)$r['active']));
    $perSpell = [];
    foreach ($active as $r) $perSpell[(string)$r['spell_id']][] = $r['grant_id'];
    foreach ($perSpell as $grantIds) {
        $check(count($grantIds) === count(array_unique($grantIds)),
            'Distinct sources remain distinct for a shared spell');
    }
    if (!$perSpell) echo "SKIP: no active spells for provenance comparison\n";

    // Temporarily make an existing FIXED grant unavailable, then synchronize.
    // All changes (including grant edits) are rolled back below.
    $fixed = $db->fetchAssociative(
        "SELECT g.id, g.unlock_level FROM feature_spell_grant g
         JOIN character_feature_spell a ON a.grant_id=g.id
         WHERE a.character_id=? AND a.active=true AND g.mode='fixed'
         ORDER BY g.id LIMIT 1",
        [$characterId]
    );
    if ($fixed) {
        $db->update('feature_spell_grant', ['unlock_level'=>20], ['id'=>$fixed['id']]);
        $service->synchronize($character);
        $retired = $db->fetchAssociative(
            'SELECT active, retired_at FROM character_feature_spell WHERE character_id=? AND grant_id=?',
            [$characterId, $fixed['id']]
        );
        $check(!$retired['active'] && $retired['retired_at'] !== null,
            'Unavailable fixed grant is retired logically');
        $db->update('feature_spell_grant', ['unlock_level'=>$fixed['unlock_level']], ['id'=>$fixed['id']]);
        $service->synchronize($character);
        $restored = $db->fetchAssociative(
            'SELECT active, retired_at FROM character_feature_spell WHERE character_id=? AND grant_id=?',
            [$characterId, $fixed['id']]
        );
        $check((bool)$restored['active'] && $restored['retired_at'] === null,
            'Fixed grant is restored when applicable again');
    } else {
        echo "SKIP: no active fixed grant for retirement/restoration test\n";
    }
    echo "PASS: {$checks} assertions (all test writes rolled back)\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage()."\n");
    exit(1);
} finally {
    if ($db->isTransactionActive()) $db->rollBack();
    $kernel->shutdown();
}
