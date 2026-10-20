<?php
declare(strict_types=1);
require __DIR__.'/../vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv(__DIR__.'/../.env');
putenv('SHELL_VERBOSITY=-1'); $_SERVER['SHELL_VERBOSITY']=$_ENV['SHELL_VERBOSITY']=-1;
$kernel=new App\Kernel('dev',true); $kernel->boot();
$registry=$kernel->getContainer()->get('doctrine'); $em=$registry->getManager(); $db=$em->getConnection(); $checks=0;
$check=static function(bool $ok,string $message) use (&$checks):void { if(!$ok) throw new RuntimeException($message); ++$checks; };
try {
    $db->beginTransaction();
    foreach ((new Doctrine\ORM\Tools\SchemaTool($em))->getCreateSchemaSql($em->getMetadataFactory()->getAllMetadata()) as $sql) $db->executeStatement(preg_replace(['/^CREATE TABLE /','/^CREATE SEQUENCE /'],['CREATE TEMP TABLE ','CREATE TEMP SEQUENCE '],$sql));
    foreach ($em->getMetadataFactory()->getAllMetadata() as $m) $check($db->fetchOne('SELECT relpersistence FROM pg_class WHERE oid=to_regclass(?)',[$m->getTableName()])==='t','Temporary '.$m->getTableName());
    $owner=(new App\Entity\User())->setEmail('race-owner@example.invalid')->setPassword('unused');
    $other=(new App\Entity\User())->setEmail('race-other@example.invalid')->setPassword('unused');
    $admin=(new App\Entity\User())->setEmail('race-admin@example.invalid')->setPassword('unused')->setRoles([App\Service\SpellCatalogue::ADMIN_ROLE]);
    foreach ([$owner,$other,$admin] as $u) $em->persist($u); $em->flush();
    $service=new App\Service\CustomReferenceCatalogueService($em);
    $fixture=['slug'=>'race-fixture','name'=>'Fixture','walkingSpeed'=>9.0,'languages'=>['common'],'movementSpeeds'=>['swim'=>6.0],'abilityModifiers'=>[['ability'=>'strength','value'=>2,'choiceKey'=>null]]];
    $official=$service->saveRace(null,$fixture,$admin,true);
    $own=$service->create('races',array_replace($fixture,['slug'=>'personal-race','parentRaceId'=>$official['id']]),$owner);
    $foreign=$service->create('races',array_replace($fixture,['slug'=>'foreign-race']),$other);
    $check($official['origin']==='OFFICIAL' && $own['origin']==='CUSTOM','Origin imposed by scope');
    $check(count($service->races($owner)['races'])===2,'Official plus own only');
    $check(count($service->races($admin,true)['races'])===1,'Official administration excludes CUSTOM');
    $check($service->races($owner)['races'][0]['editable']===false,'Official read only for MJ');
    $check($own['effectiveMetadata']['walkingSpeed']===9.0 && count($own['inheritedAbilityModifiers'])===2,'Metadata and inherited modifiers preserved');
    $saved=$service->patch('races',$own['id'],['description'=>'Editorial','senses'=>['darkvision'=>18.0]],$owner);
    $check($saved['metadata']['senses']['darkvision']===18.0 && $saved['abilityModifiers'][0]['id']===$own['abilityModifiers'][0]['id'],'Patch preserves relation identity');
    $added=$service->addRaceModifier($own['id'],['ability'=>null,'value'=>1,'choiceKey'=>'choice-1'],$owner);
    $check(count($added['abilityModifiers'])===2,'Choice modifier supported');
    $class=$service->saveEditor('classes',null,['slug'=>'official-class','name'=>'Clerc','hitDie'=>8,'subclassSelectionLevel'=>3,'spellcastingProgression'=>'full'],$admin,true);
    $subclass=$service->create('subclasses',['slug'=>'own-subclass','name'=>'Clerc des Brumes','characterClassId'=>$class['id'],'spellcastingProgression'=>null],$owner);
    $check($subclass['classId']===$class['id'] && $subclass['spellcastingProgression']===null,'Custom subclass of official class preserves null progression');
    $persisted=$db->fetchAssociative('SELECT character_class_id, origin, owner_id FROM character_subclass WHERE id=?',[$subclass['id']]);
    $check((int)$persisted['character_class_id']===$class['id'] && $persisted['origin']==='CUSTOM' && (int)$persisted['owner_id']===$owner->getId(),'Clerc des Brumes persists its official parent and personal ownership');
    $feat=$service->create('feats',['slug'=>'own-feat','name'=>'Feat','requiresAbilityChoice'=>true,'chosenAbilityIncrease'=>1,'allowedAbilities'=>['strength','wisdom']],$owner);
    $check($feat['allowedAbilities']===['strength','wisdom'],'Feat ability restrictions roundtrip');
    $updated=$service->saveEditor('feats',$feat['id'],['description'=>'Edited'],$owner,false);
    $check($updated['allowedAbilities']===$feat['allowedAbilities'],'Editorial patch preserves restrictions');
    $check(count($service->editorList('classes',$owner,false)['classes'])===1,'Class visible in personal editor');
    $privateClass=$service->create('classes',['slug'=>'private-parent','name'=>'Private parent','hitDie'=>8],$other);
    $check($service->editorList('subclasses',$admin,true)['subclasses']===[],'Admin excludes personal subclasses');
    $progression=$service->create('progressions',['slug'=>'own-progression','name'=>'Progression','minimumValue'=>0,'maximumValue'=>20,'bulkAdjustmentEnabled'=>true],$owner);
    $check($progression['bulkAdjustmentEnabled']===true && $progression['stages']===[],'Progression bulk flag and relations');
    $withStage=$service->progressionChild($progression['id'],'stages',null,['label'=>'Stage','minimumValue'=>0,'maximumValue'=>2],$owner,false);
    $stageId=$withStage['stages'][0]['id'];
    $moved=$service->progressionChild($progression['id'],'stages',$stageId,['minimumValue'=>5,'maximumValue'=>8],$owner,false);
    $check($moved['stages'][0]['id']===$stageId && $moved['stages'][0]['minimumValue']===5,'Stage range update is atomic and preserves identity');
    $withRule=$service->progressionChild($progression['id'],'adjustment-rules',null,['direction'=>'gain','description'=>'Narrative','adjustmentLabel'=>'+1'], $owner,false);
    $check(count($withRule['adjustmentRules'])===1,'Descriptive progression rule');
    $resources=new App\Service\CustomReferenceResourceService($db);
    $features=new App\Service\CustomReferenceFeatureService($db);
    $featureRules=new App\Service\CustomReferenceFeatureRuleService($db);
    $resourceRules=new App\Service\CustomReferenceResourceRuleService($db);
    $resource=$resources->saveEditor(null,['slug'=>'official-pool','name'=>'Pool','maximumType'=>'fixed','baseMaximum'=>3],$admin,true);
    $feature=$features->saveEditor(null,['slug'=>'official-power','name'=>'Power','activationType'=>'reaction','visible'=>true,'resourceDefinitionId'=>$resource['id']],$admin,true);
    $check($feature['activationType']==='reaction' && $feature['resourceDefinition']['baseMaximum']===3,'Advanced feature/resource contract');
    $fr=$featureRules->saveEditor(null,['featureDefinitionId'=>$feature['id'],'sourceType'=>'progression','sourceId'=>$progression['id'],'unlockLevel'=>null,'progressionThreshold'=>0,'displayOrder'=>4],$owner,false);
    $check($fr['progressionThreshold']===0 && $fr['unlockLevel']===null && $fr['displayOrder']===4,'Progression threshold zero and display order preserved');
    $rr=$resourceRules->saveEditor(null,['resourceDefinitionId'=>$resource['id'],'sourceType'=>'subclass','sourceId'=>$subclass['id'],'unlockLevel'=>3,'maximumOverride'=>5,'maximumBonus'=>1],$owner,false);
    $rr2=$resourceRules->saveEditor($rr['id'],['maximumOverride'=>6],$owner,false);
    $check($rr2['id']===$rr['id'] && $rr2['maximumOverride']===6 && $rr2['maximumBonus']===1,'Resource override survives patch with stable ID');
    $check($resourceRules->editorList($other,false,$resource['id'])['rules']===[],'Personal rules hidden from other users');
    $check($features->editorList($owner,false)['features'][0]['editable']===false,'Official feature visible but read-only');
    $session=new Symfony\Component\HttpFoundation\Session\Session(new Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage()); $session->start();
    $request=static function(?App\Entity\User $user,string $method,string $path,?array $payload=null) use ($kernel,$session) {
        if ($user) $session->set('_security_main',serialize(new Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken($user,'main',$user->getRoles()))); else $session->remove('_security_main');
        $r=Symfony\Component\HttpFoundation\Request::create($path,$method,[],[$session->getName()=>$session->getId()],[],['CONTENT_TYPE'=>'application/json','HTTP_ACCEPT'=>'application/json'],$payload===null?null:json_encode((object)$payload));
        $r->setSession($session); return $kernel->handle($r);
    };
    $response=$request($owner,'GET','/reference/custom/races/editor');
    $check($response->getStatusCode()===200 && count(json_decode($response->getContent(),true)['races'])===2,'HTTP personal catalogue');
    $response=$request($owner,'POST','/reference/custom/races/editor',array_replace($fixture,['slug'=>'http-race','senses'=>['darkvision'=>18.0]]));
    $check($response->getStatusCode()===201 && json_decode($response->getContent(),true)['race']['metadata']['senses']['darkvision']===18,'HTTP nested metadata survives decoding');
    $check($request($owner,'GET','/reference/official/races/editor')->getStatusCode()===403,'HTTP official denied to MJ');
    $check($request($admin,'GET','/reference/official/races/editor')->getStatusCode()===200,'HTTP official admin catalogue');
    foreach (['classes','subclasses','feats','progressions','features','resources','feature-rules','resource-rules'] as $category) {
        $check($request($owner,'GET','/reference/custom/'.$category.'/editor')->getStatusCode()===200,'HTTP personal '.$category);
        $check($request($owner,'GET','/reference/official/'.$category.'/editor')->getStatusCode()===403,'HTTP admin required '.$category);
        $check($request($admin,'GET','/reference/official/'.$category.'/editor')->getStatusCode()===200,'HTTP official '.$category);
    }
    foreach (['backgrounds','spells'] as $category) {
        $check($request($owner,'GET','/reference/custom/'.$category)->getStatusCode()===200,'Preserved personal '.$category);
        $check($request($owner,'GET','/reference/official/'.$category)->getStatusCode()===403,'Official '.$category.' requires role');
        $check($request($admin,'GET','/reference/official/'.$category)->getStatusCode()===200,'Official '.$category.' list');
    }
    $response=$request($admin,'POST','/reference/official/backgrounds',['slug'=>'official-background','name'=>'Background','description'=>'Fiction']);
    $check($response->getStatusCode()===201,'HTTP official background creation');
    $background=json_decode($response->getContent(),true)['background'];
    $check($background['origin']==='OFFICIAL' && !$background['mechanicsLocked'],'Unused official background mechanics editable');
    $check($request($owner,'PATCH','/reference/custom/backgrounds/'.$background['id'],['name'=>'No'])->getStatusCode()===404,'MJ cannot mutate official background');
    $response=$request($admin,'POST','/reference/official/spells',['slug'=>'official-spell','name'=>'Fiction','level'=>0,'school'=>'illusion','castingTime'=>'Test','range'=>'Test','duration'=>'Test','description'=>'Fiction']);
    $check($response->getStatusCode()===201,'HTTP official spell creation');
    $spell=json_decode($response->getContent(),true)['spell'];
    $check($request($owner,'PATCH','/reference/custom/spells/'.$spell['id'],['name'=>'No'])->getStatusCode()===403,'MJ cannot mutate official spell');
    $check(in_array($request(null,'GET','/reference/custom/races/editor')->getStatusCode(),[401,403],true),'HTTP anonymous refused');
    $owner=$em->find(App\Entity\User::class,$owner->getId());
    $campaign=new App\Entity\Campaign($owner,'fixture-campaign','Fixture');
    $character=new App\Entity\Character($campaign,'fixture-character','Fixture',App\Entity\Character::TYPE_PLAYER);
    $character->setRace($em->find(App\Entity\CharacterRace::class,$own['id']));
    $em->persist($campaign); $em->persist($character); $em->flush();
    $beforeCharacter=$db->fetchOne('SELECT row_to_json(c)::text FROM character c WHERE id=?',[$character->getId()]);
    $unchanged=$service->saveRace($own['id'],['walkingSpeed'=>9,'movementSpeeds'=>['swim'=>6],'description'=>'Editorial'],$owner);
    $check($unchanged['metadata']['walkingSpeed']===9.0,'Used race accepts unchanged JSON numeric speeds');
    $cases=[
        [fn($s,$u)=>$s->create('subclasses',['slug'=>'forged-parent','name'=>'Forbidden','characterClassId'=>$privateClass['id']],$u),$owner->getId(),400],
        [fn($s,$u)=>$s->saveRace($own['id'],['walkingSpeed'=>12],$u),$owner->getId(),409],
        [fn($s,$u)=>$s->saveRace($official['id'],['walkingSpeed'=>12],$u,true),$admin->getId(),409],
        [fn($s,$u)=>$s->addRaceModifier($own['id'],['ability'=>'wisdom','value'=>1],$u),$owner->getId(),409],
        [fn($s,$u)=>$features->saveEditor($feature['id'],['visible'=>null],$u,true),$admin->getId(),400],
        [fn($s,$u)=>$features->saveEditor($feature['id'],['activationType'=>null],$u,true),$admin->getId(),400],
        [fn($s,$u)=>$features->saveEditor($feature['id'],['name'=>'No'],$u,false),$owner->getId(),403],
        [fn($s,$u)=>$resources->saveEditor($resource['id'],['name'=>'No'],$u,false),$owner->getId(),403],
        [fn($s,$u)=>$featureRules->saveEditor($fr['id'],['displayOrder'=>9],$u,false),$other->getId(),404],
        [fn($s,$u)=>$featureRules->saveEditor(null,['featureDefinitionId'=>$feature['id'],'sourceType'=>'progression','sourceId'=>$progression['id'],'unlockLevel'=>null,'progressionThreshold'=>0],$u,true),$admin->getId(),400],
        [fn($s,$u)=>$resourceRules->editorList($u,true),$owner->getId(),403],
        [fn($s,$u)=>$s->progressionChild($progression['id'],'stages',null,['label'=>'Overlap','minimumValue'=>8,'maximumValue'=>10],$u,false),$owner->getId(),409],
        [fn($s,$u)=>$s->progressionChild($progression['id'],'stages',$stageId,[], $u,false,true),$other->getId(),404],
        [fn($s,$u)=>$s->saveEditor('classes',$class['id'],['hitDie'=>10],$u,true),$admin->getId(),409],
        [fn($s,$u)=>$s->saveEditor('classes',$class['id'],['name'=>'No'],$u,false),$owner->getId(),403],
        [fn($s,$u)=>$s->saveEditor('feats',$feat['id'],['name'=>'No'],$u,false),$other->getId(),404],
        [fn($s,$u)=>$s->saveRace($official['id'],['name'=>'No'],$u),$owner->getId(),403],
        [fn($s,$u)=>$s->saveRace($foreign['id'],['name'=>'No'],$u),$owner->getId(),404],
        [fn($s,$u)=>$s->saveRace($foreign['id'],['name'=>'No'],$u,true),$admin->getId(),404],
        [fn($s,$u)=>$s->races($u,true),$owner->getId(),403],
        [fn($s,$u)=>$s->saveRace(null,array_replace($fixture,['slug'=>'forged','owner'=>123]),$u),$owner->getId(),400],
        [fn($s,$u)=>$s->saveRace(null,array_replace($fixture,['slug'=>'bad-parent','parentRaceId'=>$foreign['id']]),$u),$owner->getId(),400],
        [fn($s,$u)=>$s->saveRace($foreign['id'],['name'=>'Partial','walkingSpeed'=>-1],$u),$other->getId(),400],
    ];
    foreach ($cases as [$operation,$actorId,$status]) {
        try { $operation($service,$em->find(App\Entity\User::class,$actorId)); throw new RuntimeException('Expected refusal'); }
        catch (Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) { $check($e->getStatusCode()===$status,'Expected status '.$status.', got '.$e->getStatusCode().': '.$e->getMessage()); }
        catch (InvalidArgumentException) { $check($status===400,'Domain validation'); }
        if (!$em->isOpen()) { $registry->resetManager(); $em=$registry->getManager(); $service=new App\Service\CustomReferenceCatalogueService($em); }
    }
    $check($db->fetchOne('SELECT name FROM character_race WHERE id=?',[$own['id']])==='Fixture','Rejected mutation leaves data intact');
    $check($db->fetchOne('SELECT row_to_json(c)::text FROM character c WHERE id=?',[$character->getId()])===$beforeCharacter,'Reference edits preserve existing character data');
    echo "OK: $checks race editor assertions; temporary tables only.\n";
} finally { while($db->isTransactionActive()) $db->rollBack(); $kernel->shutdown(); }
