<?php

declare(strict_types=1);

use App\Controller\CharacterSessionStateController;
use App\Controller\ProgressionController;
use App\Entity\{Campaign, Character, CharacterProgression, CharacterSessionState, GameSession, ProgressionAdjustmentRule, ProgressionDefinition, ProgressionStage, User};
use App\Kernel;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

require __DIR__ . '/../vendor/autoload.php';
(new Dotenv())->bootEnv(__DIR__ . '/../.env');
$kernel = new Kernel('dev', true);
$kernel->boot();
$container = $kernel->getContainer();
$em = $container->get('doctrine')->getManager();
$db = $em->getConnection();
$controller = $container->get(ProgressionController::class);
$user = (new User())->setEmail('progression-test@example.invalid');
$tokens = new Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage();
$controllerContainer = new Symfony\Component\DependencyInjection\Container();
$controllerContainer->set('security.authorization_checker', new Symfony\Component\Security\Core\Authorization\AuthorizationChecker(
    $tokens,
    new Symfony\Component\Security\Core\Authorization\AccessDecisionManager([new Symfony\Component\Security\Core\Authorization\Voter\RoleVoter()]),
));
$controller->setContainer($controllerContainer);
$tokens->setToken(new UsernamePasswordToken($user, 'main', ['ROLE_USER']));
$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) throw new RuntimeException($message);
    ++$checks;
};
$request = static fn (string $method, array $payload = []) => Request::create('/', $method, [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload, JSON_THROW_ON_ERROR));
$body = static fn ($response) => json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
$ruleRequest = static function (int $parent, string $method, array $payload = [], ?int $id = null) use ($controller, $em, $request) {
    return $controller->mutateAdjustmentRule($parent, $request($method, $payload), $em, $id);
};

