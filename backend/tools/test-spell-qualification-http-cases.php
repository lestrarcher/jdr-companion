<?php
declare(strict_types=1);
use App\Entity\{Character, Campaign, CharacterClass, CharacterSubclass, CharacterClassLevel};
// HTTP qualification: explicit route confirmation, owner-only and atomic failures.
$httpQualified=new Character($em->find(Campaign::class,$campaign->getId()),'qualification-http','Qualification HTTP fixture',Character::TYPE_PLAYER);
$em->persist($httpQualified);
for ($i=1;$i<=3;++$i) {
    $level=new CharacterClassLevel($httpQualified,$em->find(CharacterClass::class,$advancedClass->getId()),$i,$i===3?$em->find(CharacterSubclass::class,$advancedSub->getId()):null);
    $httpQualified->addClassLevel($level); $em->persist($level);
}
$em->flush();
$httpAcquisition=$service->acquire($httpQualified,$advancedPolicy->getId(),[$advancedChoice($spells[4]['id'],$httpQualified->getClassLevels()->last()->getId(),$byKey['unrestricted'])],'manual-reconciliation','HTTP historical fixture',$owner)[0];
$qualificationPath='/characters/'.$httpQualified->getId().'/spell-acquisitions/'.$advancedPolicy->getId().'/'.$httpAcquisition.'/qualification';
$qualificationBody=['ruleId'=>$byKey['unrestricted'],'reason'=>'MJ confirms the declared route'];
$qualificationFingerprint=static function () use ($db,$metadata): array {
    $rows=[];
    foreach ($metadata as $meta) $rows[$meta->getTableName()]=$db->fetchOne("SELECT md5(COALESCE(string_agg(row_to_json(t)::text,'' ORDER BY row_to_json(t)::text),'')) FROM ".$db->quoteIdentifier($meta->getTableName()).' t');
    return $rows;
};
foreach ([
    [null,$qualificationPath,$qualificationBody,401],
    [$other,$qualificationPath,$qualificationBody,404],
    [$admin,$qualificationPath,$qualificationBody,404],
    [$owner,str_replace('/'.$httpAcquisition.'/qualification','/'.$qualificationRaceId.'/qualification',$qualificationPath),$qualificationBody,404],
    [$owner,'/characters/'.$reconciled->getId().'/spell-acquisitions/'.$advancedPolicy->getId().'/'.$manualAdvanced.'/qualification',$qualificationBody,404],
    [$owner,$qualificationPath,['ruleId'=>$byKey['unrestricted']],400],
    [$owner,$qualificationPath,array_replace($qualificationBody,['reason'=>'  ']),400],
    [$owner,$qualificationPath,array_replace($qualificationBody,['ruleId'=>$byKey['restricted']]),409],
    [$owner,$qualificationPath,array_replace($qualificationBody,['ruleId'=>(string)$byKey['unrestricted']]),400],
    [$owner,$qualificationPath,$qualificationBody+['originKnown'=>true],400],
] as [$httpActor,$endpoint,$body,$status]) {
    $before=$qualificationFingerprint();
    $response=$http($httpActor,'POST',$endpoint,$body);
    $check($response->getStatusCode()===$status,'Qualification HTTP expected '.$status.': '.$response->getContent());
    $check($before===$qualificationFingerprint(),'Rejected HTTP qualification preserves all tables');
}
$initialHttpChoice=$db->fetchAssociative('SELECT * FROM character_spell_choice WHERE acquisition_id=?',[$httpAcquisition]);
$response=$http($owner,'POST',$qualificationPath,$qualificationBody);
$check($response->getStatusCode()===201,'Qualification HTTP success: '.$response->getContent());
$result=json_decode($response->getContent(),true)['qualification'];
$check($result['acquisitionId']===$httpAcquisition && $result['policyId']===$advancedPolicy->getId() && $result['ruleId']===$byKey['unrestricted'] && $result['originKnown']===true && $result['decidedById']===$owner->getId() && $result['reason']===$qualificationBody['reason'] && is_string($result['decidedAt']),'HTTP returns qualification identity and audit metadata');
$check($db->fetchAssociative('SELECT * FROM character_spell_choice WHERE acquisition_id=?',[$httpAcquisition])===$initialHttpChoice,'HTTP preserves original historical decision');
$optionsResponse=$http($owner,'GET','/characters/'.$httpQualified->getId().'/spell-acquisitions/'.$advancedPolicy->getId());
$effective=array_column(json_decode($optionsResponse->getContent(),true)['acquisitions'],null,'id')[$httpAcquisition];
$check(filter_var($effective['origin_known'],FILTER_VALIDATE_BOOL),'GET options reflects HTTP qualification');
$before=$qualificationFingerprint();
$check($http($owner,'POST',$qualificationPath,$qualificationBody)->getStatusCode()===409,'Repeated HTTP qualification conflicts');
$check($before===$qualificationFingerprint(),'Repeated HTTP qualification leaves journal unchanged');
