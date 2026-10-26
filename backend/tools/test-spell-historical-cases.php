<?php
declare(strict_types=1);
// Temporary/disposable schema supplied by the acquisition harness.
foreach (['campaign','owner','admin','other'] as $name) $$name=$em->find(($$name)::class,($$name)->getId());
$make=static function(string $key,array $counts) use($em,&$campaign): array {
    $c=new App\Entity\Character($campaign,$key,$key,App\Entity\Character::TYPE_PLAYER); $em->persist($c); $levels=[]; $position=1;
    foreach($counts as [$class,$count]) for($n=0;$n<$count;++$n) {
        $level=new App\Entity\CharacterClassLevel($c,$class,$position++); $c->addClassLevel($level); $em->persist($level); $levels[$class->getId()][]=$level;
    }
    $em->flush(); return [$c,$levels];
};
$warlock=new App\Entity\CharacterClass('global-warlock','Global warlock',8,20);
$sorcerer=new App\Entity\CharacterClass('global-sorcerer','Global sorcerer',6,20);
$em->persist($warlock); $em->persist($sorcerer); $em->flush();
$spec=App\Service\Dnd2014AdvancedSpellPolicies::warlock($warlock);
$wp=$service->defineAdvancedPolicy($warlock,$spec['policy'],$admin,$spec['rules']);
$sp=$service->definePolicy($sorcerer,App\Service\Dnd2014SpellPolicies::get('sorcerer-2014'),$admin);
$pool=[];
foreach([0=>8,1=>16,2=>16,5=>12,6=>3,7=>2] as $level=>$count) for($i=0;$i<$count;++$i) {
    $pool[$level][]=$catalogue->create(['slug'=>"global-$level-$i",'name'=>"Global $level $i",'level'=>$level,'school'=>'abjuration',
        'castingTime'=>'Fixture','range'=>'Fixture','duration'=>'Fixture','description'=>'Fixture','classIds'=>[$warlock->getId(),$sorcerer->getId()]],$admin,true);
}
$preview=static fn($c,$p)=>$service->historicalPreview($c,$p->getId(),$owner);
$payload=static fn($c,$p,$choices)=>['choices'=>$choices,'expectedAcquisitionIds'=>$preview($c,$p)['expectedAcquisitionIds'],'confirmed'=>true];
$snapshot=static fn($c)=>$db->fetchAllAssociative('SELECT * FROM character_spell_acquisition WHERE character_id=? ORDER BY id',[$c->getId()]);
$category=static fn($p,$label)=>array_values(array_filter($p['categories'],static fn($c)=>$c['label']===$label))[0];
$batch=static fn($spells,$rule)=>array_map(static fn($s)=>['spellId'=>$s['id'],'ruleId'=>$rule],$spells);
[$jakharis,$jl]=$make('Global Jakharis',[[$warlock,13]]);
$p=$preview($jakharis,$wp); $known=$category($p,'ordinary'); $minor=$category($p,'cantrips'); $arc6=$category($p,'arcanum-6'); $arc7=$category($p,'arcanum-7');
$check($known['remaining']===12 && $known['maximum']===12 && $minor['remaining']===4 && $arc6['remaining']===1 && $arc7['remaining']===1,'Current warlock quotas are independent of spell-level distribution');
$check(max(array_column($known['available'],'level'))===5 && min(array_column($known['available'],'level'))===1,'Whole current ordinary catalogue is available');
$bad=$payload($jakharis,$wp,$batch($pool[5],$known['ruleId']));
$refuse(fn()=>$service->completeHistorical($jakharis,$wp->getId(),$bad,$owner),409);
$check($snapshot($jakharis)===[],'Twelve fifth-level spells are refused: late gains and replacement budgets are insufficient');
$refuse(fn()=>$service->completeHistorical($jakharis,$wp->getId(),$payload($jakharis,$wp,$batch([$pool[6][0]],$known['ruleId'])),$owner),409);
$refuse(fn()=>$service->completeHistorical($jakharis,$wp->getId(),$payload($jakharis,$wp,$batch(array_slice($pool[2],0,13),$known['ruleId'])),$owner),409);
$choices=[...$batch(array_slice($pool[2],0,12),$known['ruleId']),...$batch(array_slice($pool[0],0,4),$minor['ruleId']),...$batch([$pool[6][0]],$arc6['ruleId']),...$batch([$pool[7][0]],$arc7['ruleId'])];
$input=$payload($jakharis,$wp,$choices);
$refuse(fn()=>$service->completeHistorical($jakharis,$wp->getId(),$input,$other),404);
$refuse(fn()=>$service->completeHistorical($jakharis,$wp->getId(),array_replace($input,['confirmed'=>false]),$owner),400);
$refuse(fn()=>$service->completeHistorical($jakharis,$wp->getId(),array_replace($input,['choices'=>[$choices[0],$choices[0]]]),$owner),400);
$result=$service->completeHistorical($jakharis,$wp->getId(),$input,$owner);
$check(count($result['createdAcquisitionIds'])===18,'Global selection completes ordinary spells, cantrips and two arcanums atomically');
$current=$service->options($jakharis,$wp->getId(),$owner);
$check(count(array_filter($current['acquisitions'],static fn($r)=>$r['quota_bucket']==='known' && (int)$r['spell_level']===1))===0,'Warlock 13 need not retain any level-one spell');
$check($current['used']['known']===12 && $current['used']['cantrips']===4 && count($current['acquisitions'])===18,'Arcanums do not consume ordinary quota');
$before=$snapshot($jakharis); $repeat=$service->completeHistorical($jakharis,$wp->getId(),$input,$owner);
$check($repeat['createdAcquisitionIds']===[] && $snapshot($jakharis)===$before,'Exact replay is idempotent even with the initial acquisition snapshot');
$p=$preview($jakharis,$wp); $check(array_sum(array_column($p['categories'],'remaining'))===0,'Already complete character has no duplicated opportunities');
foreach($before as $row) $check($row['provenance']==='manual-reconciliation' && !filter_var($row['historical_validation'],FILTER_VALIDATE_BOOL) && App\Service\CharacterSpellHistoricalChoices::trace($row)!==null,'Explicit reconciliation trace is not presented as real history');

