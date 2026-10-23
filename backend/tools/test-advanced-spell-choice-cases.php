<?php
declare(strict_types=1);
use App\Entity\{CharacterClass,CharacterSubclass,Character,CharacterClassLevel,Campaign,User};
// Included in the existing isolated acquisition harness.
$advancedSessionsBefore=$db->fetchOne("SELECT md5(COALESCE(string_agg(row_to_json(s)::text,'' ORDER BY s.id),'')) FROM character_session_state s");
$campaign=$em->find(Campaign::class,$campaign->getId());
$owner=$em->find(User::class,$owner->getId()); $admin=$em->find(User::class,$admin->getId()); $other=$em->find(User::class,$other->getId());
$bookClass=$em->find(CharacterClass::class,$bookClass->getId());
$makeAdvancedCharacter=static function($slug,$class,$count,$sub) use ($em,$campaign) {
    $character=new Character($campaign,$slug,$slug,Character::TYPE_PLAYER); $em->persist($character);
    for($i=1;$i<=$count;++$i) { $level=new CharacterClassLevel($character,$class,$i,$i>=3?$sub:null); $character->addClassLevel($level); $em->persist($level); }
    return $character;
};
$advancedClass=new CharacterClass('advanced-fixture','Unrelated label',6,1); $em->persist($advancedClass);
$advancedSub=new CharacterSubclass($advancedClass,'advanced-sub','Unrelated subclass'); $em->persist($advancedSub);
$advancedChar=$makeAdvancedCharacter('advanced',$advancedClass,12,$advancedSub); $em->flush();
$preset=App\Service\Dnd2014AdvancedSpellPolicies::thirdCaster($bookClass,false);
$advancedPolicy=$service->defineAdvancedPolicy($advancedSub,$preset['policy'],$admin,$preset['rules']);
$advancedRules=(new App\Service\AdvancedSpellChoices($em,$access))->rules($advancedPolicy);
$byKey=[]; foreach ($advancedRules as $rule) $byKey[$rule->getKey()]=$rule->getId();
$al=array_map(static fn($l)=>$l->getId(),$advancedChar->getClassLevels()->toArray());
$advancedChoice=static fn($spell,$level,$rule,$replaces=null)=>array_filter(['spellId'=>$spell,'classLevelId'=>$level,'ruleId'=>$rule,'replacesId'=>$replaces],static fn($v)=>$v!==null);
$refuse(fn()=>$service->acquire($advancedChar,$advancedPolicy->getId(),[$advancedChoice($spells[3]['id'],$al[2],$byKey['restricted'])],'level-history','Restricted test',$owner),400);
$firstAdvanced=$service->acquire($advancedChar,$advancedPolicy->getId(),[$advancedChoice($spells[3]['id'],$al[2],$byKey['unrestricted'])],'level-history','Exception test',$owner)[0];
$secondAdvanced=$service->acquire($advancedChar,$advancedPolicy->getId(),[$advancedChoice($spells[4]['id'],$al[3],$byKey['unrestricted'],$firstAdvanced)],'level-history','Replacement test',$owner)[0];
$thirdAdvanced=$service->acquire($advancedChar,$advancedPolicy->getId(),[$advancedChoice($spells[5]['id'],$al[4],$byKey['unrestricted'],$secondAdvanced)],'level-history','Second replacement',$owner)[0];
$check((int)$db->fetchOne('SELECT opportunity_level_id FROM character_spell_choice WHERE acquisition_id=?',[$thirdAdvanced])===$al[2],'Exception opportunity retained across replacements');
$check($advancedChar->getLevelInClass($bookClass)===0,'Borrowed wizard list needs no wizard level');
$refuse(fn()=>$service->acquire($advancedChar,$advancedPolicy->getId(),[$advancedChoice($spells[9]['id'],$al[4],$byKey['unrestricted'])],'level-history','No new exception',$owner),409);
$refuse(fn()=>$service->acquire($advancedChar,$advancedPolicy->getId(),[$advancedChoice($spells[9]['id'],$al[5],$byKey['restricted'],$thirdAdvanced)],'level-history','Forged provenance',$owner),400);
$refuse(fn()=>$service->options($advancedChar,$advancedPolicy->getId(),$other),404);
$bardClass=new CharacterClass('bard-fixture','No slug inference',8,3); $em->persist($bardClass);
$loreSub=new CharacterSubclass($bardClass,'lore-fixture','No name inference'); $em->persist($loreSub);
$bardChar=$makeAdvancedCharacter('bard-advanced',$bardClass,11,$loreSub); $em->flush();
$bardPreset=App\Service\Dnd2014AdvancedSpellPolicies::bard($bardClass);
$bardBase=$service->defineAdvancedPolicy($bardClass,$bardPreset['policy'],$admin,$bardPreset['rules']);
$lorePolicy=$service->defineAdvancedPolicy($loreSub,$bardPreset['policy'],$admin,App\Service\Dnd2014AdvancedSpellPolicies::lore($bardClass),$bardBase);
$check($service->status($bardChar,$owner)[0]['policyIds']===[$lorePolicy->getId()],'Explicit composition removes ambiguity');
$br=[]; foreach ((new App\Service\AdvancedSpellChoices($em,$access))->rules($lorePolicy) as $r) $br[$r->getKey()]=$r->getId();
$bl=array_map(static fn($l)=>$l->getId(),$bardChar->getClassLevels()->toArray());
$service->acquire($bardChar,$lorePolicy->getId(),[$advancedChoice($spells[3]['id'],$bl[5],$br['additional-secrets'])],'level-history','Lore free choice',$owner);
$service->acquire($bardChar,$lorePolicy->getId(),[$advancedChoice($spells[0]['id'],$bl[9],$br['magical-secrets']),$advancedChoice($spells[4]['id'],$bl[9],$br['magical-secrets'])],'level-history','Two counted secrets',$owner);
$bo=$service->options($bardChar,$lorePolicy->getId(),$owner);
$check($bo['spellSaveDc'] === 12 && $bo['spellAttackBonus'] === 4, 'Advanced composed CHA policy exposes current casting statistics');
$check($bo['used']['known']===2 && $bo['used']['cantrips']===0,'Secret cantrip spends known quota; Lore spends no ordinary quota');
$refuse(fn()=>$service->acquire($bardChar,$lorePolicy->getId(),[$advancedChoice($spells[5]['id'],$bl[9],$br['magical-secrets'])],'level-history','Third secret',$owner),409);
$check(array_values(array_filter($bo['choices'],static fn($r)=>$r['key']==='ordinary'))[0]['remaining']===1,'Bard ordinary opportunities independent');
$secretIds=$db->fetchAllKeyValue('SELECT spell_id,id FROM character_spell_acquisition WHERE character_id=?',[$bardChar->getId()]);
$refuse(fn()=>$service->acquire($bardChar,$lorePolicy->getId(),[$advancedChoice($spells[5]['id'],$bl[10],$br['magical-secrets'],(int)$secretIds[$spells[4]['id']])],'level-history','Replacement must use bard list',$owner),400);
$db->insert('spell_class_list_entry',['spell_id'=>$spells[5]['id'],'character_class_id'=>$bardClass->getId()]);
$db->insert('spell_class_list_entry',['spell_id'=>$spells[9]['id'],'character_class_id'=>$bardClass->getId()]);
$refuse(fn()=>$service->acquire($bardChar,$lorePolicy->getId(),[$advancedChoice($spells[5]['id'],$bl[10],$br['magical-secrets'],(int)$secretIds[$spells[0]['id']])],'level-history','No cantrip replacement',$owner),400);
$service->acquire($bardChar,$lorePolicy->getId(),[$advancedChoice($spells[5]['id'],$bl[10],$br['magical-secrets'],(int)$secretIds[$spells[4]['id']])],'level-history','Secret replaced through bard list',$owner);
$refuse(fn()=>$service->acquire($bardChar,$lorePolicy->getId(),[$advancedChoice($spells[9]['id'],$bl[10],$br['additional-secrets'],(int)$secretIds[$spells[3]['id']])],'level-history','Shared replacement budget exhausted',$owner),409);

