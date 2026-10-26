<?php
declare(strict_types=1);

use App\Entity\{User, Campaign, Character, CharacterClass, CharacterSubclass, CharacterClassLevel, SpellcastingPolicyDefinition, CharacterSpellAcquisition};
use App\Dto\SpellcastingPolicy;
use App\Enum\Ability;
use App\Service\{CharacterSpellAcquisitions, CharacterSpellAccessResolver, SpellCatalogue, SpellSubclassGrants, CharacterResourceResolver, CharacterFeatureResolver, CharacterAbilityCalculator};
use App\Repository\{CharacterFeatureRuleRepository, TrackableResourceRuleRepository};
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

require __DIR__.'/../vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv(__DIR__.'/../.env');
putenv('SHELL_VERBOSITY=-1'); $_SERVER['SHELL_VERBOSITY'] = $_ENV['SHELL_VERBOSITY'] = -1;
$kernel = new App\Kernel('dev', true); $kernel->boot();
$registry = $kernel->getContainer()->get('doctrine'); $em = $registry->getManager(); $db = $em->getConnection(); $checks = 0;
if (($argv[1] ?? '') === '--worker') {
    $schemaName = $argv[2] ?? '';
    if (!preg_match('/^spell_acq_test_[a-f0-9]{16}$/D', $schemaName)) throw new RuntimeException('Unsafe test schema');
    $db->executeStatement('SET search_path TO '.$schemaName.', public');
    $catalogue = new SpellCatalogue($db); $grants = new SpellSubclassGrants($db,$catalogue);
    $resources = new CharacterResourceResolver(new TrackableResourceRuleRepository($registry),new CharacterAbilityCalculator(),new CharacterFeatureResolver(new CharacterFeatureRuleRepository($registry)));
    $service = new CharacterSpellAcquisitions($em,new CharacterSpellAccessResolver($catalogue,$grants,$resources));
    $character = $em->find(Character::class,(int)$argv[3]); $actor = $em->find(User::class,(int)$argv[7]);
    echo "ready\n"; flush();
    try {
        if (($argv[8] ?? '') === 'historical') {
            $service->completeHistorical($character, (int)$argv[4], ['choices'=>[['spellId'=>(int)$argv[6], 'ruleId'=>null]], 'expectedAcquisitionIds'=>array_map('intval', explode(',', $argv[5])), 'confirmed'=>true], $actor);
        } elseif (($argv[8] ?? '') === 'initialization') {
            $result = $service->initialize($character, ['policyId'=>(int)$argv[4], 'reason'=>'Initial fixture', 'choices'=>[['spellId'=>(int)$argv[6], 'ruleId'=>null]]], $actor);
            echo $result['createdAcquisitionIds'] === [] ? "200\n" : "201\n";
            exit;
        } elseif (($argv[8] ?? '') === 'qualification') {
            $service->qualifyChoice($character,(int)$argv[4],(int)$argv[5],'Concurrent qualification',$actor);
        } elseif (($argv[8] ?? '') === 'substitution') {
            (new App\Service\CharacterSpellSubstitutions($em,$catalogue,new CharacterSpellAccessResolver($catalogue,$grants,$resources)))->substitute($character,(int)$argv[4],(int)$argv[5],(int)$argv[6],(int)$argv[9],'Concurrent substitution',true,$actor);
        } elseif (($argv[8] ?? '') === 'revision') {
            $service->revise($character,(int)$argv[4],(int)$argv[4],[(int)$argv[6]],array_map('intval',explode(',',$argv[5])),'Concurrent correction',false,true,$actor);
        } elseif (($argv[8] ?? '') === 'copy') {
            $quote = $service->copyQuote($character,(int)$argv[4],(int)$argv[6],$actor);
            $service->copy($character,(int)$argv[4],(int)$argv[6],['baseCostCp'=>$quote['baseCostCp'],'baseMinutes'=>$quote['baseMinutes'],'costCp'=>$quote['baseCostCp'],'minutes'=>$quote['baseMinutes'],'source'=>'Concurrent fixture','adjustment'=>'none','reason'=>'','confirmed'=>true],$actor);
        } elseif (($argv[8] ?? '') === 'advanced') {
            $service->acquire($character,(int)$argv[4],[['spellId'=>(int)$argv[6],'classLevelId'=>(int)$argv[5],'ruleId'=>(int)$argv[9]]],'level-history','Concurrent advanced fixture',$actor);
        } else $service->acquire($character,(int)$argv[4],[['spellId'=>(int)$argv[6],'classLevelId'=>(int)$argv[5]]],'level-history','Concurrent fixture',$actor);
        echo "201\n";
    }
    catch (HttpExceptionInterface $e) { echo $e->getStatusCode()."\n"; }
    exit;
}
$concurrent = in_array($argv[1] ?? '', ['--concurrency', '--initialization-concurrency', '--historical-concurrency'], true);
$isolatedSchema = $concurrent ? 'spell_acq_test_'.bin2hex(random_bytes(8)) : null;
$check = static function (bool $ok, string $message) use (&$checks): void { if (!$ok) throw new RuntimeException($message); ++$checks; };
$refuse = static function (callable $fn, int $code) use ($check): void { try { $fn(); } catch (HttpExceptionInterface $e) { $check($e->getStatusCode() === $code, 'Expected '.$code.', got '.$e->getStatusCode().': '.$e->getMessage()); return; } throw new RuntimeException('Expected refusal '.$code); };
try {
    $db->beginTransaction();
    if ($isolatedSchema !== null) { $db->executeStatement('CREATE SCHEMA '.$isolatedSchema); $db->executeStatement('SET search_path TO '.$isolatedSchema.', public'); }
    $metadata = $em->getMetadataFactory()->getAllMetadata();
    $schema = new Doctrine\ORM\Tools\SchemaTool($em);
    foreach ($schema->getCreateSchemaSql($metadata) as $sql) $db->executeStatement($concurrent ? $sql : preg_replace(['/^CREATE TABLE /', '/^CREATE SEQUENCE /'], ['CREATE TEMP TABLE ', 'CREATE TEMP SEQUENCE '], $sql));
    foreach ($metadata as $meta) $check($concurrent
        ? $db->fetchOne('SELECT n.nspname FROM pg_class c JOIN pg_namespace n ON c.relnamespace=n.oid WHERE c.oid=to_regclass(?)',[$meta->getTableName()])===$isolatedSchema
        : $db->fetchOne('SELECT relpersistence FROM pg_class WHERE oid=to_regclass(?)', [$meta->getTableName()]) === 't', 'Isolated '.$meta->getTableName());
    $qualifier = $isolatedSchema ?? 'pg_temp';
    $db->executeStatement('DROP TABLE '.$qualifier.'.character_spell_qualification');
    foreach (['character_spell_substitution','spell_grant_substitution_list','spell_grant_substitution_rule'] as $table) $db->executeStatement('DROP TABLE '.$qualifier.'.'.$table);
    $db->executeStatement('DROP TABLE '.$qualifier.'.character_spell_choice');
    $db->executeStatement('DROP TABLE '.$qualifier.'.spell_policy_choice_rule');
    $db->executeStatement('DROP TABLE '.$qualifier.'.character_spell_acquisition');
    $db->executeStatement('DROP TABLE '.$qualifier.'.character_spell_revision');
    $db->executeStatement('DROP TABLE '.$qualifier.'.spellcasting_policy_definition');
    $db->executeStatement('ALTER TABLE '.$qualifier.'.spell_subclass_grant DROP COLUMN quota_impact');
    require __DIR__.'/../migrations/Version20261017120000.php';
    $migration = new DoctrineMigrations\Version20261017120000($db, new Psr\Log\NullLogger());
    $migration->up(new Doctrine\DBAL\Schema\Schema());
    foreach ($migration->getSql() as $query) $db->executeStatement($concurrent ? $query->getStatement() : str_replace('CREATE TABLE ', 'CREATE TEMP TABLE ', $query->getStatement()));
    require __DIR__.'/../migrations/Version20261017130000.php';
    $copyMigration = new DoctrineMigrations\Version20261017130000($db, new Psr\Log\NullLogger());
    $copyMigration->up(new Doctrine\DBAL\Schema\Schema());
    foreach ($copyMigration->getSql() as $query) $db->executeStatement($query->getStatement());
    require __DIR__.'/../migrations/Version20261017140000.php';
    $revisionMigration = new DoctrineMigrations\Version20261017140000($db, new Psr\Log\NullLogger());
    $revisionMigration->up(new Doctrine\DBAL\Schema\Schema());
    foreach ($revisionMigration->getSql() as $query) $db->executeStatement($concurrent ? $query->getStatement() : str_replace('CREATE TABLE ', 'CREATE TEMP TABLE ', $query->getStatement()));
    require __DIR__.'/../migrations/Version20261020120000.php';
    $advancedMigration = new DoctrineMigrations\Version20261020120000($db,new Psr\Log\NullLogger());
    $advancedMigration->up(new Doctrine\DBAL\Schema\Schema());
    foreach ($advancedMigration->getSql() as $query) $db->executeStatement($concurrent ? $query->getStatement() : str_replace('CREATE TABLE ', 'CREATE TEMP TABLE ', $query->getStatement()));
    require __DIR__.'/../migrations/Version20261020130000.php';
    $substitutionMigration=new DoctrineMigrations\Version20261020130000($db,new Psr\Log\NullLogger());
    $substitutionMigration->up(new Doctrine\DBAL\Schema\Schema());
    foreach ($substitutionMigration->getSql() as $query) $db->executeStatement($concurrent?$query->getStatement():str_replace('CREATE TABLE ','CREATE TEMP TABLE ',$query->getStatement()));
    $tempSchema = $isolatedSchema ?? $db->fetchOne('SELECT nspname FROM pg_namespace WHERE oid=pg_my_temp_schema()');
    require __DIR__.'/../migrations/Version20261022120000.php';
    $qualificationMigration=new DoctrineMigrations\Version20261022120000($db,new Psr\Log\NullLogger());
    $qualificationMigration->up(new Doctrine\DBAL\Schema\Schema());
    foreach ($qualificationMigration->getSql() as $query) $db->executeStatement($concurrent?$query->getStatement():str_replace('CREATE TABLE ','CREATE TEMP TABLE ',$query->getStatement()));
    $meta=$em->getClassMetadata(App\Entity\CharacterSpellQualification::class);
    $check($db->createSchemaManager()->createComparator()->compareTables(
        $db->createSchemaManager()->introspectTable($tempSchema.'.'.$meta->getTableName()),
        $schema->getSchemaFromMetadata([$meta])->getTable($meta->getTableName()),
    )->isEmpty(),'Qualification migration matches ORM');
    foreach ([SpellcastingPolicyDefinition::class, CharacterSpellAcquisition::class, App\Entity\SpellSubclassGrant::class, App\Entity\CharacterSpellRevision::class, App\Entity\SpellPolicyChoiceRule::class, App\Entity\CharacterSpellChoice::class] as $type) {
        $meta = $em->getClassMetadata($type); $actual = $db->createSchemaManager()->introspectTable($tempSchema.'.'.$meta->getTableName());
        $expected = $schema->getSchemaFromMetadata([$meta])->getTable($meta->getTableName());
        $check($db->createSchemaManager()->createComparator()->compareTables($actual, $expected)->isEmpty(), 'Migration matches '.$type);
    }
    $owner = (new User())->setEmail('acq-owner@example.invalid')->setPassword('unused');
    $other = (new User())->setEmail('acq-other@example.invalid')->setPassword('unused');
    $admin = (new User())->setEmail('acq-admin@example.invalid')->setPassword('unused')->setRoles([SpellCatalogue::ADMIN_ROLE]);
    $campaign = new Campaign($owner, 'acq-campaign', 'Fixture');
    $class = new CharacterClass('acq-class', 'Synthetic class', 6, 1);
    $bookClass = new CharacterClass('acq-book', 'Synthetic book class', 6, 1);
    $preparedClass = new CharacterClass('acq-prepared', 'Synthetic prepared class', 6, 1);
    $sub = new CharacterSubclass($class, 'acq-sub', 'Synthetic source');
    (new ReflectionProperty($sub, 'origin'))->setValue($sub, App\Enum\ReferenceOrigin::Custom);
    (new ReflectionProperty($sub, 'owner'))->setValue($sub, $owner);
    foreach ([$owner,$other,$admin,$campaign,$class,$bookClass,$preparedClass,$sub] as $e) $em->persist($e);
    $makeCharacter = static function (string $slug, CharacterClass $c, int $count, ?CharacterSubclass $s = null) use ($campaign,$em): Character {
        $char = new Character($campaign, $slug, $slug, Character::TYPE_PLAYER); $em->persist($char);
        for ($i=1;$i<=$count;++$i) { $l = new CharacterClassLevel($char,$c,$i,$i===1?$s:null); $char->addClassLevel($l); $em->persist($l); }
        return $char;
    };
    $history = $makeCharacter('history',$class,3);
    $manual = $makeCharacter('manual',$class,3);
    $book = $makeCharacter('book',$bookClass,3);
    $prepared = $makeCharacter('prepared',$preparedClass,3);
    $granted = $makeCharacter('granted',$class,3,$sub);
    $crossSub = new CharacterSubclass($class,'cross-sub','Synthetic acquisition source');
    (new ReflectionProperty($crossSub,'origin'))->setValue($crossSub,App\Enum\ReferenceOrigin::Custom);
    (new ReflectionProperty($crossSub,'owner'))->setValue($crossSub,$owner);
    $em->persist($crossSub);
    $cross = $makeCharacter('cross',$class,3,$crossSub);
    for ($position=4;$position<=6;++$position) {
        $extra = new CharacterClassLevel($cross,$bookClass,$position);
        $cross->addClassLevel($extra); $em->persist($extra);
    }
    $unconfiguredClass = new CharacterClass('unconfigured','Unconfigured',6,1); $em->persist($unconfiguredClass);
    $unconfigured = $makeCharacter('unconfigured',$unconfiguredClass,1);
    for ($position=4;$position<=8;++$position) {
        $extra = new CharacterClassLevel($history,$preparedClass,$position);
        $history->addClassLevel($extra); $em->persist($extra);
    }
    foreach ([0,1] as $n) {
        $session = new App\Entity\GameSession($campaign,'acq-session-'.$n,'Session fixture');
        $state = new App\Entity\CharacterSessionState($session,$history,['hitPoints'=>['current'=>3+$n],'resources'=>[['id'=>'spell-slot-1','current'=>$n]],'progressions'=>[['id'=>'synthetic','currentValue'=>7+$n]]]);
        $em->persist($session); $em->persist($state);
    }
    $em->flush();
    $catalogue = new SpellCatalogue($db); $grants = new SpellSubclassGrants($db,$catalogue);
    $resourceResolver = new CharacterResourceResolver(new TrackableResourceRuleRepository($registry),new CharacterAbilityCalculator(),new CharacterFeatureResolver(new CharacterFeatureRuleRepository($registry)));
    $access = new CharacterSpellAccessResolver($catalogue,$grants,$resourceResolver);
    $service = new CharacterSpellAcquisitions($em,$access);
    $rows = [];
    for ($level=1;$level<=20;++$level) $rows[] = ['maxSpellLevel' => min(9,(int)ceil($level/2)), 'cantrips' => 2, 'known' => $level+1, 'bookAdditions' => 0, 'replacements' => $level===1?0:1, 'preparationBase' => null];
    $knownPolicy = new SpellcastingPolicy('synthetic-known',1,Ability::Intelligence,'known',$rows,'Synthetic fixture, not a D&D rule');
    foreach (['wizard','cleric','druid','paladin','sorcerer','ranger','warlock'] as $key) {
        $preset = App\Service\Dnd2014SpellPolicies::get($key.'-2014');
        $check(count($preset->levels)===20,'Complete ordinary table '.$key);
        $check($preset->grantQuota('add-to-list',1)===null && $preset->grantQuota('grant-cast',1)===null,'Access not generic quota '.$key);
        $check($preset->grantQuota('grant-known',0)==='cantrips','Cantrips distinct '.$key);
        $check($preset->grantQuota('grant-always-prepared',1)==='preparation','Preparation distinct '.$key);
    }
    $wizard = App\Service\Dnd2014SpellPolicies::get('wizard-2014');
    $check($wizard->additionsAtLevel(1,'spellbook')===6 && $wizard->additionsAtLevel(3,'spellbook')===2,'Verified wizard initial and level gains');
    $check($wizard->atLevel(3)['maxSpellLevel']===2 && $wizard->atLevel(10)['cantrips']===5,'Verified wizard table');
    $check(App\Service\Dnd2014SpellPolicies::get('warlock-2014')->atLevel(20)['maxSpellLevel']===5,'Pact eligibility excludes arcanums');
    $check(App\Service\Dnd2014SpellPolicies::get('ranger-2014')->atLevel(1)['known']===0,'Ranger does not learn spells at first level');
    $check(App\Service\Dnd2014SpellPolicies::get('paladin-2014')->atLevel(5)['preparationBase']===2,'Paladin half-level floor');
    foreach (App\Service\Dnd2014SpellPolicies::unsupported() as $key=>$reason) {
        try { App\Service\Dnd2014SpellPolicies::get($key); throw new RuntimeException('Unsupported preset accepted'); }
        catch (InvalidArgumentException) { $check($reason!=='','Unsupported policy explicit'); }
    }
    try { new SpellcastingPolicy('bad',1,Ability::Wisdom,'known',array_slice($rows,0,19),'Fixture'); throw new RuntimeException('Incomplete table accepted'); }
    catch (InvalidArgumentException) { $check(true,'Incomplete policy rejected'); }
    $refuse(fn () => $service->definePolicy($class,$knownPolicy,$owner),403);
    $policy = $service->definePolicy($class,$knownPolicy,$admin);
    $bookRows = array_map(static fn ($r) => array_replace($r,['known'=>0,'bookAdditions'=>1,'replacements'=>0,'preparationBase'=>1]),$rows);
    $bookPolicy = $service->definePolicy($bookClass,new SpellcastingPolicy('synthetic-book',1,Ability::Intelligence,'spellbook',$bookRows,'Fixture'),$admin);
    $preparedRows = array_map(static fn ($r) => array_replace($r,['known'=>0,'replacements'=>0,'preparationBase'=>1]),$rows);
    $prepPolicy = $service->definePolicy($preparedClass,new SpellcastingPolicy('synthetic-prepared',1,Ability::Wisdom,'prepared',$preparedRows,'Fixture'),$admin);
    $fixture = ['name'=>'Synthetic','school'=>'illusion','castingTime'=>'Fixture','range'=>'Fixture','duration'=>'Fixture','description'=>'Synthetic fixture','classIds'=>[$class->getId(),$bookClass->getId(),$preparedClass->getId()]];
    $spells = [];
    foreach ([0,0,0,1,1,1,2,2,3,1,1] as $i=>$level) $spells[] = $catalogue->create($fixture+['slug'=>'acq-spell-'.$i,'level'=>$level],$admin,true);
    $foreign = $catalogue->create($fixture+['slug'=>'acq-secret','level'=>1],$other);
    $own = $catalogue->create($fixture+['slug'=>'acq-own','level'=>1],$owner);
    $borrowed = $catalogue->create(array_replace($fixture,['slug'=>'borrowed-list','level'=>1,'classIds'=>[$bookClass->getId()]]),$admin,true);
    $levels = static fn (Character $c): array => array_map(static fn ($l)=>$l->getId(),$c->getClassLevels()->toArray());
    $choice = static fn (array $s,int $l,?int $r=null): array => ['spellId'=>$s['id'],'classLevelId'=>$l,'replacesId'=>$r];
    $fingerprints = static function () use ($metadata,$db): array { $result=[]; foreach ($metadata as $m) { $t=$m->getTableName(); if (in_array($t,['character_spell_acquisition','character_spell_revision','spell_subclass_grant'],true)) continue; $q=$db->quoteIdentifier($t); $result[$t]=$db->fetchOne("SELECT md5(COALESCE(string_agg(row_to_json(x)::text, '' ORDER BY row_to_json(x)::text), '')) FROM $q x"); } return $result; };
    $before = $fingerprints();
    // Read-only statistics: two policies on the same multiclassed character.
    $intScore = $history->getAbilityScore(Ability::Intelligence);
    $wisScore = $history->getAbilityScore(Ability::Wisdom);
    $baseProperty = new ReflectionProperty($intScore, 'baseValue');
    $oldInt = $intScore->getBaseValue(); $oldWis = $wisScore->getBaseValue();
    $baseProperty->setValue($intScore, 18); $baseProperty->setValue($wisScore, 12);
    $intOptions = $service->options($history, $policy->getId(), $owner);
    $wisOptions = $service->options($history, $prepPolicy->getId(), $owner);
    $check($intOptions['spellSaveDc'] === 15 && $intOptions['spellAttackBonus'] === 7, 'INT statistics use resolved modifier and total multiclass proficiency');
    $check($wisOptions['spellSaveDc'] === 12 && $wisOptions['spellAttackBonus'] === 4, 'WIS policy retains its own statistics on the same character');
    $scores = $history->getAbilityScores();
    $scoreKey = $scores->indexOf($intScore); $scores->remove($scoreKey);
    $missingOptions = $service->options($history, $policy->getId(), $owner);
    $check($missingOptions['spellSaveDc'] === null && $missingOptions['spellAttackBonus'] === null, 'Missing ability explicitly unavailable');
    $check($scores->count() === 5, 'Statistics do not manufacture a missing ability score');
    $scores->set($scoreKey, $intScore);
    $baseProperty->setValue($intScore, $oldInt); $baseProperty->setValue($wisScore, $oldWis);
    $check($service->status($unconfigured,$owner)[0]['status']==='policy-to-configure','Missing explicit policy reported');
    $check($service->options($history,$policy->getId(),$owner)['status']==='choices-to-complete','No invented acquisitions');
    foreach ([$other,$admin] as $actor) $refuse(fn ()=>$service->options($history,$policy->getId(),$actor),404);
    $h=$levels($history); $m=$levels($manual); $b=$levels($book); $p=$levels($prepared); $g=$levels($granted);
    $refuse(fn ()=>$service->acquire($history,$policy->getId(),[$choice($spells[6],$h[0])],'level-history','Test',$owner),400);
    $refuse(fn ()=>$service->acquire($history,$policy->getId(),[$choice($spells[8],$h[2])],'level-history','Multiclass slots do not expand eligibility',$owner),400);
    $refuse(fn ()=>$service->acquire($history,$policy->getId(),[$choice($foreign,$h[0])],'level-history','Test',$owner),400);
    $refuse(fn ()=>$service->acquire($history,$policy->getId(),[$choice($spells[3],$m[0])],'level-history','Test',$owner),400);
    $ids=$service->acquire($history,$policy->getId(),[$choice($spells[3],$h[0]),$choice($spells[4],$h[0])],'level-history','Initial choices',$owner);
    $usedSpell = $catalogue->get($spells[3]['id'], $admin, true);
    foreach ([['level'=>2], ['school'=>'evocation'], ['ritual'=>!$usedSpell['ritual']], ['classIds'=>[]]] as $change) {
        $refuse(fn ()=>$catalogue->patch($usedSpell['id'],$change,$admin,true),409);
    }
    $refuse(fn ()=>$catalogue->delete($usedSpell['id'],$admin,true),409);
    $unchanged = $catalogue->patch($usedSpell['id'],['level'=>$usedSpell['level'],'classIds'=>array_reverse($usedSpell['classIds'])],$admin,true);
    $check($unchanged === $usedSpell,'Identical mechanics and reordered memberships remain valid');
    $catalogue->patch($usedSpell['id'],['name'=>'Editorial correction'],$admin,true);
    $check($catalogue->get($usedSpell['id'],$admin,true)['name']==='Editorial correction','Used spell editorial correction allowed');
    $catalogue->patch($usedSpell['id'],['name'=>$usedSpell['name']],$admin,true);
    $refuse(fn ()=>$service->acquire($history,$policy->getId(),[$choice($spells[5],$h[0])],'level-history','Excess',$owner),409);
    $refuse(fn ()=>$service->acquire($history,$policy->getId(),[$choice($spells[3],$h[1])],'level-history','Duplicate',$owner),409);
    $service->acquire($history,$policy->getId(),[$choice($spells[0],$h[0]),$choice($spells[1],$h[0])],'level-history','Cantrips',$owner);
    $refuse(fn ()=>$service->acquire($history,$policy->getId(),[$choice($spells[2],$h[0])],'level-history','Cantrip limit',$owner),409);
    $service->acquire($history,$policy->getId(),[$choice($spells[5],$h[1]),$choice($own,$h[1],$ids[0])],'level-history','Level and replacement',$owner);
    $refuse(fn ()=>$service->acquire($history,$policy->getId(),[$choice($spells[9],$h[1],$ids[1])],'level-history','Second replacement',$owner),409);
    $check(count($service->options($history,$policy->getId(),$owner,null)['acquisitions'])===5,'Replacement retains history but one active slot');
    $check(count($service->options($history,$policy->getId(),$owner,$h[0])['acquisitions'])===4,'Historical view does not include later replacements');
    $count=(int)$db->fetchOne('SELECT COUNT(*) FROM character_spell_acquisition');
    $refuse(fn ()=>$service->acquire($history,$policy->getId(),[$choice($spells[6],$h[2]),$choice($spells[7],$h[2])],'level-history','Atomic excess',$owner),409);
    $check((int)$db->fetchOne('SELECT COUNT(*) FROM character_spell_acquisition')===$count,'Batch rollback');
    $service->acquire($manual,$policy->getId(),array_map(fn ($s)=>$choice($s,$m[2]),[$spells[3],$spells[4],$spells[6],$spells[7]]),'manual-reconciliation','MJ validates current state; past unknown',$owner);
    $refuse(fn ()=>$service->acquire($manual,$policy->getId(),[$choice($spells[5],$m[2])],'manual-reconciliation','Excess',$owner),409);
    $refuse(fn ()=>$service->acquire($manual,$policy->getId(),[$choice($spells[0],$m[0])],'level-history','Invented past',$owner),409);
    $service->acquire($book,$bookPolicy->getId(),array_map(fn ($s)=>$choice($s,$b[2]),[$spells[3],$spells[4],$spells[5],$spells[6],$spells[7]]),'manual-reconciliation','Includes historical copies, not reconstructed',$owner);
    $check(count($service->options($book,$bookPolicy->getId(),$owner)['acquisitions'])===5,'Book reconciliation not capped at level-up additions');
    $refuse(fn ()=>$service->acquire($prepared,$prepPolicy->getId(),[$choice($spells[3],$p[0])],'level-history','No mass acquisition',$owner),400);
    $service->acquire($prepared,$prepPolicy->getId(),[$choice($spells[0],$p[0])],'level-history','Prepared caster cantrip',$owner);
    $grant=$grants->save($spells[3]['id'],null,['subclassId'=>$sub->getId(),'mode'=>'grant-known','unlockLevel'=>1,'castingClassId'=>$class->getId()],$owner);
    $check($grant['quotaImpact']==='unspecified','Legacy default unspecified');
    $refuse(fn ()=>$service->acquire($granted,$policy->getId(),[$choice($spells[4],$g[0])],'level-history','Unknown quota',$owner),409);
    $service->acquire($granted,$policy->getId(),[$choice($spells[0],$g[0])],'level-history','Known quota does not block cantrip',$owner);
    $grants->save($spells[3]['id'],$grant['id'],['quotaImpact'=>'counts-toward-quota'],$owner);
    $check($service->options($granted,$policy->getId(),$owner,$g[0])['remaining']['known']===1,'Counted source quota');
    $service->acquire($granted,$policy->getId(),[$choice($spells[4],$g[0])],'level-history','Remaining ordinary choice',$owner);
    $refuse(fn ()=>$service->acquire($granted,$policy->getId(),[$choice($spells[3],$g[0])],'level-history','No grant duplication',$owner),409);
    $grants->save($spells[3]['id'],$grant['id'],['quotaImpact'=>'free'],$owner);
    $check($service->options($granted,$policy->getId(),$owner,$g[0])['remaining']['known']===1,'Free grant does not spend known quota');
    $grants->save($spells[1]['id'],null,['subclassId'=>$sub->getId(),'mode'=>'grant-always-prepared','unlockLevel'=>1,'castingClassId'=>$class->getId()],$owner);
    $service->acquire($granted,$policy->getId(),[$choice($spells[5],$g[0])],'level-history','Preparation quota is independent',$owner);
    $refuse(fn ()=>$grants->save($spells[3]['id'],$grant['id'],['quotaImpact'=>'guess'],$owner),400);
    $grants->save($spells[4]['id'],null,['subclassId'=>$sub->getId(),'mode'=>'grant-known','unlockLevel'=>1,'castingClassId'=>$class->getId(),'quotaImpact'=>'counts-toward-quota'],$owner);
    $check($service->options($granted,$policy->getId(),$owner,$g[0])['used']['known']===2,'Later grant overlapping acquired spell counts once');
    // A known-spell subclass borrows a spell from a book caster's list. Both
    // policies deliberately use Intelligence; neither names nor ability select a budget.
    $crossLevels=$levels($cross);
    $grants->save($borrowed['id'],null,['subclassId'=>$crossSub->getId(),'mode'=>'add-to-list','unlockLevel'=>1,'castingClassId'=>$class->getId()],$owner);
    $crossGrant=$grants->save($spells[4]['id'],null,['subclassId'=>$crossSub->getId(),'mode'=>'grant-known','unlockLevel'=>1,'castingClassId'=>$bookClass->getId(),'quotaImpact'=>'counts-toward-quota'],$owner);
    $sourceOptions=$service->options($cross,$policy->getId(),$owner);
    $targetOptions=$service->options($cross,$bookPolicy->getId(),$owner);
    $check($sourceOptions['used']['known']===1 && $sourceOptions['blocked']===[],'Cross-class automatic grant spends acquisition source quota');
    $check($targetOptions['used']['known']===0 && $targetOptions['automatic']===[],'Casting class does not own the automatic quota');
    $check($sourceOptions['automatic'][0]['quotaClassId']===$class->getId() && $sourceOptions['automatic'][0]['castingClassId']===$bookClass->getId(),'Quota and casting identities explicit');
    $refuse(fn ()=>$service->acquire($cross,$policy->getId(),[$choice($borrowed,$crossLevels[2])+['quotaClassId'=>$bookClass->getId()]],'manual-reconciliation','Forged quota owner',$owner),400);
    $service->acquire($cross,$policy->getId(),[$choice($borrowed,$crossLevels[2])],'manual-reconciliation','Learned through subclass, foreign list',$owner);
    $check($service->options($cross,$policy->getId(),$owner)['used']['known']===2,'Borrowed-list learning spends acquiring class known quota');
    $check($service->options($cross,$bookPolicy->getId(),$owner)['used']['spellbook']===0,'Borrowed list does not spend book caster choices');
    $service->acquire($cross,$bookPolicy->getId(),[$choice($borrowed,$crossLevels[5])],'manual-reconciliation','Same spell independently acquired through book class',$owner);
    $check($service->options($cross,$bookPolicy->getId(),$owner)['used']['spellbook']===1,'Same spell has independent acquisition contexts');
    $grants->save($spells[0]['id'],null,['subclassId'=>$crossSub->getId(),'mode'=>'grant-known','unlockLevel'=>1,'castingClassId'=>$bookClass->getId(),'quotaImpact'=>'counts-toward-quota'],$owner);
    $grants->save($spells[5]['id'],null,['subclassId'=>$crossSub->getId(),'mode'=>'grant-always-prepared','unlockLevel'=>1,'castingClassId'=>$bookClass->getId(),'quotaImpact'=>'counts-toward-quota'],$owner);
    $check($service->options($cross,$policy->getId(),$owner)['used']['cantrips']===1,'Cross-class cantrip quota stays at source');
    $check($service->options($cross,$policy->getId(),$owner)['used']['preparation']===1,'Preparation accounting remains separate at source');
    $check($service->options($cross,$bookPolicy->getId(),$owner)['used']['cantrips']===0 && $service->options($cross,$bookPolicy->getId(),$owner)['used']['preparation']===0,'No casting-class cantrip or preparation quota charged');
    $grants->save($spells[4]['id'],$crossGrant['id'],['quotaImpact'=>'free'],$owner);
    $check($service->options($cross,$policy->getId(),$owner)['used']['known']===1,'Explicit free exception preserved across classes');
    $grants->save($spells[4]['id'],$crossGrant['id'],['quotaImpact'=>'unspecified'],$owner);
    $refuse(fn ()=>$service->acquire($cross,$policy->getId(),[$choice($own,$crossLevels[2])],'manual-reconciliation','Unknown source quota',$owner),409);
    $check($service->options($cross,$bookPolicy->getId(),$owner)['blocked']===[],'Unknown source quota does not block independent book class');
    $check((int)$db->fetchOne('SELECT COUNT(*) FROM character_spell_acquisition WHERE character_id=?',[$cross->getId()])===2,'Automatic grants never create persistent acquisitions');
    $bookBefore = $service->options($book,$bookPolicy->getId(),$owner);
    $quote = $service->copyQuote($book,$bookPolicy->getId(),$spells[9]['id'],$owner);
    $check($quote['baseCostCp']===5000 && $quote['baseMinutes']===120 && !$quote['walletDebited'],'Copy quote uses 2014 theory and no automatic debit');
    $decision = ['baseCostCp'=>5000,'baseMinutes'=>120,'costCp'=>2500,'minutes'=>60,'source'=>'Accessible fictional manuscript; GM verified','adjustment'=>'rule','reason'=>'School savant verified by GM','confirmed'=>true];
    $refuse(fn ()=>$service->copy($book,$bookPolicy->getId(),$spells[9]['id'],array_replace($decision,['confirmed'=>false]),$owner),400);
    $refuse(fn ()=>$service->copy($book,$bookPolicy->getId(),$spells[9]['id'],array_replace($decision,['baseCostCp'=>0]),$owner),400);
    $refuse(fn ()=>$service->copy($book,$bookPolicy->getId(),$spells[9]['id'],array_replace($decision,['reason'=>'']),$owner),400);
    $refuse(fn ()=>$service->copy($book,$bookPolicy->getId(),$spells[9]['id'],$decision,$other),404);
    $refuse(fn ()=>$service->copy($book,$bookPolicy->getId(),$foreign['id'],$decision,$owner),400);
    $refuse(fn ()=>$service->copyQuote($book,$bookPolicy->getId(),$spells[0]['id'],$owner),400);
    $refuse(fn ()=>$service->copyQuote($history,$policy->getId(),$spells[9]['id'],$owner),400);
    $copyId = $service->copy($book,$bookPolicy->getId(),$spells[9]['id'],$decision,$owner);
    $copy = $em->find(CharacterSpellAcquisition::class,$copyId);
    $check($copy->isInSpellbook() && !$copy->isKnown() && !$copy->isHistoricallyValidated() && $copy->getProvenance()==='spellbook-copy','Copy reloads as separate traced acquisition');
    $check(json_decode($db->fetchOne('SELECT copy_details FROM character_spell_acquisition WHERE id=?',[$copyId]),true)===$decision,'Copy confirmation and adjustment retained');
    $check($service->options($book,$bookPolicy->getId(),$owner)['remaining']===$bookBefore['remaining'],'Copy does not consume level acquisition budget');
    $refuse(fn ()=>$service->copy($book,$bookPolicy->getId(),$spells[9]['id'],$decision,$owner),409);
    $refuse(fn ()=>$service->acquire($book,$bookPolicy->getId(),[$choice($spells[10],$b[2])],'spellbook-copy','Cannot bypass confirmation',$owner),400);
    $session = new Symfony\Component\HttpFoundation\Session\Session(new Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage());
    $session->start();
    $http = static function (?User $actor, string $method, string $path, ?array $payload = null) use ($session,$kernel) {
        if ($actor) $session->set('_security_main',serialize(new Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken($actor,'main',$actor->getRoles())));
        else $session->remove('_security_main');
        $request = Symfony\Component\HttpFoundation\Request::create($path,$method,[],[$session->getName()=>$session->getId()],[],['CONTENT_TYPE'=>'application/json','HTTP_ACCEPT'=>'application/json'],$payload===null?null:json_encode((object)$payload));
        $request->setSession($session);
        return $kernel->handle($request);
    };
    $path = '/characters/'.$book->getId().'/spell-acquisitions/'.$bookPolicy->getId().'/copy/'.$spells[10]['id'];
    $check($http(null,'GET',$path)->getStatusCode()===401,'Copy quote requires authentication');
    foreach ([$other,$admin] as $intruder) {
        $check($http($intruder,'GET',$path)->getStatusCode()===404,'Foreign copy quote hidden even for reference admin');
        $check($http($intruder,'POST',$path,$decision)->getStatusCode()===404,'Foreign copy denied even for reference admin');
    }
    $check($http($owner,'GET',$path)->getStatusCode()===200,'GM can obtain quote');
    $check($http($owner,'POST',$path,array_replace($decision,['confirmed'=>false]))->getStatusCode()===400,'HTTP confirmation required');
    $response = $http($owner,'POST',$path,array_replace($decision,['costCp'=>0,'minutes'=>0,'adjustment'=>'gm','reason'=>'Explicit fictional GM exemption']));
    $check($response->getStatusCode()===201,'GM copy HTTP creation');
    $freeId = json_decode($response->getContent(),true)['acquisitionId'];
    $check($freeId>$copyId,'Explicit GM free copy allowed without modifying wallet or session');
    // HTTP authentication refreshes Doctrine identities; domain calls use managed entities.
    $manual=$em->find(Character::class,$manual->getId());
    $class=$em->find(CharacterClass::class,$class->getId());
    $owner=$em->find(User::class,$owner->getId()); $admin=$em->find(User::class,$admin->getId());
    $policy=$em->find(SpellcastingPolicyDefinition::class,$policy->getId());
    $expectedIds=array_map(static fn ($r)=>(int)$r['id'],$service->options($manual,$policy->getId(),$owner)['acquisitions']);
    $revise=fn ($ids,$expected,$override=false,$actor=null,$confirmed=true)=>$service->revise($manual,$policy->getId(),$policy->getId(),$ids,$expected,'Correct fictional reconciliation',$override,$confirmed,$actor??$owner);
    $refuse(fn ()=>$revise([$spells[3]['id']],$expectedIds,false,$owner,false),400);
    $refuse(fn ()=>$revise([$spells[3]['id']],$expectedIds,true,$other),404);
    $refuse(fn ()=>$revise([$spells[3]['id'],$spells[3]['id']],$expectedIds,true),400);
    $refuse(fn ()=>$revise([$foreign['id']],$expectedIds,true),404);
    $refuse(fn ()=>$revise([$spells[8]['id']],$expectedIds),400);
    $refuse(fn ()=>$service->revise($manual,$policy->getId(),$bookPolicy->getId(),[],$expectedIds,'Wrong acquisition class',true,true,$owner),400);
    $check($service->revisions($manual,$owner)===[],'Rejected revisions leave no journal or retired rows');
    $firstRevision=$revise([$spells[3]['id'],$spells[4]['id']],$expectedIds);
    $check(count($firstRevision['acquisitionIds'])===2 && !$firstRevision['historicalValidation'],'Strict revision creates current snapshot only');
    $check((int)$db->fetchOne('SELECT COUNT(*) FROM character_spell_acquisition WHERE retirement_id=?',[$firstRevision['revisionId']])===4,'Old acquisitions preserved and linked to revision');
    $refuse(fn ()=>$revise([],$expectedIds),409);
    $overIds=array_map(static fn ($s)=>$s['id'],[$spells[3],$spells[4],$spells[5],$spells[6],$spells[7]]);
    $refuse(fn ()=>$revise($overIds,$firstRevision['acquisitionIds']),409);
    $check(count($service->revisions($manual,$owner))===1,'Strict over-quota revision rolls back all changes');
    $overrideRevision=$revise($overIds,$firstRevision['acquisitionIds'],true);
    $check($service->options($manual,$policy->getId(),$owner)['status']==='quota-exceeded','GM quota exception is visible rather than silently treated as valid');
    $check(filter_var($service->revisions($manual,$owner)[1]['override_rules'],FILTER_VALIDATE_BOOL),'GM exception journalled');
    $fixedRevision=$revise([$spells[3]['id'],$spells[4]['id']],$overrideRevision['acquisitionIds']);
    $check(count($service->options($manual,$policy->getId(),$owner)['acquisitions'])===2,'Strict revision repairs previous exception');
    $check($before===$fingerprints(),'No other ORM table, spell, character or state modified');
    $nextPolicy=$service->definePolicy($class,new SpellcastingPolicy('synthetic-known',2,Ability::Intelligence,'known',$rows,'Explicit test policy transition'),$admin);
    $check($service->status($manual,$owner)[0]['policyIds']===[$policy->getId()],'Publishing a policy version does not move existing character');
    $transition=$service->revise($manual,$policy->getId(),$nextPolicy->getId(),[$spells[3]['id']],$fixedRevision['acquisitionIds'],'Explicit version change',false,true,$owner);
    $check($service->status($manual,$owner)[0]['policyIds']===[$nextPolicy->getId()],'Explicit policy transition pins new version');
    $check($service->options($manual,$nextPolicy->getId(),$owner)['acquisitions'][0]['revision_id']===$transition['revisionId'],'New acquisition has relational transition provenance');
    $refuse(fn ()=>$service->options($manual,$policy->getId(),$owner),409);
    $revisionPath='/characters/'.$manual->getId().'/spell-acquisitions/'.$nextPolicy->getId().'/revision';
    $check($http($other,'GET',$revisionPath)->getStatusCode()===404,'Revision history remains GM private');
    $check($http($owner,'GET',$revisionPath)->getStatusCode()===200,'GM can inspect revision options and history');
    $revisionPayload=['targetPolicyId'=>$nextPolicy->getId(),'spellIds'=>[$spells[4]['id']],'expectedAcquisitionIds'=>$transition['acquisitionIds'],'reason'=>'HTTP correction','overrideRules'=>false,'confirmed'=>true];
    $check($http($owner,'POST',$revisionPath,array_replace($revisionPayload,['confirmed'=>false]))->getStatusCode()===400,'HTTP revision confirmation required');
    $check($http($admin,'POST',$revisionPath,$revisionPayload)->getStatusCode()===404,'Reference admin cannot override another GM');
    $revisionResponse=$http($owner,'POST',$revisionPath,$revisionPayload);
    $check($revisionResponse->getStatusCode()===201,'HTTP correction succeeds atomically');
    $concurrentRevisionIds=json_decode($revisionResponse->getContent(),true)['acquisitionIds'];
    $check($http($owner,'POST',$revisionPath,$revisionPayload)->getStatusCode()===409,'Repeated stale confirmation rejected');
    foreach ([
        ["UPDATE spell_subclass_grant SET quota_impact = 'guessed' WHERE id = ?",[$grant['id']]],
        ["UPDATE character_spell_acquisition SET historical_validation = false WHERE id = ?",[$ids[0]]],
        ["UPDATE spellcasting_policy_definition SET version = 0 WHERE id = ?",[$policy->getId()]],
        ["DELETE FROM spell_definition WHERE id = ?",[$spells[4]['id']]],
        ["DELETE FROM character_class_level WHERE id = ?",[$h[0]]],
    ] as [$sql,$params]) {
        $db->createSavepoint('invalid_acq');
        try { $db->executeStatement($sql,$params); throw new RuntimeException('SQL constraint not enforced'); }
        catch (Doctrine\DBAL\Exception\DriverException) { $db->rollbackSavepoint('invalid_acq'); $check(true,'SQL CHECK/FK enforced'); }
        $db->releaseSavepoint('invalid_acq');
    }
    $refuse(fn ()=>$grants->save($spells[3]['id'],$grant['id'],['quotaImpact'=>'free'],$other),404);
    $refuse(fn ()=>$service->definePolicy($sub,$knownPolicy,$other),404);
    require __DIR__.'/test-advanced-spell-choice-cases.php';
    if (in_array($argv[1] ?? '', ['--historical', '--historical-concurrency'], true)) {
        require __DIR__.'/test-spell-historical-cases.php';
        echo "OK: $checks assertions (historical completion and acquisition prerequisites)\n";
        return;
    }
    if (!in_array($argv[1] ?? '', ['--initialization-preview', '--initialization-concurrency'], true)) require __DIR__.'/test-spell-substitution-cases.php';
    (static function () use ($em,$db,$registry,$service,$access,$catalogue,$metadata,$check,$http,$campaign,$owner,$admin,$other,$advancedClass,$advancedSub,$advancedPolicy,$bookClass,$byKey,$advancedChoice): void {
        require __DIR__.'/test-level-up-spell-preview-cases.php';
        require __DIR__.'/test-spell-initialization-preview-cases.php';
    })();
    if (in_array($argv[1] ?? '', ['--initialization-preview', '--initialization-concurrency'], true)) {
        require __DIR__.'/test-spell-initialization-cases.php';
        echo "OK: $checks assertions (initialization preview and acquisition prerequisites)\n";
        return;
    }
    require __DIR__.'/test-spell-qualification-http-cases.php';
    $em->clear();
    $all=$em->getRepository(CharacterSpellAcquisition::class)->findAll();
    foreach ($all as $a) { $check($a->isLearned(),'Learned property nonexclusive'); if ($a->getProvenance()==='manual-reconciliation') $check(!$a->isHistoricallyValidated(),'Historical limitation persisted'); if ($a->getQuotaBucket()==='spellbook') $check($a->isInSpellbook()&&!$a->isKnown(),'Book inscription separate from known'); }
    if ($concurrent) {
        $db->commit(); // Only the random isolated schema becomes visible to the workers.
        $db->beginTransaction();
        $db->fetchOne('SELECT id FROM character WHERE id=? FOR UPDATE',[$qualificationRace->getId()]);
        $workers=[];
        for ($i=0;$i<2;++$i) {
            $pipes=[];
            $process=proc_open([PHP_BINARY,__FILE__,'--worker',$isolatedSchema,(string)$qualificationRace->getId(),(string)$advancedPolicy->getId(),(string)$qualificationRaceId,'0',(string)$owner->getId(),'qualification'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
            if (!is_resource($process)) throw new RuntimeException('Cannot start qualification worker');
            fclose($pipes[0]); $check(trim(fgets($pipes[1]))==='ready','Qualification worker ready'); $workers[]=[$process,$pipes];
        }
        $db->commit(); $statuses=[];
        foreach ($workers as [$process,$pipes]) { $statuses[]=trim(stream_get_contents($pipes[1])); $errors=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); $check(proc_close($process)===0,'Qualification worker completed: '.$errors); }
        sort($statuses); $check($statuses===['201','409'],'Only one concurrent qualification accepted');
        $check((int)$db->fetchOne('SELECT COUNT(*) FROM character_spell_qualification WHERE acquisition_id=?',[$qualificationRaceId])===1,'Exactly one qualification journal entry');
        $check($db->fetchAssociative('SELECT * FROM character_spell_choice WHERE acquisition_id=?',[$qualificationRaceId])===$qualificationRaceBefore,'Concurrent qualification preserves initial decision');
        $db->beginTransaction();
        $db->fetchOne('SELECT id FROM character WHERE id=? FOR UPDATE',[$prepared->getId()]);
        $workers=[];
        foreach ([$spells[1],$spells[2]] as $candidate) {
            $pipes=[];
            $process=proc_open([PHP_BINARY,__FILE__,'--worker',$isolatedSchema,(string)$prepared->getId(),(string)$prepPolicy->getId(),(string)$p[0],(string)$candidate['id'],(string)$owner->getId()],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
            if (!is_resource($process)) throw new RuntimeException('Cannot start test worker');
            fclose($pipes[0]); $check(trim(fgets($pipes[1]))==='ready','Concurrent worker ready');
            $workers[]=[$process,$pipes];
        }
        $db->commit();
        $statuses=[];
        foreach ($workers as [$process,$pipes]) { $statuses[]=trim(stream_get_contents($pipes[1])); $errors=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); $check(proc_close($process)===0,'Worker completed: '.$errors); }
        sort($statuses); $check($statuses===['201','409'],'Exactly one concurrent acquisition spends last slot');
        $check((int)$db->fetchOne('SELECT COUNT(*) FROM character_spell_acquisition WHERE character_id=?',[$prepared->getId()])===2,'No concurrent over-quota row');
        $db->beginTransaction();
        $db->fetchOne('SELECT id FROM character WHERE id=? FOR UPDATE',[$book->getId()]);
        $workers=[];
        for ($i=0;$i<2;++$i) {
            $pipes=[];
            $process=proc_open([PHP_BINARY,__FILE__,'--worker',$isolatedSchema,(string)$book->getId(),(string)$bookPolicy->getId(),(string)$b[2],(string)$own['id'],(string)$owner->getId(),'copy'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
            if (!is_resource($process)) throw new RuntimeException('Cannot start copy worker');
            fclose($pipes[0]); $check(trim(fgets($pipes[1]))==='ready','Concurrent copy worker ready');
            $workers[]=[$process,$pipes];
        }
        $db->commit(); $statuses=[];
        foreach ($workers as [$process,$pipes]) { $statuses[]=trim(stream_get_contents($pipes[1])); $errors=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); $check(proc_close($process)===0,'Copy worker completed: '.$errors); }
        sort($statuses); $check($statuses===['201','409'],'Exactly one concurrent copy accepted');
        $check((int)$db->fetchOne('SELECT COUNT(*) FROM character_spell_acquisition WHERE character_id=? AND spell_id=?',[$book->getId(),$own['id']])===1,'No duplicate copy persisted');
        $db->beginTransaction();
        $db->fetchOne('SELECT id FROM character WHERE id=? FOR UPDATE',[$manual->getId()]);
        $workers=[];
        foreach ([$spells[3],$spells[5]] as $candidate) {
            $pipes=[];
            $process=proc_open([PHP_BINARY,__FILE__,'--worker',$isolatedSchema,(string)$manual->getId(),(string)$nextPolicy->getId(),implode(',',$concurrentRevisionIds),(string)$candidate['id'],(string)$owner->getId(),'revision'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
            if (!is_resource($process)) throw new RuntimeException('Cannot start revision worker');
            fclose($pipes[0]); $check(trim(fgets($pipes[1]))==='ready','Concurrent revision worker ready');
            $workers[]=[$process,$pipes];
        }
        $db->commit(); $statuses=[];
        foreach ($workers as [$process,$pipes]) { $statuses[]=trim(stream_get_contents($pipes[1])); $errors=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); $check(proc_close($process)===0,'Revision worker completed: '.$errors); }
        sort($statuses); $check($statuses===['201','409'],'Concurrent corrections do not overwrite each other');
        $db->beginTransaction();
        $db->fetchOne('SELECT id FROM character WHERE id=? FOR UPDATE',[$advancedChar->getId()]);
        $workers=[];
        foreach ([$spells[3],$spells[4]] as $candidate) {
            $pipes=[];
            $process=proc_open([PHP_BINARY,__FILE__,'--worker',$isolatedSchema,(string)$advancedChar->getId(),(string)$advancedPolicy->getId(),(string)$al[7],(string)$candidate['id'],(string)$owner->getId(),'advanced',(string)$byKey['unrestricted']],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
            if (!is_resource($process)) throw new RuntimeException('Cannot start advanced worker');
            fclose($pipes[0]); $check(trim(fgets($pipes[1]))==='ready','Advanced concurrent worker ready'); $workers[]=[$process,$pipes];
        }
        $db->commit(); $statuses=[];
        foreach ($workers as [$process,$pipes]) { $statuses[]=trim(stream_get_contents($pipes[1])); $errors=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); $check(proc_close($process)===0,'Advanced worker completed: '.$errors); }
        sort($statuses); $check($statuses===['201','409'],'Only one concurrent choice spends the exception opportunity');
        $db->beginTransaction();
        $db->fetchOne('SELECT id FROM character WHERE id=? FOR UPDATE',[$subCharacter->getId()]);
        $workers=[];
        foreach ([$spells[3],$spells[4]] as $candidate) {
            $pipes=[];
            $process=proc_open([PHP_BINARY,__FILE__,'--worker',$isolatedSchema,(string)$subCharacter->getId(),(string)$subRuleId,(string)$subLevels[4],(string)$candidate['id'],(string)$owner->getId(),'substitution',(string)$nextDecision],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
            if (!is_resource($process)) throw new RuntimeException('Cannot start substitution worker');
            fclose($pipes[0]); $check(trim(fgets($pipes[1]))==='ready','Substitution worker ready'); $workers[]=[$process,$pipes];
        }
        $db->commit(); $statuses=[];
        foreach ($workers as [$process,$pipes]) { $statuses[]=trim(stream_get_contents($pipes[1])); $errors=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); $check(proc_close($process)===0,'Substitution worker completed: '.$errors); }
        sort($statuses); $check($statuses===['201','409'],'Concurrent substitutions preserve one successor and one spent opportunity');
    }
    echo 'OK: '.$checks.' acquisition assertions; '.($concurrent?'isolated schema cleaned':'temporary tables rolled back').".\n";
} finally {
    if ($db->isTransactionActive()) $db->rollBack();
    if ($isolatedSchema !== null && preg_match('/^spell_acq_test_[a-f0-9]{16}$/D',$isolatedSchema)) { $db->executeStatement('SET search_path TO public'); $db->executeStatement('DROP SCHEMA IF EXISTS '.$isolatedSchema.' CASCADE'); }
    $em->clear(); $kernel->shutdown();
}
