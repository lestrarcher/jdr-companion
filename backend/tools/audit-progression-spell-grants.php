<?php
declare(strict_types=1);

// Read-only diagnosis: no synchronization, flush, token or full session output.
use App\Entity\{Character, CharacterSessionState};
use App\Repository\{CharacterFeatureRuleRepository, TrackableResourceRuleRepository};
use App\Service\{CharacterAbilityCalculator, CharacterFeatureResolver, CharacterFeatureSpellService,
    CharacterFeatureSpellCastingResolver, CharacterResourceResolver, CharacterSessionStateSynchronizer};

require __DIR__.'/../vendor/autoload.php';
if ($argc !== 3 || !ctype_digit($argv[1]) || !ctype_digit($argv[2])) {
    fwrite(STDERR, "Usage: php tools/audit-progression-spell-grants.php CHARACTER_ID STATE_ID\n");
    exit(2);
}
(new Symfony\Component\Dotenv\Dotenv())->bootEnv(__DIR__.'/../.env');
$kernel = new App\Kernel('dev', true);
$kernel->boot();
$registry = $kernel->getContainer()->get('doctrine');
$em = $registry->getManager();
$db = $em->getConnection();
try {
    $db->beginTransaction();
    $db->executeStatement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
    $character = $em->find(Character::class, (int)$argv[1]);
    $session = $em->find(CharacterSessionState::class, (int)$argv[2]);
    if (!$character || !$session || $session->getCharacter()->getId() !== $character->getId()) {
        throw new RuntimeException('Character/state mismatch.');
    }
    $features = new CharacterFeatureResolver(new CharacterFeatureRuleRepository($registry));
    $service = new CharacterFeatureSpellService($db, $features);
    $casting = new CharacterFeatureSpellCastingResolver($db, $features);
    $values = CharacterSessionStateSynchronizer::extractProgressionValues($session->getState());
    $active = [];
    foreach ($features->applicableRules($character, $values) as $rule) {
        $active[$rule->getId()] = true;
    }
    $rules = $db->fetchAllAssociative('SELECT r.id, r.progression_threshold, p.slug, p.minimum_value,
        d.id feature_id, d.name feature_name, g.id grant_id, g.unlock_level, g.casting_ability,
        g.casting_rules, g.resource_definition_id, g.spell_id, s.name spell_name, s.level spell_level
        FROM character_progression cp
        JOIN progression_definition p ON p.id=cp.progression_definition_id
        JOIN character_feature_rule r ON r.progression_definition_id=p.id
        JOIN character_feature_definition d ON d.id=r.feature_definition_id
        LEFT JOIN feature_spell_grant g ON g.feature_id=d.id
        LEFT JOIN spell_definition s ON s.id=g.spell_id
        WHERE cp.character_id=? ORDER BY r.id,g.id', [$character->getId()]);
    foreach ($rules as &$row) {
        $row['currentValue'] = $values[$row['slug']] ?? null;
        $row['featureApplicable'] = isset($active[$row['id']]);
        $row['castingResolution'] = $row['grant_id'] === null ? null
            : $casting->resolve($character, (int)$row['grant_id'], $values);
    }
    unset($row);
    $resources = new CharacterResourceResolver(new TrackableResourceRuleRepository($registry),
        new CharacterAbilityCalculator(), $features);
    $known = $service->status($character, $values);
    $resourceIds = [];
    foreach ($known['known'] as $grant) {
        foreach ($grant['castingRulesResolution']['methods'] as $method) {
            if ($method['kind'] === 'resource') $resourceIds[] = $method['resourceDefinitionId'];
        }
    }
    $pools = [];
    foreach ($resources->resolve($character, $values) as $resource) {
        if (!in_array($resource->getDefinition()->getId(), $resourceIds, true)) continue;
        $counters = array_values(array_filter($session->getState()['resources'] ?? [],
            static fn(array $row): bool => $row['id'] === $resource->getSlug()));
        $pools[] = ['slug'=>$resource->getSlug(), 'maximum'=>$resource->getMaximum(),
            'rechargeType'=>$resource->getRechargeType()->value, 'counters'=>$counters];
    }
    echo json_encode(['characterId'=>$character->getId(), 'stateId'=>$session->getId(),
        'progressionRules'=>$rules, 'featureSpells'=>$known, 'castingPools'=>$pools],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
} finally {
    if ($db->isTransactionActive()) $db->rollBack();
    $kernel->shutdown();
}
