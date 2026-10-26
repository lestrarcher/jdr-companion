<?php
declare(strict_types=1);

// Synthetic fixtures and temporary sequences only. No real character is touched.
use App\Entity\{User, Campaign, Character, CharacterClass, CharacterClassLevel, Feat, CharacterFeat, CharacterFeatureDefinition, CharacterFeatureRule};
use App\Service\{CharacterFeatureSpellService, CharacterFeatureResolver, SpellCatalogue};
use App\Repository\CharacterFeatureRuleRepository;

require __DIR__.'/../vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv(__DIR__.'/../.env');
$kernel = new App\Kernel('dev', true); $kernel->boot();
$registry = $kernel->getContainer()->get('doctrine');
$em = $registry->getManager(); $db = $em->getConnection(); $checks = 0;
$check = static function (bool $ok, string $label) use (&$checks): void {
    if (!$ok) throw new RuntimeException($label);
    ++$checks;
};
try {
    $db->beginTransaction();
    foreach ((new Doctrine\ORM\Tools\SchemaTool($em))->getCreateSchemaSql($em->getMetadataFactory()->getAllMetadata()) as $sql) {
        $db->executeStatement(preg_replace(['/^CREATE TABLE /','/^CREATE SEQUENCE /'], ['CREATE TEMP TABLE ','CREATE TEMP SEQUENCE '], $sql));
    }
    foreach (['feature_spell_grant','character_feature_spell'] as $table) {
        $db->executeStatement('CREATE TEMP TABLE '.$table.' (LIKE public.'.$table.' INCLUDING DEFAULTS INCLUDING CONSTRAINTS INCLUDING IDENTITY INCLUDING INDEXES)');
        $check($db->fetchOne('SELECT relpersistence FROM pg_class WHERE oid=to_regclass(?)', [$table]) === 't', 'Temporary table');
    }
    $owner = (new User())->setEmail('fixed-grants@example.invalid')->setPassword('unused')->setRoles([SpellCatalogue::ADMIN_ROLE]);
    $campaign = new Campaign($owner, 'fixed-grants', 'Fixed grants');
    $character = new Character($campaign, 'test', 'Test', Character::TYPE_PLAYER);
    $other = new Character($campaign, 'other', 'Other', Character::TYPE_PLAYER);
    $class = new CharacterClass('fighter-test', 'Fighter', 10, 1);
    $level = new CharacterClassLevel($character, $class, 1);
    $character->addClassLevel($level);
    $feat = new Feat('test-feat', 'Test feat');
    $assignment = new CharacterFeat($character, $feat); $character->addFeat($assignment);
    $feature = new CharacterFeatureDefinition('test-fixed', 'Test fixed');
    $rule = CharacterFeatureRule::forFeat($feature, $feat);
    foreach ([$owner,$campaign,$character,$other,$class,$level,$feat,$assignment,$feature,$rule] as $entity) $em->persist($entity);
    $em->flush();
    $spell = (new SpellCatalogue($db))->create(['slug'=>'test-fixed','name'=>'Fixed spell','level'=>2,'school'=>'conjuration',
        'castingTime'=>'1 action','range'=>'Self','duration'=>'Instantaneous','description'=>'Fixture','classIds'=>[]], $owner, true);
    $service = new CharacterFeatureSpellService($db, new CharacterFeatureResolver(new CharacterFeatureRuleRepository($registry)));
    $service->synchronize($character);
    $rows = static fn() => $db->fetchAllAssociative('SELECT * FROM character_feature_spell ORDER BY id');
    $check($rows() === [], 'Feat acquired before any grants');
    $db->insert('feature_spell_grant', ['feature_id'=>$feature->getId(),'mode'=>'choice','spell_level'=>1,'unlock_level'=>1]);
    $choiceId = (int)$db->lastInsertId();
    $db->insert('character_feature_spell', ['character_id'=>$character->getId(),'grant_id'=>$choiceId,'spell_id'=>$spell['id']]);
    $choice = $rows()[0];
    $db->insert('feature_spell_grant', ['feature_id'=>$feature->getId(),'mode'=>'fixed','spell_id'=>$spell['id'],'unlock_level'=>1]);
    $grantId = (int)$db->lastInsertId();
    $db->insert('feature_spell_grant', ['feature_id'=>$feature->getId(),'mode'=>'fixed','spell_id'=>$spell['id'],'unlock_level'=>1]);
    $unrelatedId = (int)$db->lastInsertId();
    $check(count($service->status($character)['known']) === 1 && $rows() === [$choice], 'Read does not create the newly added fixed acquisition');
    $service->synchronize($character, $grantId);
    $first = $rows();
    $check(count($first) === 2, 'Fixed grant added after feat is materialized');
    $check(!in_array($unrelatedId, array_map('intval', array_column($first, 'grant_id')), true), 'Other missing fixed grant left untouched');
    $check($first[0] === $choice, 'Existing choice preserved byte for byte');
    $check($first[1]['active'] === true && (int)$first[1]['spell_id'] === $spell['id'], 'Fixed acquisition active');
    $service->synchronize($character, $grantId);
    $check($rows() === $first, 'Targeted synchronization is idempotent including timestamps');
    $service->synchronize($other, $grantId);
    $check($rows() === $first, 'Character without feat gets no acquisition');
    $service->synchronize($character, $choiceId);
    $check($rows() === $first, 'Targeted reconciliation never changes a choice');
    $db->update('feature_spell_grant', ['unlock_level'=>2], ['id'=>$grantId]);
    $service->synchronize($character, $grantId);
    $check($rows() === $first, 'Targeted reconciliation does not retire an inapplicable grant');
    $check(count($service->status($character)['known']) === 1, 'Reads still enforce unlock level');
    $db->update('feature_spell_grant', ['unlock_level'=>1], ['id'=>$grantId]);
    $db->update('character_feature_spell', ['active'=>false,'retired_at'=>'2026-01-01 00:00:00'], ['id'=>$first[1]['id']], ['active'=>Doctrine\DBAL\ParameterType::BOOLEAN]);
    $service->synchronize($character, $grantId);
    $check($rows() === $first, 'Reactivation reuses original row');
    $service->synchronize($character);
    $check(count($rows()) === 3, 'Existing full synchronization still reconciles all fixed grants');
    $full = $rows(); $service->synchronize($character);
    $check($rows() === $full, 'Full synchronization remains idempotent');

    $second = new Character($campaign, 'second', 'Second eligible', Character::TYPE_PLAYER);
    $low = new Character($campaign, 'low', 'Insufficient level', Character::TYPE_PLAYER);
    foreach ([$second, $low] as $candidate) {
        $a = new CharacterFeat($candidate, $feat); $candidate->addFeat($a);
        $em->persist($candidate); $em->persist($a);
    }
    $secondLevel = new CharacterClassLevel($second, $class, 1); $second->addClassLevel($secondLevel); $em->persist($secondLevel);
    $em->flush();
    $candidates = [$second, $low, $other, $character, $character];
    $snapshot = static function () use ($em, $db): array {
        $result = [];
        foreach ($em->getMetadataFactory()->getAllMetadata() as $meta) {
            $table = $meta->getTableName();
            $result[$table] = $db->fetchOne("SELECT md5(COALESCE(string_agg(to_jsonb(t)::text,'' ORDER BY to_jsonb(t)::text),'')) FROM ".$db->quoteIdentifier($table).' t');
        }
        return $result;
    };
    $untouched = $snapshot();
    $before = $rows();
    $preview = $service->propagateFixedGrant($grantId, $candidates, true);
    $check($preview === ['eligible'=>2,'missing'=>1,'created'=>0] && $rows() === $before, 'Read-only preview counts eligible characters and missing acquisitions');
    $check($service->propagateFixedGrant($grantId, $candidates) === ['eligible'=>2,'missing'=>1,'created'=>1], 'Only missing acquisition created');
    $after = $rows();
    $check(array_slice($after,0,count($before)) === $before, 'All existing acquisitions preserved');
    $check($service->propagateFixedGrant($grantId, $candidates)['created'] === 0 && $rows() === $after, 'Repeated propagation is idempotent');
    $check($service->propagateFixedGrant($choiceId, $candidates) === ['eligible'=>0,'missing'=>0,'created'=>0] && $rows() === $after, 'Choice grants not propagated');
    $db->update('character_feature_spell', ['active'=>false,'retired_at'=>'2026-01-01 00:00:00'], ['character_id'=>$second->getId(),'grant_id'=>$grantId], ['active'=>Doctrine\DBAL\ParameterType::BOOLEAN]);
    $inactive = $rows();
    $check($service->propagateFixedGrant($grantId, $candidates)['created'] === 0 && $rows() === $inactive, 'Existing inactive acquisitions also preserved');

    // Exercise the actual catalogue creation path, including its enclosing transaction.
    $controller = new App\Controller\FeatureSpellGrantController($db);
    $container = new Symfony\Component\DependencyInjection\Container();
    $tokens = new Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage();
    $tokens->setToken(new Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken($owner, 'test', $owner->getRoles()));
    $container->set('security.token_storage', $tokens); $controller->setContainer($container);
    $repository = new App\Repository\CharacterRepository($registry);
    $request = Symfony\Component\HttpFoundation\Request::create('/', 'POST', [], [], [], [], json_encode(['mode'=>'fixed','spellId'=>$spell['id'],'unlockLevel'=>1]));
    $response = $controller->create('official', $feature->getId(), $request, $service, $repository);
    $check($response->getStatusCode() === 201, 'Catalogue creation succeeds');
    $createdGrant = (int)$db->fetchOne('SELECT MAX(id) FROM feature_spell_grant');
    $recipients = array_map('intval', $db->fetchFirstColumn('SELECT character_id FROM character_feature_spell WHERE grant_id=? ORDER BY character_id', [$createdGrant]));
    $check($recipients === [$character->getId(),$second->getId()], 'Creation propagates to both eligible characters, not absent feat or insufficient level');
    $grantsBefore = $db->fetchAllAssociative('SELECT * FROM feature_spell_grant ORDER BY id'); $rowsBefore = $rows();
    // A failure on the second eligible recipient must undo the first and the grant itself.
    $db->executeStatement("CREATE FUNCTION pg_temp.reject_second_grant() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN IF NEW.character_id = ".$second->getId()." THEN RAISE EXCEPTION ''fixture propagation failure''; END IF; RETURN NEW; END'");
    $db->executeStatement('CREATE TRIGGER reject_second BEFORE INSERT ON character_feature_spell FOR EACH ROW EXECUTE FUNCTION pg_temp.reject_second_grant()');
    $failed = false;
    try { $controller->create('official', $feature->getId(), $request, $service, $repository); }
    catch (Doctrine\DBAL\Exception $e) { $failed = str_contains($e->getMessage(), 'fixture propagation failure'); }
    $check($failed, 'Injected partial propagation failure observed');
    $check($rows() === $rowsBefore && $db->fetchAllAssociative('SELECT * FROM feature_spell_grant ORDER BY id') === $grantsBefore, 'Atomic rollback of new grant and every acquisition');
    $db->executeStatement('DROP TRIGGER reject_second ON character_feature_spell');
    $choiceRequest = Symfony\Component\HttpFoundation\Request::create('/', 'POST', [], [], [], [], json_encode(['mode'=>'choice','spellLevel'=>1]));
    $controller->create('official', $feature->getId(), $choiceRequest, $service, $repository);
    $check($rows() === $rowsBefore, 'Catalogue choice creation does not propagate');
    $check($snapshot() === $untouched, 'All other persisted data, including sessions, preparation and resources, unchanged');
    echo "PASS: $checks assertions\n";
} finally {
    if ($db->isTransactionActive()) $db->rollBack();
    $kernel->shutdown();
}
