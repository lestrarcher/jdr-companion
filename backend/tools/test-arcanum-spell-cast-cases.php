<?php
declare(strict_types=1);

// Included by the existing directory harness; all entities and sequences are temporary.
(static function () use ($em,$db,$registry,$campaign,$owner,$catalogue,$directory,$validator,$acquisitions,$ability,$resources,$check,$classes): void {
    $class = $em->getRepository(App\Entity\CharacterClass::class)->findOneBy(['slug'=>'warlock']);
    $caster = new App\Entity\Character($campaign,'arcanum-fixture','Synthetic arcanum caster',App\Entity\Character::TYPE_PLAYER);
    $em->persist($class); $em->persist($caster);
    $levels = [];
    for ($n=1; $n<=17; ++$n) {
        $levels[$n] = new App\Entity\CharacterClassLevel($caster,$class,$n,null,$n===1?8:5,$n===1?App\Enum\HitPointGainMethod::FirstLevel:App\Enum\HitPointGainMethod::Average);
        $caster->addClassLevel($levels[$n]); $em->persist($levels[$n]);
    }
    $other = new App\Entity\CharacterClassLevel($caster,$classes['bard'],18,null,4,App\Enum\HitPointGainMethod::Average);
    $caster->addClassLevel($other); $em->persist($other); $em->flush();
    $preset = App\Service\Dnd2014AdvancedSpellPolicies::warlock($class);
    $policy = $acquisitions->defineAdvancedPolicy($class,$preset['policy'],$owner,$preset['rules']);
    $ids=[]; $state=['hitPoints'=>['current'=>20,'temporary'=>0],'resources'=>[
        ['id'=>'spell-slot-1','currentValue'=>2], ['id'=>'warlock-pact-slots','currentValue'=>3],
    ]];
    foreach ([6,7,8,9] as $level) {
        $slug='mystic-arcanum-'.$level.'-use';
        $resource=new App\Entity\TrackableResourceDefinition($slug,'Synthetic arcanum '.$level,App\Enum\ResourceRechargeType::LongRest);
        $resource->setBaseMaximum(1);
        $feature=new App\Entity\CharacterFeatureDefinition('mystic-arcanum-'.$level,'Synthetic arcanum '.$level);
        $feature->setResourceDefinition($resource);
        foreach ([$resource,$feature,App\Entity\CharacterFeatureRule::forClass($feature,$class,2*$level-1)] as $entity) $em->persist($entity);
        $spell=$catalogue->create(['slug'=>'arcanum-fixture-'.$level,'name'=>'Arcanum '.$level,'level'=>$level,'school'=>'divination',
            'castingTime'=>'1 action','range'=>'Self','duration'=>'1 minute','description'=>'Synthetic','classIds'=>[$class->getId()]],$owner,true);
        $rule=(int)$db->fetchOne('SELECT id FROM spell_policy_choice_rule WHERE policy_id=? AND rule_key=?',[$policy->getId(),'arcanum-'.$level]);
        $ids[$level]=$acquisitions->acquire($caster,$policy->getId(),[['spellId'=>$spell['id'],'ruleId'=>$rule,'classLevelId'=>$levels[2*$level-1]->getId()]],'level-history','Synthetic arcanum',$owner)[0];
        $state['resources'][]=['id'=>$slug,'currentValue'=>1];
    }
    $em->flush();
    $game=(new App\Entity\GameSession($campaign,'arcanum-cast','Synthetic arcanum'))->setStatus(App\Entity\GameSession::STATUS_LIVE);
    $session=new App\Entity\CharacterSessionState($game,$caster,$state); $em->persist($game); $em->persist($session); $em->flush();
    $hp=new App\Service\CharacterHitPointCalculator($ability); $hpState=new App\Service\CharacterHitPointStateService($hp);
    $effects=new App\Repository\CharacterActiveEffectRepository($registry);
    $executor=new App\Service\CharacterSpellCastExecutor($em,$validator,new App\Service\CharacterSpellHitPointEffects($em,new App\Service\CharacterHitPointBoostService($hpState,$effects,$em)));
    $command=static fn(int $level): array => ['revision'=>$session->getRevision(),'requestId'=>sprintf('8a00a010-0000-4000-8000-%012d',$level),
        'sourceKey'=>'acquisition:'.$ids[$level],'method'=>'arcanum','castingLevel'=>$level];
    $acquisitionsBefore=$db->fetchAllAssociative('SELECT * FROM character_spell_acquisition ORDER BY id');
    $remaining=static fn(array $s,string $slug): int => array_column($s['resources'],'currentValue','id')[$slug];
    foreach ([6,7,8,9] as $level) {
        $entries=array_column($directory->forCharacter($caster,$session->getState()),null,'key');
        $entry=$entries['acquisition:'.$ids[$level]];
        $check(!$entry['known'] && !$entry['inSpellbook'] && $entry['arcanum'],'Arcanum retains distinct acquisition provenance');
        $check(array_column($entry['castingAvailability']['methods'],'kind')===['arcanum'] && $entry['castingAvailability']['status']==='available','Only dedicated arcanum method available');
        foreach (['standard-slot','pact-slot','ritual','resource'] as $method) {
            $check(!$validator->validate($caster,$session->getState(),array_replace($command($level),['method'=>$method]))['valid'],'Alternative method refused: '.$method);
        }
        $cmd=$command($level); $before=$session->getState();
        $result=$executor->execute($session,$cmd);
        $expected=$before['resources']; foreach ($expected as &$row) if ($row['id']==='mystic-arcanum-'.$level.'-use') --$row['currentValue']; unset($row);
        $check($result['state']['resources']===$expected,'Exactly corresponding arcanum resource consumed: '.$level);
        $check($result['cast']['cost']===1 && $result['cast']['poolSlug']==='mystic-arcanum-'.$level.'-use','Server resolved dedicated cost');
        $replay=$executor->execute($session,$cmd);
        $check($replay['replayed'] && $replay['state']===$result['state'],'Identical replay consumes nothing');
        $check(!$validator->validate($caster,$session->getState(),$command($level))['valid'],'Exhausted arcanum refused');
        $exhausted=array_replace($command($level),['requestId'=>sprintf('8a00a010-0000-4000-8000-%012d',100+$level)]);
        try { $executor->execute($session,$exhausted); throw new RuntimeException('Expected exhausted refusal'); }
        catch (Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException $error) { $check($error->getMessage()==='exhausted','Executor refuses depleted arcanum'); }
        $check($session->getState()===$result['state'],'Rejected cast preserves counters and receipts');
        try { $executor->execute($session,array_replace($cmd,['castingLevel'=>$level===6?7:6])); throw new RuntimeException('Expected request conflict'); }
        catch (Symfony\Component\HttpKernel\Exception\ConflictHttpException $error) { $check($error->getMessage()==='request_id_conflict','Changed replay refused'); }
    }
    $check(!$validator->validate($caster,$state,array_replace($command(6),['sourceKey'=>'acquisition:2147483647']))['valid'],'Unacquired source refused');
    $check(!$validator->validate($caster,$state,array_replace($command(6),['castingLevel'=>7]))['valid'],'Arcanum cannot be upcast');
    $check(!$validator->validate($caster,$state,$command(6)+['poolSlug'=>'mystic-arcanum-7-use'])['valid'],'Client cannot supply a resource');
    foreach ([null,'1',2] as $counter) {
        $bad=$state;
        if ($counter===null) array_splice($bad['resources'],2,1); else $bad['resources'][2]['currentValue']=$counter;
        $check(!$validator->validate($caster,$bad,$command(6))['valid'],'Missing, invalid or excessive counter refused');
    }
    $bad=$state; $bad['resources'][]=$bad['resources'][2];
    $check(!$validator->validate($caster,$bad,$command(6))['valid'],'Duplicate arcanum counter refused');
    // Removing only the original choice provenance must not invent an Arcanum from the spell level.
    $db->executeStatement('UPDATE spell_policy_choice_rule SET rule_key=? WHERE policy_id=? AND rule_key=?',['unrelated-cast',$policy->getId(),'arcanum-6']);
    $check(!$validator->validate($caster,$state,$command(6))['valid'],'Unrelated cast acquisition is not an arcanum');
    $db->executeStatement('UPDATE spell_policy_choice_rule SET rule_key=? WHERE policy_id=? AND rule_key=?',['arcanum-6',$policy->getId(),'unrelated-cast']);
    // A high total level cannot substitute for warlock levels, even with stale acquisition/resource data.
    $otherLevels=[];
    foreach ([12,13,14,15,16,17] as $n) {
        $caster->getClassLevels()->removeElement($levels[$n]);
        $otherLevels[$n]=new App\Entity\CharacterClassLevel($caster,$classes['bard'],$n);
        $caster->getClassLevels()->add($otherLevels[$n]);
    }
    $check($validator->validate($caster,$state,$command(6))['valid'] && !$validator->validate($caster,$state,$command(7))['valid'],'Warlock 11 permits only acquired level 6 despite other class');
    foreach ([12,13] as $n) { $caster->getClassLevels()->removeElement($otherLevels[$n]); $caster->getClassLevels()->add($levels[$n]); }
    $check($validator->validate($caster,$state,$command(7))['valid'] && !$validator->validate($caster,$state,$command(8))['valid'],'Warlock 13 permits level 7, not level 8');
    foreach ([14,15,16,17] as $n) { $caster->getClassLevels()->removeElement($otherLevels[$n]); $caster->getClassLevels()->add($levels[$n]); }
    $slots=new App\Service\CharacterSpellSlotCalculator();
    $synchronizer=new App\Service\CharacterSessionStateSynchronizer($hp,$hpState,$resources,new App\Repository\CharacterSessionStateRepository($registry),new App\Service\CharacterSpellSlotStateService($slots));
    $rest=new App\Service\CharacterRestService($hp,$hpState,$resources,$synchronizer,$slots,new App\Repository\TrackableResourceDefinitionRepository($registry),$ability,$effects,new App\Service\CharacterActiveEffectService($hpState),$em);
    $rest->apply($session,App\Entity\RestRequest::TYPE_SHORT_REST);
    foreach ([6,7,8,9] as $level) $check($remaining($session->getState(),'mystic-arcanum-'.$level.'-use')===0,'Short rest preserves exhausted arcanum');
    $rest->apply($session,App\Entity\RestRequest::TYPE_LONG_REST);
    foreach ([6,7,8,9] as $level) $check($remaining($session->getState(),'mystic-arcanum-'.$level.'-use')===1,'Long rest restores arcanum');
    $em->flush();
    $again=array_replace($command(6),['requestId'=>'8a00a010-0000-4000-8000-000000000206']);
    $check($remaining($executor->execute($session,$again)['state'],'mystic-arcanum-6-use')===0,'Arcanum can be cast again after long rest');
    $check($db->fetchAllAssociative('SELECT * FROM character_spell_acquisition ORDER BY id')===$acquisitionsBefore,'Casting/rest never changes acquisitions');
})();
