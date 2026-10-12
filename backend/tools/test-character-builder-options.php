<?php
declare(strict_types=1);
require __DIR__.'/character-training-fixture.php';
use App\Controller\CharacterBuilderController;
use App\Entity\{CharacterClass, CharacterRace, CharacterBackground, User};
use App\Service\CharacterTrainingPresenter;
use App\Security\Voter\CampaignVoter;
use Symfony\Component\Security\Core\Authentication\Token\{Storage\TokenStorage, UsernamePasswordToken};
use Symfony\Component\Security\Core\Authorization\{AuthorizationChecker, AccessDecisionManager};
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
$f = trainingFixture(); extract($f);
$em = new class extends Doctrine\ORM\EntityManager {
    public array $rows = [];
    public function __construct() {}
    public function find($className, mixed $id, Doctrine\DBAL\LockMode|int|null $lockMode = null, ?int $lockVersion = null): ?object { return $this->rows[$className][$id] ?? null; }
    public function persist(object $object): void { throw new RuntimeException('Preview attempted persistence.'); }
    public function flush(): void { throw new RuntimeException('Preview attempted flush.'); }
};
foreach ([CharacterClass::class => $classes, CharacterRace::class => $races, CharacterBackground::class => $backgrounds] as $class => $rows) foreach ($rows as $r) $em->rows[$class][$r->getId()] = $r;
$tokens = new TokenStorage();
$tokens->setToken(new UsernamePasswordToken($owner, 'test', []));
$container = new Container();
$container->set('security.authorization_checker', new AuthorizationChecker($tokens, new AccessDecisionManager([new CampaignVoter()])));
$controller = new CharacterBuilderController(); $controller->setContainer($container);
$presenter = new CharacterTrainingPresenter($refs,$resolver,$proficiencyResolver);
$selection = ['raceId' => $races['human-phb']->getId(), 'classId' => $classes['rogue']->getId(), 'backgroundId' => $backgrounds['criminal-phb']->getId()];
$call = static fn (array $s) => $controller->options($campaign, Request::create('/', 'GET', ['selection' => json_encode($s)]), $em, $service, $presenter);
$checks=0;
$check=static function(bool $ok,string $m)use(&$checks):void{if(!$ok)throw new RuntimeException($m);++$checks;};
$response = $call($selection);
$check($response->getStatusCode() === 200, 'Read-only options succeeds without page-two choices.');
$json=json_decode($response->getContent(),true,512,JSON_THROW_ON_ERROR);
$check(isset($json['proficiencyRules'],$json['languageRules'],$json['expertiseRules'],$json['resolved']), 'Page-two contract keys.');
$selection['training']=$payload;
$response=$call($selection); $json=json_decode($response->getContent(),true,512,JSON_THROW_ON_ERROR);
$check(count($json['resolved']['expertises']) === 2 && count($json['substitutions']) >= 2, 'Preview reflects selected proficiencies and expertise.');
foreach (range(1, 2) as $refresh) {
    $json = json_decode($call($selection)->getContent(), true, 512, JSON_THROW_ON_ERROR);
    $languages = array_column($json['languageRules'], null, 'sourceKey');
    $check(in_array('elvish', $languages['race:human-phb']['optionSlugs'], true), 'Refreshing retains selected language in editable options');
    $expertises = array_column($json['expertiseRules'], null, 'ruleId');
    $chosen = $payload['expertiseChoices'][0];
    $check(array_diff($chosen['proficiencyIds'], $expertises[$chosen['ruleId']]['optionIds']) === [], 'Refreshing retains selected expertises in editable options');
}
$races['human-phb']->setLanguages(null)->setLanguageChoiceCount(null);
$response = $call(array_diff_key($selection, ['training' => true]));
$check($response->getStatusCode() === 422 && str_contains($response->getContent(), 'import-training'), 'Missing catalogue metadata rejected before page-two validation');
$races['human-phb']->setLanguages(['common'])->setLanguageChoiceCount(1);
$selection['classId']=99999;
$check($call($selection)->getStatusCode()===422,'Unknown references return 422.');
$foreign=new User(); $id($foreign,2); $tokens->setToken(new UsernamePasswordToken($foreign,'test',[]));
try { $call($selection); throw new RuntimeException('Unauthorized preview accepted.'); }
catch (Symfony\Component\Security\Core\Exception\AccessDeniedException) { ++$checks; }
echo "OK: $checks builder options controller assertions.\n";

