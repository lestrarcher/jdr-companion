<?php

declare(strict_types=1);

use App\Entity\{User, Campaign, Character, CharacterClass, CharacterClassLevel, ProgressionDefinition, CharacterProgression, CharacterFeatureDefinition, CharacterFeatureRule, TrackableResourceDefinition, TrackableResourceRule};
use App\Enum\{SpellcastingProgressionType, ResourceRechargeType};
use App\Repository\{CharacterFeatureRuleRepository, TrackableResourceRuleRepository, TrackableResourceDefinitionRepository};
use App\Service\{CharacterAbilityCalculator, CharacterFeatureResolver, CharacterResourceResolver, CharacterSpellSlotCalculator, CharacterSpellSlotStateService, CharacterSpellAvailabilityContext};

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
        $db->executeStatement(preg_replace(['/^CREATE TABLE /','/^CREATE SEQUENCE /'], ['CREATE TEMP TABLE ','CREATE TEMP SEQUENCE '], $sql));
    }
    $tables = array_map(static fn($meta) => $meta->getTableName(), $metadata);
    foreach ($tables as $table) $check($db->fetchOne('SELECT relpersistence FROM pg_class WHERE oid=to_regclass(?)', [$table]) === 't', 'Temporary table: '.$table);
    $owner = (new User())->setEmail('availability@example.invalid')->setPassword('unused');
    $campaign = new Campaign($owner, 'availability', 'Availability');
    $wizard = new CharacterClass('wizard', 'Wizard', 6, 1, SpellcastingProgressionType::Full);
    $paladin = new CharacterClass('paladin', 'Paladin', 10, 1, SpellcastingProgressionType::Half);
    $warlock = new CharacterClass('warlock', 'Warlock', 8, 1, SpellcastingProgressionType::Pact);
    $pact = new TrackableResourceDefinition('warlock-pact-slots', 'Pact', ResourceRechargeType::ShortRest);
    $progression = new ProgressionDefinition('availability-progress', 'Progress');
    $charge = new TrackableResourceDefinition('availability-charge', 'Charge', ResourceRechargeType::LongRest);
    $charge->setBaseMaximum(2);
    $feature = new CharacterFeatureDefinition('availability-feature', 'Feature'); $feature->setResourceDefinition($charge);
    foreach ([$owner, $campaign, $wizard, $paladin, $warlock, $pact, $progression, $charge, $feature,
        TrackableResourceRule::forClass($pact, $warlock, 1, 1), TrackableResourceRule::forClass($pact, $warlock, 2, 2),
        CharacterFeatureRule::forProgression($feature, $progression, 3)] as $entity) $em->persist($entity);
    $make = static function (string $slug, array $classes) use ($campaign, $em): Character {
        $character = new Character($campaign, $slug, $slug, Character::TYPE_PLAYER);
        $em->persist($character);
        foreach ($classes as [$class, $count]) for ($i=0; $i<$count; ++$i) {
            $level = new CharacterClassLevel($character, $class, $character->getTotalLevel()+1);
            $character->addClassLevel($level); $em->persist($level);
        }
        return $character;
    };
    $mono = $make('mono', [[$wizard, 3]]);
    $multi = $make('multi', [[$wizard, 3], [$paladin, 2]]);
    $pactOnly = $make('pact', [[$warlock, 3]]);
    $mixed = $make('mixed', [[$wizard, 3], [$warlock, 3]]);
    $none = $make('none', []);
    $assignment = new CharacterProgression($mono, $progression); $mono->addProgression($assignment); $em->persist($assignment);
    $em->flush();
    $calculator = new CharacterSpellSlotCalculator();
    $resolver = new CharacterResourceResolver(new TrackableResourceRuleRepository($registry), new CharacterAbilityCalculator(), new CharacterFeatureResolver(new CharacterFeatureRuleRepository($registry)));
    $service = new CharacterSpellAvailabilityContext($calculator, new CharacterSpellSlotStateService($calculator), $resolver, new TrackableResourceDefinitionRepository($registry));
    $snapshot = static function () use ($db, $tables): array {
        $result=[];
        foreach ($tables as $table) $result[$table]=$db->fetchOne('SELECT md5(COALESCE(string_agg(to_jsonb(t)::text,\'\' ORDER BY to_jsonb(t)::text),\'\')) FROM '.$db->quoteIdentifier($table).' t');
        return $result;
    };
    $before = $snapshot();
    $build = static function (Character $character, array $state) use ($service, $check): array {
        $original = $state; $result = $service->build($character, $state);
        $check($state === $original, 'Input is unchanged');
        $check(count($result['standardSlots']) === 9, 'Nine explicit levels');
        $check($service->build($character, $state) === $result, 'Stable repeat read');
        return $result;
    };
    $state = ['resources'=>[['id'=>'spell-slot-1','currentValue'=>2], ['id'=>'spell-slot-2','currentValue'=>0]]];
    $result = $build($mono, $state);
    $check($result['standardSlots'][0]['maximum']===4 && $result['standardSlots'][0]['remaining']===2 && $result['standardSlots'][0]['status']==='available', 'Monoclass partial consumption');
    $check($result['standardSlots'][1]['reason']==='exhausted' && $result['standardSlots'][1]['maximum']===2, 'Exhausted standard pool');
    $check($result['standardSlots'][8]['status']==='unavailable' && $result['standardSlots'][8]['maximum']===0, 'Inactive standard level');
    $check($build($multi, $state)['standardSlots'][1]['maximum']===3, 'Multiclass uses existing calculator');
    $temporary = ['resources'=>[['id'=>'spell-slot-1','currentValue'=>5,'flexibleCastingBonus'=>1], ['id'=>'spell-slot-5','currentValue'=>0,'flexibleCastingBonus'=>1]]];
    $result = $build($mono, $temporary);
    $check($result['standardSlots'][0]['maximum']===5 && $result['standardSlots'][0]['remaining']===5, 'Temporary bonus increases existing maximum');
    $check($result['standardSlots'][4]['maximum']===1 && $result['standardSlots'][4]['reason']==='exhausted', 'Exhausted temporary-only pool preserved');
    foreach ([0, 1] as $remaining) {
        $result = $build($pactOnly, ['resources'=>[['id'=>'warlock-pact-slots','currentValue'=>$remaining]]]);
        $check($result['pactSlots']['maximum']===2 && $result['pactSlots']['level']===2 && $result['pactSlots']['status']===($remaining ? 'available':'unavailable'), 'Pact available/exhausted');
        $check($result['standardSlots'][0]['maximum']===0, 'Pact never creates standard slots');
    }
    $result = $build($mixed, ['resources'=>[...$state['resources'], ['id'=>'warlock-pact-slots','currentValue'=>1]]]);
    $check($result['standardSlots'][0]['remaining']===2 && $result['pactSlots']['remaining']===1, 'Independent coexisting pools');
    $check($build($none, [])['pactSlots']['applicable']===false, 'No invented pact');
    $check($build($mono, ['resources'=>[['id'=>'warlock-pact-slots','currentValue'=>2]]])['pactSlots']['status']!=='available', 'Historical pact counter does not grant Pact Magic');
    foreach ([[], [['id'=>'spell-slot-1','currentValue'=>1],['id'=>'spell-slot-1','currentValue'=>1]]] as $i=>$rows) {
        $pool=$build($mono, ['resources'=>$rows])['standardSlots'][0];
        $check($pool['status']==='unknown' && $pool['remaining']===null && $pool['reason']===($i===0?'missing_counter':'duplicate_counter'), 'Missing/duplicate counter');
    }
    foreach ([-1, 5, '2', 1.5, true, null] as $invalid) {
        $pool=$build($mono, ['resources'=>[['id'=>'spell-slot-1','currentValue'=>$invalid]]])['standardSlots'][0];
        $check($pool['status']==='unknown' && $pool['remaining']===null, 'Invalid counter is never available');
    }
    foreach ([-1, '1', null, PHP_INT_MAX] as $bonus) {
        $pool=$build($mono, ['resources'=>[['id'=>'spell-slot-1','currentValue'=>1,'flexibleCastingBonus'=>$bonus]]])['standardSlots'][0];
        $check($pool['status']==='unknown' && $pool['maximum']===null, 'Invalid bonus is not silently ignored');
    }
    foreach ([null, 'bad', [['currentValue'=>2]], ['key'=>['id'=>'spell-slot-1','currentValue'=>2]]] as $rows) {
        $result=$build($mono, ['resources'=>$rows]);
        $check($result['issues']!==[] && $result['standardSlots'][0]['status']==='unknown', 'Malformed collection explicit');
    }
    foreach ([0, 2, 3, 4] as $value) foreach ([0, 1] as $remaining) {
        $result=$build($mono, ['progressions'=>[['id'=>'availability-progress','currentValue'=>$value]], 'resources'=>[['id'=>'availability-charge','currentValue'=>$remaining]]]);
        $pool=array_column($result['resources'],null,'slug')['availability-charge'];
        $check($pool['definitionId']===$charge->getId() && $pool['applicable']===($value>=3), 'Progression context and identity');
        $check($pool['status']===($value>=3 && $remaining>0?'available':'unavailable'), 'Inactive or exhausted resource');
        $check($pool['maximum']===($value>=3?2:null), 'Inactive maximum is not fabricated');
    }
    $result=$build($mono, ['progressions'=>[['id'=>'availability-progress','currentValue'=>3]]]);
    $check($result['resources'][0]['reason']==='missing_counter', 'Applicable resource without counter is unknown');
    foreach (['availability-charge', 'warlock-pact-slots'] as $slug) {
        foreach ([[], [['id'=>$slug,'currentValue'=>1],['id'=>$slug,'currentValue'=>0]], [['id'=>$slug,'currentValue'=>3]]] as $rows) {
            $result=$build($slug==='availability-charge'?$mono:$pactOnly,
                ['progressions'=>[['id'=>'availability-progress','currentValue'=>3]], 'resources'=>$rows]);
            $pool=$slug==='availability-charge'?$result['resources'][0]:$result['pactSlots'];
            $check($pool['status']==='unknown' && $pool['remaining']===null, 'Missing, duplicate or excessive resource/pact counter');
        }
    }
    $result=$build($mono, ['resources'=>[['id'=>'unknown-charge','currentValue'=>1]]]);
    $check($result['resources'][0]['status']==='unknown' && $result['resources'][0]['definitionId']===null, 'Unresolved historical resource');
    // D2.3-B consumes the actual context contract without rebuilding its pools.
    $casting = new App\Service\CharacterSpellCastAvailability();
    $eligibility = new App\Service\CharacterSpellCastEligibility();
    $spellEntry=['key'=>'acquisition:fixture','policyId'=>1,'sourceType'=>'class','level'=>1,'known'=>true];
    $spellEntry['castingEligibility']=$eligibility->evaluate($spellEntry,'known','wizard');
    $actualContext=$build($mixed, ['resources'=>[['id'=>'spell-slot-1','currentValue'=>0],
        ['id'=>'spell-slot-2','currentValue'=>0],['id'=>'warlock-pact-slots','currentValue'=>1]]]);
    $unchangedContext=$actualContext;
    $decision=$casting->evaluate($spellEntry,$actualContext);
    $usable=array_values(array_filter($decision['methods'],static fn(array $method): bool=>$method['status']==='available'));
    $check($decision['status']==='available' && count($usable)===1 && $usable[0]['poolSlug']==='warlock-pact-slots'
        && $usable[0]['castingLevel']===2,'Real context offers pact upcasting while standards are exhausted');
    $check($actualContext===$unchangedContext,'Evaluation leaves real context unchanged');
    $check($casting->evaluate($spellEntry,$build($mono, []))['status']==='unknown','Real missing counters stay unknown');
    $check($snapshot()===$before, 'All database tables unchanged');
    $em->getUnitOfWork()->computeChangeSets();
    $check($em->getUnitOfWork()->getScheduledEntityUpdates()===[] && $em->getUnitOfWork()->getScheduledEntityInsertions()===[] && $em->getUnitOfWork()->getScheduledEntityDeletions()===[], 'No pending entity mutations');
    echo "PASS: $checks availability context assertions; temporary schema rolled back.\n";
} finally {
    while ($db->isTransactionActive()) $db->rollBack();
    $kernel->shutdown();
}
