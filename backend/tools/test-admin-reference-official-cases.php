<?php

declare(strict_types=1);

// Included by the admin harness: all tables and sequences are temporary.
$db->createSavepoint('official_catalogue');
try {
    $otherUser = (new App\Entity\User())->setEmail('other-admin-test@example.invalid')->setPassword('test-only');
    $em->persist($otherUser);
    $em->flush();
    $ownerIds = [$user->getId(), $otherUser->getId()];
    $categories = ['features' => App\Entity\CharacterFeatureDefinition::class] + array_map(static fn ($definition) => $definition['entity'], App\Service\AdminReferenceCatalogue::CATEGORIES);
    foreach ($categories as $category => $entityClass) {
        $db->createSavepoint('official_category');
        try {
            $tableName = $em->getClassMetadata($entityClass)->getTableName();
            $table = $db->quoteIdentifier($tableName);
            $fixtures = $db->fetchAllAssociative("SELECT id, slug FROM $table ORDER BY id");
            // Interleave CUSTOM with OFFICIAL in name order and invert the legacy flag.
            $db->executeStatement("UPDATE $table SET name = 'Visibility', description = NULL, custom = true");
            foreach ($ownerIds as $index => $ownerId) {
                // Unique search terms: extra-1 would also match official extra-10, etc.
                $fixtures[$index]['slug'] = 'private-only-'.$category.'-'.$index;
                $db->executeStatement("UPDATE $table SET origin = 'CUSTOM', owner_id = ?, custom = false, slug = ? WHERE id = ?", [$ownerId, $fixtures[$index]['slug'], $fixtures[$index]['id']]);
            }
            $em->clear();
            $path = '/admin/reference/'.$category;
            $officialIds = array_map('intval', $db->fetchFirstColumn("SELECT id FROM $table WHERE origin = 'OFFICIAL' ORDER BY name, id"));
            $expected = count($officialIds);
            foreach (['', '?q=Visibility', '?editorial=missing-description', '?custom=0', '?custom=1'] as $query) {
                $response = $request($path.$query);
                $check($response->getStatusCode() === 200 && $total($response) === $expected, 'Official list count '.$category.$query);
                $check($dom($response)->query('//select[@name="custom"]')->length === 0, 'No legacy filter '.$category);
            }
            $listed = [];
            for ($pageNumber = 1; $pageNumber <= (int) ceil($expected / 25); ++$pageNumber) {
                $response = $request($path.'?q=Visibility&editorial=missing-description&page='.$pageNumber);
                foreach ($dom($response)->query('//tr[@data-reference-id or @data-feature-id]') as $row) {
                    $listed[] = (int) ($row->getAttribute('data-reference-id') ?: $row->getAttribute('data-feature-id'));
                }
            }
            $check($listed === $officialIds, 'Official filtering precedes pagination '.$category);
            $counts = $catalogue->counts();
            $dashboard = $catalogue->dashboard();
            $check($counts[$category] === $expected && $dashboard['categories'][$category]['total'] === $expected, 'Official counters '.$category);
            $check($dashboard['categories'][$category]['missingDescriptions'] === $expected, 'Official editorial counter '.$category);
            $check($dashboard['total'] === array_sum($counts), 'Official aggregate '.$category);
            $dashboardHtml = $dom($request('/admin'));
            $check((int) $dashboardHtml->evaluate('string(//article[@data-category="'.$category.'"]//*[@data-count="total"])') === $expected, 'Official dashboard HTTP '.$category);

            foreach (array_slice($fixtures, 0, 2) as $fixture) {
                $original = $db->fetchAssociative("SELECT * FROM $table WHERE id = ?", [$fixture['id']]);
                $check($total($request($path.'?q='.$fixture['slug'])) === 0, 'Custom slug hidden '.$category);
                foreach (['GET', 'POST'] as $method) {
                    $response = $request($path.'/'.$fixture['id'], $method, ['name' => 'FORGED', 'description' => 'FORGED']);
                    $check($response->getStatusCode() === 404, 'Custom direct '.$method.' '.$category);
                    $check(!str_contains($response->getContent(), $fixture['slug']), '404 does not reveal custom '.$category);
                }
                $check($original === $db->fetchAssociative("SELECT * FROM $table WHERE id = ?", [$fixture['id']]), 'Custom row unchanged '.$category);
            }
            foreach ([$officialIds[0], $officialIds[count($officialIds) - 1]] as $officialId) {
                $entity = $em->find($entityClass, $officialId);
                $context = ['q' => 'Visibility', 'editorial' => 'missing-description', 'page' => 1];
                $neighbours = $category === 'features' ? $reference->neighbours($entity, $context) : $catalogue->neighbours($category, $entity, $context);
                $position = array_search($officialId, $officialIds, true);
                $check($neighbours['previous'] === ($officialIds[$position - 1] ?? null) && $neighbours['next'] === ($officialIds[$position + 1] ?? null), 'Official neighbours '.$category);
            }
            // Saving the first official item leaves the editorial filter and skips both CUSTOMs.
            $editPath = $path.'/'.$officialIds[0].'?q=Visibility&editorial=missing-description&page=1';
            $edit = $request($editPath);
            $payload = ['_token' => $token($edit), '_action' => 'save-next', 'name' => 'Visibility', 'description' => 'Completed'];
            if ($category === 'progressions') $payload += ['gainLabel' => '', 'spendLabel' => ''];
            $saved = $request($editPath, 'POST', $payload);
            $check($saved->getStatusCode() === 303 && (int) basename(parse_url($saved->headers->get('Location'), PHP_URL_PATH)) === $officialIds[1], 'Official save-next '.$category);
            $optionType = array_search($category, ['class' => 'classes', 'subclass' => 'subclasses', 'race' => 'races', 'feat' => 'feats', 'progression' => 'progressions'], true);
            if ($optionType !== false) {
                $customIds = array_map('intval', array_column(array_slice($fixtures, 0, 2), 'id'));
                foreach ($reference->filterOptions()[$optionType] as $sourceOption) {
                    $check(!in_array((int) $sourceOption['id'], $customIds, true), 'Official source option '.$category);
                }
                if (in_array($category, ['classes', 'races'], true)) {
                    $options = $category === 'classes' ? $catalogue->filters('subclasses')['class']['choices'] : $catalogue->filters('races')['parent']['choices'];
                    $check(array_intersect($customIds, array_keys($options)) === [], 'Official parent choices '.$category);
                }
            }
            $db->executeStatement("UPDATE $table SET name = ''");
            $em->clear();
            $check($catalogue->dashboard()['categories'][$category]['missingNames'] === $expected, 'Official missing names '.$category);
        } finally {
            $db->rollbackSavepoint('official_category');
            $db->releaseSavepoint('official_category');
            $em->clear();
        }
    }

    // Only CUSTOM assignments: the official feature must survive the LEFT JOIN.
    $db->executeStatement("UPDATE character_feature_rule SET origin = 'CUSTOM', owner_id = ? WHERE feature_definition_id = ?", [$ownerIds[0], $id]);
    $db->executeStatement("UPDATE trackable_resource_rule SET origin = 'CUSTOM', owner_id = ?", [$ownerIds[1]]);
    $db->executeStatement("UPDATE character_action_class_rule SET origin = 'CUSTOM', owner_id = ?", [$ownerIds[0]]);
    $em->clear();
    $officialFeature = $reference->find($id);
    $result = $reference->search($officialFeature->getSlug(), [], 1, 25);
    $check($result['total'] === 1 && $result['features'][0]['origins'] === [], 'LEFT JOIN retains official feature with only CUSTOM rules');
    $check($reference->search($officialFeature->getSlug(), ['class' => $class->getId()], 1, 25)['total'] === 0, 'CUSTOM rule cannot satisfy class filter');
    $check($reference->search($officialFeature->getSlug(), ['sourceType' => 'class'], 1, 25)['total'] === 0, 'CUSTOM rule cannot satisfy source filter');
    $detail = $reference->detail($officialFeature);
    $check($detail['origins'] === [] && $detail['resource']['rules'] === [], 'Feature detail hides custom feature/resource rules');
    $classDetail = $catalogue->detail('classes', $catalogue->find('classes', $class->getId()));
    $check(empty($classDetail['groups']['Capacités attribuées']) && empty($classDetail['groups']['Règles de ressource']) && empty($classDetail['tables']['Actions métier']), 'Class detail hides all three custom attribution types');
    $resourceDetail = $catalogue->detail('resources', $catalogue->find('resources', $resource->getId()));
    $check(empty($resourceDetail['groups']['Fournie par les attributions de capacités']) && empty($resourceDetail['groups']['Règles de maximum']), 'Resource detail hides custom providers');

    // Even an inconsistent OFFICIAL relation must not reveal a CUSTOM definition.
    $db->executeStatement("UPDATE character_feature_rule SET origin = 'OFFICIAL', owner_id = NULL");
    $db->executeStatement("UPDATE trackable_resource_rule SET origin = 'OFFICIAL', owner_id = NULL");
    $db->executeStatement("UPDATE character_action_class_rule SET origin = 'OFFICIAL', owner_id = NULL");
    foreach (['character_class' => $class->getId(), 'trackable_resource_definition' => $resource->getId(), 'character_action_definition' => $action->getId()] as $tableName => $fixtureId) {
        $db->update($tableName, ['origin' => 'CUSTOM', 'owner_id' => $ownerIds[1]], ['id' => $fixtureId]);
    }
    $em->clear();
    $detail = $reference->detail($reference->find($id));
    $check($detail['resource'] === null && $detail['origins'] === [], 'Direct CUSTOM resource and source hidden');
    $check($reference->search('', ['class' => $class->getId()], 1, 25)['total'] === 0, 'Known CUSTOM source ID cannot filter official features');
    $subPage = $request('/admin/reference/subclasses/'.$subclass->getId());
    $check(!str_contains($subPage->getContent(), '/admin/reference/classes/'.$class->getId()), 'CUSTOM parent link hidden');
    $db->executeStatement("UPDATE character_class SET origin = 'OFFICIAL', owner_id = NULL WHERE id = ?", [$class->getId()]);
    $em->clear();
    $classDetail = $catalogue->detail('classes', $catalogue->find('classes', $class->getId()));
    $check(empty($classDetail['groups']['Ressources via capacités']) && empty($classDetail['groups']['Règles de ressource']) && empty($classDetail['tables']['Actions métier']), 'Official assignments cannot expose CUSTOM resource/action');
} finally {
    $db->rollbackSavepoint('official_catalogue');
    $db->releaseSavepoint('official_catalogue');
    $em->clear();
}
