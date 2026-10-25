<?php

declare(strict_types=1);

// Included only with --cast-execution, after committing the isolated temporary fixtures.
$executor = new App\Service\CharacterSpellCastExecutor($em,$validator);
$game=(new App\Entity\GameSession($campaign,'cast-execution','Cast execution'))->setStatus(App\Entity\GameSession::STATUS_LIVE);
$executionState=$commandState;
$executionState['resources'][0]['flexibleCastingBonus']=1;
$executionState['resources'][0]['notes']='Preserved metadata';
$executionState['resources'][]=['id'=>'unrelated-resource','currentValue'=>7,'notes'=>'Untouched'];
$executionSession=new App\Entity\CharacterSessionState($game,$character,$executionState);
$em->persist($game); $em->persist($executionSession); $em->flush();
$sessionId=$executionSession->getId();
// game_session is also changed explicitly by the closed-session fixture below.
$otherTablesBefore=$snapshot(); unset($otherTablesBefore['character_session_state'], $otherTablesBefore['game_session']);
$stored = static fn(): array => $db->fetchAssociative('SELECT state,revision,updated_at FROM character_session_state WHERE id=?',[$sessionId]);
$reset = static function (array $state) use ($executionSession,$em): void {
    $executionSession->setState($state); $em->flush();
};
$execute = static function (array $cmd) use ($executor,$executionSession): array {
    return $executor->execute($executionSession,array_replace($cmd,['revision'=>$executionSession->getRevision()]));
};
$reject = static function (array $cmd, int $code, string $reason) use ($executor,$executionSession,$stored,$check): void {
    $before=$stored();
    try { $executor->execute($executionSession,$cmd); throw new RuntimeException('Expected refusal'); }
    catch (Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $error) {
        $check($error->getStatusCode()===$code && $error->getMessage()===$reason,'Refusal: '.$reason);
    }
    $check($stored()===$before,'Refusal preserves state, revision and timestamp');
};
$initialRevision=$executionSession->getRevision();
$result=$execute($command);
$expected=$executionState; $expected['resources'][0]['currentValue']--;
$check($result['state']['resources']===$expected['resources'],'Standard decrements exactly once, preserving metadata and other resources');
$check($result['cast']['source']['spellId']===$ordinary['id'] && $result['cast']['poolSlug']==='spell-slot-1' && $result['cast']['cost']===1,'Structured committed result');
$check($result['revision']===$initialRevision+1 && (int)$stored()['revision']===$result['revision'],'Revision persisted before success');
$originalCommand=array_replace($command,['revision'=>$initialRevision]);
$historicalCast=$result['cast'];
$check($result['replayed']===false,'First acceptance is not a replay');
$assertReplay=static function (array $cmd, array $cast) use ($executor,$executionSession,$stored,$check): void {
    $before=$stored();
    $replay=$executor->execute($executionSession,$cmd);
    $check($replay['replayed']===true && $replay['cast']===$cast,'Replay returns historical cast');
    $check($replay['revision']===$executionSession->getRevision() && $replay['state']===$executionSession->getState(),'Replay returns current revision and state');
    $check($stored()===$before,'Replay preserves counters, receipt, timestamp and revision');
};
$assertReplay($originalCommand,$historicalCast); // The consumed pool is already exhausted.
$assertReplay(array_replace($originalCommand,['requestId'=>strtoupper($originalCommand['requestId'])]),$historicalCast);
foreach (['castingLevel'=>2,'method'=>'pact-slot','sourceKey'=>$wizardCommand['sourceKey'],'revision'=>$initialRevision+1] as $field=>$value) {
    $reject(array_replace($originalCommand,[$field=>$value]),409,'request_id_conflict');
}
$reject(array_replace($originalCommand,['requestId'=>'7a00a010-0000-4000-8000-000000000002']),409,'stale_revision');
$changed=$executionSession->getState(); $changed['resources'][3]['currentValue']=6;
$reset($changed);
$assertReplay($originalCommand,$historicalCast);
$secondCommand=array_replace($command,['revision'=>$executionSession->getRevision(),
    'requestId'=>'7a00a010-0000-4000-8000-000000000002','castingLevel'=>2]);
