<?php

declare(strict_types=1);

// Included only inside test-spell-slot-synchronization.php's temporary schema.
// Read the approved reference configuration; never import into the real schema.
$reference = json_decode(file_get_contents(__DIR__.'/../data/reference/dnd-2014-class-features.json'), true, flags: JSON_THROW_ON_ERROR);
$warlock = new \App\Entity\CharacterClass('warlock', 'Warlock', 8, 1, \App\Enum\SpellcastingProgressionType::Pact);
$em->persist($warlock);
$pactDefinitionData = array_values(array_filter($reference['resources'], static fn(array $entry): bool => $entry['slug'] === 'warlock-pact-slots'))[0];
$pactDefinition = new \App\Entity\TrackableResourceDefinition('warlock-pact-slots', $pactDefinitionData['name'], \App\Enum\ResourceRechargeType::from($pactDefinitionData['rechargeType']));
$em->persist($pactDefinition);
foreach ($reference['resourceRules'] as $rule) {
    if ($rule['resourceSlug'] !== 'warlock-pact-slots') continue;
    $em->persist(\App\Entity\TrackableResourceRule::forClass($pactDefinition, $warlock, $rule['unlockLevel'], $rule['maximumOverride']));
}
$em->flush();
$pactCalculator = new \App\Service\CharacterSpellSlotCalculator();
$factory = new \App\Service\CharacterSessionStateFactory($hp, $resources, $pactCalculator, new \App\Repository\CharacterActiveEffectRepository($registry));
foreach ([false, true] as $multiclass) {
    $pactCharacter = new \App\Entity\Character($campaign, 'pact-test-'.(int)$multiclass, 'Pact test', \App\Entity\Character::TYPE_PLAYER);
    $em->persist($pactCharacter);
    if ($multiclass) {
        $pactCharacter->addClassLevel(new \App\Entity\CharacterClassLevel($pactCharacter, $class, 1, null, 4, \App\Enum\HitPointGainMethod::Average));
    }
    for ($warlockLevel=1; $warlockLevel<=($multiclass ? 19 : 20); ++$warlockLevel) {
        $pactCharacter->addClassLevel(new \App\Entity\CharacterClassLevel($pactCharacter, $warlock, $pactCharacter->getTotalLevel()+1, null, 5, \App\Enum\HitPointGainMethod::Average));
        $em->flush();
        $expectedMaximum = $warlockLevel === 1 ? 1 : ($warlockLevel < 11 ? 2 : ($warlockLevel < 17 ? 3 : 4));
        $expectedLevel = [1,1,2,2,3,3,4,4,5,5,5,5,5,5,5,5,5,5,5,5][$warlockLevel-1];
        $check($resources->resolve($pactCharacter)['warlock-pact-slots']->getMaximum() === $expectedMaximum, 'Canonical pact resource count at level '.$warlockLevel);
        $check($pactCalculator->pactSlotLevel($pactCharacter) === $expectedLevel, 'Pact slot level independent of total character level');
        $check($pactCalculator->calculate($pactCharacter) === ($multiclass ? [1=>2] : []), 'Pact never contributes standard slots');
        $profileResources = array_column($profileSerializer->serialize($pactCharacter)['resources'], null, 'slug');
        $check($profileResources['warlock-pact-slots']['slotLevel'] === $expectedLevel, 'Profile exposes derived pact slot level');
        $initial = $factory->create($pactCharacter);
        $check($pool($initial, 'warlock-pact-slots')['currentValue'] === $expectedMaximum, 'Factory initializes canonical pact resource');
        foreach ($initial['resources'] as &$entry) {
            if ($entry['id'] === 'warlock-pact-slots') $entry['currentValue'] = $expectedMaximum-1;
            if ($entry['id'] === 'spell-slot-1') $entry['currentValue'] = 1;
        }
        unset($entry);
        $recalculated = $sync->synchronize($pactCharacter, $initial);
        $check($recalculated === $initial, 'Recalculation retains consumption without metadata in persisted state');
        foreach ([\App\Entity\RestRequest::TYPE_SHORT_REST, \App\Entity\RestRequest::TYPE_LONG_REST] as $restType) {
            $pactSession = new \App\Entity\CharacterSessionState(new \App\Entity\GameSession($campaign, 'pact-rest', 'Pact rest'), $pactCharacter, $initial);
            $rest->apply($pactSession, $restType);
            $check($pool($pactSession->getState(), 'warlock-pact-slots')['currentValue'] === $expectedMaximum, 'Both rests restore pact slots');
            if ($multiclass) $check($pool($pactSession->getState(), 'spell-slot-1')['currentValue'] === ($restType === \App\Entity\RestRequest::TYPE_SHORT_REST ? 1 : 2), 'Only long rest restores standard slots');
        }
    }
}
$check($pactCalculator->pactSlotLevel($character) === null, 'No canonical warlock means no invented pact level');
