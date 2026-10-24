<?php

declare(strict_types=1);

// Synthetic integration fixtures only; all ORM tables/sequences are temporary.
use App\Entity\{User, Campaign, Character, CharacterClass, CharacterSubclass, CharacterClassLevel, CharacterSpellAcquisition, SpellcastingPolicyDefinition, CharacterFeatureDefinition, CharacterFeatureRule, TrackableResourceDefinition, SpellDefinition};
use App\Enum\{Ability, SpellcastingProgressionType, ResourceRechargeType};
use App\Service\{CharacterAbilityCalculator, CharacterFeatureResolver, CharacterResourceResolver, CharacterSpellAccessResolver, CharacterSpellAcquisitions, CharacterSpellPreparation, CharacterFeatureSpellService, PlayerSpellDirectory, SpellCatalogue, SpellSubclassGrants};
use App\Repository\{CharacterFeatureRuleRepository, TrackableResourceRuleRepository};

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
    $metadata = $em->getMetadataFactory()->getAllMetadata();
    foreach ((new Doctrine\ORM\Tools\SchemaTool($em))->getCreateSchemaSql($metadata) as $sql) {
        $db->executeStatement(preg_replace(['/^CREATE TABLE /','/^CREATE SEQUENCE /'],['CREATE TEMP TABLE ','CREATE TEMP SEQUENCE '],$sql));
    }
    foreach (['feature_spell_grant','character_feature_spell'] as $table) {
        $db->executeStatement('CREATE TEMP TABLE '.$table.' (LIKE public.'.$table.' INCLUDING DEFAULTS INCLUDING CONSTRAINTS)');
    }
    $tables = [...array_map(static fn($meta)=>$meta->getTableName(),$metadata),'feature_spell_grant','character_feature_spell'];
    foreach ($tables as $table) $check($db->fetchOne('SELECT relpersistence FROM pg_class WHERE oid=to_regclass(?)',[$table])==='t','Temporary isolation: '.$table);
    $owner = (new User())->setEmail('directory@example.invalid')->setPassword('unused')->setRoles([SpellCatalogue::ADMIN_ROLE]);
    $campaign = new Campaign($owner,'directory-test','Directory test');
    $character = new Character($campaign,'directory-test','Directory test',Character::TYPE_PLAYER);
    foreach ([$owner,$campaign,$character] as $entity) $em->persist($entity);
    $classes=[]; $levels=[]; $policies=[];
    foreach (['wizard'=>'spellbook','bard'=>'known','cleric'=>'prepared'] as $slug=>$style) {
        $classes[$slug] = new CharacterClass($slug,'Intentionally identical display name',6,1,SpellcastingProgressionType::Full);
        $subclass = $slug==='wizard' ? new CharacterSubclass($classes[$slug],'fixture-subclass','Fixture subclass') : null;
        if ($subclass) { $sub=$subclass; $em->persist($subclass); }
        $levels[$slug] = new CharacterClassLevel($character,$classes[$slug],count($levels)+1,$subclass);
        $character->addClassLevel($levels[$slug]);
        $policy = new App\Dto\SpellcastingPolicy('fixture-'.$slug,1,Ability::Intelligence,$style,
            array_fill(0,20,['maxSpellLevel'=>1,'cantrips'=>2,'known'=>$style==='known'?2:0,
                'bookAdditions'=>$style==='spellbook'?2:0,'replacements'=>0,'preparationBase'=>$style==='known'?null:1]),'Synthetic fixture');
        // A subclass-owned wizard policy must still resolve the parent class slug.
        $policies[$slug] = new SpellcastingPolicyDefinition($subclass ?? $classes[$slug],$policy);
        foreach ([$classes[$slug],$levels[$slug],$policies[$slug]] as $entity) $em->persist($entity);
    }
    $feature = new CharacterFeatureDefinition('directory-feature','Independent capability');
    $resource = new TrackableResourceDefinition('directory-charge','Grant charge',ResourceRechargeType::LongRest);
    $progression = new App\Entity\ProgressionDefinition('directory-progress','Grant progression');
    $assignment = new App\Entity\CharacterProgression($character,$progression);
    $character->addProgression($assignment);
    $resource->setBaseMaximum(2);
    $progressionFeature = new CharacterFeatureDefinition('directory-progress-feature','Progression resource');
    $progressionFeature->setResourceDefinition($resource);
    foreach ([$feature,CharacterFeatureRule::forClass($feature,$classes['wizard'],1),$resource,
        $progression,$assignment,$progressionFeature,CharacterFeatureRule::forProgression($progressionFeature,$progression,3)] as $entity) $em->persist($entity);
    $em->flush();
    $catalogue = new SpellCatalogue($db);
    $spell = $catalogue->create(['slug'=>'directory-ritual','name'=>'Shared ritual','level'=>1,'school'=>'divination',
        'castingTime'=>'1 action','range'=>'Self','duration'=>'1 minute','description'=>'Fixture','ritual'=>true,
        'classIds'=>array_map(static fn($class)=>$class->getId(),array_values($classes))],$owner,true);
    $spellEntity = $em->find(SpellDefinition::class,$spell['id']);
    $cantrips=[];
    foreach (['known','unknown'] as $kind) {
        $cantrips[$kind]=$catalogue->create(['slug'=>'directory-'.$kind,'name'=>$kind.' cantrip','level'=>0,'school'=>'divination',
            'castingTime'=>'1 action','range'=>'Self','duration'=>'1 minute','description'=>'Cantrip fixture','ritual'=>false,
            'classIds'=>[$classes['bard']->getId()]],$owner,true);
    }
    $em->persist(new CharacterSpellAcquisition($character,$em->find(SpellDefinition::class,$cantrips['known']['id']),
        $policies['bard'],$levels['bard'],$owner,'manual-reconciliation','cantrips','Fixture'));
    foreach (['wizard'=>'spellbook','bard'=>'known'] as $slug=>$bucket) {
        $em->persist(new CharacterSpellAcquisition($character,$spellEntity,$policies[$slug],$levels[$slug],$owner,'manual-reconciliation',$bucket,'Fixture'));
    }
    $em->flush();
    $grants = new SpellSubclassGrants($db,$catalogue);
    foreach (['grant-known','grant-always-prepared'] as $mode) {
        $grants->save($spell['id'],null,['subclassId'=>$sub->getId(),'mode'=>$mode,'unlockLevel'=>1,
            'castingClassId'=>$classes['bard']->getId(),'quotaImpact'=>'free'],$owner,true);
    }
    $grants->save($spell['id'],null,['subclassId'=>$sub->getId(),'mode'=>'grant-cast','unlockLevel'=>1,
        'castingClassId'=>$classes['bard']->getId(),'slotUsage'=>'required','restrictions'=>'Only under a specific condition',
        'resourceId'=>$resource->getId(),'resourceCost'=>1],$owner,true);
    // Explicit IDs avoid invoking real defaults/sequences on these non-ORM tables.
    $db->insert('feature_spell_grant',['id'=>1,'feature_id'=>$feature->getId(),'spell_id'=>$spell['id'],'mode'=>'fixed','unlock_level'=>1,'casting_ability'=>'INT']);
    $db->insert('character_feature_spell',['id'=>1,'character_id'=>$character->getId(),'grant_id'=>1,'spell_id'=>$spell['id']]);
    $ability = new CharacterAbilityCalculator();
    $features = new CharacterFeatureResolver(new CharacterFeatureRuleRepository($registry));
    $resources = new CharacterResourceResolver(new TrackableResourceRuleRepository($registry),$ability,$features);
    $access = new CharacterSpellAccessResolver($catalogue,$grants,$resources);
    $acquisitions = new CharacterSpellAcquisitions($em,$access,$ability);
    $definitions = new class($registry) extends App\Repository\TrackableResourceDefinitionRepository {
        public int $reads = 0;
        public function findVisibleBySlugs(array $slugs, User $owner): array {
            ++$this->reads;
            return parent::findVisibleBySlugs($slugs, $owner);
        }
    };
    $slotCalculator = new App\Service\CharacterSpellSlotCalculator();
    $contextBuilder = new App\Service\CharacterSpellAvailabilityContext($slotCalculator,
        new App\Service\CharacterSpellSlotStateService($slotCalculator),$resources,$definitions);
    $directory = new PlayerSpellDirectory($db,$em,new CharacterFeatureSpellService($db,$features,$ability),$ability,
        $access,$acquisitions,new CharacterSpellPreparation($acquisitions,$ability),$catalogue,$contextBuilder);
    $state=['preparedSpells'=>[$policies['cleric']->getId()=>[$spell['id']]],'spellPreparationAllowed'=>false,
        'progressions'=>[['id'=>'directory-progress','currentValue'=>3]],
        'resources'=>[['id'=>'spell-slot-1','currentValue'=>0],['id'=>'directory-charge','currentValue'=>0]]];
    $stateBefore=$state;
    $snapshot = static function () use ($db,$tables): array {
        $result=[];
        foreach ($tables as $table) $result[$table]=$db->fetchOne('SELECT md5(COALESCE(string_agg(to_jsonb(t)::text,\'\' ORDER BY to_jsonb(t)::text),\'\')) FROM '.$db->quoteIdentifier($table).' t');
        return $result;
    };
    $before=$snapshot();
    $entries=$directory->forCharacter($character,$state);
    $check($definitions->reads===1,'Context built once for all directory sources');
    $check(count($entries)===8,'Seven ritual sources plus one learned cantrip');
    $check(!in_array($cantrips['unknown']['id'],array_column($entries,'spellId'),true),'Unlearned catalogue cantrip is absent from directory');
    $paths=array_column($access->resolve($character,['directory-progress'=>3]),null,'key');
    foreach ($entries as $entry) {
        $check(array_keys($entry['castingEligibility'])===['canCastNormally','canCastAsRitual','reason','ritualReason','requiresGrantReview'],'Eligibility API shape');
        foreach (['key','policyId','alwaysPrepared','spellId','name','level','school','source','sourceType','castingAbility','spellSaveDc','spellAttackBonus','known','inSpellbook','prepared','details'] as $field) $check(array_key_exists($field,$entry),'Existing contract: '.$field);
        $decision=$entry['castingEligibility'];
        $availability=$entry['castingAvailability'];
        $check(array_keys($availability)===['sourceKey','policyId','status','reason','methods'],'Availability contract');
        $check($availability['sourceKey']===$entry['key'] && $availability['policyId']===$entry['policyId'],'Availability preserves source identity');
        $check($availability['status']===($entry['sourceType']==='class'?'available':'unknown'),'Cantrip/ritual available, unresolved grants unknown');
        $isCantrip=$entry['spellId']===$cantrips['known']['id'];
        foreach (['spellId'=>$isCantrip?$cantrips['known']['id']:$spell['id'],'name'=>$isCantrip?'known cantrip':'Shared ritual',
            'level'=>$isCantrip?0:1,'school'=>'divination','spellSaveDc'=>$entry['sourceType']==='subclass'?null:10,
            'spellAttackBonus'=>$entry['sourceType']==='subclass'?null:2] as $field=>$expected) {
            $check($entry[$field]===$expected,'Existing value and type: '.$field);
        }
        $check(is_string($entry['key']) && $entry['key']!=='','Stable string identity');
        $check($entry['details']['description']===($isCantrip?'Cantrip fixture':'Fixture')
            && $entry['details']['ritual']===!$isCantrip && $entry['details']['castingTime']==='1 action'
            && $entry['details']['range']==='Self' && $entry['details']['duration']==='1 minute','Existing detail values and types');
        foreach (['higherLevels'=>null,'verbal'=>false,'somatic'=>false,'material'=>false,
            'materialDescription'=>null,'concentration'=>false] as $field=>$expected) {
            $check(array_key_exists($field,$entry['details']) && $entry['details'][$field]===$expected,'Existing detail value and type: '.$field);
        }
        if ($entry['sourceType']==='class') {
            $wizard=$entry['policyId']===$policies['wizard']->getId();
            $bard=$entry['policyId']===$policies['bard']->getId();
            $check(is_int($entry['policyId']) && $entry['alwaysPrepared']===false && $entry['castingAbility']==='intelligence'
                && $entry['source']===($wizard?'Fixture subclass':'Intentionally identical display name')
                && $entry['known']===$bard && $entry['inSpellbook']===$wizard
                && $entry['prepared']===($wizard?false:($bard?null:true)),'Class legacy fields retain exact values and types');
            $check($decision['canCastNormally']===!$wizard && $decision['canCastAsRitual']===!$isCantrip,'Exact policy and cantrip eligibility');
            if ($wizard) $check($decision['reason']==='spell_not_prepared' && $entry['prepared']===false,'Unprepared wizard book ritual');
        } else {
            $check(!$decision['canCastNormally'] && !$decision['canCastAsRitual'] && $decision['requiresGrantReview'],'No automatic grant permission');
            $check($entry['policyId']===null,'Grant is not a class acquisition');
            $subclass=$entry['sourceType']==='subclass';
            $always=$subclass && $entry['mode']==='grant-always-prepared';
            $check($entry['source']===($subclass?'Fixture subclass':'Independent capability')
                && $entry['castingAbility']===($subclass?null:'intelligence') && $entry['alwaysPrepared']===$always
                && $entry['known']===(!$subclass || $entry['mode']==='grant-known')
                && $entry['inSpellbook']===false && $entry['prepared']===($subclass?$always:null),'Grant legacy fields retain exact values and types');
            if ($entry['sourceType']==='subclass') {
                if ($entry['mode']==='grant-cast') {
                    $check($entry['resource']['resolvedForCharacter']===true && $entry['resource']['maximum']===2,'Session progression unlocks grant resource at threshold');
                }
                foreach (['mode','slotUsage','restrictions','resource','castingClass','quotaImpact'] as $field) $check($entry[$field]===$paths[$entry['key']]->$field,'Grant metadata preserved: '.$field);
            }
        }
    }
    $check($state===$stateBefore,'Directory does not mutate any supplied session field');
    foreach ([null,0,2,3,4] as $value) {
        $context=$stateBefore;
        unset($context['progressions']);
        if ($value!==null) $context['progressions']=[['id'=>'directory-progress','currentValue'=>$value]];
        foreach ($directory->forCharacter($character,$context) as $entry) {
            if (($entry['mode']??null)!=='grant-cast') continue;
            $active=$value!==null && $value>=3;
            $check($entry['resource']['resolvedForCharacter']===$active && $entry['resource']['maximum']===($active?2:null),'Explicit progression context: '.var_export($value,true));
            $check($entry['castingEligibility']['requiresGrantReview']===true
                && $entry['castingEligibility']['canCastNormally']===false
                && $entry['resource']['current']===null,'Unlocked resource is not casting permission or an implicit counter');
        }
    }
    $state['preparedSpells'][$policies['wizard']->getId()]=[$spell['id']];
    $changed=$directory->forCharacter($character,$state);
    foreach ($changed as $entry) if ($entry['policyId']===$policies['wizard']->getId()) $check($entry['castingEligibility']['canCastNormally'],'Prepared wizard now eligible');
    $check($directory->forCharacter($character,$stateBefore)===$entries,'Repeat read is stable and does not mutate preparation');
    $check($snapshot()===$before,'Directory reads leave all stored tables unchanged');
    $check($stateBefore['resources']===$state['resources'],'No slot or grant resource consumption');
    // An already recorded grant remains stored when its session progression drops.
    $db->insert('feature_spell_grant',['id'=>2,'feature_id'=>$progressionFeature->getId(),'spell_id'=>$spell['id'],'mode'=>'fixed','unlock_level'=>1,'casting_ability'=>'INT']);
    $db->insert('character_feature_spell',['id'=>2,'character_id'=>$character->getId(),'grant_id'=>2,'spell_id'=>$spell['id']]);
    $beforeProgressionReads=$snapshot();
    foreach ([null,0,2,3,4,2,3] as $value) {
        $context=$stateBefore;
        unset($context['progressions']);
        if ($value!==null) $context['progressions']=[['id'=>'directory-progress','currentValue'=>$value]];
        $originalContext=$context;
        $byKey=array_column($directory->forCharacter($character,$context),null,'key');
        $active=$value!==null && $value>=3;
        $check(isset($byKey['feature:2'])===$active,'Progression feature spell visible exactly at threshold: '.var_export($value,true));
        $check(isset($byKey['feature:1']),'Class feature spell unaffected by progression');
        if ($active) {
            $entry=$byKey['feature:2'];
            $check($entry['sourceType']==='feature' && $entry['policyId']===null && $entry['spellId']===$spell['id'],'Progression grant keeps independent source');
            $check($entry['castingEligibility']===$byKey['feature:1']['castingEligibility'],'Progression grant retains conservative eligibility');
        }
        $check($context===$originalContext,'Progression read preserves complete supplied state');
    }
    $check($snapshot()===$beforeProgressionReads,'Progression reads never acquire, retire, prepare or consume');
    // A non-ritual spell exposes actual pool availability without a ritual fallback.
    $ordinary=$catalogue->create(['slug'=>'directory-ordinary','name'=>'Ordinary spell','level'=>1,'school'=>'divination',
        'castingTime'=>'1 action','range'=>'Self','duration'=>'1 minute','description'=>'Fixture','ritual'=>false,
        'classIds'=>[$classes['bard']->getId()]],$owner,true);
    $em->persist(new CharacterSpellAcquisition($character,$em->find(SpellDefinition::class,$ordinary['id']),
        $policies['bard'],$levels['bard'],$owner,'manual-reconciliation','known','Fixture'));
    $warlock=new CharacterClass('warlock','Warlock',8,1,SpellcastingProgressionType::Pact);
    $pact=new TrackableResourceDefinition('warlock-pact-slots','Pact',ResourceRechargeType::ShortRest);
    $warlockLevel=new CharacterClassLevel($character,$warlock,4);
    $character->addClassLevel($warlockLevel);
    foreach ([$warlock,$warlockLevel,$pact,App\Entity\TrackableResourceRule::forClass($pact,$warlock,1,1)] as $entity) $em->persist($entity);
    $em->flush();
    $beforeAvailabilityReads=$snapshot();
    $baselineLegacy=null;
    foreach ([[0,0,'unavailable'],[1,0,'available'],[0,1,'available'],[null,0,'unknown'],['1',0,'unknown']] as [$standard,$pactRemaining,$expected]) {
        $context=$stateBefore;
        $context['resources']=[['id'=>'spell-slot-2','currentValue'=>0],['id'=>'warlock-pact-slots','currentValue'=>$pactRemaining]];
        if ($standard!==null) $context['resources'][]=['id'=>'spell-slot-1','currentValue'=>$standard];
        $original=$context; $reads=$definitions->reads;
        $rows=$directory->forCharacter($character,$context);
        $check($definitions->reads===$reads+1,'One context build per changed session snapshot');
        $check($context===$original,'Availability read preserves supplied session state');
        $legacy=array_map(static fn($row)=>array_diff_key($row,['castingAvailability'=>true]),$rows);
        $baselineLegacy??=$legacy;
        $check($legacy===$baselineLegacy,'All legacy values/types including eligibility and grant metadata unchanged across resource changes');
        $row=array_values(array_filter($rows,static fn($row)=>$row['spellId']===$ordinary['id']))[0];
        $check($row['castingAvailability']['status']===$expected,'Availability follows session counters: '.$expected);
        if ($expected==='available') {
            $usable=array_values(array_filter($row['castingAvailability']['methods'],static fn($method)=>$method['status']==='available'));
            $check(count($usable)===1 && $usable[0]['kind']===($pactRemaining?'pact-slot':'standard-slot'),'Standard and pact methods remain distinct');
        }
        foreach ($rows as $row) if (($row['mode']??null)==='grant-cast') {
            $check($row['castingAvailability']['status']==='unknown' && $row['castingEligibility']['requiresGrantReview']===true,'Full pools cannot authorize grant-cast');
        }
    }
    $session=new App\Entity\CharacterSessionState(new App\Entity\GameSession($campaign,'availability-view','Availability view'),$character,$stateBefore);
    $management=new App\Service\PlayerSpellManagement($em,$acquisitions,new CharacterFeatureSpellService($db,$features,$ability),
        new CharacterSpellPreparation($acquisitions,$ability),$directory);
    $revision=$session->getRevision(); $originalState=$session->getState();
    $view=$management->view($session);
    $check($view['revision']===$revision && $session->getRevision()===$revision && $session->getState()===$originalState,'Public view preserves revision and state');
    $check($snapshot()===$beforeAvailabilityReads,'Availability projection never writes or consumes');
    require __DIR__.'/test-spell-cast-command-cases.php';
    if (in_array('--cast-execution', $argv, true)) {
        // All tables/sequences were verified temporary above. Preserve them across real
        // commits so the executor can own its transaction; connection close drops them.
        $db->commit();
        require __DIR__.'/test-spell-cast-execution-cases.php';
    }
    echo "PASS: $checks spell directory/command assertions; temporary schema only.\n";
} finally {
    while ($db->isTransactionActive()) $db->rollBack();
    if (in_array('--cast-execution', $argv, true)) $db->close();
    $kernel->shutdown();
}
