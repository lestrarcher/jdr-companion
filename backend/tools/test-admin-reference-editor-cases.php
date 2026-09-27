<?php

declare(strict_types=1);

// Included by test-admin-feature-reference.php inside its temporary-table transaction.
foreach (['features' => App\Entity\CharacterFeatureDefinition::class] + array_map(static fn ($definition) => $definition['entity'], App\Service\AdminReferenceCatalogue::CATEGORIES) as $category => $entityClass) {
    $db->createSavepoint('editor_navigation');
    $meta = $em->getClassMetadata($entityClass);
    $table = $db->quoteIdentifier($meta->getTableName());
    $fixtureIds = $db->fetchFirstColumn("SELECT id FROM $table ORDER BY id");
    foreach ($fixtureIds as $index => $fixtureId) {
        // Reverse ID order, including equal names: ID +/- 1 cannot pass these checks.
        $db->update($meta->getTableName(), ['name' => sprintf('Édition %03d', intdiv(count($fixtureIds) - $index, 2)), 'description' => null], ['id' => $fixtureId]);
    }
    $em->clear();
    if ($category === 'features') {
        foreach ($fixtureIds as $fixtureId) {
            $em->persist(App\Entity\CharacterFeatureRule::forClass($em->find($entityClass, $fixtureId), $em->find(App\Entity\CharacterClass::class, $class->getId()), 19));
        }
        $em->flush();
    }
    $ordered = $db->fetchAllAssociative("SELECT id, name FROM $table ORDER BY name, id");
    $path = '/admin/reference/'.$category;
    $linkId = static function ($response, string $rel) use ($dom): ?int {
        $href = $dom($response)->evaluate('string(//nav[@aria-label="Navigation dans la liste filtrée"]/a[@rel="'.$rel.'"]/@href)');
        return $href === '' ? null : (int) basename(parse_url($href, PHP_URL_PATH));
    };
    foreach ([0, 1, 24, count($ordered) - 1] as $index) {
        $page = $request($path.'/'.$ordered[$index]['id']);
        $check($page->getStatusCode() === 200, 'Editor GET '.$category);
        $check($linkId($page, 'prev') === (isset($ordered[$index - 1]) ? (int) $ordered[$index - 1]['id'] : null), 'Previous database order '.$category.' '.$index);
        $check($linkId($page, 'next') === (isset($ordered[$index + 1]) ? (int) $ordered[$index + 1]['id'] : null), 'Next database order including page boundary '.$category.' '.$index);
    }
    $sameName = array_values(array_filter($ordered, static fn ($row) => $row['name'] === $ordered[1]['name']));
    $searchPage = $request($path.'/'.$sameName[0]['id'].'?'.http_build_query(['q' => $sameName[0]['name']]));
    $check($linkId($searchPage, 'prev') === null && $linkId($searchPage, 'next') === (isset($sameName[1]) ? (int) $sameName[1]['id'] : null), 'Search restricts neighbours '.$category);
    $business = match ($category) {
        'features' => ['class' => (string) $class->getId(), 'sourceType' => 'class'],
        'subclasses' => ['class' => (string) $class->getId()],
        'races' => ['selectable' => '1'],
        'resources' => ['recharge' => 'long-rest'],
        default => [],
    };
    $context = ['q' => 'Édition', 'editorial' => 'missing-description', 'page' => 2] + $business;
    $url = $path.'/'.$ordered[0]['id'].'?'.http_build_query($context);
    $db->update($meta->getTableName(), ['description' => 'Déjà renseignée'], ['id' => $ordered[1]['id']]);
    $em->clear();
    $page = $request($url);
    $check($linkId($page, 'next') === (int) $ordered[2]['id'], 'Editorial neighbour skips nonmatching record '.$category);
    $middle = $request($path.'/'.$ordered[2]['id'].'?'.http_build_query($context));
    $check($linkId($middle, 'prev') === (int) $ordered[0]['id'], 'Editorial previous skips nonmatching record '.$category);
    $nextUrl = $dom($page)->evaluate('string(//nav/a[@rel="next"]/@href)');
    parse_str(parse_url($nextUrl, PHP_URL_QUERY), $nextContext);
    $check($nextContext == $context, 'Neighbour retains complete context '.$category);
    $back = $dom($page)->evaluate('string(//a[@class="back-link"]/@href)');
    parse_str(parse_url($back, PHP_URL_QUERY), $backContext);
    $check(parse_url($back, PHP_URL_PATH) === $path && $backContext == $context, 'Exact list return '.$category);
    $check($dom($page)->query('//form//span[@class="badge" and text()="Description manquante"]')->length === 1, 'Missing description indicator '.$category);
    $check($dom($page)->query('//details//input | //details//textarea | //details//select | //details//form')->length === 0, 'Mechanics strictly readonly '.$category);
    $fields = [];
    foreach ($dom($page)->query('//form[@data-editor]//input | //form[@data-editor]//textarea') as $field) $fields[] = $field->getAttribute('name');
    sort($fields);
    $expectedFields = ['_token', 'description', 'name'];
    if ($category === 'progressions') $expectedFields = ['_token', 'description', 'gainLabel', 'name', 'spendLabel'];
    $check($fields === $expectedFields, 'Only allowed editorial fields '.$category);
    $untrusted = $request($url.'&returnUrl=https%3A%2F%2Fexample.invalid&next=999999');
    $check($dom($untrusted)->evaluate('string(//form[@data-editor]/@action)') === $dom($page)->evaluate('string(//form[@data-editor]/@action)'), 'Unknown GET navigation parameters discarded '.$category);
    $payload = ['_token' => $token($page), '_action' => 'save-next', 'name' => $ordered[0]['name'], 'description' => 'Brouillon conservé'];
    if ($category === 'progressions') $payload += ['gainLabel' => 'Gagner', 'spendLabel' => 'Dépenser'];
    foreach ([['name' => ' '], ['_action' => ['save-next']], ['_action' => 'https://example.invalid'], ['returnUrl' => 'https://example.invalid'], ['slug' => 'forged']] as $invalid) {
        $rejected = $request($url, 'POST', array_replace($payload, $invalid));
        $check($rejected->getStatusCode() === 422, 'Save-next rejects invalid/forged fields '.$category);
        $check($dom($rejected)->evaluate('string(//textarea[@name="description"])') === 'Brouillon conservé', 'Validation preserves draft '.$category);
    }
    $check($request($url, 'POST', array_replace($payload, ['_token' => 'bad']))->getStatusCode() === 403, 'Save-next CSRF '.$category);
    $check($db->fetchOne("SELECT description FROM $table WHERE id = ?", [$ordered[0]['id']]) === null, 'Rejected saves do not write '.$category);
    $saved = $request($url, 'POST', array_replace($payload, ['_action' => 'save']));
    $check($saved->getStatusCode() === 303 && parse_url($saved->headers->get('Location'), PHP_URL_PATH) === $path.'/'.$ordered[0]['id'], 'Save stays on corrected record '.$category);
    parse_str(parse_url($saved->headers->get('Location'), PHP_URL_QUERY), $savedContext);
    $check($savedContext == $context, 'PRG preserves every context parameter '.$category);
    $afterSave = $request($saved->headers->get('Location'));
    $check(str_contains($afterSave->getContent(), 'ne correspond plus') && $linkId($afterSave, 'next') === (int) $ordered[2]['id'], 'Excluded record still has next '.$category);
    $payload['_token'] = $token($afterSave);
    $nextSave = $request($url, 'POST', $payload);
    $check($nextSave->getStatusCode() === 303 && parse_url($nextSave->headers->get('Location'), PHP_URL_PATH) === $path.'/'.$ordered[2]['id'], 'Save-next redirects to remaining record '.$category);
    $check(str_contains($request($nextSave->headers->get('Location'))->getContent(), 'Modifications enregistrées.'), 'Save-next success flash '.$category);
    // Renaming beyond the end and leaving the filter wraps to the first remaining item.
    $last = $ordered[count($ordered) - 1];
    $lastUrl = $path.'/'.$last['id'].'?'.http_build_query($context);
    $lastPage = $request($lastUrl);
    $wrap = $request($lastUrl, 'POST', array_replace($payload, ['_token' => $token($lastPage), 'name' => 'ZZZ corrigé']));
    $check($wrap->getStatusCode() === 303 && parse_url($wrap->headers->get('Location'), PHP_URL_PATH) === $path.'/'.$ordered[2]['id'], 'Excluded last record wraps to remaining first '.$category);
    $check($db->fetchOne("SELECT name FROM $table WHERE id = ?", [$last['id']]) === 'ZZZ corrigé', 'Name edit persisted '.$category);
    $db->executeStatement("UPDATE $table SET description = 'Complète'");
    $db->update($meta->getTableName(), ['name' => $last['name'], 'description' => null], ['id' => $last['id']]);
    $em->clear();
    $lastPage = $request($lastUrl);
    $done = $request($lastUrl, 'POST', array_replace($payload, ['_token' => $token($lastPage), 'name' => $last['name']]));
    $check($done->getStatusCode() === 303 && parse_url($done->headers->get('Location'), PHP_URL_PATH) === $path, 'Final correction returns to list '.$category);
    parse_str(parse_url($done->headers->get('Location'), PHP_URL_QUERY), $doneContext);
    $check($doneContext == $context, 'Final list retains context '.$category);
    $check(str_contains($request($done->headers->get('Location'))->getContent(), 'Modifications enregistrées.'), 'Final list success flash '.$category);
    $lastUnfiltered = $request($path.'/'.$last['id']);
    $finished = $request($path.'/'.$last['id'], 'POST', array_replace($payload, ['_token' => $token($lastUnfiltered), 'name' => $last['name']]));
    $check($finished->getStatusCode() === 303 && parse_url($finished->headers->get('Location'), PHP_URL_PATH) === $path, 'Save-next on last matching record returns to list '.$category);
    $db->rollbackSavepoint('editor_navigation');
    $db->releaseSavepoint('editor_navigation');
    $em->clear();
}
