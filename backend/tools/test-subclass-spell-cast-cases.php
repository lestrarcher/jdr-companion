<?php
declare(strict_types=1);
// Included by the directory harness: synthetic characters and temporary tables only.
(static function () use ($em,$db,$registry,$campaign,$owner,$catalogue,$grants,$directory,$validator,$acquisitions,$ability,$check): void {
    foreach (['paladin'=>13,'cleric'=>5,'druid'=>5] as $slug=>$levelCount) {
        $class=new App\Entity\CharacterClass('grant-test-'.$slug,'Fixture '.$slug,8,1,
            $slug==='paladin' ? App\Enum\SpellcastingProgressionType::Half : App\Enum\SpellcastingProgressionType::Full);
        $sub=new App\Entity\CharacterSubclass($class,'grant-test-'.$slug,'Fixture subclass');
        $c=new App\Entity\Character($campaign,'grant-test-'.$slug,'Synthetic caster',App\Entity\Character::TYPE_PLAYER);
        $c->getAbilityScore(App\Enum\Ability::Charisma)->setBaseValue(19);
        $c->getAbilityScore(App\Enum\Ability::Wisdom)->setBaseValue(18);
        $spec=App\Service\Dnd2014AdvancedSpellPolicies::ordinary($class,App\Service\Dnd2014SpellPolicies::get($slug.'-2014'));
        foreach ([$class,$sub,$c] as $entity) $em->persist($entity);
        for ($n=1;$n<=$levelCount;++$n) {
            $l=new App\Entity\CharacterClassLevel($c,$class,$n,$sub); $c->addClassLevel($l); $em->persist($l);
        }
        $em->flush();
        $policy=$acquisitions->defineAdvancedPolicy($class,$spec['policy'],$owner,$spec['rules']);
        $ordinary=$catalogue->create(['slug'=>'grant-ordinary-'.$slug,'name'=>'Ordinary','level'=>1,'school'=>'abjuration',
            'castingTime'=>'1 action','range'=>'Self','duration'=>'1 minute','description'=>'Fixture','classIds'=>[$class->getId()]],$owner,true);
        $grantIds=[];
        foreach (($slug==='paladin' ? [1,1,2,2,3,3,4,4] : [1,2,3]) as $i=>$spellLevel) {
            $spell=$catalogue->create(['slug'=>'grant-auto-'.$slug.'-'.$i,'name'=>'Always prepared '.$i,'level'=>$spellLevel,'school'=>'abjuration',
                'castingTime'=>'1 action','range'=>'Self','duration'=>'1 minute','description'=>'Fixture','classIds'=>[]],$owner,true);
            $grant=$grants->save($spell['id'],null,['subclassId'=>$sub->getId(),'mode'=>'grant-always-prepared',
                'unlockLevel'=>$slug==='paladin' ? [3,5,9,13][$spellLevel-1] : 2*$spellLevel-1,
                'castingClassId'=>$class->getId(),'quotaImpact'=>'free'],$owner,true);
            $grantIds[]=$grant['id'];
        }
        $resources=[];
        foreach ((new App\Service\CharacterSpellSlotCalculator())->calculate($c) as $n=>$max) $resources[]=['id'=>'spell-slot-'.$n,'currentValue'=>$max];
        $state=['preparedSpells'=>[$policy->getId()=>[$ordinary['id']]],'resources'=>$resources];
        $options=$acquisitions->options($c,$policy->getId(),$owner);
        $plan=(new App\Service\CharacterSpellPreparation($acquisitions,$ability))->plan($c,$options,$state);
        $check($plan['maximum']===($slug==='paladin'?10:9) && $options['preparation']['automaticCounted']===0,"$slug: automatic spells do not spend preparation quota");
        $entries=array_column($directory->forCharacter($c,$state),null,'key');
        foreach ($grantIds as $id) {
            $entry=$entries['subclass-grant:'.$id];
            $check($entry['alwaysPrepared'] && $entry['prepared'] && $entry['policyId']===null,"$slug: automatic provenance stays independent of acquisitions");
            $check($entry['castingAbility']===($slug==='paladin'?'charisma':'wisdom'),"$slug: ability from applicable casting policy");
            $check($entry['spellSaveDc']===($slug==='paladin'?17:15) && $entry['spellAttackBonus']===($slug==='paladin'?9:7),"$slug: casting statistics");
            $methods=array_values(array_filter($entry['castingAvailability']['methods'],static fn($m)=>$m['status']==='available'));
            $check($entry['castingAvailability']['status']==='available' && $methods!==[],"$slug: automatic spell castable with available slots");
            foreach ($methods as $m) $check($m['kind']==='standard-slot' && $m['cost']===1,"$slug: no implicit free cast");
        }
        $normal=$entries['prepared:'.$policy->getId().':'.$ordinary['id']];
        $check($normal['castingAvailability']['status']==='available',"$slug: ordinary preparation still works");
        $game=(new App\Entity\GameSession($campaign,'grant-cast-'.$slug,'Synthetic'))->setStatus(App\Entity\GameSession::STATUS_LIVE);
        $session=new App\Entity\CharacterSessionState($game,$c,$state); $em->persist($game); $em->persist($session); $em->flush();
        $before=$db->fetchAllAssociative('SELECT * FROM character_spell_acquisition ORDER BY id');
        $cmd=['revision'=>$session->getRevision(),'requestId'=>'8a00a010-0000-4000-8000-000000000001',
            'sourceKey'=>'subclass-grant:'.$grantIds[0],'method'=>'standard-slot','castingLevel'=>$slug==='paladin'?4:3];
        $executor=new App\Service\CharacterSpellCastExecutor($em,$validator,new App\Service\CharacterSpellHitPointEffects($em,new App\Service\CharacterHitPointBoostService(new App\Service\CharacterHitPointStateService(new App\Service\CharacterHitPointCalculator(new App\Service\CharacterAbilityCalculator())),new App\Repository\CharacterActiveEffectRepository($registry),$em)));
        $result=$executor->execute($session,$cmd);
        $expected=$resources; --$expected[count($expected)-1]['currentValue'];
        $check($result['state']['resources']===$expected,"$slug: upcast consumes exactly one chosen slot");
        $check($result['state']['preparedSpells']===$state['preparedSpells'],"$slug: cast preserves preparation");
        $replay=$executor->execute($session,$cmd);
        $check($replay['replayed'] && $replay['state']['resources']===$expected,"$slug: replay does not consume again");
        $check($before===$db->fetchAllAssociative('SELECT * FROM character_spell_acquisition ORDER BY id'),"$slug: no acquisition mutation");
        $empty=$state; foreach ($empty['resources'] as &$r) $r['currentValue']=0; unset($r);
        $entry=array_column($directory->forCharacter($c,$empty),null,'key')[$cmd['sourceKey']];
        $check($entry['castingAvailability']['status']==='unavailable',"$slug: exhausted slots disable the spell");
        foreach (['resource','ritual'] as $method) $check(!$validator->validate($c,$state,array_replace($cmd,['method'=>$method]))['valid'],"$slug: unconfigured free/resource method refused");
    }
})();
