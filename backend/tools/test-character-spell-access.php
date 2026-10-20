<?php

declare(strict_types=1);

use App\Entity\{User, Campaign, Character, CharacterClass, CharacterSubclass, CharacterClassLevel, GameSession, CharacterSessionState, CharacterProgression, ProgressionDefinition, CharacterFeatureDefinition, CharacterFeatureRule, TrackableResourceDefinition, TrackableResourceRule};
use App\Enum\{ReferenceOrigin, ResourceRechargeType, ResourceMaximumType, SpellcastingProgressionType};
use App\Repository\{CharacterFeatureRuleRepository, TrackableResourceRuleRepository};
use App\Service\{CharacterSpellAccessResolver, CharacterResourceResolver, CharacterFeatureResolver, CharacterAbilityCalculator, CharacterSpellSlotCalculator, SpellCatalogue, SpellSubclassGrants};

require __DIR__.'/../vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv(__DIR__.'/../.env');
$kernel = new App\Kernel('dev', true); $kernel->boot();
$registry = $kernel->getContainer()->get('doctrine');
$em = $registry->getManager(); $db = $em->getConnection(); $checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void { if (!$condition) throw new RuntimeException($message); ++$checks; };
$byKey = static fn (array $rows): array => array_column($rows, null, 'key');
try {
    $db->beginTransaction();
    $metadata = $em->getMetadataFactory()->getAllMetadata();
    foreach ((new Doctrine\ORM\Tools\SchemaTool($em))->getCreateSchemaSql($metadata) as $sql) $db->executeStatement(preg_replace(['/^CREATE TABLE /', '/^CREATE SEQUENCE /'], ['CREATE TEMP TABLE ', 'CREATE TEMP SEQUENCE '], $sql));
    foreach ($metadata as $meta) $check($db->fetchOne('SELECT relpersistence FROM pg_class WHERE oid=to_regclass(?)', [$meta->getTableName()]) === 't', 'Isolated '.$meta->getTableName());
    $owner = (new User())->setEmail('access-owner@example.invalid')->setPassword('unused');
    $other = (new User())->setEmail('access-other@example.invalid')->setPassword('unused');
    $admin = (new User())->setEmail('access-admin@example.invalid')->setPassword('unused')->setRoles([SpellCatalogue::ADMIN_ROLE]);
    $campaign = new Campaign($owner, 'access-campaign', 'Fixture');
    $otherCampaign = new Campaign($other, 'access-other', 'Other fixture');
    $persist = static function (object $entity, ?User $customOwner = null) use ($em): object {
        if ($customOwner !== null) {
            (new ReflectionProperty($entity, 'origin'))->setValue($entity, ReferenceOrigin::Custom);
            (new ReflectionProperty($entity, 'owner'))->setValue($entity, $customOwner);
        }
        $em->persist($entity); return $entity;
    };
    foreach ([$owner, $other, $admin, $campaign, $otherCampaign] as $entity) $persist($entity);
    $a = $persist((new CharacterClass('access-a', 'Class A', 6, 1))->setSpellcastingProgression(SpellcastingProgressionType::Full));
    $b = $persist((new CharacterClass('access-b', 'Class B', 6, 1))->setSpellcastingProgression(SpellcastingProgressionType::Full));
    $c = $persist(new CharacterClass('access-c', 'Class C', 6, 1));
    $privateClass = $persist(new CharacterClass('access-private', 'Private class', 6, 1), $owner);
    $foreignClass = $persist(new CharacterClass('access-foreign', 'Secret class', 6, 1), $other);
    $sub = $persist(new CharacterSubclass($a, 'access-sub', 'Chosen source'));
    $unusedSub = $persist(new CharacterSubclass($a, 'access-unused', 'Unchosen source'));
    $ownSub = $persist(new CharacterSubclass($a, 'access-own', 'Own custom source'), $owner);
    $otherSub = $persist(new CharacterSubclass($a, 'access-other', 'Secret source'), $other);
    $makeCharacter = static function (string $slug, Campaign $camp, array $spec) use ($persist): Character {
        $character = new Character($camp, $slug, $slug, Character::TYPE_PLAYER);
        $position = 0;
        foreach ($spec as [$class, $count, $subclass]) {
            for ($i = 0; $i < $count; ++$i) {
                $level = new CharacterClassLevel($character, $class, ++$position, $i === 0 ? $subclass : null);
                $character->addClassLevel($level); $persist($level);
            }
        }
        $persist($character); return $character;
    };
    $single = $makeCharacter('single', $campaign, [[$a, 1, $sub]]);
    $multi = $makeCharacter('multi', $campaign, [[$a, 2, $sub], [$b, 4, null]]);
    $atThree = $makeCharacter('three', $campaign, [[$a, 3, $sub]]);
    $custom = $makeCharacter('custom', $campaign, [[$a, 3, $ownSub]]);
    $otherCharacter = $makeCharacter('other', $otherCampaign, [[$a, 3, $otherSub]]);
    $none = $makeCharacter('none', $campaign, []);
    $noSubclass = $makeCharacter('no-subclass', $campaign, [[$a, 3, null]]);
    $privateCharacter = $makeCharacter('private-class', $campaign, [[$privateClass, 1, null]]);
    $invalidCharacter = $makeCharacter('foreign-class', $campaign, [[$foreignClass, 1, null]]);
    $resource = $persist(new TrackableResourceDefinition('access-resource', 'Resolved resource', ResourceRechargeType::LongRest, ResourceMaximumType::Fixed, 5));
    $absentResource = $persist(new TrackableResourceDefinition('access-absent', 'Unassigned resource', ResourceRechargeType::LongRest));
    $foreignResource = $persist(new TrackableResourceDefinition('access-secret', 'Secret resource', ResourceRechargeType::LongRest), $other);
    $contextResource = $persist(new TrackableResourceDefinition('access-context', 'Context resource', ResourceRechargeType::LongRest, ResourceMaximumType::Fixed, 2));
    $persist(TrackableResourceRule::forClass($resource, $a, 1));
    $progression = $persist(new ProgressionDefinition('access-progress', 'Progression fixture'));
    $feature = $persist((new CharacterFeatureDefinition('access-feature', 'Feature fixture'))->setResourceDefinition($contextResource));
    $persist(CharacterFeatureRule::forProgression($feature, $progression, 3));
    $characterProgression = new CharacterProgression($custom, $progression);
    $custom->addProgression($characterProgression); $persist($characterProgression);
    $states = [];
    foreach ([0, 3] as $value) {
        $session = $persist(new GameSession($campaign, 'access-session-'.$value, 'Fixture session'));
        $states[] = $persist(new CharacterSessionState($session, $custom, ['hitPoints' => ['current' => 7], 'resources' => [['id' => 'spell-slot-1', 'current' => 0], ['id' => 'access-resource', 'current' => 1]], 'progressions' => [['id' => 'access-progress', 'currentValue' => $value]]]));
    }
    $em->flush();
    $catalogue = new SpellCatalogue($db); $grants = new SpellSubclassGrants($db, $catalogue);
    $resources = new CharacterResourceResolver(new TrackableResourceRuleRepository($registry), new CharacterAbilityCalculator(), new CharacterFeatureResolver(new CharacterFeatureRuleRepository($registry)));
    $resolver = new CharacterSpellAccessResolver($catalogue, $grants, $resources);
    $fixture = ['slug' => 'access-spell', 'name' => 'Fictional spell', 'level' => 1, 'school' => 'illusion', 'castingTime' => 'Fixture', 'range' => 'Fixture', 'duration' => 'Fixture', 'description' => 'Synthetic test content'];
    $spell = $catalogue->create($fixture + ['classIds' => [$a->getId(), $b->getId()]], $admin, true);
    $high = $catalogue->create(array_replace($fixture, ['slug' => 'access-high', 'level' => 9, 'classIds' => [$a->getId()]]), $admin, true);
    $ownSpell = $catalogue->create(array_replace($fixture, ['slug' => 'access-own', 'classIds' => [$a->getId(), $privateClass->getId()]]), $owner);
    $otherSpell = $catalogue->create(array_replace($fixture, ['slug' => 'access-other', 'name' => 'Secret spell', 'classIds' => [$a->getId()]]), $other);
    $createGrant = static function (CharacterSubclass $source, string $mode, int $level, array $extra = [], ?int $target = null) use ($grants, $spell, $a, $owner, $other, $admin): array {
        $official = $source->getOrigin() === ReferenceOrigin::Official;
        $actor = $official ? $admin : ($source->getOwner()->getId() === $owner->getId() ? $owner : $other);
        return $grants->save($target ?? $spell['id'], null, array_replace(['subclassId' => $source->getId(), 'mode' => $mode, 'unlockLevel' => $level, 'castingClassId' => $a->getId()], $extra), $actor, $official);
    };
    $extension = $createGrant($sub, 'add-to-list', 1);
    $known = $createGrant($sub, 'grant-known', 2, ['castingClassId' => $b->getId()]);
    $prepared = $createGrant($sub, 'grant-always-prepared', 3);
    $cast = $createGrant($sub, 'grant-cast', 2, ['castingClassId' => null, 'slotUsage' => 'not-required', 'resourceId' => $resource->getId(), 'resourceCost' => 2, 'restrictions' => 'Descriptive condition only']);
    $differentClass = $createGrant($sub, 'grant-cast', 1, ['castingClassId' => $c->getId(), 'slotUsage' => 'either']);
    $unchosen = $createGrant($unusedSub, 'grant-known', 1);
    $customGrant = $createGrant($ownSub, 'grant-known', 1);
    $customTarget = $createGrant($ownSub, 'add-to-list', 1, [], $ownSpell['id']);
    $absent = $createGrant($ownSub, 'grant-cast', 1, ['slotUsage' => 'required', 'resourceId' => $absentResource->getId(), 'resourceCost' => 1]);
    $context = $createGrant($ownSub, 'grant-cast', 2, ['slotUsage' => 'not-required', 'resourceId' => $contextResource->getId(), 'resourceCost' => 1]);
    $foreignGrant = $createGrant($otherSub, 'grant-known', 1);
    $key = static fn (array $grant): string => 'subclass-grant:'.$grant['id'];
    $fingerprints = static function () use ($db, $metadata): array {
        $result = [];
        foreach ($metadata as $meta) $result[$meta->getTableName()] = $db->fetchOne('SELECT md5(COALESCE(string_agg(to_jsonb(t)::text, \'\' ORDER BY to_jsonb(t)::text), \'\')) FROM '.$db->quoteIdentifier($meta->getTableName()).' t');
        return $result;
    };
    $before = $fingerprints(); $statesBefore = array_map(static fn ($s) => $s->getState(), $states);
    $slots = new CharacterSpellSlotCalculator(); $slotsBefore = $slots->calculate($multi);
    $singleRows = $byKey($resolver->resolve($single));
    $multiRows = $byKey($resolver->resolve($multi));
    $threeRows = $byKey($resolver->resolve($atThree));
    $customRows = $byKey($resolver->resolve($custom));
    $check(isset($singleRows[$key($extension)]) && !isset($singleRows[$key($known)]), 'Class level one includes extension but not level-two knowledge');
    $check(isset($multiRows[$key($known)]) && !isset($multiRows[$key($prepared)]), 'Total level six cannot unlock a source-class level-three grant');
    $check(isset($threeRows[$key($prepared)]) && $threeRows[$key($prepared)]->mode === 'grant-always-prepared', 'Exact level threshold activates prepared grant');
    $check($multiRows[$key($known)]->castingClass['id'] === $b->getId() && $multiRows[$key($known)]->castingClass['characterLevel'] === 4 && $multiRows[$key($known)]->source['classLevel'] === 2, 'Casting class independent of activation class');
    $check($singleRows[$key($differentClass)]->castingClass['characterLevel'] === 0 && $singleRows[$key($differentClass)]->canCast === null, 'Missing casting class recorded without inventing class acquisition or castability');
    $sameSpell = array_values(array_filter($multiRows, static fn ($r) => $r->spell['id'] === $spell['id']));
    $check(count($sameSpell) === 6 && count(array_unique(array_column($sameSpell, 'key'))) === 6, 'Two class lists and four unlocked grants retain six identities for one spell');
    $check(count(array_filter($sameSpell, static fn ($r) => $r->mode === 'class-list')) === 2 && $multiRows[$key($extension)]->mode === 'add-to-list', 'Base list and extension are distinct from knowledge/preparation');
    $check(!isset($multiRows[$key($unchosen)]) && !isset($customRows[$key($extension)]), 'Only chosen subclass grants resolve');
    $check(count(array_filter($resolver->resolve($noSubclass), static fn ($r) => $r->source['type'] === 'subclass-grant')) === 0, 'No chosen subclass means no grants');
    $check($resolver->resolve($none) === [] && $resolver->resolve($invalidCharacter) === [], 'No levels or invisible class means no access');
    $check(count($resolver->resolve($privateCharacter)) === 1, 'Own custom class list visible');
    $check($customRows[$key($customGrant)]->source['origin'] === 'CUSTOM' && $customRows[$key($customGrant)]->spell['origin'] === 'OFFICIAL', 'Custom source grants official target without mutating it');
    $check(isset($customRows[$key($customTarget)]), 'Own custom source and target supported');
    $otherRows = $byKey($resolver->resolve($otherCharacter));
    $check(isset($otherRows[$key($foreignGrant)]) && !isset($otherRows[$key($customGrant)]), 'Campaign owner defines visibility');
    $check(!str_contains(json_encode($customRows), 'Secret') && !in_array($otherSpell['id'], array_map(static fn ($r) => $r->spell['id'], $customRows), true), 'No foreign spell, class, source or resource names leaked');
    $check($multiRows[$key($cast)]->resource['resolvedForCharacter'] && $multiRows[$key($cast)]->resource['maximum'] === 5 && $multiRows[$key($cast)]->resource['current'] === null, 'Assigned resource resolved with no implicit session counter');
    $check($multiRows[$key($cast)]->resource['cost'] === 2 && $multiRows[$key($cast)]->restrictions === 'Descriptive condition only' && $multiRows[$key($cast)]->slotUsage === 'not-required' && $multiRows[$key($cast)]->castingClass === null, 'All cast configuration preserved verbatim');
    $check(!$customRows[$key($absent)]->resource['resolvedForCharacter'] && $customRows[$key($absent)]->resource['maximum'] === null, 'Visible but unassigned resource does not get granted');
    $check(!$customRows[$key($context)]->resource['resolvedForCharacter'], 'Absent context does not load one of two sessions');
    $atZero = $byKey($resolver->resolve($custom, ['access-progress' => 0]));
    $atThreshold = $byKey($resolver->resolve($custom, ['access-progress' => 3]));
    $check(!$atZero[$key($context)]->resource['resolvedForCharacter'] && $atThreshold[$key($context)]->resource['resolvedForCharacter'], 'Existing resource resolver receives explicit progression context, including zero');
    $check(!isset($byKey($resolver->resolve($multi, ['access-progress' => 20]))[$key($prepared)]), 'Narrative threshold never substitutes for source class level');
    $check(count(array_filter($singleRows, static fn ($r) => $r->spell['id'] === $high['id'] && $r->spellLevelEligibility === 'not-evaluated')) === 1, 'No invented maximum spell-level policy from slots');
    foreach ($multiRows as $row) $check($row->canCast === null && $row->castingAbility === null, 'No invented casting permission or ability');
    $check(json_encode($multiRows) === json_encode($byKey($resolver->resolve($multi))), 'Stable order and identities on repeat resolution');
    $check($before === $fingerprints() && $statesBefore === array_map(static fn ($s) => $s->getState(), $states) && $slotsBefore === $slots->calculate($multi), 'All tables, session states and slot calculations unchanged');
    $em->getUnitOfWork()->computeChangeSets();
    $check($em->getUnitOfWork()->getScheduledEntityUpdates() === [] && $em->getUnitOfWork()->getScheduledEntityInsertions() === [] && $em->getUnitOfWork()->getScheduledEntityDeletions() === [], 'Resolution leaves no pending ORM writes');

    // Corruption scenarios use savepoints; restore fixtures after each read-only resolution.
    foreach ([['resource_id' => $foreignResource->getId()], ['owner_id' => $other->getId()], ['casting_class_id' => $foreignClass->getId()], ['unlock_level' => 0], ['mode' => 'unsupported'], ['slot_usage' => null]] as $corruption) {
        $db->createSavepoint('corruption');
        $db->update('spell_subclass_grant', $corruption, ['id' => $absent['id']]);
        $check(!isset($byKey($resolver->resolve($custom))[$key($absent)]), 'Malformed/invisible grant excluded: '.implode(',', array_keys($corruption)));
        $db->rollbackSavepoint('corruption'); $db->releaseSavepoint('corruption');
    }
    $db->createSavepoint('bad_dependency');
    $db->insert('spell_class_list_entry', ['spell_id' => $spell['id'], 'character_class_id' => $foreignClass->getId()]);
    $check(count(array_filter($resolver->resolve($multi), static fn ($r) => $r->spell['id'] === $spell['id'])) === 0, 'Corrupt spell list dependency excludes both base and grant paths');
    $db->rollbackSavepoint('bad_dependency'); $db->releaseSavepoint('bad_dependency');
    $secondLevel = $multi->getClassLevels()->get(1);
    $secondLevel->setSubclass($unusedSub);
    $check(count(array_filter($resolver->resolve($multi), static fn ($r) => $r->source['type'] === 'subclass-grant')) === 0, 'Ambiguous subclass choices never choose an arbitrary grant source');
    $secondLevel->setSubclass(null);
    (new ReflectionProperty($secondLevel, 'subclass'))->setValue($secondLevel, $otherSub);
    $check(count(array_filter($resolver->resolve($multi), static fn ($r) => $r->source['type'] === 'subclass-grant')) === 0, 'Invisible subclass does not expose source grants');
    $secondLevel->setSubclass(null);
    $check($before === $fingerprints(), 'Malformed-fixture probes and resolver leave all persistent fixture data unchanged');
    $db->createSavepoint('wrong_parent');
    $db->update('character_subclass', ['character_class_id' => $b->getId()], ['id' => $sub->getId()]);
    $check(count(array_filter($resolver->resolve($multi), static fn ($r) => $r->source['type'] === 'subclass-grant')) === 0, 'Stored source parent must agree with chosen class, even when ORM data is stale');
    $db->rollbackSavepoint('wrong_parent'); $db->releaseSavepoint('wrong_parent');
    $multiId = $multi->getId(); $customId = $custom->getId(); $em->clear();
    $check(json_encode($multiRows) === json_encode($byKey($resolver->resolve($em->find(Character::class, $multiId)))), 'Same result after ORM reload and lazy class-level hydration');
    $reloadedContext = $byKey($resolver->resolve($em->find(Character::class, $customId), ['access-progress' => 3]));
    $check($reloadedContext[$key($context)]->resource['resolvedForCharacter'], 'Resource context also works after fresh hydration');
    $check($before === $fingerprints(), 'Freshly hydrated resolution is read-only too');
    echo 'OK: '.$checks." spell access assertions; temporary tables rolled back, no session lookup or runtime writes.\n";
} finally {
    if ($db->isTransactionActive()) $db->rollBack();
    $em->clear(); $kernel->shutdown();
}