// A partial batch must not consume its first valid opportunity.
$beforeAdvanced=(int)$db->fetchOne('SELECT COUNT(*) FROM character_spell_acquisition');
$refuse(fn()=>$service->acquire($advancedChar,$advancedPolicy->getId(),[$advancedChoice($spells[3]['id'],$al[7],$byKey['unrestricted']),$advancedChoice($spells[4]['id'],$al[7],$byKey['unrestricted'])],'level-history','Atomic failure',$owner),409);
$check((int)$db->fetchOne('SELECT COUNT(*) FROM character_spell_acquisition')===$beforeAdvanced,'Advanced batch rolled back entirely');

// Manual reconciliation carries no invented historical opportunity.
$reconciled=$makeAdvancedCharacter('advanced-reconciled',$advancedClass,3,$advancedSub); $em->flush();
$rl=array_map(static fn($l)=>$l->getId(),$reconciled->getClassLevels()->toArray());
$manualAdvanced=$service->acquire($reconciled,$advancedPolicy->getId(),[$advancedChoice($spells[3]['id'],$rl[2],$byKey['unrestricted'])],'manual-reconciliation','Current state only',$owner)[0];
$nextLevel=new CharacterClassLevel($reconciled,$advancedClass,4,$advancedSub); $reconciled->addClassLevel($nextLevel); $em->persist($nextLevel); $em->flush();
$replaceManual=fn()=>$service->acquire($reconciled,$advancedPolicy->getId(),[$advancedChoice($spells[4]['id'],$nextLevel->getId(),$byKey['unrestricted'],$manualAdvanced)],'level-history','Qualified replacement',$owner);
$refuse($replaceManual,409);
$refuse(fn()=>$service->qualifyChoice($reconciled,$advancedPolicy->getId(),$manualAdvanced,'',$owner),400);
$refuse(fn()=>$service->qualifyChoice($reconciled,$advancedPolicy->getId(),$manualAdvanced,'Forged owner',$other),404);
$initialChoice=$db->fetchAssociative('SELECT * FROM character_spell_choice WHERE acquisition_id=?',[$manualAdvanced]);
$initialAcquisition=$db->fetchAssociative('SELECT * FROM character_spell_acquisition WHERE id=?',[$manualAdvanced]);
$journalBefore=(int)$db->fetchOne('SELECT COUNT(*) FROM character_spell_qualification');
$service->qualifyChoice($reconciled,$advancedPolicy->getId(),$manualAdvanced,'MJ confirms unrestricted acquisition route',$owner);
$check($db->fetchAssociative('SELECT * FROM character_spell_choice WHERE acquisition_id=?',[$manualAdvanced])===$initialChoice,'Qualification preserves every initial choice field');
$check($db->fetchAssociative('SELECT * FROM character_spell_acquisition WHERE id=?',[$manualAdvanced])===$initialAcquisition,'Qualification preserves provenance and historical validation');
$journal=$db->fetchAssociative('SELECT * FROM character_spell_qualification WHERE acquisition_id=?',[$manualAdvanced]);
$check((int)$journal['rule_id']===$byKey['unrestricted'] && (int)$journal['decided_by_id']===$owner->getId() && $journal['reason']==='MJ confirms unrestricted acquisition route' && $journal['decided_at']!==null,'Journal records rule, author, reason and date');
$qualifiedOptions=$service->options($reconciled,$advancedPolicy->getId(),$owner);
$qualifiedRow=array_column($qualifiedOptions['acquisitions'],null,'id')[$manualAdvanced];
$check(filter_var($qualifiedRow['origin_known'],FILTER_VALIDATE_BOOL),'Options expose effective qualified origin');
$check(!(new App\Service\AdvancedSpellChoices($em,$access))->requiresQualification($qualifiedRow,$em->find(App\Entity\SpellPolicyChoiceRule::class,$byKey['unrestricted']),(new App\Service\AdvancedSpellChoices($em,$access))->rules($advancedPolicy)),'Shared qualification predicate accepts journal confirmation');
$refuse(fn()=>$service->qualifyChoice($reconciled,$advancedPolicy->getId(),$manualAdvanced,'Repeated qualification',$owner),409);
$check((int)$db->fetchOne('SELECT COUNT(*) FROM character_spell_qualification')===$journalBefore+1,'Repeated qualification adds no journal entry');
$qualifiedReplacement=$replaceManual()[0];
$refuse(fn()=>$service->qualifyChoice($reconciled,$advancedPolicy->getId(),$manualAdvanced,'Inactive acquisition',$owner),404);
$check($db->fetchOne('SELECT opportunity_level_id FROM character_spell_choice WHERE acquisition_id=?',[$qualifiedReplacement])===null,'Qualification never invents a historical level');
$check((int)$db->fetchOne('SELECT rule_id FROM character_spell_choice WHERE acquisition_id=?',[$qualifiedReplacement])===$byKey['unrestricted'],'Replacement keeps confirmed rule');
$qualificationRace=$makeAdvancedCharacter('qualification-race',$advancedClass,3,$advancedSub); $em->flush();
$qualificationRaceId=$service->acquire($qualificationRace,$advancedPolicy->getId(),[$advancedChoice($spells[3]['id'],$qualificationRace->getClassLevels()->last()->getId(),$byKey['unrestricted'])],'manual-reconciliation','Concurrent historical fixture',$owner)[0];
$qualificationRaceBefore=$db->fetchAssociative('SELECT * FROM character_spell_choice WHERE acquisition_id=?',[$qualificationRaceId]);
$db->beginTransaction();
$service->qualifyChoice($qualificationRace,$advancedPolicy->getId(),$qualificationRaceId,'Rolled back qualification',$owner);
$check((int)$db->fetchOne('SELECT COUNT(*) FROM character_spell_qualification WHERE acquisition_id=?',[$qualificationRaceId])===1,'Qualification participates in enclosing transaction');
$db->rollBack();
$check(!(bool)$db->fetchOne('SELECT 1 FROM character_spell_qualification WHERE acquisition_id=?',[$qualificationRaceId]),'Rollback removes qualification');
$check($db->fetchAssociative('SELECT * FROM character_spell_choice WHERE acquisition_id=?',[$qualificationRaceId])===$qualificationRaceBefore,'Rollback preserves initial choice');

