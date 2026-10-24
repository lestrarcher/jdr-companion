<?php

declare(strict_types=1);

// Included by test-player-spells.php inside its isolated temporary tables/sequences.
use App\Dto\SpellcastingPolicy;
use App\Entity\{Character, CharacterClass, CharacterClassLevel, CharacterSessionState, GameSession, RestRequest, SpellClassListEntry, SpellDefinition, SpellcastingPolicyDefinition};
use App\Enum\{Ability, HitPointGainMethod, SpellcastingProgressionType};

$stored = static fn(CharacterSessionState $session): array => $db->fetchAssociative(
    'SELECT state, revision FROM character_session_state WHERE id=?', [$session->getId()]);
$confirm = static function (CharacterSessionState $session, array $selections, int $expected = 200, ?int $revision = null) use ($request, $url, $check, $stored): array {
    $before = $stored($session);
    [$code, $body] = $request('POST', $url($session).'/confirm-preparation', [
        'revision'=>$revision ?? (int)$before['revision'], 'selections'=>$selections,
    ]);
    $check($code === $expected, 'Global preparation: expected '.$expected.', got '.$code.': '.json_encode($body));
    $after = $stored($session);
    if ($expected !== 200) {
        $check($after === $before, 'Rejected confirmation preserves the entire persisted state AND revision');
    } else {
        $check($body['spellPreparationAllowed'] === false, 'Confirmation returns a locked preparation');
        $check(json_decode($after['state'], true)['spellPreparationAllowed'] === false, 'Lock is persisted');
        $check((int)$after['revision'] > (int)$before['revision'] && $body['revision'] === (int)$after['revision'], 'Confirmation returns the new persisted revision');
    }
    return $body;
};
$applyRest = static function (CharacterSessionState $session, string $type) use ($em, $rest, $check, $stored): void {
    $before = json_decode($stored($session)['state'], true);
    $managed = $em->find(CharacterSessionState::class, $session->getId());
    $em->refresh($managed);
    $rest->apply($managed, $type);
    $em->flush();
    $after = json_decode($stored($session)['state'], true);
    $check(($after['preparedSpells'] ?? []) === ($before['preparedSpells'] ?? []), 'Rest retains all active selections');
    $check(($after['spellPreparationAllowed'] ?? true) === ($type === RestRequest::TYPE_LONG_REST ? true : ($before['spellPreparationAllowed'] ?? true)), 'Rest respects preparation lifecycle');
};

// One real spellbook policy: unchanged empty selection, stale revision, locks, reopening.
$singleView = $get($sylveon);
$single = $singleView['preparations'][0];
$unaffectedSpells = static fn(array $view): array => array_values(array_filter($view['spells'], static fn(array $spell): bool => $spell['known'] || $spell['alwaysPrepared']));
$singleSelection = [['policyId'=>$single['policyId'], 'spellIds'=>$single['selected']]];
$check($single['selected'] === [], 'Single-policy fixture starts with a valid empty selection');
$confirm($sylveon, [], 422);
$confirm($sylveon, $singleSelection, 409, 1);
$confirm($sylveon, $singleSelection);
$confirm($sylveon, $singleSelection, 409);
$post($sylveon, 'prepare', ['policyId'=>$single['policyId'], 'spellIds'=>[$spellId]], 409);
$applyRest($sylveon, RestRequest::TYPE_SHORT_REST);
$confirm($sylveon, $singleSelection, 409);
$applyRest($sylveon, RestRequest::TYPE_LONG_REST);
$singleSelection[0]['spellIds'] = [$spellId];
$confirm($sylveon, $singleSelection);
$applyRest($sylveon, RestRequest::TYPE_LONG_REST);
$singleConfirmed = $confirm($sylveon, $singleSelection); // Identical non-empty selection still locks.
$check($unaffectedSpells($singleConfirmed) === $unaffectedSpells($singleView), 'Known and always-prepared spells remain unchanged');