try {
    $db->beginTransaction();
    $metadata = array_map($em->getClassMetadata(...), [ProgressionDefinition::class, ProgressionStage::class, ProgressionAdjustmentRule::class]);
    foreach ((new SchemaTool($em))->getCreateSchemaSql($metadata) as $sql) {
        $db->executeStatement(preg_replace('/^CREATE TABLE /', 'CREATE TEMP TABLE ', $sql));
    }
    // Isolate the global feature resolver from rules pointing at real progression definitions.
    $db->executeStatement('CREATE TEMP TABLE character_feature_rule (LIKE public.character_feature_rule INCLUDING ALL)');
    $before = [];
    foreach (['progression_definition', 'progression_stage', 'progression_adjustment_rule', 'character_session_state'] as $table) {
        $before[$table] = $db->fetchAllAssociative('SELECT * FROM public.' . $table . ' ORDER BY id');
    }
    $definition = new ProgressionDefinition('test-reference', 'Test reference');
    $other = new ProgressionDefinition('test-other', 'Other');
    $em->persist($definition); $em->persist($other); $em->flush();
    $parent = $definition->getId(); $otherId = $other->getId();
    $stage = new ProgressionStage($definition, 'Phase', 0);
    $check($stage->getDescription() === null, 'Stage description defaults to null');
    $stage->setDescription(" \n ");
    $check($stage->getDescription() === null, 'Whitespace becomes null');
    $secret = "(RP) SECRET stage\n(M) SECRET effect";
    $response = $controller->createStage($parent, $request('POST', ['label' => 'Phase', 'minimumValue' => 0, 'maximumValue' => 9, 'description' => $secret]), $em);
    $check($response->getStatusCode() === 201, 'Stage POST');
    $stageId = $body($response)['stage']['id'];
    $em->clear();
    $check($em->find(ProgressionStage::class, $stageId)->getDescription() === $secret, 'Multiline persistence');
    $response = $controller->updateStage($parent, $stageId, $request('PATCH', ['description' => 'Changed']), $em);
    $check($body($response)['stage']['description'] === 'Changed', 'Stage update');
    $response = $controller->updateStage($parent, $stageId, $request('PATCH', ['description' => '  ']), $em);
    $check($body($response)['stage']['description'] === null, 'Stage clear');
    $controller->updateStage($parent, $stageId, $request('PATCH', ['description' => $secret]), $em);
    $response = $controller->createStage($parent, $request('POST', ['label' => 'Overlap', 'minimumValue' => 9, 'maximumValue' => 12]), $em);
    $check($response->getStatusCode() === 422, 'Inclusive overlap remains invalid');
    $response = $controller->createStage($parent, $request('POST', ['label' => 'Open', 'minimumValue' => 12]), $em);
    $check($response->getStatusCode() === 201, 'Gaps and open-ended stages remain valid');
    $stage = $em->find(ProgressionStage::class, $stageId);
    $check($stage->containsValue(0) && $stage->containsValue(9) && !$stage->containsValue(10), 'Inclusive boundaries preserved');

    $gain = ['direction' => 'gain', 'triggerType' => '  ', 'description' => 'SECRET gain', 'adjustmentLabel' => '+1 si DD 10 + (phase * 2)', 'displayOrder' => 2];
    $response = $ruleRequest($parent, 'POST', $gain);
    $check($response->getStatusCode() === 201, 'Create gain');
    $rule = $body($response)['progression']['adjustmentRules'][0]; $gainId = $rule['id'];
    $check($rule['triggerType'] === null && $rule['adjustmentLabel'] === $gain['adjustmentLabel'], 'Nullable trigger and literal formula');
    $response = $ruleRequest($parent, 'POST', ['direction' => 'loss', 'description' => 'SECRET loss', 'adjustmentLabel' => '-1 à -2 selon action', 'displayOrder' => 0]);
    $rules = $body($response)['progression']['adjustmentRules']; $lossId = $rules[0]['id'];
    $check($response->getStatusCode() === 201 && $rules[0]['direction'] === 'loss', 'Create loss and display ordering');
    $response = $ruleRequest($parent, 'PATCH', ['triggerType' => 'Automatique', 'displayOrder' => 0, 'adjustmentLabel' => '+1 à +3'], $gainId);
    $rules = $body($response)['progression']['adjustmentRules'];
    $check($rules[0]['id'] === $gainId && $rules[0]['triggerType'] === 'Automatique' && $rules[0]['adjustmentLabel'] === '+1 à +3', 'Update, textual range and ID tie-break');
    foreach ([['direction' => 'invalid'], ['description' => ' '], ['adjustmentLabel' => ''], ['displayOrder' => -1], ['displayOrder' => 1.5], ['triggerType' => str_repeat('x', 121)], ['adjustmentLabel' => str_repeat('x', 256)], ['description' => null]] as $invalid) {
        $check($ruleRequest($parent, 'PATCH', $invalid, $gainId)->getStatusCode() === 422, 'Reject invalid rule ' . json_encode(array_keys($invalid)));
    }
    $check($ruleRequest($otherId, 'PATCH', ['description' => 'wrong'], $gainId)->getStatusCode() === 404, 'Wrong parent PATCH');
    $check($ruleRequest($otherId, 'DELETE', [], $gainId)->getStatusCode() === 404, 'Wrong parent DELETE');
    $check($ruleRequest($parent, 'POST', ['direction' => 'gain'])->getStatusCode() === 422, 'Missing required fields');
    $em->clear();
    $reference = $body($controller->list($em))['progressions'];
    $reference = array_values(array_filter($reference, fn ($p) => $p['id'] === $parent))[0];
    $check($reference['stages'][0]['description'] === $secret && count($reference['adjustmentRules']) === 2, 'Reference response contains GM fields');

    // Exercise the exact serializer used by both public GET and PATCH responses.
    $definition = $em->find(ProgressionDefinition::class, $parent);
    $campaign = new Campaign($user, 'test', 'Test');
    $character = new Character($campaign, 'test', 'Test', 'player');
    $character->addProgression(new CharacterProgression($character, $definition));
    $session = new CharacterSessionState(new GameSession($campaign, 'test', 'Test'), $character, ['progressions' => [['id' => 'test-reference', 'currentValue' => 4]]]);
    $stateController = $container->get(CharacterSessionStateController::class);
    $serialize = new ReflectionMethod($stateController, 'serializeState');
    $public = $serialize->invoke($stateController, $session);
    $progression = $public['character']['progressions'][0];
    $check(!array_key_exists('adjustmentRules', $progression), 'Public response has no adjustmentRules');
    foreach ($progression['stages'] as $publicStage) {
        $check(!array_key_exists('description', $publicStage), 'Public stages have no description');
    }
    $check(!str_contains(json_encode($public), 'SECRET'), 'No secret content anywhere in public session response');
    $tokens->setToken(null);
    try {
        $controller->list($em);
        throw new RuntimeException('Anonymous reference access accepted');
    } catch (Symfony\Component\Security\Core\Exception\AccessDeniedException) {
        ++$checks;
    }
    $tokens->setToken(new UsernamePasswordToken($user, 'main', ['ROLE_USER']));
    $routes = $container->get('router')->getRouteCollection();
    foreach (['api_dnd_progression_rules_update', 'api_dnd_progression_rules_delete'] as $routeName) {
        $check(preg_match($routes->get($routeName)->compile()->getRegex(), '/dnd/progressions/1/adjustment-rules') === 0, 'Rule ID required for ' . $routeName);
    }
    $response = $ruleRequest($parent, 'DELETE', [], $lossId);
    $check(count($body($response)['progression']['adjustmentRules']) === 1, 'Delete rule');
    $definition->removeAdjustmentRule($em->find(ProgressionAdjustmentRule::class, $gainId));
    $em->flush();
    $check($db->fetchOne('SELECT COUNT(*) FROM progression_adjustment_rule') === 0, 'Orphan removal');
    $ruleRequest($parent, 'POST', $gain);
    $db->executeStatement('DELETE FROM progression_definition WHERE id = ?', [$parent]);
    $check($db->fetchOne('SELECT COUNT(*) FROM progression_adjustment_rule') === 0 && $db->fetchOne('SELECT COUNT(*) FROM progression_stage') === 0, 'Database cascade');
    foreach ($before as $table => $rows) {
        $check($rows === $db->fetchAllAssociative('SELECT * FROM public.' . $table . ' ORDER BY id'), 'Real data unchanged: ' . $table);
    }
    echo "OK: $checks progression reference assertions; temporary tables rolled back.\n";
} finally {
    while ($db->isTransactionActive()) $db->rollBack();
    $tokens->setToken(null);
    $kernel->shutdown();
}
