<?php

declare(strict_types=1);

use App\Entity\{User, Campaign, Character, CharacterFeatureDefinition, CharacterFeatureRule, CharacterClass, CharacterSubclass, CharacterRace, Feat, ProgressionDefinition, CharacterClassLevel, CharacterFeat, CharacterProgression};
use App\Service\{CustomReferenceFeatureRuleService, CharacterFeatureResolver, ReferenceVisibility};
use Symfony\Component\HttpFoundation\{Request, Session\Session, Session\Storage\MockArraySessionStorage};
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

require __DIR__.'/../vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv(__DIR__.'/../.env');
putenv('SHELL_VERBOSITY=-1');
$_SERVER['SHELL_VERBOSITY'] = $_ENV['SHELL_VERBOSITY'] = -1;
$kernel = new App\Kernel('dev', true); $kernel->boot();
$em = $kernel->getContainer()->get('doctrine')->getManager(); $db = $em->getConnection();
$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks): void {
    if (!$ok) throw new RuntimeException($label);
    ++$checks;
};
$snapshot = static function () use ($db): array {
    $out = [];
    foreach ($db->fetchFirstColumn("SELECT tablename FROM pg_tables WHERE schemaname='public' AND tablename<>'doctrine_migration_versions' ORDER BY tablename") as $table) {
        $quoted = 'public.'.$db->quoteIdentifier($table);
        $out[$table] = $db->fetchOne("SELECT md5(COALESCE(string_agg(to_jsonb(t)::text, '' ORDER BY to_jsonb(t)::text),'')) FROM $quoted t");
    }
    return $out;
};
$before = $snapshot();
$session = new Session(new MockArraySessionStorage()); $session->start();
$login = static function (?User $user) use ($session): void {
    if ($user === null) $session->remove('_security_main');
    else $session->set('_security_main', serialize(new UsernamePasswordToken($user, 'main', ['ROLE_USER'])));
};
$base = '/reference/custom/feature-rules';
$request = static function (string $method, string $suffix = '', array|string|null $payload = null) use ($kernel, $session, $base): array {
    $body = is_array($payload) ? json_encode((object) $payload, JSON_THROW_ON_ERROR) : $payload;
    $request = Request::create(str_starts_with($suffix, '/reference/') ? $suffix : $base.$suffix, $method, [], [$session->getName() => $session->getId()], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
    ], $body);
    $request->setSession($session);
    $response = $kernel->handle($request);
    return [$response->getStatusCode(), json_decode($response->getContent(), true)];
};
try {
    $service = new CustomReferenceFeatureRuleService($db);
    $db->beginTransaction(); $db->executeStatement('SET TRANSACTION READ ONLY');
    $owner1 = $em->find(User::class, 1); $login($owner1);
    [$status, $body] = $request('GET');
    $check($status === 200 && array_column($body['rules'] ?? [], 'id') === [243, 244, 245], 'Owner1 real list');
    foreach ([243 => [1, 247, 10], 244 => [2, 248, 15], 245 => [3, 249, 50]] as $id => [$progression, $feature, $threshold]) {
        [$status, $body] = $request('GET', '/'.$id); $rule = $body['rule'];
        $check($status === 200 && $rule['source']['type'] === 'progression' && $rule['source']['id'] === $progression
            && $rule['featureDefinition']['id'] === $feature && $rule['progressionThreshold'] === $threshold
            && $rule['unlockLevel'] === null, 'Real serialized assignment '.$id);
        $count = $service->usageCount($id, $owner1);
        $actual = (int)$db->fetchOne('SELECT count(DISTINCT c.id) FROM character_progression a
            JOIN "character" c ON c.id=a.character_id JOIN campaign p ON p.id=c.campaign_id
            WHERE p.owner_id=1 AND a.progression_definition_id=?', [$progression]);
        $check($count === $actual, 'Real acquisition count '.$id);
        echo json_encode(['rule' => $id, 'progression' => $progression, 'feature' => $feature,
            'threshold' => $threshold, 'characters' => $count, 'used' => $count > 0], JSON_THROW_ON_ERROR)."\n";
    }
    // Verify deployed CHECKs and partial indexes, then copy CHECK definitions to TEMP
    // tables: SchemaTool alone includes indexes but not migration-only constraints.
    $constraints = $db->fetchAllAssociative("SELECT conname, pg_get_constraintdef(oid) AS definition FROM pg_constraint
        WHERE conrelid = 'public.character_feature_rule'::regclass AND contype='c'");
    $check(count($constraints) === 3, 'Deployed origin, one-source and threshold CHECKs');
    $check((int) $db->fetchOne("SELECT count(*) FROM pg_indexes WHERE schemaname='public' AND tablename='character_feature_rule' AND indexdef LIKE '%UNIQUE%' AND indexdef LIKE '%WHERE%'") === 10, 'Ten deployed partial unique indexes');
    $db->rollBack(); $em->clear();

    $db->beginTransaction();
    foreach ((new Doctrine\ORM\Tools\SchemaTool($em))->getCreateSchemaSql($em->getMetadataFactory()->getAllMetadata()) as $sql) {
        $db->executeStatement(preg_replace(['/^CREATE TABLE /', '/^CREATE SEQUENCE /'], ['CREATE TEMP TABLE ', 'CREATE TEMP SEQUENCE '], $sql));
    }
    foreach ($em->getMetadataFactory()->getAllMetadata() as $meta) {
        $check($db->fetchOne('SELECT relpersistence FROM pg_class WHERE oid=to_regclass(?)', [$meta->getTableName()]) === 't', 'Isolated '.$meta->getTableName());
    }
    foreach ($constraints as $constraint) $db->executeStatement('ALTER TABLE pg_temp.character_feature_rule ADD CONSTRAINT '.$db->quoteIdentifier($constraint['conname']).' '.$constraint['definition']);
    $a = (new User())->setEmail('a@custom-rule.invalid')->setPassword('unused');
    $b = (new User())->setEmail('b@custom-rule.invalid')->setPassword('unused');
    $em->persist($a); $em->persist($b);
    $features = []; $sources = [];
    foreach (['o', 'a', 'b'] as $group) {
        $features[$group] = new CharacterFeatureDefinition('feature-'.$group, 'Feature '.$group);
        $sources[$group]['class'] = new CharacterClass('class-'.$group, 'Class '.$group, 8, 1);
        $sources[$group]['subclass'] = new CharacterSubclass($sources[$group]['class'], 'custom-'.str_repeat(['o'=>'0','a'=>'a','b'=>'b'][$group], 32), 'Subclass '.$group);
        $sources[$group]['race'] = new CharacterRace('race-'.$group, 'Race '.$group);
        $sources[$group]['feat'] = new Feat('feat-'.$group, 'Feat '.$group);
        $sources[$group]['progression'] = new ProgressionDefinition('progression-'.$group, 'Progression '.$group);
        $em->persist($features[$group]);
        foreach ($sources[$group] as $entity) $em->persist($entity);
    }
    $officialRule = CharacterFeatureRule::forClass($features['o'], $sources['o']['class'], 1);
    $em->persist($officialRule); $em->flush();
    $sourceIds = []; $featureIds = [];
    $tables = ['class'=>'character_class', 'subclass'=>'character_subclass', 'race'=>'character_race', 'feat'=>'feat', 'progression'=>'progression_definition'];
    foreach (['o', 'a', 'b'] as $group) {
        $featureIds[$group] = $features[$group]->getId();
        foreach ($sources[$group] as $type => $entity) $sourceIds[$group][$type] = $entity->getId();
        if ($group === 'o') continue;
        $ownerId = $group === 'a' ? $a->getId() : $b->getId();
        $db->update('character_feature_definition', ['origin'=>'CUSTOM', 'owner_id'=>$ownerId], ['id'=>$featureIds[$group]]);
        foreach ($tables as $type=>$table) $db->update($table, ['origin'=>'CUSTOM','owner_id'=>$ownerId], ['id'=>$sourceIds[$group][$type]]);
    }
    $aId = $a->getId(); $bId = $b->getId(); $officialId = $officialRule->getId(); $em->clear();
    $a = $em->find(User::class, $aId); $b = $em->find(User::class, $bId);
    $payload = static fn (string $type, int $source, int $feature, int $value = 1): array => [
        'featureDefinitionId'=>$feature, 'sourceType'=>$type, 'sourceId'=>$source,
        $type === 'progression' ? 'progressionThreshold' : 'unlockLevel' => $value,
    ];
    $create = static function (array $data) use ($request, $check): array {
        [$status,$body] = $request('POST','',$data);
        $check($status === 201, 'POST '.json_encode($data).' response '.json_encode([$status,$body]));
        return $body['rule'];
    };
    $row = static fn (int $id): array => $db->fetchAssociative('SELECT * FROM character_feature_rule WHERE id=?', [$id]);
    $rules = []; $bRules = [];
    foreach ($tables as $type => $table) {
        $login($a);
        $data = $payload($type, $sourceIds['o'][$type], $featureIds['o']);
        $rules[$type] = $create($data);
        $check($request('POST', '', $data)[0] === 409, 'Same owner duplicate '.$type);
        $raw = $row($rules[$type]['id']);
        $check($raw['origin'] === 'CUSTOM' && (int)$raw['owner_id'] === $aId && (int)$raw['display_order'] === 0, 'Server provenance/default '.$type);
        $check(count(array_filter(array_intersect_key($raw, array_flip(['character_class_id','character_subclass_id','character_race_id','feat_id','progression_definition_id'])), static fn ($v) => $v !== null)) === 1, 'Exactly one source '.$type);
        $check(array_keys($rules[$type]) === ['id','featureDefinition','source','unlockLevel','progressionThreshold'], 'Compact rule JSON '.$type);
        $check(array_keys($rules[$type]['featureDefinition']) === ['id','name','slug','origin'] && array_keys($rules[$type]['source']) === ['type','id','name','slug','origin'], 'Compact relations '.$type);
        $login($b); $bRules[$type] = $create($data);
        $check($bRules[$type]['id'] !== $rules[$type]['id'], 'Cross-owner shared official assignment '.$type);
    }
    $login($a);
    // Full O/C matrix on every family, plus symmetrical foreign relation rejection.
    foreach ($tables as $type => $table) {
        foreach ([['o','a'], ['a','o'], ['a','a']] as [$fg,$sg]) {
            $rule = $create($payload($type, $sourceIds[$sg][$type], $featureIds[$fg], 2));
            $check($rule['featureDefinition']['origin'] === ($fg === 'o' ? 'OFFICIAL' : 'CUSTOM')
                && $rule['source']['origin'] === ($sg === 'o' ? 'OFFICIAL' : 'CUSTOM'), 'Composition '.$type.$fg.$sg);
        }
        foreach (['feature','source'] as $relation) {
            $foreign = $payload($type, $sourceIds[$relation==='source'?'b':'a'][$type], $featureIds[$relation==='feature'?'b':'a']);
            $missing = $foreign; $missing[$relation==='source'?'sourceId':'featureDefinitionId'] = 999999;
            $check($request('POST','',$foreign) === $request('POST','',$missing) && $request('POST','',$foreign)[0] === 400, 'Foreign/missing indistinguishable '.$type.$relation);
            $beforeRow = $row($rules[$type]['id']);
            $check($request('PATCH','/'.$rules[$type]['id'],$foreign)[0] === 400 && $row($rules[$type]['id']) === $beforeRow, 'Invalid relation PATCH atomic '.$type.$relation);
        }
    }
    foreach ([$bRules['class']['id'], $officialId, 999999] as $id) {
        foreach (['GET','PATCH','DELETE'] as $method) {
            $check($request($method,'/'.$id,[]) === [404,['message'=>'Attribution introuvable.']], 'Uniform scoped 404 '.$method);
        }
        $check($request('PATCH','/'.$id,'{bad')[0] === 404, 'Scope before parsing');
    }
    $listed = $request('GET')[1]['rules'];
    $expected = array_map('intval', $db->fetchFirstColumn("SELECT id FROM character_feature_rule WHERE origin='CUSTOM' AND owner_id=? ORDER BY id", [$aId]));
    $check(array_column($listed,'id') === $expected, 'Owned list in stable ID order');
    $check($request('GET','?ownerId='.$bId)[1]['rules'] === $listed, 'No query owner override');

    $valid = $payload('class',$sourceIds['o']['class'],$featureIds['o'],3);
    foreach (['POST','PATCH'] as $method) {
        $suffix = $method === 'POST' ? '' : '/'.$rules['class']['id'];
        foreach (['id','origin','owner','ownerId','slug','custom','displayOrder','name','description','characterClassId','characterSubclassId','characterRaceId','featId','progressionDefinitionId','featureDefinition','source','unknown'] as $field) {
            $check($request($method,$suffix,$valid+[$field=>1])[0] === 400, 'Forbidden '.$field.' '.$method);
        }
        foreach (['featureDefinitionId','sourceId'] as $field) foreach ([null,0,-1,'1',true,[],2147483648] as $bad) {
            $check($request($method,$suffix,array_replace($valid,[$field=>$bad]))[0] === 400, 'Strict ID '.$field.' '.$method);
        }
        foreach ([null,'unknown',1,[]] as $bad) $check($request($method,$suffix,array_replace($valid,['sourceType'=>$bad]))[0] === 400, 'Strict type '.$method);
        foreach ([null,0,21,-1,'1',true,[],1.5] as $bad) $check($request($method,$suffix,array_replace($valid,['unlockLevel'=>$bad]))[0] === 400, 'Strict level '.$method);
        foreach (['[]','null','42','{broken'] as $bad) $check($request($method,$suffix,$bad)[0] === 400, 'Object JSON '.$method);
        $check($request($method,$suffix,$valid+['progressionThreshold'=>1])[0] === 400, 'Ordinary threshold forbidden '.$method);
    }
    foreach (array_keys($valid) as $required) {
        $bad=$valid; unset($bad[$required]);
        $check($request('POST','',$bad)[0] === 400, 'POST required '.$required);
    }
    foreach ([null,-1,'0',false,[],2147483648] as $bad) $check($request('POST','',array_replace($payload('progression',$sourceIds['o']['progression'],$featureIds['o']),['progressionThreshold'=>$bad]))[0] === 400, 'Strict threshold');
    $check($request('POST','',$payload('progression',$sourceIds['o']['progression'],$featureIds['o'])+['unlockLevel'=>1])[0] === 400, 'No progression dummy in input');
    $zero = $create($payload('progression',$sourceIds['o']['progression'],$featureIds['o'],0));
    $check($zero['unlockLevel'] === null && $zero['progressionThreshold'] === 0 && (int)$row($zero['id'])['unlock_level'] === 1, 'Zero threshold; dummy stays internal');

    // Unused transitions, including a family change clearing every previous FK.
    $editable = $create($valid); $editId=$editable['id'];
    foreach ($tables as $type=>$table) {
        $data=$payload($type,$sourceIds['o'][$type],$featureIds['o'],4);
        $check($request('PATCH','/'.$editId,$data)[0] === 200, 'Unused family transition '.$type);
        $check($request('GET','/'.$editId)[1]['rule']['source']['type'] === $type, 'Source changed '.$type);
    }
    $check($request('PATCH','/'.$editId,['sourceType'=>'class','unlockLevel'=>4])[0] === 400, 'Family change requires source ID');
    $check($request('PATCH','/'.$editId,['sourceType'=>'class','sourceId'=>$sourceIds['o']['class']])[0] === 400, 'Family change requires new level');
    $prior=$row($editId);
    $check($request('PATCH','/'.$editId,$payload('class',$sourceIds['o']['class'],$featureIds['o']))[0] === 409 && $row($editId)===$prior, 'DB duplicate PATCH rollback including source switch');
    $check($request('PATCH','/'.$editId,['progressionThreshold'=>5,'featureDefinitionId'=>$featureIds['a']])[0] === 200, 'Unused feature and threshold change');
    $check($request('PATCH','/'.$editId,['sourceId'=>$sourceIds['a']['progression']])[0] === 200, 'Unused source ID change');
    $check($request('DELETE','/'.$editId)[0] === 204 && $request('GET','/'.$editId)[0] === 404, 'Unused DELETE');

    // Composition with both existing CRUDs. No new transitive Resource guard.
    [$status,$body]=$request('POST','/reference/custom/resources',['name'=>'Composition resource']);
    $check($status===201,'Composition resource create'); $resource=$body['resource'];
    [$status,$body]=$request('POST','/reference/custom/features',['name'=>'Composition feature','resourceDefinitionId'=>$resource['id']]);
    $check($status===201,'Composition feature create'); $feature=$body['feature'];
    $assignment=$create($payload('class',$sourceIds['a']['class'],$feature['id']));
    $fp='/reference/custom/features/'.$feature['id']; $rp='/reference/custom/resources/'.$resource['id'];
    $check($request('PATCH',$fp,['resourceDefinitionId'=>null])[0]===409,'Rule freezes Feature resource');
    $check($request('DELETE',$fp)[0]===409,'Rule blocks Feature delete');
    $check($request('PATCH',$rp,['baseMaximum'=>7])[0]===409 && $request('DELETE',$rp)[0]===409,'Feature freezes Resource');
    $check($request('DELETE','/'.$assignment['id'])[0]===204,'Unused Rule removal');
    $check($request('GET',$fp)[0]===200 && $request('GET',$rp)[0]===200,'Rule deletion preserves definitions');
    $check($request('DELETE',$rp)[0]===409,'Feature still blocks Resource after Rule deletion');
    $check($request('PATCH',$fp,['resourceDefinitionId'=>null])[0]===200,'Feature relation becomes editable');
    $check($request('DELETE',$fp)[0]===204 && $request('DELETE',$rp)[0]===204,'Composition definitions now unused');

    // Dependency visibility parity: official parents cannot depend on CUSTOM,
    // even same-owner. Also test malformed foreign dependencies on own references.
    $db->createSavepoint('dependencies');
    $db->update('character_subclass',['character_class_id'=>$sourceIds['b']['class']],['id'=>$sourceIds['a']['subclass']]);
    $db->update('character_race',['parent_race_id'=>$sourceIds['b']['race']],['id'=>$sourceIds['a']['race']]);
    foreach (['subclass','race'] as $type) {
        $check($request('POST','',$payload($type,$sourceIds['a'][$type],$featureIds['o'],7))[0]===400,'Foreign dependency '.$type);
        $check(!in_array($sourceIds['a'][$type],array_column(array_column(array_filter($request('GET')[1]['rules'],static fn($r)=>$r['source']['type']===$type),'source'),'id'),true),'No corrupt source read leak '.$type);
    }
    $db->rollbackSavepoint('dependencies'); $db->releaseSavepoint('dependencies');

    $login($b);
    [$status,$body]=$request('POST','/reference/custom/resources',['name'=>'Foreign dependency']);
    $check($status===201,'Foreign resource fixture'); $foreignResourceId=$body['resource']['id'];
    $login($a);
    [$status,$body]=$request('POST','/reference/custom/resources',['name'=>'Own dependency']);
    $check($status===201,'Own resource fixture'); $ownResourceId=$body['resource']['id'];
    foreach ([['a',$foreignResourceId],['o',$ownResourceId]] as [$group,$resourceId]) {
        $db->createSavepoint('feature_dependency');
        $db->update('character_feature_definition',['resource_definition_id'=>$resourceId],['id'=>$featureIds[$group]]);
        $check($request('POST','',$payload('class',$sourceIds['o']['class'],$featureIds[$group],7))[0]===400,'Hidden feature dependency rejected '.$group);
        $check($request('PATCH','/'.$rules['class']['id'],['featureDefinitionId'=>$featureIds[$group]])[0]===($group==='o'?404:400),'Hidden feature dependency PATCH rejected '.$group);
        $check(!in_array($featureIds[$group],array_column(array_column($request('GET')[1]['rules'],'featureDefinition'),'id'),true),'Hidden feature dependency excluded from list '.$group);
        if($group==='o') $check($request('GET','/'.$rules['class']['id'])[0]===404,'Existing corrupt feature rule hidden');
        $em->clear();
        $check(!ReferenceVisibility::allows($em->find(CharacterFeatureDefinition::class,$featureIds[$group]),$a),'DBAL visibility matches runtime feature dependency '.$group);
        $db->rollbackSavepoint('feature_dependency'); $db->releaseSavepoint('feature_dependency');
    }
    foreach (['subclass','race'] as $type) {
        $db->createSavepoint('official_dependency');
        $column=$type==='subclass'?'character_class_id':'parent_race_id';
        $parent=$type==='subclass'?$sourceIds['a']['class']:$sourceIds['a']['race'];
        $db->update($tables[$type],[$column=>$parent],['id'=>$sourceIds['o'][$type]]);
        $check($request('POST','',$payload($type,$sourceIds['o'][$type],$featureIds['o'],7))[0]===400,'OFFICIAL cannot depend on own CUSTOM '.$type);
        $check($request('GET','/'.$rules[$type]['id'])[0]===404,'Existing invalid OFFICIAL dependency hidden '.$type);
        $em->clear();
        $check(!ReferenceVisibility::allows($em->find($type==='subclass'?CharacterSubclass::class:CharacterRace::class,$sourceIds['o'][$type]),$a),'DBAL visibility matches runtime source dependency '.$type);
        $db->rollbackSavepoint('official_dependency'); $db->releaseSavepoint('official_dependency');
    }
    $db->createSavepoint('race_cycle');
    $db->update('character_race',['parent_race_id'=>$sourceIds['a']['race']],['id'=>$sourceIds['a']['race']]);
    $check($request('POST','',$payload('race',$sourceIds['a']['race'],$featureIds['o'],7))[0]===400,'Corrupt race cycle rejected without recursion loop');
    $db->rollbackSavepoint('race_cycle'); $db->releaseSavepoint('race_cycle');

    // Migration-only CHECKs are real database guards, not only service validation.
    foreach ([['character_class_id'=>null],['feat_id'=>$sourceIds['o']['feat']],['progression_threshold'=>1]] as $invalid) {
        $db->createSavepoint('invalid_shape'); $rejected=false;
        try { $db->update('character_feature_rule',$invalid,['id'=>$rules['class']['id']]); }
        catch (Doctrine\DBAL\Exception\DriverException $e) { $rejected=$e->getSQLState()==='23514'; }
        finally { $db->rollbackSavepoint('invalid_shape');$db->releaseSavepoint('invalid_shape'); }
        $check($rejected,'DB CHECK rejects invalid ordinary shape');
    }
    foreach ([null,-1] as $invalid) {
        $db->createSavepoint('invalid_threshold');$rejected=false;
        try { $db->update('character_feature_rule',['progression_threshold'=>$invalid],['id'=>$rules['progression']['id']]); }
        catch (Doctrine\DBAL\Exception\DriverException $e) { $rejected=$e->getSQLState()==='23514'; }
        finally { $db->rollbackSavepoint('invalid_threshold');$db->releaseSavepoint('invalid_threshold'); }
        $check($rejected,'DB CHECK rejects invalid progression threshold');
    }

    // Same-owner subclass may have OFFICIAL parent, without rewriting it.
    $db->createSavepoint('subclass_parent');
    $db->update('character_subclass',['character_class_id'=>$sourceIds['o']['class']],['id'=>$sourceIds['a']['subclass']]);
    $sub=$create($payload('subclass',$sourceIds['a']['subclass'],$featureIds['a'],7));
    $check((int)$db->fetchOne('SELECT character_class_id FROM character_subclass WHERE id=?',[$sourceIds['a']['subclass']])===$sourceIds['o']['class'],'Subclass parent unchanged');
    $db->rollbackSavepoint('subclass_parent'); $db->releaseSavepoint('subclass_parent');

    // Acquisitions belong to Campaign.owner, not to a direct Character user.
    $em->clear(); $a=$em->find(User::class,$aId); $b=$em->find(User::class,$bId);
    $ca=new Campaign($a,'rule-a','A'); $cb=new Campaign($b,'rule-b','B');
    $charA=new Character($ca,'rule-a','A','player'); $charB=new Character($cb,'rule-b','B','player');
    $em->persist($ca);$em->persist($cb);$em->persist($charA);$em->persist($charB);$em->flush();
    $charAId=$charA->getId();$charBId=$charB->getId();
    $acquire=static function (Character $character, string $type) use ($em,$sourceIds): void {
        $id=$sourceIds['o'][$type];
        $entity=match($type) {
            'class'=>new CharacterClassLevel($character,$em->find(CharacterClass::class,$id),1),
            'subclass'=>new CharacterClassLevel($character,$em->find(CharacterClass::class,$sourceIds['o']['class']),2,$em->find(CharacterSubclass::class,$id)),
            'feat'=>new CharacterFeat($character,$em->find(Feat::class,$id)),
            'progression'=>new CharacterProgression($character,$em->find(ProgressionDefinition::class,$id)),
            'race'=>null,
        };
        if($entity!==null) $em->persist($entity);
        else $character->setRace($em->find(CharacterRace::class,$id));
        $em->flush();
    };
    foreach ($tables as $type=>$table) $acquire($charB,$type);
    $login($a);
    foreach ($tables as $type=>$table) {
        $id=$rules[$type]['id'];
        $check($service->usageCount($id,$a)===0,'Other campaign owner does not freeze '.$type);
        $check($request('PATCH','/'.$id,[$type==='progression'?'progressionThreshold':'unlockLevel'=>20])[0]===200,'B acquisition permits A edit '.$type);
        $spare=$create($payload($type,$sourceIds['o'][$type],$featureIds['a'],19));
        $check($request('DELETE','/'.$spare['id'])[0]===204,'B acquisition permits A delete '.$type);
    }
    $em->clear();$charA=$em->find(Character::class,$charAId);
    foreach ($tables as $type=>$table) $acquire($charA,$type);
    foreach ($tables as $type=>$table) {
        $id=$rules[$type]['id']; $key=$type==='progression'?'progressionThreshold':'unlockLevel';
        $check($service->usageCount($id,$a)===1,'Actual own acquisition counted once '.$type);
        $prior=$row($id);
        $check($request('PATCH','/'.$id,[$key=>20])[0]===200 && $row($id)===$prior,'Used same value no-op '.$type);
        foreach ([[$key=>19],['featureDefinitionId'=>$featureIds['a']],['sourceId'=>$sourceIds['a'][$type]]] as $change) {
            $check($request('PATCH','/'.$id,$change)[0]===409 && $row($id)===$prior,'Used structural change blocked atomically '.$type);
        }
        $check($request('DELETE','/'.$id)[0]===409 && $row($id)===$prior,'Used delete blocked '.$type);
    }
    // A descendant race inherits the attribution; count DISTINCT characters, not levels.
    $em->clear();$parent=$em->find(CharacterRace::class,$sourceIds['o']['race']);
    $child=(new CharacterRace('child-race','Child'))->setParentRace($parent);$em->persist($child);$em->flush();
    $db->update('character',['race_id'=>$child->getId()],['id'=>$charAId]);
    $check($service->usageCount($rules['race']['id'],$a)===1,'Descendant race acquisition freezes ancestor Rule');
    $check($request('DELETE','/'.$rules['race']['id'])[0]===409,'Inherited race deletion guard');

    // Runtime remains the existing resolver: owner from Character, explicit threshold.
    [$status,$body]=$request('POST','/reference/custom/features',['name'=>'Runtime only']);
    $check($status===201,'Dedicated runtime feature without competing assignments');
    $runtimeFeature=$body['feature'];
    $runtime=$create($payload('progression',$sourceIds['o']['progression'],$runtimeFeature['id'],50));
    $em->clear();$charA=$em->find(Character::class,$charAId);$charB=$em->find(Character::class,$charBId);
    $resolver=new CharacterFeatureResolver($em->getRepository(CharacterFeatureRule::class));
    $slug=$runtimeFeature['slug']; $progressionSlug='progression-o';
    $check(!isset($resolver->resolve($charA)[$slug]),'No implicit progression session');
    $check(!isset($resolver->resolve($charA,[$progressionSlug=>49])[$slug]),'Created progression rule inactive below threshold');
    $resolved=$resolver->resolve($charA,[$progressionSlug=>50]);
    $check(isset($resolved[$slug]) && $resolved[$slug]->getId()===$runtime['id'],'Existing resolver resolves created CUSTOM A rule for A');
    $check(!isset($resolver->resolve($charB,[$progressionSlug=>50])[$slug]),'Existing resolver never gives CUSTOM A rule to B');
    $check(isset($resolver->resolve($charA,[$progressionSlug=>51])[$slug]),'Created progression rule active above threshold');
    $check($service->usageCount($runtime['id'],$a)===1 && $request('DELETE','/'.$runtime['id'])[0]===409,'Progression frozen below threshold as well as above');

    $login($b);
    $check($request('GET','/'.$runtime['id'])[0]===404,'B cannot read A');
    $check(array_column($request('GET')[1]['rules'],'id')===array_column($bRules,'id'),'B list contains only B');
    $login(null);
    foreach (['GET','POST','PATCH','DELETE'] as $method) {
        $check(in_array($request($method,in_array($method,['PATCH','DELETE'],true)?'/'.$runtime['id']:'',[])[0],[401,403],true),'Anonymous '.$method);
    }
} finally {
    while ($db->isTransactionActive()) $db->rollBack();
    $em->clear();
    $check($before === $snapshot(), 'All public fingerprints unchanged');
    $kernel->shutdown();
}
echo "OK: $checks custom feature rule CRUD assertions; owner1 READ ONLY; isolated fixtures rolled back.\n";
