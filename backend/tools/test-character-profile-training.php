<?php

declare(strict_types=1);

require __DIR__.'/character-training-fixture.php';

use App\Entity\{Character, CharacterManualGrant, CharacterSessionState, GameSession};
use App\Repository\{CharacterFeatureRuleRepository, TrackableResourceRuleRepository, CharacterActionClassRuleRepository, CharacterActiveEffectRepository};
use App\Service\{CharacterTrainingPresenter, CharacterProfileSerializer, CharacterSessionStateSerializer, CharacterAbilityCalculator, CharacterFeatureResolver, CharacterResourceResolver, CharacterActionResolver, CharacterHitPointCalculator, CharacterHitPointStateService, CharacterSpellSlotCalculator, CharacterSpellSlotStateService};

(new Symfony\Component\Dotenv\Dotenv())->bootEnv(__DIR__.'/../.env');
$kernel = new App\Kernel('dev', true);
$kernel->boot();
$registry = $kernel->getContainer()->get('doctrine');
$em = $registry->getManager();
$db = $em->getConnection();
$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void {
    if (!$ok) throw new RuntimeException($message);
    ++$checks;
};

try {
    // Empty transaction-local tables isolate the other profile resolvers from real data.
    $db->beginTransaction();
    $metadata = $em->getMetadataFactory()->getAllMetadata();
    foreach ((new Doctrine\ORM\Tools\SchemaTool($em))->getCreateSchemaSql($metadata) as $sql) {
        $db->executeStatement(preg_replace(['/^CREATE TABLE /', '/^CREATE SEQUENCE /'], ['CREATE TEMP TABLE ', 'CREATE TEMP SEQUENCE '], $sql));
    }
    foreach ($metadata as $meta) {
        if ($db->fetchOne('SELECT relpersistence FROM pg_class WHERE oid = to_regclass(?)', [$meta->getTableName()]) !== 't') {
            throw new RuntimeException('Table not isolated: '.$meta->getTableName());
        }
    }
    extract(trainingFixture());
    $character = $make();
    $id($character, 1);
    $service->apply($character, $payload, $owner);
    $presenter = new CharacterTrainingPresenter($refs, $resolver, $proficiencyResolver);
    $ability = new CharacterAbilityCalculator();
    $hp = new CharacterHitPointCalculator($ability);
    $slots = new CharacterSpellSlotCalculator();
    $features = new CharacterFeatureResolver(new CharacterFeatureRuleRepository($registry));
    $profile = new CharacterProfileSerializer($ability, $features,
        new CharacterResourceResolver(new TrackableResourceRuleRepository($registry), $ability, $features),
        $hp, $slots, new CharacterActionResolver(new CharacterActionClassRuleRepository($registry)), $presenter);
    $serialized = $profile->serialize($character);
    $training = $serialized['training'];
    $check($training === $presenter->resolved($character, false), 'Profile reuses resolved presenter contract');
    $check(isset($serialized['abilities'], $serialized['features'], $serialized['feats']), 'Existing profile fields retained');
    $check(array_keys($training) === ['proficiencies', 'languages', 'expertises'], 'Only effective public training, no GM audit');
    $profs = array_column($training['proficiencies'], null, 'slug');
    $check(count($profs) === count($training['proficiencies']), 'One entry per effective proficiency');
    $check(count($profs['stealth']['grants']) === 2, 'Class and background sources retained');
    $check($profs['stealth']['id'] === $def('stealth') && $profs['stealth']['type'] === 'SKILL', 'Stable skill identity and type');
    $check($profs['saving-throw-dexterity']['type'] === 'SAVING_THROW', 'Automatic saving throw type');
    $check(isset($profs['dice-set']) && !isset($profs['acrobatics']), 'Only persisted selections, not all options');
    $check(array_column($training['languages'], 'slug') === ['common', 'elvish', 'thieves-cant'], 'Automatic and chosen languages resolved');
    $expertises = array_column($training['expertises'], null, 'slug');
    foreach (['stealth' => 'SKILL', 'thieves-tools' => 'TOOL'] as $slug => $type) {
        $check($expertises[$slug]['proficiencyId'] === $profs[$slug]['id'] && $expertises[$slug]['type'] === $type, 'Unambiguous expertise link '.$slug);
        $check($expertises[$slug]['sources'] === ['phb-2014-rogue-expertise-1'], 'Expertise origin '.$slug);
    }
    $rule = $existing['expertiseRules']['phb-2014-rogue-expertise-1'];
    (new ReflectionProperty($rule, 'unlockLevel'))->setValue($rule, 2);
    $check($profile->serialize($character)['training']['expertises'] === [], 'Future expertise excluded at current level');
    (new ReflectionProperty($rule, 'unlockLevel'))->setValue($rule, 1);
    $manual = new CharacterManualGrant($character, 'PROFICIENCY', $owner, 'PRIVATE-GM-REASON', $definitions['stealth'], null);
    $id($manual, 900);
    $character->addManualGrant($manual);
    $training = $profile->serialize($character)['training'];
    $profs = array_column($training['proficiencies'], null, 'slug');
    $check($profs['stealth']['manualGrantIds'] === [900] && count($profs['stealth']['grants']) === 2, 'Manual origin coexists without duplicate');
    $check(!str_contains(json_encode($training), 'PRIVATE-GM-REASON'), 'Public contract hides manual audit details');
    $check($presenter->resolved($character)['manualGrants'][0]['reason'] === 'PRIVATE-GM-REASON', 'Existing GM contract retains audit details');

    $state = new CharacterSessionState(new GameSession($campaign, 'training', 'Training'), $character, []);
    $sessionSerializer = new CharacterSessionStateSerializer($profile, new CharacterHitPointStateService($hp),
        new CharacterActiveEffectRepository($registry), new CharacterSpellSlotStateService($slots));
    $check($sessionSerializer->serialize($state)['character']['training'] === $training, 'Initial session response includes resolved training');
    $state->setState(['notes' => 'Updated']);
    $check($sessionSerializer->serialize($state)['character']['training'] === $training, 'Updated session response retains resolved training');
    $empty = new Character($campaign, 'empty', 'Empty', Character::TYPE_PLAYER);
    $check($profile->serialize($empty)['training'] === ['proficiencies' => [], 'languages' => [], 'expertises' => []], 'Empty training uses JSON lists');
    echo "OK: $checks profile training assertions; temporary tables rolled back.\n";
} finally {
    while ($db->isTransactionActive()) $db->rollBack();
    $kernel->shutdown();
}
