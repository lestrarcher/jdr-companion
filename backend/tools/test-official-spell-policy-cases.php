<?php
declare(strict_types=1);
// Also usable as a standalone entrypoint; isolation remains owned by the harness.
if (!isset($em)) {
    $argv[1] = '--official-policies';
    require __DIR__.'/test-character-spell-acquisitions.php';
    return;
}
// Included in the acquisition harness: all tables are temporary, rolled back on exit.
foreach (['campaign','owner','admin'] as $name) $$name = $em->find(($$name)::class, ($$name)->getId());
$officialClasses = [];
foreach (['bard','cleric','paladin','ranger','artificer','druid','wizard','sorcerer','warlock','fighter','rogue'] as $slug) {
    $progression = match ($slug) {
        'paladin','ranger' => App\Enum\SpellcastingProgressionType::Half,
        'artificer' => App\Enum\SpellcastingProgressionType::Artificer,
        'warlock' => App\Enum\SpellcastingProgressionType::Pact,
        'fighter','rogue' => App\Enum\SpellcastingProgressionType::None,
        default => App\Enum\SpellcastingProgressionType::Full,
    };
    $officialClasses[$slug] = new App\Entity\CharacterClass($slug, $slug, 8, 3, $progression);
    $em->persist($officialClasses[$slug]);
}
$subclasses = [];
$subSpecs = ['college-of-lore'=>'bard','circle-of-the-land'=>'druid','arcane-trickster'=>'rogue','eldritch-knight'=>'fighter'];
foreach (App\Service\Dnd2014SubclassSpellCatalogue::entries() as [$parent,$slug]) $subSpecs[$slug] = $parent;
foreach ($subSpecs as $slug=>$parent) {
    $subclasses[$slug] = new App\Entity\CharacterSubclass($officialClasses[$parent], $slug, $slug);
    $em->persist($subclasses[$slug]);
}
$em->flush();
$spellSlugs = ['mage-hand'=>0];
foreach (App\Service\Dnd2014SubclassSpellCatalogue::entries() as [$parent,$slug,$level,$spell,$mode]) {
    $spellSlugs[$spell] = $mode === 'grant-known' && in_array($spell, ['light','spare-the-dying','mage-hand','chill-touch','guidance','sacred-flame','mind-sliver'], true) ? 0 : 1;
}
foreach ($spellSlugs as $slug=>$level) $catalogue->create(['slug'=>$slug,'name'=>$slug,'level'=>$level,'school'=>'abjuration','castingTime'=>'Fixture','range'=>'Fixture','duration'=>'Fixture','description'=>'Synthetic reference','classIds'=>[$officialClasses['wizard']->getId()]], $admin, true);
$installer = new App\Service\Dnd2014SpellPolicyInstaller($em);
$before = $db->fetchAllAssociative('SELECT * FROM character_spell_acquisition ORDER BY id');
$statesBefore = $db->fetchAllAssociative('SELECT * FROM character_session_state ORDER BY id');
$policiesBefore = $db->fetchAllAssociative('SELECT * FROM spellcasting_policy_definition ORDER BY id');
$count = (int)$db->fetchOne('SELECT count(*) FROM spellcasting_policy_definition');
$dry = $installer->run();
$check(!$dry['missing'] && !$dry['conflicts'] && !$dry['applied'], 'Complete reference dry-run is valid');
$check($subclasses['arcane-trickster']->getSpellcastingProgression() === null, 'Dry-run leaves managed subclass metadata unchanged');
$check((int)$db->fetchOne('SELECT count(*) FROM spellcasting_policy_definition') === $count, 'Dry-run never persists definitions');
$applied = $installer->run(true);
$check($applied['applied'], 'Reference bootstrap applies in isolated tables');
$again = $installer->run(true);
$check($again['applied'] && !$again['grants'] && !$again['substitutions'] && !$again['progressions'] && !array_filter($again['policies'], static fn($p)=>$p['action'] === 'create'), 'Second bootstrap is idempotent');
$check($before === $db->fetchAllAssociative('SELECT * FROM character_spell_acquisition ORDER BY id'), 'Bootstrap preserves every existing acquisition');
$check($statesBefore === $db->fetchAllAssociative('SELECT * FROM character_session_state ORDER BY id'), 'Bootstrap preserves session resources and preparation');
foreach ($policiesBefore as $row) $check($row === $db->fetchAssociative('SELECT * FROM spellcasting_policy_definition WHERE id=?', [$row['id']]), 'Existing policy remains byte-for-byte unchanged');
$policies = [];
foreach ($officialClasses as $slug=>$class) {
    $rows = $em->getRepository(App\Entity\SpellcastingPolicyDefinition::class)->findBy(['characterClass'=>$class]);
    if ($rows) $policies[$slug] = $rows[0];
}
$makeOfficial = static function (string $slug, int $n, ?string $sub = null) use ($em,$campaign,$officialClasses,$subclasses): App\Entity\Character {
    static $serial = 0;
    $c = new App\Entity\Character($campaign, 'official-'.$slug.'-'.$n.'-'.++$serial, 'Fixture', App\Entity\Character::TYPE_PLAYER);
    $c->getAbilityScore(App\Enum\Ability::Wisdom)->setBaseValue(16);
    $c->getAbilityScore(App\Enum\Ability::Charisma)->setBaseValue(16);
    $em->persist($c);
    for ($i=1; $i<=$n; ++$i) {
        $l = new App\Entity\CharacterClassLevel($c,$officialClasses[$slug],$i,$sub ? $subclasses[$sub] : null);
        $c->addClassLevel($l); $em->persist($l);
    }
    $em->flush();
    return $c;
};
$pool = [];
foreach ([0=>6,1=>24,2=>2,3=>2,4=>2,5=>2,6=>2,7=>2] as $level=>$number) {
    for ($i=0; $i<$number; ++$i) $pool[$level][] = $catalogue->create(['slug'=>"official-pool-$level-$i",'name'=>"Fixture $level $i",'level'=>$level,'school'=>'enchantment','castingTime'=>'Fixture','range'=>'Fixture','duration'=>'Fixture','description'=>'Synthetic','classIds'=>array_map(static fn($c)=>$c->getId(), array_values($officialClasses))],$admin,true);
}
$preparation = new App\Service\CharacterSpellPreparation($service, new App\Service\CharacterAbilityCalculator());
$expected = [
    'bard'=>[[2,4,1],[2,5,1],[3,8,3],[4,14,5],[4,16,7]],
    'cleric'=>[[3,0,1],[3,0,1],[4,0,3],[5,0,5],[5,0,7]],
    'paladin'=>[[0,0,0],[0,0,1],[0,0,2],[0,0,3],[0,0,4]],
    'ranger'=>[[0,0,0],[0,2,1],[0,4,2],[0,6,3],[0,8,4]],
];
foreach ($expected as $slug=>$rows) foreach ([1,2,5,10,13] as $index=>$level) {
    $c = $makeOfficial($slug,$level);
    $p = $policies[$slug];
    $options = $service->options($c,$p->getId(),$owner);
    [$cantrips,$known,$max] = $rows[$index];
    $check($options['limits']['cantrips'] === $cantrips && $options['limits']['known'] === $known && $options['limits']['maxSpellLevel'] === $max, "$slug $level: independent official quotas");
    $plan = $preparation->plan($c,$options,[]);
    if ($slug === 'cleric' || ($slug === 'paladin' && $level >= 2)) {
        $base = $slug === 'cleric' ? $level : intdiv($level,2);
        $check($plan['maximum'] === $base+3, "$slug $level: preparation ability and class level");
        $c->getAbilityScore($slug === 'cleric' ? App\Enum\Ability::Wisdom : App\Enum\Ability::Charisma)->setBaseValue(1);
        $check($preparation->plan($c,$options,[])['maximum'] === max(1,$base-5), "$slug $level: preparation minimum one");
        $c->getAbilityScore($slug === 'cleric' ? App\Enum\Ability::Wisdom : App\Enum\Ability::Charisma)->setBaseValue(16);
        $state = $preparation->update($c,$p->getId(),[$pool[1][0]['id']],[]);
        $check($state['preparedSpells'][(string)$p->getId()] === [$pool[1][0]['id']], "$slug $level: daily preparation uses existing service");
    } else $check($plan === null, "$slug $level: no preparation quota");
    $historical = $service->historicalPreview($c,$p->getId(),$owner);
    $categories = array_column($historical['categories'],null,'label');
    if ($cantrips) $check($categories['cantrips']['remaining'] === $cantrips, "$slug $level: historical cantrips");
    if ($known) {
        $secrets = $slug === 'bard' && $level >= 10 ? 2 : 0;
        $check($categories['ordinary']['remaining'] === $known-$secrets, "$slug $level: historical ordinary opportunities");
        if ($secrets) $check($categories['magical-secrets']['remaining'] === 2, 'Secrets retain distinct historical budget');
        $input = ['choices'=>[['spellId'=>$pool[1][0]['id'],'ruleId'=>$categories['ordinary']['ruleId']]],'expectedAcquisitionIds'=>[],'confirmed'=>true];
        $result = $service->completeHistorical($c,$p->getId(),$input,$owner);
        $check(count($result['createdAcquisitionIds']) === 1, "$slug $level: historical completion");
        $check($service->completeHistorical($c,$p->getId(),$input,$owner)['createdAcquisitionIds'] === [], "$slug $level: replay is idempotent");
    }
}
foreach (['cleric'=>'life-domain','paladin'=>'vengeance','ranger'=>'gloom-stalker'] as $slug=>$sub) {
    foreach ([1,2,5,10,13] as $level) {
        $c = $makeOfficial($slug,$level,$sub); $p = $policies[$slug];
        $o = $service->options($c,$p->getId(),$owner);
        $count = count(array_filter(App\Service\Dnd2014SubclassSpellCatalogue::entries(),static fn($r)=>$r[1] === $sub && $r[2] <= $level));
        $check(count($o['automatic']) === $count, "$sub $level: only unlocked subclass spells");
        $check($o['preparation']['automaticCounted'] === 0 && $o['used']['known'] === 0, "$sub $level: automatic grants consume no quota");
    }
}
$multi = $makeOfficial('cleric',5);
for ($n=1;$n<=5;++$n) { $l=new App\Entity\CharacterClassLevel($multi,$officialClasses['paladin'],5+$n); $multi->addClassLevel($l); $em->persist($l); }
$em->flush();
$check((new App\Service\CharacterSpellSlotCalculator())->calculate($multi) === [1=>4,2=>3,3=>3,4=>1], 'Cleric 5 / Paladin 5 has caster-level 7 slots');
$check($service->options($multi,$policies['cleric']->getId(),$owner)['limits']['maxSpellLevel'] === 3 && $service->options($multi,$policies['paladin']->getId(),$owner)['limits']['maxSpellLevel'] === 2, 'Multiclass slots do not unlock higher class spell choices');
$refuse(fn()=>$preparation->update($multi,$policies['cleric']->getId(),[$pool[4][0]['id']],[]),422);
$wizardOnly = (int)$db->fetchOne("SELECT id FROM spell_definition WHERE slug='bless'");
$refuse(fn()=>$preparation->update($multi,$policies['cleric']->getId(),[$wizardOnly],[]),422);
$check(!in_array($wizardOnly,array_column($service->options($multi,$policies['cleric']->getId(),$owner)['available'],'id'),true), 'Prepared catalogue does not leak a different class list');
foreach (['bard','cleric'] as $slug) {
    $c=$makeOfficial($slug,1); $p=$policies[$slug];
    $rules=array_column($service->options($c,$p->getId(),$owner)['choices'],null,'key');
    $choices=[];
    foreach (array_slice($pool[0],0,$slug === 'bard' ? 2 : 3) as $s) $choices[]=['spellId'=>$s['id'],'ruleId'=>$rules['cantrips']['ruleId']];
    if ($slug === 'bard') foreach (array_slice($pool[1],0,4) as $s) $choices[]=['spellId'=>$s['id'],'ruleId'=>$rules['ordinary']['ruleId']];
    $result=$service->initialize($c,['policyId'=>$p->getId(),'reason'=>'Official initial fixture','choices'=>$choices],$owner);
    $check(count($result['createdAcquisitionIds']) === count($choices), "$slug: level-one initialization");
}
foreach (['bard'=>1,'ranger'=>2] as $slug=>$start) {
    $c=$makeOfficial($slug,$start); $p=$policies[$slug];
    $rule=array_column($service->options($c,$p->getId(),$owner)['choices'],null,'key')['ordinary']['ruleId'];
    $old=$service->acquire($c,$p->getId(),[['spellId'=>$pool[1][0]['id'],'ruleId'=>$rule,'classLevelId'=>$c->getClassLevels()->last()->getId()]],'level-history','Fixture initial known',$owner)[0];
    $otherOld=$service->acquire($c,$p->getId(),[['spellId'=>$pool[1][4]['id'],'ruleId'=>$rule,'classLevelId'=>$c->getClassLevels()->last()->getId()]],'level-history','Fixture second known',$owner)[0];
    $l=new App\Entity\CharacterClassLevel($c,$officialClasses[$slug],$start+1); $c->addClassLevel($l); $em->persist($l); $em->flush();
    $payload=static fn($s)=>['policyId'=>$p->getId(),'spellId'=>$s['id'],'ruleId'=>$rule];
    (new App\Service\CharacterLevelUpSpells($em,$service))->apply($l,[$payload($pool[1][1]),$payload($pool[1][2])+['replacesAcquisitionId'=>$old,'replacementConfirmed'=>true]],false);
    $o=$service->options($c,$p->getId(),$owner);
    $check($o['used']['known'] === 3 && !in_array($old,array_column($o['acquisitions'],'id')), "$slug: level-up replacement and new spell have independent budgets");
    $refuse(fn()=>$service->acquire($c,$p->getId(),[['spellId'=>$pool[1][3]['id'],'ruleId'=>$rule,'classLevelId'=>$l->getId(),'replacesId'=>$otherOld]],'level-history','Second replacement',$owner),409);
}
$lore=$makeOfficial('bard',10,'college-of-lore');
$loreId=$service->status($lore,$owner)[0]['policyIds'][0];
$lp=$service->historicalPreview($lore,$loreId,$owner);
$lc=array_column($lp['categories'],null,'label');
$check($lc['additional-secrets']['remaining'] === 2 && $lc['magical-secrets']['remaining'] === 2 && $lc['ordinary']['remaining'] === 12, 'Lore 10 separates both secrets budgets from ordinary choices');
$nature=$makeOfficial('cleric',1,'nature-domain');
$natureId=$service->status($nature,$owner)[0]['policyIds'][0];
$nc=array_column($service->historicalPreview($nature,$natureId,$owner)['categories'],null,'label');
$check($nc['nature-domain-cantrip']['remaining'] === 1 && $nc['cantrips']['remaining'] === 3, 'Nature bonus druid cantrip does not consume cleric cantrip quota');
$trickster=$makeOfficial('rogue',3,'arcane-trickster');
$check((new App\Service\CharacterSpellSlotCalculator())->calculate($trickster) === [1=>2], 'Installed Arcane Trickster reference supplies third-caster slots');
$tid=$service->status($trickster,$owner)[0]['policyIds'][0];
$tc=array_column($service->options($trickster,$tid,$owner)['choices'],null,'key');
$check(count($tc['required-cantrip']['available']) === 1 && $tc['required-cantrip']['available'][0]['slug'] === 'mage-hand', 'Arcane Trickster requires Mage Hand');
$check($tc['restricted']['remaining'] === 2 && $tc['unrestricted']['remaining'] === 1 && $tc['cantrips']['remaining'] === 2, 'Arcane Trickster level-three school and cantrip budgets');
// A conflicting existing grant blocks the whole bootstrap; it is never overwritten.
$existing = $em->getRepository(App\Entity\SpellSubclassGrant::class)->findOneBy(['subclass'=>$subclasses['life-domain']]);
$existing->setQuotaImpact(App\Enum\SpellQuotaImpact::Counts); $em->flush();
$conflict = $installer->run(true);
$check(!$conflict['applied'] && $conflict['conflicts'] !== [], 'Conflicting existing grant blocks application');
$check($existing->getQuotaImpact() === App\Enum\SpellQuotaImpact::Counts, 'Conflicting user configuration remains untouched');