// Arcane trickster uses an explicit required cantrip FK, not a spell name.
$trickClass=new CharacterClass('trick-fixture','Synthetic trickster',8,2); $em->persist($trickClass);
$trickSub=new CharacterSubclass($trickClass,'trick-sub','Synthetic subclass'); $em->persist($trickSub);
$trick=$makeAdvancedCharacter('trick',$trickClass,3,$trickSub); $em->flush();
$tp=App\Service\Dnd2014AdvancedSpellPolicies::thirdCaster($bookClass,true,$em->find(App\Entity\SpellDefinition::class,$spells[0]['id']));
$trickPolicy=$service->defineAdvancedPolicy($trickSub,$tp['policy'],$admin,$tp['rules']);
$tr=[]; foreach ((new App\Service\AdvancedSpellChoices($em,$access))->rules($trickPolicy) as $r) $tr[$r->getKey()]=$r->getId();
$tl=$trick->getClassLevels()->last()->getId();
$refuse(fn()=>$service->acquire($trick,$trickPolicy->getId(),[$advancedChoice($spells[1]['id'],$tl,$tr['required-cantrip'])],'level-history','Wrong imposed cantrip',$owner),400);
$service->acquire($trick,$trickPolicy->getId(),[$advancedChoice($spells[0]['id'],$tl,$tr['required-cantrip']),$advancedChoice($spells[3]['id'],$tl,$tr['restricted'])],'level-history','Explicit imposed cantrip and illusion',$owner);
$to=$service->options($trick,$trickPolicy->getId(),$owner);
$check($to['used']['cantrips']===1 && $to['used']['known']===1,'Trickster keeps cantrip and known quotas distinct');
foreach ([3,8,14,20] as $exceptionLevel) $check($tp['rules'][1]['parameters']->at('opportunities',$exceptionLevel)===1,'Explicit unrestricted opportunity '.$exceptionLevel);
$invalid=$tp['rules'][0]['parameters']->values; $invalid['classId']=42;
try { new App\Dto\SpellChoiceParameters($invalid); throw new RuntimeException('Unknown JSON key accepted'); } catch (InvalidArgumentException) { $check(true,'JSON cannot conceal reference IDs'); }