// Synthetic multiclass character: two preparation policies, one known policy, one martial class.
$campaign = $em->find(Character::class, $sylveon->getCharacter()->getId())->getCampaign();
$multi = new Character($campaign, 'test-global-preparation', 'Global preparation', Character::TYPE_PLAYER);
$em->persist($multi);
$definitions = [];
$spellIds = array_column(array_slice($single['available'], 0, 2), 'id');
$check(count($spellIds) === 2, 'Two distinct eligible fixture spells');
foreach (['first', 'second', 'known', 'martial'] as $index => $name) {
    $class = new CharacterClass('test-preparation-'.$name, 'Preparation '.$name, 6, 20,
        $name === 'martial' ? SpellcastingProgressionType::None : SpellcastingProgressionType::Full);
    $level = new CharacterClassLevel($multi, $class, $index + 1, null, 4, HitPointGainMethod::Average);
    $multi->addClassLevel($level);
    $em->persist($class); $em->persist($level);
    if ($name === 'martial') continue;
    $known = $name === 'known';
    $policy = new SpellcastingPolicy('test-preparation-'.$name, 1, Ability::Wisdom, $known ? 'known' : 'prepared',
        array_fill(0, 20, ['maxSpellLevel'=>1, 'cantrips'=>0, 'known'=>$known ? 2 : 0,
            'bookAdditions'=>0, 'replacements'=>0, 'preparationBase'=>$known ? null : 1]), 'Synthetic preparation test');
    $definitions[$name] = new SpellcastingPolicyDefinition($class, $policy);
    $em->persist($definitions[$name]);
    foreach ($spellIds as $id) $em->persist(new SpellClassListEntry($em->find(SpellDefinition::class, $id), $class));
}
$em->flush();
$game = (new GameSession($campaign, 'test-global-preparation', 'Global preparation'))->setStatus(GameSession::STATUS_LIVE);
$state = $factory->create($multi);
unset($state['spellPreparationAllowed']); // Legacy fallback must also work for confirmation.
$first = $definitions['first']->getId(); $second = $definitions['second']->getId();
$state['preparedSpells'] = [$first=>[$spellIds[0]], $second=>[$spellIds[0]], 2147483646=>[99]];
$state['characterActions'] = ['prepared'=>['independent-action'], 'preparationPending'=>false];
$multiSession = new CharacterSessionState($game, $multi, $state);
$em->persist($game); $em->persist($multiSession); $em->flush();
$multiView = $get($multiSession);
$check(count($multiView['preparations']) === 2, 'Only the two prepared policies require selections');
foreach ($multiView['preparations'] as $p) $check($p['maximum'] === 1 && count($p['available']) === 2 && !$p['blocked'], 'Fixture has a quota of one and two eligible spells');
$complete = [['policyId'=>$first, 'spellIds'=>[$spellIds[1]]], ['policyId'=>$second, 'spellIds'=>[$spellIds[1]]]];

$confirm($multiSession, [$complete[0]], 422); // Omitted policy cannot disappear.
$confirm($multiSession, [...$complete, $complete[0]], 422);
$confirm($multiSession, [...$complete, ['policyId'=>2147483647, 'spellIds'=>[]]], 422);
$confirm($multiSession, [...$complete, ['policyId'=>$definitions['known']->getId(), 'spellIds'=>[]]], 422);
$confirm($multiSession, [...$complete, ['policyId'=>$single['policyId'], 'spellIds'=>[]]], 422); // Foreign class.
$confirm($multiSession, ['not-a-list'=>$complete[0]], 422);
foreach (['wrong', 0, -1, true, 1.5] as $invalidId) {
    $confirm($multiSession, [$complete[0], ['policyId'=>$invalidId, 'spellIds'=>[]]], 422);
}
// The first policy is valid and changed; every failure in the second must roll back everything.
foreach ([[$spellIds[0], $spellIds[0]], $spellIds, [2147483647], ['wrong'], [0], [-1], [true], [[$spellIds[0]]], ['key'=>$spellIds[0]]] as $invalidSpells) {
    $confirm($multiSession, [$complete[0], ['policyId'=>$second, 'spellIds'=>$invalidSpells]], 422);
}
$confirm($multiSession, [$complete[0], ['policyId'=>$second, 'spellIds'=>[], 'extra'=>true]], 422);
$confirm($multiSession, $complete, 409, (int)$stored($multiSession)['revision'] + 1);
$beforeConfirmation = json_decode($stored($multiSession)['state'], true);
$result = $confirm($multiSession, $complete);
$after = json_decode($stored($multiSession)['state'], true);
$expectedState = $beforeConfirmation;
$expectedState['preparedSpells'][$first] = [$spellIds[1]];
$expectedState['preparedSpells'][$second] = [$spellIds[1]];
$expectedState['spellPreparationAllowed'] = false;
$check($after === $expectedState, 'Only submitted selections and the lock change; history and actions are preserved');
$check(array_column($result['preparations'], 'selected') === [[$spellIds[1]], [$spellIds[1]]], 'Both policies are active in the response');
$confirm($multiSession, $complete, 409);
$post($multiSession, 'prepare', ['policyId'=>$first, 'spellIds'=>[]], 409);
$applyRest($multiSession, RestRequest::TYPE_LONG_REST);
$complete[1]['spellIds'] = [];
$confirm($multiSession, $complete);
$check(json_decode($stored($multiSession)['state'], true)['preparedSpells'][$second] === [], 'Explicit empty selection clears only its own policy');

// A missing configuration must not silently remove a class from the required policies.
$applyRest($multiSession, RestRequest::TYPE_LONG_REST);
$multi = $em->find(Character::class, $multi->getId());
$unconfigured = new CharacterClass('test-preparation-unconfigured', 'Unconfigured caster', 6, 20, SpellcastingProgressionType::Full);
$unconfiguredLevel = new CharacterClassLevel($multi, $unconfigured, 5, null, 4, HitPointGainMethod::Average);
$multi->addClassLevel($unconfiguredLevel);
$em->persist($unconfigured); $em->persist($unconfiguredLevel); $em->flush();
$confirm($multiSession, $complete, 409);
