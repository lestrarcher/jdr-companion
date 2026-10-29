<?php

declare(strict_types=1);

// Included inside the import harness: all writes are synthetic and rolled back.
$document = json_decode(file_get_contents(__DIR__.'/../data/spells-2014-class-assignments.json'), true, 512, JSON_THROW_ON_ERROR);
$sha = hash_file('sha256', __DIR__.'/../data/jdr_companion_490_sorts_2014_durees.json');
$assignmentMapper = new App\Service\Catalogue\Spell2014ClassAssignments();
$assignments = $assignmentMapper->prepare($document, $sha, $result['prepared']);
$check(count($assignments) === 1235 && count(array_filter($assignments, static fn ($r) => $r['source'] === 'TCoE')) === 65, 'Full reviewed association corpus');
$key = array_key_first($document['entries']);
foreach (['sha','missing','extra','duplicate','class','source','status','url'] as $case) {
    $bad = $document;
    switch ($case) {
        case 'sha': $bad['source_sha256'] = 'wrong'; break;
        case 'missing': unset($bad['entries'][$key]); break;
        case 'extra': $bad['entries']['extra'] = $bad['entries'][$key]; break;
        case 'duplicate': $bad['entries'][$key]['classes'][] = $bad['entries'][$key]['classes'][0]; break;
        case 'class': $bad['entries'][$key]['classes'][0]['name'] = 'Artificier'; break;
        case 'source': $bad['entries'][$key]['classes'][0]['source'] = '2024'; break;
        case 'status': $bad['entries'][$key]['status'] = 'error'; break;
        case 'url': $bad['entries'][$key]['url'] = 'https://example.invalid'; break;
    }
    try { $assignmentMapper->prepare($bad, $sha, $result['prepared']); throw new RuntimeException('Invalid assignments accepted'); }
    catch (InvalidArgumentException) { $check(true, 'Invalid assignments rejected: '.$case); }
}
$db->createSavepoint('association_cases');
try {
    $fixture = $make('association-fixture', 'fixture:association');
    $pairs = [['key'=>'fixture:association','classSlug'=>'wizard','source'=>'base'], ['key'=>'fixture:association','classSlug'=>'bard','source'=>'TCoE']];
    $first = $planner->insertMissing([$fixture], $pairs);
    $check($first === ['created'=>1,'kept'=>0,'associationsCreated'=>2,'associationsKept'=>0], 'Definitions and pairs inserted together');
    $relations = $db->fetchAllAssociative('SELECT * FROM spell_class_list_entry ORDER BY id');
    $second = $planner->insertMissing([$fixture], $pairs);
    $check($second === ['created'=>0,'kept'=>1,'associationsCreated'=>0,'associationsKept'=>2], 'Repeated pair import idempotent');
    $planner->insertMissing([$fixture], [$pairs[0]]);
    $check($relations === $db->fetchAllAssociative('SELECT * FROM spell_class_list_entry ORDER BY id'), 'Existing and additional relations retain identity');
    $check(count($planner->plan([$fixture], [$pairs[0],$pairs[0]])['conflicts']) === 1, 'Duplicate pairs rejected');
    $db->executeStatement("UPDATE character_class SET origin = 'CUSTOM', owner_id = 1 WHERE slug = 'bard'");
    $check(count($planner->plan([$fixture], $pairs)['conflicts']) === 1, 'CUSTOM class cannot replace OFFICIAL identity');
    try { $planner->insertMissing([$make('new-with-missing-class','fixture:missing')], [['key'=>'fixture:missing','classSlug'=>'bard','source'=>'base']]); throw new RuntimeException('Missing class accepted'); }
    catch (RuntimeException $e) { $check(str_contains($e->getMessage(), 'Classe OFFICIAL'), 'Missing class rejects complete transaction'); }
    $check(!$db->fetchOne("SELECT id FROM spell_definition WHERE slug = 'new-with-missing-class'"), 'Missing class leaves no definition');
    $spellId = $db->fetchOne("SELECT id FROM spell_definition WHERE slug = 'association-fixture'");
    $db->insert('character_spell_acquisition', ['spell_id'=>$spellId]);
    $check(count($planner->plan([$fixture], [['key'=>'fixture:association','classSlug'=>'cleric','source'=>'base']])['conflicts']) === 1, 'Acquired spell lists remain locked');
    $check($planner->plan([$fixture], [$pairs[0]])['associationKeep'] === 1, 'Existing acquired association retained');
    $db->executeStatement('ALTER TABLE spell_class_list_entry ADD CONSTRAINT fixture_reject CHECK (character_class_id < 0) NOT VALID');
    try { $planner->insertMissing([$make('association-rollback','fixture:rollback-pair')], [['key'=>'fixture:rollback-pair','classSlug'=>'wizard','source'=>'base']]); throw new RuntimeException('Relation failure accepted'); }
    catch (Doctrine\DBAL\Exception\DriverException) { $check(!$db->fetchOne("SELECT id FROM spell_definition WHERE slug = 'association-rollback'"), 'Relation SQL failure rolls back inserted definition'); }
} finally {
    $db->rollbackSavepoint('association_cases');
    $db->releaseSavepoint('association_cases');
}
