<?php
declare(strict_types=1);

// Synthetic fixtures and temporary sequences only. No real character is touched.
use App\Entity\{User, Campaign, Character, CharacterClass, CharacterClassLevel, Feat, CharacterFeat, CharacterFeatureDefinition, CharacterFeatureRule};
use App\Service\{CharacterFeatureSpellService, CharacterFeatureResolver, SpellCatalogue};
use App\Repository\CharacterFeatureRuleRepository;

require __DIR__.'/../vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv(__DIR__.'/../.env');
putenv('SHELL_VERBOSITY=-1'); $_SERVER['SHELL_VERBOSITY'] = $_ENV['SHELL_VERBOSITY'] = -1;
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
        if ($db->fetchOne("SELECT attidentity FROM pg_attribute WHERE attrelid=to_regclass(?) AND attname='id'", [$table]) === '') {
            $db->executeStatement('CREATE TEMP SEQUENCE fixture_'.$table.'_id');
            $db->executeStatement("ALTER TABLE ".$table." ALTER COLUMN id SET DEFAULT nextval('fixture_".$table."_id')");
        }
    }
    require __DIR__.'/feature-grant-fixture-schema.php';
    // Apply the new DDL to the temporary fixture only, even before local migration.
    if (!$db->fetchOne("SELECT 1 FROM pg_attribute WHERE attrelid='feature_spell_grant'::regclass AND attname='casting_rules' AND NOT attisdropped")) {
        require_once __DIR__.'/../migrations/Version20261026010000.php';
        $migration = new DoctrineMigrations\Version20261026010000($db, new Psr\Log\NullLogger());
        $migration->up(new Doctrine\DBAL\Schema\Schema());
        foreach ($migration->getSql() as $query) $db->prepare($query->getStatement())->executeStatement();
    } else {
        // LIKE does not copy foreign keys. Keep the isolated fixture equivalent.
        $db->executeStatement('ALTER TABLE feature_spell_grant ADD CONSTRAINT fixture_casting_resource_fk FOREIGN KEY (resource_definition_id) REFERENCES trackable_resource_definition(id) ON DELETE RESTRICT');
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
    $db->insert('feature_spell_grant', ['feature_id'=>$feature->getId(),'mode'=>'choice','spell_level'=>2,'unlock_level'=>1]);
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
    $check(!$rows()[1]['active'] && $rows()[0] === $first[0], 'Targeted reconciliation retires only the inapplicable grant');
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
    $resource = new App\Entity\TrackableResourceDefinition('fixture-casting-use','Fixture use',App\Enum\ResourceRechargeType::LongRest);
    $em->persist($resource); $em->flush(); $resourceId=$resource->getId();
    $rules=['version'=>1,'methods'=>[['kind'=>'resource','cost'=>1,'castingLevel'=>3],['kind'=>'standard-slot']], 'restrictions'=>'Only in the stated circumstances'];
    $patch=static fn(array $data) => Symfony\Component\HttpFoundation\Request::create('/', 'PATCH', [], [], [], [], json_encode($data));
    $acquired=$rows(); $stateBeforeConfig=$snapshot();
    $historical=json_decode($controller->list('official',$feature->getId())->getContent(),true)['grants'];
    $check($historical[0]['castingRules']===null && $historical[0]['resourceDefinitionId']===null,'Historical grants remain unconfigured');
    $controller->configure('official',$feature->getId(),$grantId,$patch(['castingRules'=>$rules,'resourceDefinitionId'=>$resourceId]),$service,$repository);
    $configured=array_column(json_decode($controller->list('official',$feature->getId())->getContent(),true)['grants'],null,'id')[$grantId];
    $canonical=static function(mixed $value) use (&$canonical): mixed {
        if (!is_array($value)) return $value;
        $value=array_map($canonical,$value);
        if (!array_is_list($value)) ksort($value);
        return $value;
    };
    $check($canonical($configured['castingRules'])===$canonical($rules) && $configured['resourceDefinitionId']===$resourceId,'Fixed grant configuration roundtrip preserves values and types');
    $check($configured['mode']==='fixed' && $configured['spellId']===$spell['id'],'Acquisition contract unchanged');
    $db->beginTransaction(); $fkRejected=false;
    try { $db->delete('trackable_resource_definition',['id'=>$resourceId]); }
    catch (Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException) { $fkRejected=true; }
    finally { $db->rollBack(); }
    $check($fkRejected,'Referenced resource protected by SQL foreign key');
    $controller->configure('official',$feature->getId(),$choiceId,$patch(['castingRules'=>['version'=>1,'methods'=>[['kind'=>'standard-slot']]]]),$service,$repository);
    $check($rows()===$acquired && $snapshot()===$stateBeforeConfig,'Configuration updates do not affect acquisitions, resources or sessions');
    $beforeInvalid=$db->fetchAllAssociative('SELECT * FROM feature_spell_grant ORDER BY id');
    $invalid=[
        ['castingRules'=>[]], ['castingRules'=>['version'=>2,'methods'=>[['kind'=>'standard-slot']]]],
        ['castingRules'=>['version'=>1,'methods'=>[]]],
        ['castingRules'=>['version'=>1,'methods'=>[['kind'=>'ritual']]]],
        ['castingRules'=>['version'=>1,'methods'=>[['kind'=>'standard-slot'],['kind'=>'standard-slot']]]],
        ['castingRules'=>['version'=>1,'methods'=>[['kind'=>'resource','cost'=>'1','castingLevel'=>3]]]],
        ['castingRules'=>['version'=>1,'methods'=>[['kind'=>'resource','cost'=>1,'castingLevel'=>1]]]],
        ['castingRules'=>['version'=>1,'methods'=>[['kind'=>'resource','cost'=>1]]]],
        ['castingRules'=>$rules,'resourceDefinitionId'=>null],
        ['castingRules'=>$rules,'resourceDefinitionId'=>2147483647],
        ['castingRules'=>$rules,'resourceDefinitionId'=>(string)$resourceId],
        ['castingRules'=>['version'=>1,'methods'=>[['kind'=>'standard-slot']]],'resourceDefinitionId'=>$resourceId],
        ['castingRules'=>null], ['mode'=>'choice'],
        ['castingRules'=>['version'=>1,'methods'=>[['kind'=>'standard-slot']],'unknown'=>true],'resourceDefinitionId'=>null],
    ];
    foreach($invalid as $payload){
        $rejected=false;
        try{$controller->configure('official',$feature->getId(),$grantId,$patch($payload),$service,$repository);}
        catch(Symfony\Component\HttpKernel\Exception\BadRequestHttpException){$rejected=true;}
        $check($rejected && $db->fetchAllAssociative('SELECT * FROM feature_spell_grant ORDER BY id')===$beforeInvalid,'Invalid configuration rejected without mutation: '.json_encode($payload));
    }
    $controller->configure('official',$feature->getId(),$grantId,$patch(['castingRules'=>null,'resourceDefinitionId'=>null]),$service,$repository);
    $check($db->fetchOne('SELECT casting_rules FROM feature_spell_grant WHERE id=?',[$grantId])===null,'Explicit reset to unconfigured state');
    $createConfigured=$patch(['mode'=>'choice','spellLevel'=>2,'castingRules'=>$rules,'resourceDefinitionId'=>$resourceId]);
    $check($controller->create('official',$feature->getId(),$createConfigured,$service,$repository)->getStatusCode()===201,'Create accepts configured choice grant');
    $check($rows()===$acquired && $snapshot()===$stateBeforeConfig,'Configured choice creation does not initialize resources');
    $fixedConfigured=$patch(['mode'=>'fixed','spellId'=>$spell['id'],'castingRules'=>$rules,'resourceDefinitionId'=>$resourceId]);
    $check($controller->create('official',$feature->getId(),$fixedConfigured,$service,$repository)->getStatusCode()===201,'Create accepts configured fixed grant and preserves propagation');
    $check($snapshot()===$stateBeforeConfig,'Configured fixed grant does not initialize resources or modify sessions');
    $eligibility=(new App\Service\CharacterSpellCastEligibility())->evaluate(['sourceType'=>'feature','level'=>2,'known'=>true,'castingRules'=>$rules],null,null);
    $check($eligibility['requiresGrantReview'] && !$eligibility['canCastNormally'],'Configuration does not unlock runtime casting');
    $resolveCasting=new App\Service\CharacterFeatureSpellCastingResolver($db,new CharacterFeatureResolver(new CharacterFeatureRuleRepository($registry)));
    $db->update('feature_spell_grant',['casting_rules'=>json_encode($rules),'resource_definition_id'=>$resourceId,'casting_ability'=>'INT'],['id'=>$grantId]);
    $resolved=$resolveCasting->resolve($character,$grantId);
    $check($resolved['status']==='resolved' && $resolved['castingAbility']==='intelligence','Explicit ability resolved');
    $check($resolved['provenance']['type']==='feat' && $resolved['provenance']['characterFeatId']===$assignment->getId(),'Exact feat acquisition provenance');
    $check($resolved['methods']===[['kind'=>'resource','resourceDefinitionId'=>$resourceId,'cost'=>1,'castingLevel'=>3],['kind'=>'standard-slot','minimumCastingLevel'=>2]],'Normalized methods contain configured costs and levels, not availability');
    $check($resolved['requiresRestrictionReview'] && $resolved['restrictions']===$rules['restrictions'],'Descriptive restrictions remain explicit');
    $feat->setRequiresAbilityChoice(true)->setAllowedAbilities(App\Enum\Ability::Intelligence,App\Enum\Ability::Wisdom,App\Enum\Ability::Charisma);
    $db->update('feature_spell_grant',['casting_ability'=>'CHOICE'],['id'=>$grantId]);
    foreach ([App\Enum\Ability::Intelligence,App\Enum\Ability::Wisdom,App\Enum\Ability::Charisma] as $chosen) {
        $assignment->setChosenAbility($chosen);
        $check($resolveCasting->resolve($character,$grantId)['castingAbility']===$chosen->value,'Feat choice resolved: '.$chosen->value);
    }
    $assignment->setChosenAbility(null);
    $missing=$resolveCasting->resolve($character,$grantId);
    $check($missing['status']==='unknown' && $missing['abilityReason']==='ability_choice_missing' && $missing['methods']===[],'Missing choice not inferred');
    $db->update('feature_spell_grant',['casting_ability'=>null],['id'=>$grantId]);
    $check($resolveCasting->resolve($character,$grantId)['abilityReason']==='ability_not_configured','NULL is not CHOICE');
    $db->update('feature_spell_grant',['casting_ability'=>'WIS'],['id'=>$grantId]);
    $feat->setRepeatable(true);
    $assignment->setChosenAbility(App\Enum\Ability::Wisdom);
    $duplicate=(new CharacterFeat($character,$feat))->setChosenAbility(App\Enum\Ability::Charisma); $character->addFeat($duplicate);
    $check($resolveCasting->resolve($character,$grantId)['reason']==='provenance_ambiguous','Repeated feat acquisitions are ambiguous even with explicit ability');
    $db->update('feature_spell_grant',['casting_ability'=>'CHOICE'],['id'=>$grantId]);
    $check($resolveCasting->resolve($character,$grantId)['castingAbility']===null,'Different feat choices are not arbitrarily selected');
    $character->removeFeat($duplicate);
    $additionalRule=CharacterFeatureRule::forClass($feature,$class,1); $em->persist($additionalRule); $em->flush();
    $ambiguous=$resolveCasting->resolve($character,$grantId);
    $check($ambiguous['reason']==='provenance_ambiguous' && $ambiguous['methods']===[] && $ambiguous['requiresRestrictionReview'],'Different applicable origins are not arbitrarily reduced');
    $em->remove($additionalRule); $em->flush();
    $em->remove($rule); $em->flush();
    $check($resolveCasting->resolve($character,$grantId)['reason']==='provenance_missing','Acquisition alone is not provenance');
    $rule=CharacterFeatureRule::forFeat($feature,$feat); $em->persist($rule); $em->flush();
    $db->update('feature_spell_grant',['casting_rules'=>null,'resource_definition_id'=>null],['id'=>$grantId]);
    $check($resolveCasting->resolve($character,$grantId)['reason']==='casting_rules_missing','Historical configuration remains unknown');
    $db->update('feature_spell_grant',['casting_rules'=>json_encode(['version'=>1,'methods'=>[['kind'=>'unsupported']]])],['id'=>$grantId]);
    $check($resolveCasting->resolve($character,$grantId)['reason']==='casting_rules_invalid','Invalid persisted configuration rejected conservatively');
    $db->update('feature_spell_grant',['casting_rules'=>json_encode(['version'=>1,'methods'=>[['kind'=>'standard-slot']]]),'casting_ability'=>'CHA','spell_level'=>2],['id'=>$choiceId]);
    $check($resolveCasting->resolve($character,$choiceId)['status']==='resolved','Choice grant resolved from its acquired spell');
    $readBefore=$snapshot(); $acquisitionsBefore=$rows();
    $status=$service->status($character);
    $sameSpell=array_filter($status['known'],fn($entry)=>$entry['spellId']===$spell['id']);
    $check(count($sameSpell)>1 && count(array_unique(array_column($sameSpell,'grantId')))===count($sameSpell),'Multiple grant sources for the same spell preserved');
    $check($snapshot()===$readBefore && $rows()===$acquisitionsBefore,'Resolution and status perform no writes');
    // Exercise other origins using the same applicability checks, not a parallel resolver.
    $db->update('feature_spell_grant',['casting_rules'=>json_encode(['version'=>1,'methods'=>[['kind'=>'standard-slot']]]),'casting_ability'=>'INT'],['id'=>$grantId]);
    $em->remove($rule); $em->flush();
    $classRule=CharacterFeatureRule::forClass($feature,$class,1); $em->persist($classRule); $em->flush();
    $check($resolveCasting->resolve($character,$grantId)['provenance']['type']==='class','Class provenance resolved');
    $em->remove($classRule); $em->flush();
    $race=new App\Entity\CharacterRace('casting-race','Casting race'); $character->setRace($race);
    $raceRule=CharacterFeatureRule::forRace($feature,$race,1); $em->persist($race); $em->persist($raceRule); $em->flush();
    $check($resolveCasting->resolve($character,$grantId)['provenance']['type']==='race','Race provenance resolved');
    $db->update('feature_spell_grant',['casting_ability'=>'CHOICE'],['id'=>$grantId]);
    $check($resolveCasting->resolve($character,$grantId)['abilityReason']==='ability_choice_unsupported','Race CHOICE does not borrow the feat ability');
    $em->remove($raceRule); $em->flush();
    $subclass=new App\Entity\CharacterSubclass($class,'casting-subclass','Casting subclass');
    $level->setSubclass($subclass);
    $subclassRule=CharacterFeatureRule::forSubclass($feature,$subclass,1);
    $em->persist($subclass); $em->persist($subclassRule); $em->flush();
    $db->update('feature_spell_grant',['casting_ability'=>'INT'],['id'=>$grantId]);
    $check($resolveCasting->resolve($character,$grantId)['provenance']['type']==='subclass','Subclass provenance resolved');
    $check($resolveCasting->resolve($other,$grantId)['reason']==='acquisition_missing','No source inferred without an active acquisition');
    require __DIR__.'/test-feature-spell-availability-cases.php';
    require __DIR__.'/test-progression-spell-acquisition-cases.php';
    // POST isolation: use ordinary MJ accounts and temporary fixtures only.
    (static function () use ($em, $db, $service, $repository, $check): void {
        $actor = (new User())->setEmail('grant-isolation-owner@example.invalid')->setPassword('unused')->setRoles(['ROLE_USER']);
        $foreignOwner = (new User())->setEmail('grant-isolation-foreign@example.invalid')->setPassword('unused')->setRoles(['ROLE_USER']);
        $officialClass = new CharacterClass('grant-isolation-official', 'Official class', 8, 1);
        $personalClass = (new CharacterClass('grant-isolation-personal', 'Personal class', 8, 1))->markAsPersonalReference($actor);
        $foreignClass = (new CharacterClass('grant-isolation-foreign', 'PRIVATE FOREIGN CLASS', 8, 1))->markAsPersonalReference($foreignOwner);
        $personalFeature = new CharacterFeatureDefinition('grant-isolation-feature', 'Personal feature');
        foreach ([$actor, $foreignOwner, $officialClass, $personalClass, $foreignClass, $personalFeature] as $entity) $em->persist($entity);
        $em->flush();
        $db->update('character_feature_definition', ['origin'=>'CUSTOM', 'owner_id'=>$actor->getId()], ['id'=>$personalFeature->getId()]);
        $controller = new App\Controller\FeatureSpellGrantController($db);
        $container = new Symfony\Component\DependencyInjection\Container();
        $tokens = new Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage();
        $tokens->setToken(new Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken($actor, 'test', $actor->getRoles()));
        $container->set('security.token_storage', $tokens); $controller->setContainer($container);
        $create = static fn(mixed $classId) => $controller->create('custom', $personalFeature->getId(),
            Symfony\Component\HttpFoundation\Request::create('/', 'POST', [], [], [], [], json_encode(['mode'=>'choice', 'spellLevel'=>1, 'classId'=>$classId])), $service, $repository);
        foreach ([$foreignClass->getId(), 2147483647, (string)$personalClass->getId()] as $inaccessibleId) {
            $before = $db->fetchAllAssociative('SELECT * FROM feature_spell_grant ORDER BY id');
            $error = null;
            try { $create($inaccessibleId); }
            catch (Symfony\Component\HttpKernel\Exception\BadRequestHttpException $exception) { $error = $exception; }
            $check($error !== null && $error->getStatusCode() === 400, 'POST rejects foreign, missing or malformed class references');
            $check($error->getMessage() === 'Filtres invalides.' && !str_contains($error->getMessage(), $foreignClass->getName()), 'Rejection contains no private class metadata');
            $check($db->fetchAllAssociative('SELECT * FROM feature_spell_grant ORDER BY id') === $before, 'Rejected POST creates no grant');
        }
        foreach ([$officialClass, $personalClass, null] as $allowedClass) {
            $response = $create($allowedClass?->getId());
            $check($response->getStatusCode() === 201, 'POST accepts official, personal or unrestricted class filter');
            $grants = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR)['grants'];
            $created = $grants[array_key_last($grants)];
            $check($created['classId'] === $allowedClass?->getId() && $created['className'] === $allowedClass?->getName(), 'Authorized class reference is preserved in response');
            $check(!str_contains($response->getContent(), $foreignClass->getName()), 'Successful response contains no foreign class name');
        }
    })();
    echo "PASS: $checks assertions\n";
} finally {
    if ($db->isTransactionActive()) $db->rollBack();
    $kernel->shutdown();
}
