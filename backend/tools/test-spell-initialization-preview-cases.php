<?php
declare(strict_types=1);

// Shares synthetic fixtures and fingerprint helper with the isolated preview harness.
foreach (['previewCharacter', 'plainCharacter', 'previewPreparedCharacter', 'owner', 'admin', 'other'] as $name) {
    $$name = $em->find(($$name)::class, ($$name)->getId());
}
$initialization = new App\Service\CharacterSpellInitializationPreview($em, $service, $access);
$campaign = $em->find(App\Entity\Campaign::class, $campaign->getId());
$wizardClass = new App\Entity\CharacterClass('init-wizard', 'Initialization wizard', 6, 20);
$wizardClass->setSpellcastingProgression(App\Enum\SpellcastingProgressionType::Full);
$em->persist($wizardClass);
$wizard = new App\Entity\Character($campaign, 'init-wizard', 'Initialization wizard', App\Entity\Character::TYPE_PLAYER);
$wizardLevel = new App\Entity\CharacterClassLevel($wizard, $wizardClass, 1);
$wizard->addClassLevel($wizardLevel);
$em->persist($wizard); $em->persist($wizardLevel); $em->flush();
$check($initialization->preview($wizard, $owner)['classes'][0]['status'] === 'policy-to-configure', 'Caster without policy remains explicitly unconfigured');
$service->definePolicy($wizardClass, App\Service\Dnd2014SpellPolicies::get('wizard-2014'), $admin);
$wizardOptions = $initialization->preview($wizard, $owner)['classes'][0]['options'];
$check($wizardOptions['remaining']['spellbook'] === 6 && $wizardOptions['remaining']['cantrips'] === 3 && $wizardOptions['acquisitions'] === [], 'Level one wizard exposes six book inscriptions and three cantrips without acquiring them');
$wizardSub = new App\Entity\CharacterSubclass($wizardClass, 'init-wizard-sub', 'Ambiguous fixture');
$em->persist($wizardSub);
$secondLevel = new App\Entity\CharacterClassLevel($wizard, $wizardClass, 2, $wizardSub);
$wizard->addClassLevel($secondLevel); $em->persist($secondLevel); $em->flush();
$service->definePolicy($wizardSub, App\Service\Dnd2014SpellPolicies::get('wizard-2014'), $admin);
$ambiguous = $initialization->preview($wizard, $owner)['classes'][0];
$check($ambiguous['status'] === 'policy-ambiguous' && count($ambiguous['policies']) === 2 && $ambiguous['options'] === null, 'Ambiguous policies expose versions without selecting a winner');
$grantService = new App\Service\SpellSubclassGrants($db, $catalogue);
$grantService->save($previewSpells[6]['id'], null, ['subclassId'=>$advancedSub->getId(),
    'mode'=>'grant-always-prepared', 'unlockLevel'=>3, 'castingClassId'=>$advancedClass->getId(),
    'quotaImpact'=>'free'], $admin, true);
$extraLevel = new App\Entity\CharacterClassLevel($wizard, $plainCharacter->getClassLevels()->first()->getCharacterClass(), 3);
$wizard->addClassLevel($extraLevel); $em->persist($extraLevel); $em->flush();
$before = $previewSnapshot();
$multi = $initialization->preview($wizard, $owner)['classes'];
$check(count($multi) === 2 && $multi[0]['classLevel'] === 2 && $multi[1]['classLevel'] === 1, 'Multiclass preview uses independent real class levels');
$result = $initialization->preview($previewCharacter, $owner);
$entry = $result['classes'][0];
$check($entry['classLevel'] === 6 && $entry['options']['classLevelId'] !== null, 'Initialization uses existing level six, never future level seven');
$check(count($entry['options']['acquisitions']) === 6 && $entry['options']['used']['known'] === 4 && $entry['options']['used']['cantrips'] === 2, 'Initialization preserves all six reconciled acquisitions');
$check($entry['policies'][0]['id'] === $advancedPolicy->getId() && $entry['policies'][0]['version'] === $advancedPolicy->policy()->version, 'Initialization preserves the effective policy version');
$check(count($entry['subclasses']) === 1 && count($entry['restrictions']) === 3, 'Subclass and advanced rule restrictions exposed');
$check($entry['options'] === $service->options($previewCharacter, $advancedPolicy->getId(), $owner), 'Initialization delegates options without changing quota or candidate semantics');
$check($result['historicalChoicesReconstructed'] === false && $entry['optionsScope'] === 'current-persisted-class-level', 'No reconstructed opportunities claimed');
$expectedGrants = array_values(array_map('get_object_vars', array_filter($access->resolve($previewCharacter), static fn ($p) => in_array($p->mode, ['grant-known', 'grant-always-prepared', 'grant-cast'], true))));
$check($result['grants'] === $expectedGrants, 'Calculated grants preserve resolver modes and provenance');
$check(count($result['grants']) > 0 && in_array('grant-always-prepared', array_column($result['grants'], 'mode'), true), 'Fixed always-prepared grant remains separate from six acquisitions');
$check($initialization->preview($plainCharacter, $owner)['classes'][0]['status'] === 'no-spellcasting', 'Noncaster has no artificial policy requirement');
$prepared = $initialization->preview($previewPreparedCharacter, $owner)['classes'][0]['options'];
$check($prepared['learningStyle'] === 'prepared' && $prepared['acquisitions'] === [] && !$prepared['preparation']['dailyChoicesManaged'], 'Prepared list is neither acquired nor daily prepared');
$check($before === $previewSnapshot(), 'Repeated initialization projections preserve every ORM table including sessions');
$check($em->getUnitOfWork()->getScheduledEntityInsertions() === [] && $em->getUnitOfWork()->getScheduledEntityUpdates() === [] && $em->getUnitOfWork()->getScheduledEntityDeletions() === [], 'Initialization schedules no ORM writes');
$url = '/characters/'.$previewCharacter->getId().'/spell-acquisitions/initialization';
$response = $http($owner, 'GET', $url);
$check($response->getStatusCode() === 200 && json_decode($response->getContent(), true) == $result, 'Owner HTTP preview matches service with level-up authorization disabled');
$check($http($other, 'GET', $url)->getStatusCode() === 404, 'Foreign owner cannot read initialization');
$check($http($admin, 'GET', $url)->getStatusCode() === 404, 'Reference admin cannot bypass character ownership');
$check($before === $previewSnapshot(), 'Initialization HTTP leaves all persistent state unchanged');