$customSub=(new CharacterSubclass($advancedClass,'custom-advanced','Personal declarative source'))->markAsPersonalReference($owner); $em->persist($customSub);
$customAdvanced=$makeAdvancedCharacter('custom-advanced',$advancedClass,3,$customSub); $em->flush();
$refuse(fn()=>$service->defineAdvancedPolicy($customSub,$preset['policy'],$other,$preset['rules']),404);
$customPolicy=$service->defineAdvancedPolicy($customSub,$preset['policy'],$owner,$preset['rules']);
$customRules=(new App\Service\AdvancedSpellChoices($em,$access))->rules($customPolicy);
$customOpen=array_values(array_filter($customRules,static fn($r)=>$r->getKey()==='unrestricted'))[0];
$service->acquire($customAdvanced,$customPolicy->getId(),[$advancedChoice($spells[3]['id'],$customAdvanced->getClassLevels()->last()->getId(),$customOpen->getId())],'level-history','Personal source with official list',$owner);
$check($service->options($customAdvanced,$customPolicy->getId(),$owner)['used']['known']===1,'CUSTOM policy borrows OFFICIAL list without changing it');
$extended=$catalogue->create(array_replace($fixture,['slug'=>'advanced-extension','level'=>1,'classIds'=>[],'school'=>'evocation']),$admin,true);
$grants->save($extended['id'],null,['subclassId'=>$customSub->getId(),'mode'=>'add-to-list','unlockLevel'=>3,'castingClassId'=>$bookClass->getId()],$owner);
$service->acquire($customAdvanced,$customPolicy->getId(),[$advancedChoice($extended['id'],$customAdvanced->getClassLevels()->last()->getId(),array_values(array_filter($customRules,static fn($r)=>$r->getKey()==='restricted'))[0]->getId())],'level-history','Inherited personal list extension',$owner);
$check($service->options($customAdvanced,$customPolicy->getId(),$owner)['used']['known']===2,'List extension preserves acquisition quota and school restriction');

