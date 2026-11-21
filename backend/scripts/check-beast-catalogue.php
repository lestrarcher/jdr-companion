<?php
declare(strict_types=1);

// Read-only compatibility check against the current catalogue; transformations stay in memory.
require dirname(__DIR__).'/vendor/autoload.php';
if (empty($argv[1])) { fwrite(STDERR, "Usage: php scripts/check-beast-catalogue.php <character-name>\n"); exit(1); }
$kernel = new App\Kernel('dev', false); $kernel->boot();
$em = $kernel->getContainer()->get('doctrine')->getManager(); $db = $em->getConnection();
$check = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$db->beginTransaction(); $db->executeStatement('SET TRANSACTION READ ONLY');
try {
    $repository = $em->getRepository(App\Entity\Beast::class);
    $serializer = new App\Service\BeastSerializer();
    $characters = $em->getRepository(App\Entity\Character::class)->findBy(['name'=>$argv[1]]);
    $check(count($characters) === 1, 'Expected exactly one matching character.'); $character = $characters[0];
    $catalogue = new App\Service\BeastCatalogue($db,$repository,$serializer);
    $records = $catalogue->listForCharacter($character->getId());
    $check(count($records) === (int) $db->fetchOne('SELECT count(*) FROM beast'), 'Catalogue count mismatch');
    $weapons = 0;
    foreach ($records as $record) {
        $row = $db->fetchAssociative('SELECT * FROM beast WHERE id = ?',[$record['id']]);
        $check($record['slug'] === $row['slug'] && $record['name'] === $row['name'] && $record['challengeRating'] === $row['challenge_rating'], 'Identity mismatch');
        $check($record['statBlock'] === ($row['stat_block'] === null ? null : json_decode($row['stat_block'],true)), 'Statistics changed');
        $expected = $db->fetchFirstColumn('SELECT habitat FROM beast_habitat WHERE beast_id = ? ORDER BY habitat',[$record['id']]);
        $check($record['habitats'] === $expected,'Habitat mismatch');
        $weapons += count($record['naturalWeapons']);
    }
    $check($weapons === (int) $db->fetchOne('SELECT count(*) FROM beast_natural_weapon'),'Weapon count mismatch');
    $rules = new App\Service\WildShapeRules2014(); $service = new App\Service\WildShapeSessionService($rules);
    $profile = $service->druidProfile($character);
    $state = ['hitPoints'=>['current'=>25], 'wildShape'=>['usesRemaining'=>2,'active'=>null]];
    $tested = [];
    foreach ($records as $record) {
        if ($rules->unavailableReason($record,$profile['level'],$profile['moon'],$record['seen']) !== null) continue;
        if ($state['wildShape']['usesRemaining'] === 0) $state = $service->afterRest($state);
        $uses = $state['wildShape']['usesRemaining'];
        $state = $service->transform($state,$record,$profile['level'],$profile['moon'],true,'merge');
        $check($state['wildShape']['usesRemaining'] === $uses - 1,'Use not consumed');
        $check($state['wildShape']['active']['beastId'] === $record['id'],'Wrong form');
        $check($state['wildShape']['active']['abilities'] === $record['statBlock']['abilities'],'Ability mismatch');
        $check($state['hitPoints']['current'] === 25,'Humanoid HP changed');
        $tested[] = $record['slug'];
    }
    $check(count($tested) >= 2,'Need at least two eligible observed beasts for direct-change coverage');
    $check($service->revert($state)['wildShape']['active'] === null,'Revert failed');
    echo json_encode(['catalogue'=>count($records),'weapons'=>$weapons,'character'=>$character->getName(),'druidLevel'=>$profile['level'],
        'moonCircle'=>$profile['moon'],'observed'=>count(array_filter($records,fn ($r)=>$r['seen'])),'simulatedForms'=>$tested,
        'databaseWrites'=>0], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";
} finally { $db->rollBack(); $kernel->shutdown(); }
