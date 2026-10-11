<?php

declare(strict_types=1);

use App\Entity\{User, Campaign, Character, GameSession, CharacterSessionState, TrackableResourceDefinition, TrackableResourceRule, CharacterFeatureRule, CharacterClass, CharacterSubclass, CharacterRace, Feat, CharacterClassLevel, CharacterFeat};
use App\Enum\{ReferenceOrigin, ResourceRechargeType, ResourceMaximumType};
use App\Service\{CustomReferenceResourceRuleService, CharacterFeatureResolver, CharacterResourceResolver, CharacterAbilityCalculator, ReferenceVisibility};
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
$base = '/reference/custom/resource-rules';
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
    $service = new CustomReferenceResourceRuleService($db);
    $db->beginTransaction(); $db->executeStatement('SET TRANSACTION READ ONLY');
    $login($em->find(User::class, 1));
    $check($request('GET') === [200, ['rules'=>[]]], 'Real owner1 empty list');
    $check((int)$db->fetchOne("SELECT count(*) FROM trackable_resource_rule WHERE origin='CUSTOM'") === 0, 'No existing CUSTOM ResourceRule');
    $stats=$db->fetchAssociative('SELECT count(*) AS rules, min(maximum_bonus) AS minimum, max(maximum_bonus) AS maximum, count(maximum_override) AS overrides FROM trackable_resource_rule');
    echo 'READ ONLY resource rules: '.json_encode($stats)."\n";
    $subclass=$em->find(CharacterSubclass::class,20);
    $check($subclass->getOrigin()===ReferenceOrigin::Custom && $subclass->getOwner()->getId()===1
        && ReferenceVisibility::allows($subclass,$em->find(User::class,1)), 'Real arch-hag source visible to owner1');
    $check(!ReferenceVisibility::allows($subclass,$em->find(User::class,2)), 'Real arch-hag not globally visible');
    $constraints=$db->fetchAllAssociative("SELECT conname, pg_get_constraintdef(oid) AS definition FROM pg_constraint WHERE conrelid='public.trackable_resource_rule'::regclass AND contype='c'");
    $check(count($constraints)===2,'Deployed origin/owner and single-source CHECKs');
    $check((int)$db->fetchOne("SELECT count(*) FROM pg_indexes WHERE schemaname='public' AND tablename='trackable_resource_rule' AND indexdef LIKE '%UNIQUE%' AND indexdef LIKE '%WHERE%'")===8,'Eight deployed partial unique indexes');
    $db->rollBack();$em->clear();

    $db->beginTransaction();
    foreach ((new Doctrine\ORM\Tools\SchemaTool($em))->getCreateSchemaSql($em->getMetadataFactory()->getAllMetadata()) as $sql) {
        $db->executeStatement(preg_replace(['/^CREATE TABLE /','/^CREATE SEQUENCE /'],['CREATE TEMP TABLE ','CREATE TEMP SEQUENCE '],$sql));
    }
    foreach($em->getMetadataFactory()->getAllMetadata() as $meta) $check($db->fetchOne('SELECT relpersistence FROM pg_class WHERE oid=to_regclass(?)',[$meta->getTableName()])==='t','Isolated '.$meta->getTableName());
    foreach($constraints as $c) $db->executeStatement('ALTER TABLE pg_temp.trackable_resource_rule ADD CONSTRAINT '.$db->quoteIdentifier($c['conname']).' '.$c['definition']);
    $a=(new User())->setEmail('a@resource-rule.invalid')->setPassword('unused');
    $b=(new User())->setEmail('b@resource-rule.invalid')->setPassword('unused');
    $em->persist($a);$em->persist($b);
    $resources=[];$sources=[];$sourceIds=[];$resourceIds=[];
    $tables=['class'=>'character_class','subclass'=>'character_subclass','race'=>'character_race','feat'=>'feat'];
    foreach(['o','a','b'] as $group) {
        $resources[$group]=new TrackableResourceDefinition('resource-'.$group,'Resource '.$group,ResourceRechargeType::LongRest,ResourceMaximumType::Fixed,5);
        $resources[$group]->setCustom($group==='o'); // Provenance, never this legacy flag.
        $sources[$group]['class']=new CharacterClass('class-'.$group,'Class '.$group,8,1);
        $sources[$group]['subclass']=new CharacterSubclass($sources[$group]['class'],'custom-'.str_repeat(['o'=>'0','a'=>'a','b'=>'b'][$group],32),'Subclass '.$group);
        $sources[$group]['race']=new CharacterRace('race-'.$group,'Race '.$group);
        $sources[$group]['feat']=new Feat('feat-'.$group,'Feat '.$group);
        $em->persist($resources[$group]);
        foreach($sources[$group] as $source) $em->persist($source);
    }
    $official=TrackableResourceRule::forClass($resources['o'],$sources['o']['class'],1);
    $em->persist($official);$em->flush();$officialId=$official->getId();$aId=$a->getId();$bId=$b->getId();
    try { $official->setMaximumBonus(-1);$check(false,'Entity must reject negative bonus'); }
    catch(InvalidArgumentException) { $check(true,'Entity rejects negative bonus'); }
    foreach(['o','a','b'] as $group) {
        $resourceIds[$group]=$resources[$group]->getId();
        foreach($sources[$group] as $type=>$entity) $sourceIds[$group][$type]=$entity->getId();
        if($group==='o') continue;
        $ownership=['origin'=>'CUSTOM','owner_id'=>$group==='a'?$aId:$bId];
        $db->update('trackable_resource_definition',$ownership,['id'=>$resourceIds[$group]]);
        foreach($tables as $type=>$table) $db->update($table,$ownership,['id'=>$sourceIds[$group][$type]]);
    }
    $em->clear();$a=$em->find(User::class,$aId);$b=$em->find(User::class,$bId);
    $payload=static fn(string $type,int $source,int $resource,int $level=1,int $bonus=0):array=>[
        'resourceDefinitionId'=>$resource,'sourceType'=>$type,'sourceId'=>$source,'unlockLevel'=>$level,'maximumBonus'=>$bonus,
    ];
    $create=static function(array $data) use($request,$check):array {
        [$status,$body]=$request('POST','',$data);
        $check($status===201,'POST '.json_encode($data).' response '.json_encode([$status,$body]));
        return $body['rule'];
    };
    $row=static fn(int $id):array=>$db->fetchAssociative('SELECT * FROM trackable_resource_rule WHERE id=?',[$id]);
    $rules=[];$bRules=[];
    foreach($tables as $type=>$table) {
        $login($a);$data=$payload($type,$sourceIds['o'][$type],$resourceIds['o']);
        $rules[$type]=$create($data);$id=$rules[$type]['id'];
        $check($request('POST','',$data)[0]===409,'Same owner duplicate '.$type);
        $check($request('POST','',array_replace($data,['maximumBonus'=>7]))[0]===409,'Bonus not part of uniqueness '.$type);
        $raw=$row($id);
        $check($raw['origin']==='CUSTOM' && (int)$raw['owner_id']===$aId && $raw['maximum_override']===null,'Server provenance and null override '.$type);
        $check(count(array_filter(array_intersect_key($raw,array_flip(['character_class_id','character_subclass_id','character_race_id','feat_id'])),static fn($v)=>$v!==null))===1,'Exactly one source '.$type);
        $check(array_keys($rules[$type])===['id','resourceDefinition','source','unlockLevel','maximumBonus'],'Compact rule JSON '.$type);
        $check(array_keys($rules[$type]['resourceDefinition'])===['id','name','slug','origin'] && array_keys($rules[$type]['source'])===['type','id','name','slug','origin'],'Compact references '.$type);
        $login($b);$bRules[$type]=$create($data);
        $check($bRules[$type]['id']!==$id,'Shared OFFICIAL references across owners '.$type);
        $login($a);
        foreach([['o','a'],['a','o'],['a','a']] as [$rg,$sg]) {
            $rule=$create($payload($type,$sourceIds[$sg][$type],$resourceIds[$rg],2,1));
            $check($rule['resourceDefinition']['origin']===($rg==='o'?'OFFICIAL':'CUSTOM') && $rule['source']['origin']===($sg==='o'?'OFFICIAL':'CUSTOM'),'Composition '.$type.$rg.$sg);
        }
        foreach(['resource','source'] as $relation) {
            $foreign=$payload($type,$sourceIds[$relation==='source'?'b':'a'][$type],$resourceIds[$relation==='resource'?'b':'a']);
            $missing=$foreign;$missing[$relation==='source'?'sourceId':'resourceDefinitionId']=999999;
            $check($request('POST','',$foreign)===$request('POST','',$missing) && $request('POST','',$foreign)[0]===400,'Foreign/missing indistinguishable '.$type.$relation);
            $prior=$row($id);
            $check($request('PATCH','/'.$id,$foreign)[0]===400 && $row($id)===$prior,'Invalid relation PATCH atomic '.$type.$relation);
        }
        $unused=$create($payload($type,$sourceIds['o'][$type],$resourceIds['o'],3));
        $check($request('PATCH','/'.$unused['id'],['resourceDefinitionId'=>$resourceIds['a'],'sourceId'=>$sourceIds['a'][$type],'unlockLevel'=>4,'maximumBonus'=>3])[0]===200,'Unused all fields PATCH '.$type);
        $check($request('DELETE','/'.$unused['id'])[0]===204,'Unused DELETE '.$type);
        $check((bool)$db->fetchOne('SELECT id FROM '.$table.' WHERE id=?',[$sourceIds['a'][$type]]) && (bool)$db->fetchOne('SELECT id FROM trackable_resource_definition WHERE id=?',[$resourceIds['a']]),'DELETE preserves resource/source '.$type);
    }
    foreach([$bRules['class']['id'],$officialId,999999] as $id) {
        foreach(['GET','PATCH','DELETE'] as $method) $check($request($method,'/'.$id,[])===[404,['message'=>'Attribution introuvable.']],'Uniform root 404 '.$method);
        $check($request('PATCH','/'.$id,'{bad')[0]===404,'Scope before parsing');
    }
    $expected=array_map('intval',$db->fetchFirstColumn("SELECT id FROM trackable_resource_rule WHERE origin='CUSTOM' AND owner_id=? ORDER BY id",[$aId]));
    $check(array_column($request('GET')[1]['rules'],'id')===$expected,'Own-only ID ordered list');
    $check($request('GET','?ownerId='.$bId)===$request('GET'),'Query owner ignored');
    $valid=$payload('class',$sourceIds['o']['class'],$resourceIds['o'],8,2);
    foreach(['POST','PATCH'] as $method) {
        $suffix=$method==='POST'?'':'/'.$rules['class']['id'];
        foreach(['id','origin','owner','ownerId','slug','custom','displayOrder','name','description','maximumOverride','maximum_override','characterClassId','characterSubclassId','characterRaceId','featId','character_class_id','resource_definition_id','resourceDefinition','source','progressionThreshold','progressionDefinitionId','featureDefinitionId','rechargeType','unknown'] as $field) {
            $check($request($method,$suffix,$valid+[$field=>null])[0]===400,'Forbidden even null '.$field.' '.$method);
        }
        foreach(['sourceId','resourceDefinitionId'] as $field) foreach([null,0,-1,'1',true,[],2147483648] as $bad) $check($request($method,$suffix,array_replace($valid,[$field=>$bad]))[0]===400,'Strict ID '.$field.' '.$method);
        foreach([null,'progression','unknown',1,[]] as $bad) $check($request($method,$suffix,array_replace($valid,['sourceType'=>$bad]))[0]===400,'Strict source type '.$method);
        foreach([null,0,21,-1,'1',true,[],1.5] as $bad) $check($request($method,$suffix,array_replace($valid,['unlockLevel'=>$bad]))[0]===400,'Strict level '.$method);
        foreach([null,-1,'0',true,[],1.5,2147483648] as $bad) $check($request($method,$suffix,array_replace($valid,['maximumBonus'=>$bad]))[0]===400,'Strict bonus '.$method);
        foreach(['[]','null','42','{bad'] as $bad) $check($request($method,$suffix,$bad)[0]===400,'Object JSON '.$method);
    }
    foreach(['resourceDefinitionId','sourceType','sourceId','unlockLevel'] as $field) {
        $bad=$valid;unset($bad[$field]);$check($request('POST','',$bad)[0]===400,'Required '.$field);
    }
    $withoutBonus=$valid;unset($withoutBonus['maximumBonus']);$editable=$create($withoutBonus);$editId=$editable['id'];
    $check($editable['maximumBonus']===0,'Omitted bonus defaults to zero');
    $check($request('PATCH','/'.$editId,['maximumBonus'=>2147483647])[1]['rule']['maximumBonus']===2147483647,'PostgreSQL integer bonus upper bound');
    $check($request('PATCH','/'.$editId,['maximumBonus'=>0])[0]===200,'Zero bonus PATCH');
    foreach($tables as $type=>$table) $check($request('PATCH','/'.$editId,$payload($type,$sourceIds['o'][$type],$resourceIds['o'],8,3))[0]===200,'Unused family switch '.$type);
    $check($request('PATCH','/'.$editId,['sourceType'=>'class','unlockLevel'=>8])[0]===400,'Family switch needs source ID');
    $check($request('PATCH','/'.$editId,['sourceType'=>'class','sourceId'=>$sourceIds['o']['class']])[0]===400,'Family switch needs level');
    $prior=$row($editId);
    $check($request('PATCH','/'.$editId,$payload('class',$sourceIds['o']['class'],$resourceIds['o'],1,9))[0]===409 && $row($editId)===$prior,'DB collision rolls back mixed PATCH');
    $check($request('PATCH','/'.$editId,['maximumBonus'=>7,'resourceDefinitionId'=>$resourceIds['b']])[0]===400 && $row($editId)===$prior,'Mixed invalid PATCH atomic');
    $check($request('DELETE','/'.$editId)[0]===204,'Delete transitioned rule');

    // ResourceDefinition's existing guard observes the new incoming FK automatically.
    [$status,$body]=$request('POST','/reference/custom/resources',['name'=>'Composition','baseMaximum'=>5]);
    $check($status===201,'Composition resource created');$resource=$body['resource'];$rp='/reference/custom/resources/'.$resource['id'];
    $check($request('PATCH',$rp,['baseMaximum'=>6])[0]===200,'Unused resource mechanics editable');
    $composition=$create($payload('class',$sourceIds['a']['class'],$resource['id']));
    $check($request('PATCH',$rp,['baseMaximum'=>7])[0]===409 && $request('DELETE',$rp)[0]===409,'ResourceRule freezes Resource mechanics/delete');
    $check($request('DELETE','/'.$composition['id'])[0]===204,'Unused ResourceRule removed');
    $check($request('PATCH',$rp,['baseMaximum'=>7])[0]===200,'Resource mechanics released');
    // An independent Feature reference still blocks Resource after Rule deletion.
    [$status,$body]=$request('POST','/reference/custom/features',['name'=>'Independent feature','resourceDefinitionId'=>$resource['id']]);
    $check($status===201,'Independent feature shares resource');$fp='/reference/custom/features/'.$body['feature']['id'];
    $check($request('DELETE',$rp)[0]===409,'Feature FK remains independent usage');
    $check($request('DELETE',$fp)[0]===204 && $request('DELETE',$rp)[0]===204,'All usages removed; Resource deletion allowed');

    // Structural dependencies: same semantics as ReferenceVisibility, no parent mutation.
    foreach(['subclass','race'] as $type) foreach(['foreign','official-custom'] as $scenario) {
        $db->createSavepoint('dependency');$group=$scenario==='foreign'?'a':'o';
        $parentGroup=$scenario==='foreign'?'b':'a';$parentType=$type==='subclass'?'class':'race';
        $column=$type==='subclass'?'character_class_id':'parent_race_id';
        $db->update($tables[$type],[$column=>$sourceIds[$parentGroup][$parentType]],['id'=>$sourceIds[$group][$type]]);
        $check($request('POST','',$payload($type,$sourceIds[$group][$type],$resourceIds['o'],7))[0]===400,'Invalid dependency '.$type.$scenario);
        $check(!in_array($sourceIds[$group][$type],array_column(array_column(array_filter($request('GET')[1]['rules'],static fn($r)=>$r['source']['type']===$type),'source'),'id'),true),'No dependency leak in LIST '.$type.$scenario);
        if($group==='o') $check($request('GET','/'.$rules[$type]['id'])[0]===404,'Invalid dependency GET hidden');
        $em->clear();$check(!ReferenceVisibility::allows($em->find($type==='subclass'?CharacterSubclass::class:CharacterRace::class,$sourceIds[$group][$type]),$a),'ReferenceVisibility parity');
        $db->rollbackSavepoint('dependency');$db->releaseSavepoint('dependency');
    }
    $db->createSavepoint('same_owner_parent');
    $db->update('character_subclass',['character_class_id'=>$sourceIds['o']['class']],['id'=>$sourceIds['a']['subclass']]);
    $create($payload('subclass',$sourceIds['a']['subclass'],$resourceIds['a'],7));
    $check((int)$db->fetchOne('SELECT character_class_id FROM character_subclass WHERE id=?',[$sourceIds['a']['subclass']])===$sourceIds['o']['class'],'CUSTOM subclass accepts OFFICIAL parent without changing it');
    $db->rollbackSavepoint('same_owner_parent');$db->releaseSavepoint('same_owner_parent');
    foreach([['character_class_id'=>null],['feat_id'=>$sourceIds['o']['feat']]] as $invalid) {
        $db->createSavepoint('shape');$rejected=false;
        try{$db->update('trackable_resource_rule',$invalid,['id'=>$rules['class']['id']]);}
        catch(Doctrine\DBAL\Exception\DriverException $e){$rejected=$e->getSQLState()==='23514';}
        finally{$db->rollbackSavepoint('shape');$db->releaseSavepoint('shape');}
        $check($rejected,'DB exactly-one-source CHECK');
    }

    // Persisted acquisitions freeze all four families below unlockLevel as well.
    $em->clear();$a=$em->find(User::class,$aId);$b=$em->find(User::class,$bId);
    $ca=new Campaign($a,'rule-a','A');$cb=new Campaign($b,'rule-b','B');
    $charA=new Character($ca,'rule-a','A',Character::TYPE_PLAYER);$charB=new Character($cb,'rule-b','B',Character::TYPE_PLAYER);
    foreach([$ca,$cb,$charA,$charB] as $entity)$em->persist($entity);$em->flush();$charAId=$charA->getId();$charBId=$charB->getId();
    $acquire=static function(Character $character) use($em,$sourceIds):void {
        $class=$em->find(CharacterClass::class,$sourceIds['o']['class']);
        $level=new CharacterClassLevel($character,$class,1,$em->find(CharacterSubclass::class,$sourceIds['o']['subclass']));
        $feat=new CharacterFeat($character,$em->find(Feat::class,$sourceIds['o']['feat']));
        $character->setRace($em->find(CharacterRace::class,$sourceIds['o']['race']));
        $em->persist($level);$em->persist($feat);$em->flush();
    };
    $acquire($charB);
    foreach($tables as $type=>$table) {
        $id=$rules[$type]['id'];$check($service->usageCount($id,$a)===0,'B acquisitions do not freeze A '.$type);
        $check($request('PATCH','/'.$id,['unlockLevel'=>20,'maximumBonus'=>3])[0]===200,'B acquisitions permit A edit '.$type);
        $spare=$create($payload($type,$sourceIds['o'][$type],$resourceIds['a'],19));
        $check($request('DELETE','/'.$spare['id'])[0]===204,'B acquisitions permit A delete '.$type);
    }
    $em->clear();$charA=$em->find(Character::class,$charAId);$acquire($charA);
    foreach($tables as $type=>$table) {
        $id=$rules[$type]['id'];$prior=$row($id);
        $check($service->usageCount($id,$a)===1,'Own acquisition freezes under level '.$type);
        $check($request('PATCH','/'.$id,$payload($type,$sourceIds['o'][$type],$resourceIds['o'],20,3))[0]===200 && $row($id)===$prior,'Used identical PATCH no-op '.$type);
        $check($request('PATCH','/'.$id,[])[0]===200,'Used empty PATCH '.$type);
        foreach([['maximumBonus'=>4],['unlockLevel'=>19],['resourceDefinitionId'=>$resourceIds['a']],['sourceId'=>$sourceIds['a'][$type]]] as $change) {
            $check($request('PATCH','/'.$id,$change)[0]===409 && $row($id)===$prior,'Used mutation frozen atomically '.$type);
        }
        $check($request('DELETE','/'.$id)[0]===409 && $row($id)===$prior,'Used delete blocked '.$type);
    }
    $em->clear();$child=(new CharacterRace('child-race','Child'))->setParentRace($em->find(CharacterRace::class,$sourceIds['o']['race']));$em->persist($child);$em->flush();
    $db->update('character',['race_id'=>$child->getId()],['id'=>$charAId]);
    $check($service->usageCount($rules['race']['id'],$a)===1 && $request('DELETE','/'.$rules['race']['id'])[0]===409,'Descendant race freezes ancestor rule');

    // Runtime fixtures have dedicated resources to avoid unrelated assignment sums.
    $runtimeIds=[];$customRuntime=[];
    foreach($tables as $type=>$table) {
        $resource=new TrackableResourceDefinition('runtime-'.$type,'Runtime '.$type,ResourceRechargeType::LongRest,ResourceMaximumType::Fixed,5);
        $em->persist($resource);$em->flush();$runtimeIds[$type]=$resource->getId();
        $db->insert('trackable_resource_rule',['resource_definition_id'=>$resource->getId(),$type==='class'?'character_class_id':($type==='subclass'?'character_subclass_id':($type==='race'?'character_race_id':'feat_id'))=>$sourceIds['o'][$type], 'unlock_level'=>1,'maximum_bonus'=>2,'origin'=>'OFFICIAL','owner_id'=>null]);
        $customRuntime[$type]=$create($payload($type,$sourceIds['o'][$type],$resource->getId(),2,3));
    }
    $em->clear();
    $resolver=new CharacterResourceResolver($em->getRepository(TrackableResourceRule::class),new CharacterAbilityCalculator(),new CharacterFeatureResolver($em->getRepository(CharacterFeatureRule::class)));
    $resolve=static function(int $id) use($em,$resolver):array { $em->clear();return $resolver->resolve($em->find(Character::class,$id)); };
    foreach([$charAId,$charBId] as $characterId) {
        $resolved=$resolve($characterId);
        foreach($tables as $type=>$table) $check($resolved['runtime-'.$type]->getMaximum()===7,'Under unlock only base 5 + OFFICIAL 2 '.$type);
        $em->persist(new CharacterClassLevel($em->find(Character::class,$characterId),$em->find(CharacterClass::class,$sourceIds['o']['class']),2));$em->flush();
    }
    $resolvedA=$resolve($charAId);$resolvedB=$resolve($charBId);
    foreach($tables as $type=>$table) {
        $check($resolvedA['runtime-'.$type]->getMaximum()===10 && $resolvedB['runtime-'.$type]->getMaximum()===7,'Additive base 5 + O2 + CUSTOM A3; B only O2 '.$type);
        $check($row($customRuntime[$type]['id'])['maximum_override']===null,'CUSTOM never creates override '.$type);
    }
    $db->insert('trackable_resource_rule',['resource_definition_id'=>$runtimeIds['class'],'character_class_id'=>$sourceIds['o']['class'],'unlock_level'=>2,'maximum_override'=>11,'maximum_bonus'=>0,'origin'=>'OFFICIAL','owner_id'=>null]);
    $check($resolve($charAId)['runtime-class']->getMaximum()===16 && $resolve($charBId)['runtime-class']->getMaximum()===13,'OFFICIAL override 11 then all bonuses: A16 B13');
    // Zero bonus can grant the definition itself; it is not a no-op assignment.
    $zeroResource=new TrackableResourceDefinition('zero-grant','Zero grant',ResourceRechargeType::None,ResourceMaximumType::Fixed,5);$em->persist($zeroResource);$em->flush();
    $create($payload('class',$sourceIds['o']['class'],$zeroResource->getId(),2,0));
    $check($resolve($charAId)['zero-grant']->getMaximum()===5 && !isset($resolve($charBId)['zero-grant']),'Zero bonus grants resource only to A');

    // Historical resource entries cannot identify a contributing Rule. Keep the trace,
    // allow unused Rule deletion, and let ResourceDefinition's existing trace guard act.
    [$status,$body]=$request('POST','/reference/custom/resources',['name'=>'Historical resource','baseMaximum'=>5]);
    $check($status===201,'Historical fixture resource');$historyResource=$body['resource'];
    $history=$create($payload('class',$sourceIds['a']['class'],$historyResource['id'],1,3));
    $em->clear();$charA=$em->find(Character::class,$charAId);
    $acquisition=new CharacterClassLevel($charA,$em->find(CharacterClass::class,$sourceIds['a']['class']),3);$em->persist($acquisition);$em->flush();$acquisitionId=$acquisition->getId();
    $check($resolve($charAId)[$historyResource['slug']]->getMaximum()===8,'Historical rule previously contributed observable bonus');
    $charA=$em->find(Character::class,$charAId);$game=new GameSession($charA->getCampaign(),'history','History');$em->persist($game);
    $state=new CharacterSessionState($game,$charA,['resources'=>[['id'=>$historyResource['slug'],'currentValue'=>8]]]);$em->persist($state);$em->flush();$stateId=$state->getId();
    $stateBefore=$db->fetchOne('SELECT state FROM character_session_state WHERE id=?',[$stateId]);
    $db->delete('character_class_level',['id'=>$acquisitionId]);
    $check($service->usageCount($history['id'],$a)===0,'Source removed: history cannot attribute rule');
    $check($request('PATCH','/'.$history['id'],['maximumBonus'=>4])[0]===200 && $request('DELETE','/'.$history['id'])[0]===204,'History alone does not freeze Rule');
    $check($db->fetchOne('SELECT state FROM character_session_state WHERE id=?',[$stateId])===$stateBefore,'Historical state preserved exactly');
    $check($request('DELETE','/reference/custom/resources/'.$historyResource['id'])[0]===409,'Resource trace guard remains effective');

    $login($b);$check($request('GET','/'.$rules['class']['id'])[0]===404,'B cannot read A');
    $check(array_column($request('GET')[1]['rules'],'id')===array_column($bRules,'id'),'B list only B rules');
    $login(null);
    foreach(['GET','POST','PATCH','DELETE'] as $method) $check(in_array($request($method,in_array($method,['PATCH','DELETE'],true)?'/'.$rules['class']['id']:'',[])[0],[401,403],true),'Anonymous '.$method);
} finally {
    while($db->isTransactionActive())$db->rollBack();
    $em->clear();$check($before===$snapshot(),'All public fingerprints unchanged');$kernel->shutdown();
}
echo "OK: $checks custom resource rule CRUD assertions; owner1 READ ONLY; isolated fixtures rolled back.\n";