// A new base version never silently replaces the version pinned by Lore.
$bardV2=App\Service\Dnd2014AdvancedSpellPolicies::bard($bardClass,2);
$service->defineAdvancedPolicy($bardClass,$bardV2['policy'],$admin,$bardV2['rules']);
$check($lorePolicy->getBasePolicy()->getId()===$bardBase->getId() && $service->options($bardChar,$lorePolicy->getId(),$owner)['used']['known']===2,'Pinned composition survives newer base version');

// Explicit transition and new copies retain the legacy revision/copy boundaries.
$copyClass=new CharacterClass('advanced-book','Declarative book',6,1); $em->persist($copyClass);
$copyCharacter=$makeAdvancedCharacter('advanced-book',$copyClass,3,null); $em->flush();
$oldBook=$service->definePolicy($copyClass,new App\Dto\SpellcastingPolicy('book-transition',1,App\Enum\Ability::Intelligence,'spellbook',$bookRows,'Synthetic'),$admin);
$db->insert('spell_class_list_entry',['spell_id'=>$spells[3]['id'],'character_class_id'=>$copyClass->getId()]);
$legacyBookIds=$service->acquire($copyCharacter,$oldBook->getId(),[['spellId'=>$spells[3]['id'],'classLevelId'=>$copyCharacter->getClassLevels()->last()->getId()]],'manual-reconciliation','Legacy book snapshot',$owner);
$newBookDto=new App\Dto\SpellcastingPolicy('book-transition',2,App\Enum\Ability::Intelligence,'spellbook',$bookRows,'Synthetic');
$bookParameters=$preset['rules'][0]['parameters']->values;
$bookParameters=array_replace($bookParameters,['opportunities'=>array_fill(0,20,2),'maxSpellLevels'=>array_column($bookRows,'maxSpellLevel'),'schools'=>[],'replacementSchools'=>[],'quota'=>'spellbook','result'=>'spellbook','replacements'=>array_fill(0,20,0)]);
$newBook=$service->defineAdvancedPolicy($copyClass,$newBookDto,$admin,[['key'=>'book-learning','parameters'=>new App\Dto\SpellChoiceParameters($bookParameters),'listClass'=>$bookClass]]);
$bookRule=array_values((new App\Service\AdvancedSpellChoices($em,$access))->rules($newBook))[0];
$refuse(fn()=>$service->options($copyCharacter,$newBook->getId(),$owner),409);
$transition=$service->revise($copyCharacter,$oldBook->getId(),$newBook->getId(),[$spells[3]['id']],$legacyBookIds,'Explicit current state',false,true,$owner,[$bookRule->getId()]);
$check($transition['historicalValidation']===false,'Advanced revision remains current-state reconciliation');
$check((int)$db->fetchOne('SELECT retirement_id FROM character_spell_acquisition WHERE id=?',[$legacyBookIds[0]])===$transition['revisionId'],'Legacy acquisition retained with explicit transition journal');
$copyOptions=$service->options($copyCharacter,$newBook->getId(),$owner);
$q=$service->copyQuote($copyCharacter,$newBook->getId(),$spells[4]['id'],$owner);
$copyDecision=['baseCostCp'=>$q['baseCostCp'],'baseMinutes'=>$q['baseMinutes'],'costCp'=>$q['baseCostCp'],'minutes'=>$q['baseMinutes'],'source'=>'Synthetic inspected manuscript','adjustment'=>'none','reason'=>'','confirmed'=>true];
$service->copy($copyCharacter,$newBook->getId(),$spells[4]['id'],$copyDecision,$owner);
$afterCopy=$service->options($copyCharacter,$newBook->getId(),$owner);
$check($afterCopy['choices'][0]['remaining']===$copyOptions['choices'][0]['remaining'],'Advanced copy spends no level opportunity');
$refuse(fn()=>$service->copy($copyCharacter,$newBook->getId(),$spells[4]['id'],$copyDecision,$owner),409);

