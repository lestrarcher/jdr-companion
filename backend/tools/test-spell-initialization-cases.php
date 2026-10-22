<?php
declare(strict_types=1);
use App\Entity\{Character, CharacterClass, CharacterSubclass, CharacterClassLevel};

foreach (['campaign', 'owner', 'admin', 'other'] as $name) $$name = $em->find(($$name)::class, ($$name)->getId());
$initialClass = new CharacterClass('initial-wizard', 'Synthetic wizard', 6, 20);
$initialClass->setSpellcastingProgression(App\Enum\SpellcastingProgressionType::Full);
$em->persist($initialClass);
$initialCharacter = new Character($campaign, 'initial-wizard', 'Synthetic wizard', Character::TYPE_PLAYER);
$initialLevel = new CharacterClassLevel($initialCharacter, $initialClass, 1);
$initialCharacter->addClassLevel($initialLevel); $em->persist($initialCharacter); $em->persist($initialLevel); $em->flush();
$initialPolicy = $service->definePolicy($initialClass, App\Service\Dnd2014SpellPolicies::get('wizard-2014'), $admin);
$initialSpells = [];
foreach ([0,0,0,0,1,1,1,1,1,1,1,2] as $i=>$level) {
    $initialSpells[] = $catalogue->create(['slug'=>'initial-spell-'.$i, 'name'=>'Initial '.$i, 'level'=>$level,
        'school'=>'abjuration', 'castingTime'=>'Fixture', 'range'=>'Fixture', 'duration'=>'Fixture',
        'description'=>'Synthetic', 'classIds'=>[$initialClass->getId()]], $admin, true);
}
$payload = static fn (array $indices, string $reason='Initial fixture'): array => ['policyId'=>$initialPolicy->getId(),
    'reason'=>$reason, 'choices'=>array_map(static fn ($i)=>['spellId'=>$initialSpells[$i]['id'], 'ruleId'=>null], $indices)];