$secondResult=$executor->execute($executionSession,$secondCommand);
$check($secondResult['replayed']===false && count($secondResult['state']['spellCastReceipts'])===2,'A second accepted command preserves the first receipt');
$assertReplay($originalCommand,$historicalCast);
$assertReplay($secondCommand,$secondResult['cast']);
foreach ([null,[$originalCommand['requestId']=>['version'=>1]],[$originalCommand['requestId']=>array_replace($changed['spellCastReceipts'][$originalCommand['requestId']],['version'=>2])]] as $receipts) {
    $malformed=$executionState; $malformed['spellCastReceipts']=$receipts; $reset($malformed);
    $reject(array_replace($command,['revision'=>$executionSession->getRevision()]),409,
        is_array($receipts) && ($receipts[$originalCommand['requestId']]['version']??null)===2
            ? 'unsupported_spell_cast_receipt_version' : 'malformed_spell_cast_receipts');
}
$reset($executionState);
$result=$execute(array_replace($command,['castingLevel'=>2]));
$check($result['state']['resources'][1]['currentValue']===0 && $result['state']['resources'][0]['currentValue']===1,'Upcasting uses only chosen level');
$reset($executionState);
$pactRevision=$executionSession->getRevision();
$result=$execute(array_replace($command,['method'=>'pact-slot']));
$check($result['state']['resources'][2]['currentValue']===0 && $result['state']['resources'][0]['currentValue']===1,'Multiclass bard uses separate pact pool');
$assertReplay(array_replace($command,['revision'=>$pactRevision,'method'=>'pact-slot']),$result['cast']);
$reset($executionState);
$reject(array_replace($command,['revision'=>$executionSession->getRevision(),'method'=>'pact-slot','castingLevel'=>2]),422,'casting_level_mismatch');
foreach ([null,0,'1',-1] as $remaining) {
    $state=$executionState;
    if ($remaining===null) array_shift($state['resources']); else $state['resources'][0]['currentValue']=$remaining;
    $reset($state);
    $reason=match ($remaining) {null=>'missing_counter',0=>'exhausted',default=>'invalid_counter'};
    $reject(array_replace($command,['revision'=>$executionSession->getRevision()]),422,$reason);
}
$state=$executionState; $state['resources'][]=$state['resources'][0]; $reset($state);
$reject(array_replace($command,['revision'=>$executionSession->getRevision()]),422,'duplicate_counter');
$reset($executionState);
$reject(array_replace($command,['revision'=>$executionSession->getRevision(),'sourceKey'=>'acquisition:2147483647']),422,'source_not_found');
foreach ($entriesByKey as $entry) if (($entry['mode']??null)==='grant-cast') {
    $reject(array_replace($command,['revision'=>$executionSession->getRevision(),'sourceKey'=>$entry['key']]),422,'grant_rules_pending');
}
$reject(array_replace($command,['revision'=>$executionSession->getRevision(),'sourceKey'=>$wizardCommand['sourceKey']]),422,'spell_not_prepared');
$reject(array_replace($command,['revision'=>$executionSession->getRevision(),'cost'=>0]),422,'invalid_command');
$reject(array_replace($command,['revision'=>'1']),422,'invalid_command');
$game->setStatus(App\Entity\GameSession::STATUS_CLOSED); $em->flush();
try { $execute($command); throw new RuntimeException('Closed session accepted'); }
catch (Symfony\Component\HttpKernel\Exception\ConflictHttpException $error) { $check(true,'Closed session refused by shared lock'); }
$game->setStatus(App\Entity\GameSession::STATUS_LIVE); $em->flush();
$beforeNested=$stored(); $db->beginTransaction();
try {
    try { $execute($command); throw new RuntimeException('Nested transaction accepted'); }
    catch (LogicException $error) { $check(str_contains($error->getMessage(),'own its transaction'),'No success within an uncommitted outer transaction'); }
} finally { $db->rollBack(); }
$check($stored()===$beforeNested,'Nested transaction refusal leaves session unchanged');
foreach ([$cantrip,array_replace($wizardCommand,['method'=>'ritual'])] as $freeCommand) {
    $reset($executionState);
    $before=$executionSession->getState(); $revision=$executionSession->getRevision();
    $result=$execute($freeCommand);
    $withoutReceipts=$result['state']; unset($withoutReceipts['spellCastReceipts']);
    $check($withoutReceipts===$before && $result['cast']['cost']===0 && $result['cast']['poolSlug']===null,'Free casting changes only receipts, no state counters');
    $check($result['revision']===$revision+1 && (int)$stored()['revision']===$revision+1,'Free acceptance advances durable revision');
    $assertReplay(array_replace($freeCommand,['revision'=>$revision]),$result['cast']);
}
// A fresh locked read must discard stale in-memory availability and revision.
$reset($executionState); $oldRevision=$executionSession->getRevision();
$db->executeStatement('UPDATE character_session_state SET revision=revision+1 WHERE id=?',[$sessionId]);
$reject(array_replace($command,['revision'=>$oldRevision]),409,'stale_revision');
$state=$executionState; $state['resources'][0]['currentValue']=0;
$db->executeStatement('UPDATE character_session_state SET state=? WHERE id=?',[json_encode($state,JSON_THROW_ON_ERROR),$sessionId]);
$reject(array_replace($command,['revision'=>$executionSession->getRevision()]),422,'exhausted');

// Fail at COMMIT, after flush succeeded: deferred trigger belongs only to pg_temp.
$reset($executionState);
$db->executeStatement("CREATE FUNCTION pg_temp.reject_spell_commit() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN RAISE EXCEPTION ''synthetic cast commit failure''; RETURN NEW; END;'");
$db->executeStatement('CREATE CONSTRAINT TRIGGER reject_spell_commit AFTER UPDATE ON character_session_state DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION pg_temp.reject_spell_commit()');
$before=$stored();
try { $execute($command); throw new RuntimeException('Expected commit failure'); }
catch (Doctrine\DBAL\Exception $error) { $check(str_contains($error->getMessage(),'synthetic cast commit failure'),'Failure actually occurs at commit: '.$error->getMessage()); }
$check($stored()===$before,'Commit failure rolls back resource, receipt, revision and timestamp');
$check(!$em->isOpen(),'Failed transaction leaves no reusable mutated entity manager');
$otherTablesAfter=$snapshot(); unset($otherTablesAfter['character_session_state'], $otherTablesAfter['game_session']);
$check($otherTablesAfter===$otherTablesBefore,'All tables outside the explicitly changed session fixtures unchanged');
$db->executeStatement('DROP TRIGGER reject_spell_commit ON character_session_state');