[$frank,$fl]=$make('Global Frank',[[$sorcerer,11],[$warlock,2]]);
$sl=$fl[$sorcerer->getId()]; $wl=$fl[$warlock->getId()];
$old=$service->acquire($frank,$sp->getId(),[['spellId'=>$pool[1][0]['id'],'classLevelId'=>$sl[0]->getId()]],'level-history','Existing root',$owner)[0];
$service->acquire($frank,$sp->getId(),[['spellId'=>$pool[1][1]['id'],'classLevelId'=>$sl[1]->getId(),'replacesId'=>$old]],'level-history','Existing replacement',$owner);
$before=$snapshot($frank); $p=$preview($frank,$sp);
$check($category($p,'known')['remaining']===11 && $category($p,'cantrips')['remaining']===6,'Partial sorcerer 11 subtracts the replacement chain once');
$wpv=$preview($frank,$wp); $wk=$category($wpv,'ordinary');
$check($wk['remaining']===3 && $category($wpv,'cantrips')['remaining']===2 && count($wpv['categories'])===2,'Warlock 2 has independent quotas and no arcanum');
$check(max(array_column($wk['available'],'level'))===1,'Multiclass spell eligibility uses class level, not combined slots');
$service->completeHistorical($frank,$sp->getId(),$payload($frank,$sp,$batch(array_slice($pool[2],0,5),null)),$owner);
$mid=$preview($frank,$sp); $check($category($mid,'known')['remaining']===6,'Progressive batches reserve current quotas');
$service->completeHistorical($frank,$sp->getId(),$payload($frank,$sp,$batch(array_slice($pool[2],5,6),null)),$owner);
$check(array_slice($snapshot($frank),0,2)===$before,'Both preexisting replacement rows remain byte-for-byte unchanged');
$check($category($preview($frank,$wp),'ordinary')['remaining']===3,'Sorcerer selection does not spend warlock budget');
$service->completeHistorical($frank,$wp->getId(),$payload($frank,$wp,$batch(array_slice($pool[1],2,3),$wk['ruleId'])),$owner);
$check($category($preview($frank,$wp),'ordinary')['remaining']===0,'Second class completes independently');
// Normal future level-up still accepts its own acquisition budget.
$level12=new App\Entity\CharacterClassLevel($frank,$sorcerer,14); $frank->addClassLevel($level12); $em->persist($level12); $em->flush();
$active=$service->options($frank,$sp->getId(),$owner)['acquisitions'];
$toReplace=array_values(array_filter($active,static fn($r)=>$r['quota_bucket']==='known'))[0];
$service->acquire($frank,$sp->getId(),[['spellId'=>$pool[6][1]['id'],'classLevelId'=>$level12->getId(),'replacesId'=>(int)$toReplace['id']]],'level-history','Normal future level-up replacement',$owner);
$check($service->options($frank,$sp->getId(),$owner)['used']['known']===12,'Normal later replacement works without increasing the known quota');

[$manual,$ml]=$make('Global ambiguous',[[$sorcerer,3]]);
$service->acquire($manual,$sp->getId(),[['spellId'=>$pool[1][0]['id'],'classLevelId'=>$ml[$sorcerer->getId()][2]->getId()]],'manual-reconciliation','Unknown origin',$owner);
$check($preview($manual,$sp)['warning']!=='','Unknown preexisting reconciliation requires GM intervention');
$refuse(fn()=>$service->completeHistorical($manual,$sp->getId(),$payload($manual,$sp,$batch([$pool[1][1]],null)),$owner),409);

if($concurrent) {
    [$race,$rl]=$make('Global race',[[$sorcerer,2]]);
    $service->completeHistorical($race,$sp->getId(),$payload($race,$sp,$batch(array_slice($pool[0],0,3),null)),$owner);
    $expected=implode(',',$preview($race,$sp)['expectedAcquisitionIds']);
    $db->commit(); $db->beginTransaction(); $db->fetchOne('SELECT id FROM character WHERE id=? FOR UPDATE',[$race->getId()]); $workers=[];
    foreach(array_slice($pool[0],3,2) as $spell) {
        $pipes=[]; $process=proc_open([PHP_BINARY,__DIR__.'/test-character-spell-acquisitions.php','--worker',$isolatedSchema,(string)$race->getId(),(string)$sp->getId(),$expected,(string)$spell['id'],(string)$owner->getId(),'historical'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if(!is_resource($process)) throw new RuntimeException('Worker failed');
        fclose($pipes[0]); $check(trim(fgets($pipes[1]))==='ready','Concurrent reconciliation worker ready'); $workers[]=[$process,$pipes];
    }
    $db->commit(); $statuses=[];
    foreach($workers as [$process,$pipes]) {
        $statuses[]=trim(stream_get_contents($pipes[1])); $errors=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        $check(proc_close($process)===0,'Worker completed: '.$errors);
    }
    sort($statuses); $check($statuses===['201','409'],'Only one concurrent batch consumes the last cantrip quota');
    $check(count($snapshot($race))===4,'No duplicate or excess row after concurrent completion'); $db->beginTransaction();
}
