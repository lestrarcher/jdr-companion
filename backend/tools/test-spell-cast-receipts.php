<?php
declare(strict_types=1);
require __DIR__.'/../vendor/autoload.php';
use App\Service\SpellCastReceipts as R;
$checks=0;
$check=static function(bool $ok,string $label) use (&$checks): void { if (!$ok) throw new RuntimeException($label); ++$checks; };
$reject=static function(callable $call) use ($check): void {
    try { $call(); } catch (InvalidArgumentException|UnexpectedValueException) { $check(true,'Rejected invalid input'); return; }
    throw new RuntimeException('Invalid input accepted');
};
$id='7a00a010-0000-4000-8000-000000000001';
$command=['revision'=>42,'requestId'=>strtoupper($id),'sourceKey'=>'acquisition:123','method'=>'standard-slot','castingLevel'=>3];
$cast=['source'=>['key'=>'acquisition:123','spellId'=>12,'policyId'=>1,'sourceType'=>'class'],
    'method'=>'standard-slot','castingLevel'=>3,'poolSlug'=>'spell-slot-3','cost'=>1];
$receipt=R::create($command,$cast); $state=[R::STATE_KEY=>[$id=>$receipt]]; $original=$state;
$check(R::normalizeUuid($command['requestId'])===$id,'UUID normalization');
$check(R::compare([],$command)==='missing','Legacy state');
$check(R::compare($state,$command)==='identical','Same command and normalized UUID');
$check(R::compare($state,array_reverse($command,true))==='identical','Object key order irrelevant');
foreach (['revision'=>43,'sourceKey'=>'acquisition:124','method'=>'pact-slot','castingLevel'=>4] as $field=>$value) {
    $check(R::compare($state,array_replace($command,[$field=>$value]))==='conflict','Strict conflict: '.$field);
}
foreach (['revision'=>'42','castingLevel'=>'3','requestId'=>null,'method'=>1] as $field=>$value) $reject(fn()=>R::compare($state,array_replace($command,[$field=>$value])));
foreach ([null,'bad',42] as $value) $reject(fn()=>R::validateState([R::STATE_KEY=>$value]));
foreach ([2,'1',null] as $value) { $bad=$state; $bad[R::STATE_KEY][$id]['version']=$value; $reject(fn()=>R::validateState($bad)); }
foreach (['version','command','cast'] as $key) { $bad=$state; unset($bad[R::STATE_KEY][$id][$key]); $reject(fn()=>R::validateState($bad)); }
foreach (['cost'=>'1','poolSlug'=>'spell-slot-2','castingLevel'=>2,'method'=>'ritual','state'=>[]] as $field=>$value) {
    $bad=$state; $bad[R::STATE_KEY][$id]['cast'][$field]=$value; $reject(fn()=>R::validateState($bad));
}
$reject(fn()=>R::validateState([R::STATE_KEY=>[strtoupper($id)=>$receipt]]));
$reject(fn()=>R::compare($state,$command+['cost'=>0]));
foreach (['cantrip','ritual','pact-slot'] as $method) {
    $level=$method==='cantrip'?0:1;
    $cmd=array_replace($command,['method'=>$method,'castingLevel'=>$level]);
    $result=array_replace($cast,['method'=>$method,'castingLevel'=>$level,'poolSlug'=>$method==='pact-slot'?'warlock-pact-slots':null,'cost'=>$method==='pact-slot'?1:0]);
    $check(R::compare([R::STATE_KEY=>[$id=>R::create($cmd,$result)]],$cmd)==='identical','Valid method receipt: '.$method);
}
$check($state===$original,'No mutations');
$resourceCommand=array_replace($command,['sourceKey'=>'feature:9','method'=>'resource','castingLevel'=>0]);
$resourceCast=['source'=>['key'=>'feature:9','spellId'=>12,'policyId'=>null,'sourceType'=>'feature'],
    'method'=>'resource','castingLevel'=>0,'poolSlug'=>'feature-use','cost'=>2];
$resourceReceipt=R::create($resourceCommand,$resourceCast);
$mixed=$state; $mixed[R::STATE_KEY]['7a00a010-0000-4000-8000-000000000002']=$resourceReceipt;
$check(count(R::validateState($mixed))===2,'Historical and resource receipts coexist in format version 1');
$check(R::compare($mixed,array_replace($resourceCommand,['requestId'=>'7a00a010-0000-4000-8000-000000000002']))==='identical','Resource receipt supports level zero and a configured cost greater than one');
foreach (['cost'=>0,'poolSlug'=>'spell-slot-1'] as $field=>$value) $reject(fn()=>R::create($resourceCommand,array_replace($resourceCast,[$field=>$value])));
foreach (['2',-1,1.5,2147483648] as $cost) $reject(fn()=>R::create($resourceCommand,array_replace($resourceCast,['cost'=>$cost])));
foreach ([null,'','warlock-pact-slots'] as $slug) $reject(fn()=>R::create($resourceCommand,array_replace($resourceCast,['poolSlug'=>$slug])));
$badSource=$resourceCast; $badSource['source']['sourceType']='class'; $reject(fn()=>R::create($resourceCommand,$badSource));
$targetCommand=$command+['targetCharacterIds'=>[3,1], 'hitPointRoll'=>10];
$targetReceipt=R::create($targetCommand,$cast);
$check($targetReceipt['version']===2 && $targetReceipt['command']['targetCharacterIds']===[1,3],'Effect receipt v2 normalizes target set');
$targetState=[R::STATE_KEY=>[$id=>$targetReceipt]];
$check(R::compare($targetState,array_replace($targetCommand,['targetCharacterIds'=>[1,3]]))==='identical','Target order does not change intent');
foreach ([['targetCharacterIds'=>[2,3]],['hitPointRoll'=>11]] as $patch) {
    $check(R::compare($targetState,array_replace($targetCommand,$patch))==='conflict','Changed effect parameters conflict');
}
$check(R::compare($targetState,$command)==='conflict','Omitting effect parameters conflicts with v2');
$check(R::compare($state,$targetCommand)==='conflict','Adding effect parameters conflicts with historical v1');
foreach ([['targetCharacterIds'=>[]],['targetCharacterIds'=>[1,1]],['targetCharacterIds'=>['1']],['targetCharacterIds'=>[0]],
    ['targetCharacterIds'=>range(1,13)],['hitPointRoll'=>1],['hitPointRoll'=>21],['hitPointRoll'=>'10'],['hitPointRoll'=>10.5]] as $patch) {
    $reject(fn()=>R::normalizeCommand(array_replace($targetCommand,$patch)));
}
$reject(fn()=>R::normalizeCommand($command+['hitPointRoll'=>10]));
foreach ([1,3] as $version) {
    $bad=$targetState; $bad[R::STATE_KEY][$id]['version']=$version;
    $reject(fn()=>R::validateState($bad));
}
echo "PASS: $checks receipt assertions; no database.\n";
