<?php

declare(strict_types=1);

// Catalogue mutations are closed. Preserve model/persistence and GM/player disclosure coverage.
use App\Entity\{Campaign, Character, CharacterProgression, CharacterSessionState, GameSession, ProgressionAdjustmentRule, ProgressionDefinition, ProgressionStage, User};
use App\Enum\ProgressionAdjustmentDirection;
use Symfony\Component\HttpFoundation\{Request, Session\Session, Session\Storage\MockArraySessionStorage};
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

require __DIR__.'/../vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv(__DIR__.'/../.env');
putenv('SHELL_VERBOSITY=-1'); $_SERVER['SHELL_VERBOSITY'] = $_ENV['SHELL_VERBOSITY'] = -1;
$kernel = new App\Kernel('dev', true); $kernel->boot();
$container = $kernel->getContainer(); $em = $container->get('doctrine')->getManager(); $db = $em->getConnection();
$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks): void { if (!$ok) throw new RuntimeException($label); ++$checks; };
$session = new Session(new MockArraySessionStorage()); $session->start();
$request = static function (string $path) use ($kernel, $session): array {
    $r = Request::create($path, 'GET', [], [$session->getName() => $session->getId()]); $r->setSession($session);
    $response = $kernel->handle($r);
    return [$response->getStatusCode(), json_decode($response->getContent(), true)];
};
try {
    $db->beginTransaction();
    $metadata = $em->getMetadataFactory()->getAllMetadata();
    foreach ((new Doctrine\ORM\Tools\SchemaTool($em))->getCreateSchemaSql($metadata) as $sql) {
        $db->executeStatement(preg_replace(['/^CREATE TABLE /', '/^CREATE SEQUENCE /'], ['CREATE TEMP TABLE ', 'CREATE TEMP SEQUENCE '], $sql));
    }
    foreach ($metadata as $meta) $check($db->fetchOne('SELECT relpersistence FROM pg_class WHERE oid=to_regclass(?)', [$meta->getTableName()]) === 't', 'Isolated '.$meta->getTableName());
    $user = (new User())->setEmail('progression@example.invalid')->setPassword('unused');
    $otherUser = (new User())->setEmail('other@example.invalid')->setPassword('unused');
    $campaign = new Campaign($user, 'test', 'Test');
    $definition = new ProgressionDefinition('test-reference', 'Test reference');
    $stage = (new ProgressionStage($definition, 'Phase', 0))->setMaximumValue(9);
    $check($stage->getDescription() === null, 'Description defaults to null');
    $stage->setDescription(" \n "); $check($stage->getDescription() === null, 'Blank description normalized');
    $secret = "(RP) SECRET stage\n(M) SECRET effect"; $stage->setDescription($secret);
    $open = new ProgressionStage($definition, 'Open', 12);
    $definition->addStage($stage); $definition->addStage($open);
    $check($stage->containsValue(0) && $stage->containsValue(9) && !$stage->containsValue(10), 'Inclusive stage boundaries');
    $check(!$open->containsValue(11) && $open->containsValue(150), 'Gaps and open ended stages');
    $gain = (new ProgressionAdjustmentRule($definition, ProgressionAdjustmentDirection::GAIN, 'SECRET gain', '+1 si DD 10 + (phase * 2)'))->setTriggerType('  ')->setDisplayOrder(2);
    $loss = new ProgressionAdjustmentRule($definition, ProgressionAdjustmentDirection::LOSS, 'SECRET loss', '-1 à -2 selon action');
    $definition->addAdjustmentRule($gain); $definition->addAdjustmentRule($loss);
    $check($gain->getTriggerType() === null && $gain->getAdjustmentLabel() === '+1 si DD 10 + (phase * 2)', 'Nullable trigger and literal formula');
    foreach ([fn () => $gain->setDescription(' '), fn () => $gain->setAdjustmentLabel(''), fn () => $gain->setDisplayOrder(-1), fn () => $gain->setTriggerType(str_repeat('x', 121)), fn () => $gain->setAdjustmentLabel(str_repeat('x', 256))] as $invalid) {
        try { $invalid(); throw new RuntimeException('Invalid rule accepted'); } catch (DomainException) { ++$checks; }
    }
    $character = new Character($campaign, 'test', 'Test', Character::TYPE_PLAYER);
    $assignment = new CharacterProgression($character, $definition); $character->addProgression($assignment);
    $game = (new GameSession($campaign, 'test', 'Test'))->setStatus(GameSession::STATUS_LIVE);
    $state = new CharacterSessionState($game, $character, ['progressions' => [['id' => 'test-reference', 'currentValue' => 4]], 'resources' => []]);
    foreach ([$user,$otherUser,$campaign,$definition,$stage,$open,$gain,$loss,$character,$assignment,$game,$state] as $entity) $em->persist($entity);
    $em->flush();
    $ids = [$definition->getId(), $gain->getId(), $loss->getId(), $stage->getId()];
    $check($db->fetchOne('SELECT description FROM progression_stage WHERE id=?', [$stage->getId()]) === $secret, 'Multiline persistence');
    $stage->setDescription('Changed'); $em->flush();
    $check($db->fetchOne('SELECT description FROM progression_stage WHERE id=?', [$stage->getId()]) === 'Changed', 'Stage edit persists');
    $stage->setDescription(' '); $em->flush();
    $check($db->fetchOne('SELECT description FROM progression_stage WHERE id=?', [$stage->getId()]) === null, 'Stage clear persists');
    $stage->setDescription($secret); $em->flush();
    $url = '/campaigns/'.$campaign->getId().'/dnd/progressions'; $token = $state->getAccessToken();
    $session->set('_security_main', serialize(new UsernamePasswordToken($user, 'main', ['ROLE_USER'])));
    [$status, $body] = $request($url);
    $check($status === 200, 'Owner campaign reference');
    $reference = $body['progressions'][0];
    $check($reference['stages'][0]['description'] === $secret && count($reference['adjustmentRules']) === 2, 'Campaign response retains GM fields');
    $check($reference['adjustmentRules'][0]['direction'] === 'loss', 'Rules display ordering');
    $gain = $em->find(ProgressionAdjustmentRule::class, $ids[1]);
    $gain->setDisplayOrder(0)->setTriggerType('Automatique')->setAdjustmentLabel('+1 à +3'); $em->flush();
    [$status, $body] = $request($url);
    $check($body['progressions'][0]['adjustmentRules'][0]['id'] === $ids[1], 'Rule ID tie-break preserved');
    $check($body['progressions'][0]['adjustmentRules'][0]['adjustmentLabel'] === '+1 à +3', 'Textual rule range preserved');
    $session->set('_security_main', serialize(new UsernamePasswordToken($otherUser, 'main', ['ROLE_USER'])));
    $check($request($url)[0] === 403, 'Other user cannot read campaign GM fields');
    $session->remove('_security_main');
    $check($request($url)[0] === 401, 'Anonymous campaign reference denied');
    [$status, $public] = $request('/public/characters/'.$token);
    $check($status === 200, 'Token profile');
    $progression = $public['character']['progressions'][0];
    $check(!array_key_exists('adjustmentRules', $progression), 'No adjustment rules in player profile');
    foreach ($progression['stages'] as $publicStage) $check(!array_key_exists('description', $publicStage), 'No secret stage description');
    $check(!str_contains(json_encode($public), 'SECRET'), 'No secret content anywhere in token response');
    $check($public['state']['progressions'][0]['currentValue'] === 4, 'Current value preserved');
    $definition = $em->find(ProgressionDefinition::class, $ids[0]);
    foreach ($definition->getAdjustmentRules()->toArray() as $rule) $definition->removeAdjustmentRule($rule);
    $em->flush();
    $check((int) $db->fetchOne('SELECT count(*) FROM progression_adjustment_rule') === 0, 'Orphan removal');
    $rule = new ProgressionAdjustmentRule($definition, ProgressionAdjustmentDirection::GAIN, 'Cascade', '+1');
    $definition->addAdjustmentRule($rule); $em->persist($rule); $em->flush();
    $db->executeStatement('DELETE FROM character_progression');
    $db->executeStatement('DELETE FROM progression_definition WHERE id=?', [$ids[0]]);
    $check((int) $db->fetchOne('SELECT count(*) FROM progression_stage') === 0 && (int) $db->fetchOne('SELECT count(*) FROM progression_adjustment_rule') === 0, 'Child database cascades');
    echo "OK: $checks progression model/catalogue/disclosure assertions; temporary tables rolled back.\n";
} finally {
    while ($db->isTransactionActive()) $db->rollBack();
    $em->clear(); $kernel->shutdown();
}