$independentLevel=new CharacterClassLevel($advancedChar,$bookClass,13); $advancedChar->addClassLevel($independentLevel); $em->persist($independentLevel); $em->flush();
$service->acquire($advancedChar,$bookPolicy->getId(),[['spellId'=>$spells[5]['id'],'classLevelId'=>$independentLevel->getId()]],'manual-reconciliation','Same spell through a different acquisition class',$owner);
$check($service->options($advancedChar,$advancedPolicy->getId(),$owner)['used']['known']===1 && $service->options($advancedChar,$bookPolicy->getId(),$owner)['used']['spellbook']===1,'Advanced multiclass choices retain independent quotas for the same spell');
foreach ([
    ['INSERT INTO character_spell_qualification SELECT * FROM character_spell_qualification WHERE acquisition_id=?',[$manualAdvanced]],
    ["UPDATE character_spell_qualification SET reason=' ' WHERE acquisition_id=?",[$manualAdvanced]],
    ['UPDATE character_spell_qualification SET rule_id=-1 WHERE acquisition_id=?',[$manualAdvanced]],
    ['DELETE FROM spell_policy_choice_rule WHERE id=?',[$byKey['unrestricted']]],
    ["UPDATE spell_policy_choice_rule SET parameters='[]' WHERE id=?",[$byKey['restricted']]],
    ['UPDATE spellcasting_policy_definition SET base_policy_id=id WHERE id=?',[$lorePolicy->getId()]],
    ['UPDATE character_spell_choice SET opportunity_level_id=-1 WHERE acquisition_id=?',[$thirdAdvanced]],
] as [$sql,$params]) {
    $db->createSavepoint('invalid_advanced');
    try { $db->executeStatement($sql,$params); throw new RuntimeException('Advanced constraint absent'); }
    catch (Doctrine\DBAL\Exception\DriverException) { $db->rollbackSavepoint('invalid_advanced'); $check(true,'Advanced migration constraint enforced'); }
    $db->releaseSavepoint('invalid_advanced');
}
$check($advancedSessionsBefore===$db->fetchOne("SELECT md5(COALESCE(string_agg(row_to_json(s)::text,'' ORDER BY s.id),'')) FROM character_session_state s"),'Advanced choices never change session states');