$initialSnapshot = static fn (): array => $db->fetchAllAssociative('SELECT * FROM character_spell_acquisition WHERE character_id=? ORDER BY id', [$initialCharacter->getId()]);
$first = $service->initialize($initialCharacter, $payload([0]), $owner);
$check(count($first['createdAcquisitionIds']) === 1, 'Initial choices can be recorded progressively');
$before = $initialSnapshot();
$repeat = $service->initialize($initialCharacter, $payload([0]), $owner);
$check($repeat['replayedAcquisitionIds'] === $first['createdAcquisitionIds'] && $repeat['createdAcquisitionIds'] === [] && $before === $initialSnapshot(), 'Exact replay preserves decision and quota');
$refuse(fn ()=>$service->initialize($initialCharacter, $payload([0], 'Different reason'), $owner), 409);
$refuse(fn ()=>$service->initialize($initialCharacter, $payload([0,0]), $owner), 400);
$refuse(fn ()=>$service->initialize($initialCharacter, $payload([1]), $other), 404);
$refuse(fn ()=>$service->initialize($initialCharacter, $payload([1,11]), $owner), 400);
$check($before === $initialSnapshot(), 'Ineligible second choice rolls back the first insertion');
$mixed = $service->initialize($initialCharacter, $payload([0,1,2,4,5,6,7,8,9]), $owner);
$check($mixed['replayedAcquisitionIds'] === $first['createdAcquisitionIds'] && count($mixed['createdAcquisitionIds']) === 8, 'Mixed replay and new choices complete progressively without extra quota');
$complete = $initialSnapshot();
$options = $service->options($initialCharacter, $initialPolicy->getId(), $owner);
$check(count($complete) === 9 && $options['used']['cantrips'] === 3 && $options['used']['spellbook'] === 6, 'Wizard obtains exactly three cantrips and six book spells');
$check($complete[0] === $before[0], 'Progressive completion leaves previous acquisition intact');
foreach ($complete as $row) $check($row['provenance'] === 'level-history' && (int)$row['policy_definition_id'] === $initialPolicy->getId() && (int)$row['class_level_id'] === $initialLevel->getId(), 'Initial provenance uses real level and policy version');
$refuse(fn ()=>$service->initialize($initialCharacter, $payload([3]), $owner), 409);
$refuse(fn ()=>$service->initialize($initialCharacter, $payload([10]), $owner), 409);
$check($complete === $initialSnapshot(), 'Exceeded quotas preserve completed choices');
$url = '/characters/'.$initialCharacter->getId().'/spell-acquisitions/initialization';
$response = $http($owner, 'POST', $url, $payload([0]));
$check($response->getStatusCode() === 200 && json_decode($response->getContent(), true)['replayedAcquisitionIds'] === $first['createdAcquisitionIds'], 'HTTP exact replay returns existing identity');
$check($http($other, 'POST', $url, $payload([0]))->getStatusCode() === 404, 'HTTP initialization remains owner-only');
foreach (['campaign', 'owner', 'admin', 'initialClass', 'initialPolicy'] as $name) $$name = $em->find(($$name)::class, ($$name)->getId());
$race = new Character($campaign, 'initial-race', 'Synthetic concurrent wizard', Character::TYPE_PLAYER);
$raceLevel = new CharacterClassLevel($race, $initialClass, 1); $race->addClassLevel($raceLevel);
$em->persist($race); $em->persist($raceLevel); $em->flush();
$missingInput = $payload([0]); $missingInput['policyId'] = 2147483647;
$refuse(fn ()=>$service->initialize($race, $missingInput, $owner), 404);
$badRule = $payload([0]); $badRule['choices'][0]['ruleId'] = 1;
$refuse(fn ()=>$service->initialize($race, $badRule, $owner), 400);
$ambiguousClass = new CharacterClass('initial-ambiguous', 'Ambiguous fixture', 6, 20);
$ambiguousSub = new CharacterSubclass($ambiguousClass, 'initial-ambiguous-sub', 'Ambiguous subclass');
$em->persist($ambiguousClass); $em->persist($ambiguousSub);
$ambiguousCharacter = new Character($campaign, 'initial-ambiguous', 'Ambiguous fixture', Character::TYPE_PLAYER);
$ambiguousLevel = new CharacterClassLevel($ambiguousCharacter, $ambiguousClass, 1, $ambiguousSub);
$ambiguousCharacter->addClassLevel($ambiguousLevel); $em->persist($ambiguousCharacter); $em->persist($ambiguousLevel); $em->flush();
$ambiguousPolicy = $service->definePolicy($ambiguousClass, App\Service\Dnd2014SpellPolicies::get('wizard-2014'), $admin);
$service->definePolicy($ambiguousSub, App\Service\Dnd2014SpellPolicies::get('wizard-2014'), $admin);
$ambiguousInput = $payload([0]); $ambiguousInput['policyId'] = $ambiguousPolicy->getId();
$refuse(fn ()=>$service->initialize($ambiguousCharacter, $ambiguousInput, $owner), 409);
$second = new CharacterClassLevel($ambiguousCharacter, $ambiguousClass, 2);
$ambiguousCharacter->addClassLevel($second); $em->persist($second); $em->flush();
$refuse(fn ()=>$service->initialize($ambiguousCharacter, $ambiguousInput, $owner), 409);
$check((int)$db->fetchOne('SELECT count(*) FROM character_spell_acquisition WHERE character_id=?', [$ambiguousCharacter->getId()]) === 0, 'Absent or ambiguous policy and advanced character create no decisions');
if ($concurrent) {
    $db->commit(); $db->beginTransaction();
    $db->fetchOne('SELECT id FROM character WHERE id=? FOR UPDATE', [$race->getId()]);
    $workers = [];
    for ($i=0; $i<2; ++$i) {
        $pipes = [];
        $process = proc_open([PHP_BINARY, __DIR__.'/test-character-spell-acquisitions.php', '--worker', $isolatedSchema,
            (string)$race->getId(), (string)$initialPolicy->getId(), (string)$raceLevel->getId(), (string)$initialSpells[0]['id'], (string)$owner->getId(), 'initialization'],
            [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
        if (!is_resource($process)) throw new RuntimeException('Cannot start initialization worker');
        fclose($pipes[0]); $check(trim(fgets($pipes[1])) === 'ready', 'Initialization worker ready'); $workers[] = [$process,$pipes];
    }
    $db->commit(); $statuses = [];
    foreach ($workers as [$process,$pipes]) {
        $statuses[] = trim(stream_get_contents($pipes[1])); $errors = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        $check(proc_close($process) === 0, 'Initialization worker completes: '.$errors);
    }
    sort($statuses); $check($statuses === ['200','201'], 'Concurrent identical choices create once and replay once');
    $check((int)$db->fetchOne('SELECT count(*) FROM character_spell_acquisition WHERE character_id=?', [$race->getId()]) === 1, 'Concurrent initialization consumes one quota');
    $db->beginTransaction();
}
