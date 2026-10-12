<?php
declare(strict_types=1);
// Shared, entirely in-memory reference fixture. No kernel or SQL.
use App\Entity\{Campaign, Character, CharacterClass, CharacterClassLevel, CharacterRace, CharacterBackground, ProficiencyDefinition, User};
use App\Enum\{ProficiencyType, Ability};
use App\Repository\ProficiencyGrantRuleRepository;
use App\Service\{CharacterTrainingReferences, CharacterTrainingResolver, CharacterTrainingService, CharacterProficiencyResolver, CharacterProficiencyChoiceService, CharacterRaceMetadataResolver, ReferenceVisibility};
use App\Service\Catalogue\{TrainingImportPlanner, BackgroundImportPlanner, ClassProficiencyImportPlanner, RaceProficiencyImportPlanner};

require_once __DIR__.'/../vendor/autoload.php';
function trainingFixture(): array
{
    $load = static fn ($file) => json_decode(file_get_contents(__DIR__.'/../data/reference/'.$file), true, 512, JSON_THROW_ON_ERROR);
    $id = static function (object $entity, int $id): void { (new ReflectionProperty($entity, 'id'))->setValue($entity, $id); };
    $owner = new User(); $id($owner, 1);
    $campaign = new Campaign($owner, 'test', 'Test');
    $definitions = [];
    foreach ($load('dnd-2014-proficiencies.json')['proficiencies'] as $i => $row) {
        $d = new ProficiencyDefinition($row['slug'], $row['name'], ProficiencyType::from($row['type']));
        $id($d, $i + 1); $definitions[$row['slug']] = $d;
    }
    $classes = [];
    foreach (['barbarian','bard','cleric','druid','sorcerer','fighter','wizard','monk','warlock','paladin','ranger','rogue'] as $i => $slug) {
        $classes[$slug] = new CharacterClass($slug, $slug, 8, in_array($slug, ['cleric','sorcerer','warlock'], true) ? 1 : 3);
        $id($classes[$slug], $i + 1);
    }
    $races = [];
    $raceCatalogue = $load('dnd-2014-races.json');
    foreach ($raceCatalogue['entries'] as $i => $entry) { $races[$entry['slug']] = new CharacterRace($entry['slug'], $entry['name']); $id($races[$entry['slug']], $i + 1); }
    foreach ($raceCatalogue['entries'] as $entry) if ($entry['parentSlug'] ?? null) $races[$entry['slug']]->setParentRace($races[$entry['parentSlug']]);
    $backgroundPlan = (new BackgroundImportPlanner())->plan($load('dnd-2014-backgrounds.json'), [], $definitions, []);
    $backgrounds = [];
    foreach ($backgroundPlan['backgrounds'] as $i => $item) { $backgrounds[$item['candidate']->getSlug()] = $item['candidate']; $id($item['candidate'], $i + 1); }
    $proficiencyRules = array_column($backgroundPlan['rules'], 'candidate');
    $proficiencyRules = [...$proficiencyRules,
        ...array_column((new ClassProficiencyImportPlanner())->plan($load('dnd-2014-class-proficiencies.json'), $classes, $definitions, []), 'candidate'),
        ...array_column((new RaceProficiencyImportPlanner())->plan($load('dnd-2014-race-proficiencies.json'), $races, $definitions, []), 'candidate')];
    foreach ($proficiencyRules as $i => $r) $id($r, $i + 1);
    $existing = ['languages' => [], 'languageRules' => [], 'expertiseRules' => [], 'classes' => $classes, 'backgrounds' => $backgrounds, 'races' => $races, 'proficiencies' => $definitions];
    $catalogue = $load('dnd-2014-training.json');
    $planner = new TrainingImportPlanner();
    $plan = $planner->plan($catalogue, $raceCatalogue, $existing, true);
    foreach (['languages','languageRules','expertiseRules'] as $kind) {
        foreach (array_values($plan[$kind]) as $i => $item) {
            $candidate = $item['candidate']; $id($candidate, $i + 1);
            $existing[$kind][$kind === 'languages' ? $candidate->getSlug() : $candidate->getImportKey()] = $candidate;
        }
    }
    foreach ($plan['races'] as $item) if ($item['action'] === 'update') $planner->applyUpdate('races', $item);
    $refs = new class extends CharacterTrainingReferences {
        public array $data = [];
        public function __construct() {}
        public function load(Character $character): array
        {
            $result = [];
            foreach ($this->data as $key => $rows) $result[$key] = array_values(array_filter($rows, static fn ($r) => ReferenceVisibility::allows($r, $character->getCampaign()->getOwner())));
            return $result;
        }
    };
    $refs->data = ['proficiencies' => array_values($definitions), 'proficiencyRules' => $proficiencyRules,
        'languages' => array_values($existing['languages']), 'languageRules' => array_values($existing['languageRules']), 'expertiseRules' => array_values($existing['expertiseRules'])];
    $repo = new class extends ProficiencyGrantRuleRepository {
        public array $rules = [];
        public function __construct() {}
        public function findVisibleForOwner(User $owner): array { return $this->rules; }
    };
    $repo->rules = $proficiencyRules;
    $proficiencyResolver = new CharacterProficiencyResolver($repo);
    $metadata = new CharacterRaceMetadataResolver();
    $resolver = new CharacterTrainingResolver($proficiencyResolver, $metadata, $refs);
    $service = new CharacterTrainingService($refs, $resolver, $proficiencyResolver, new CharacterProficiencyChoiceService(), $metadata);
    $make = static function (string $class = 'rogue', string $race = 'human-phb', ?string $background = 'criminal-phb', int $level = 1) use ($campaign, $classes, $races, $backgrounds): Character {
        $c = (new Character($campaign, 'hero', 'Hero', Character::TYPE_PLAYER))->setRace($races[$race])->setBackground($background === null ? null : $backgrounds[$background]);
        for ($i = 1; $i <= $level; ++$i) $c->addClassLevel(new CharacterClassLevel($c, $classes[$class], $i));
        return $c;
    };
    $rule = static function (string $key) use ($proficiencyRules) {
        foreach ($proficiencyRules as $rule) if ($rule->getImportKey() === $key) return $rule->getId();
        throw new RuntimeException('Missing fixture rule '.$key);
    };
    $def = static fn (string $slug) => $definitions[$slug]->getId();
    $payload = [
        'proficiencyChoices' => [
            ['ruleId' => $rule('phb-2014-rogue-starting-skills'), 'proficiencyIds' => array_map($def, ['stealth','deception','insight','investigation'])],
            ['ruleId' => $rule('phb-2014-background-criminal-phb-tools-choice'), 'proficiencyIds' => [$def('dice-set')]],
        ],
        'languageChoices' => [['sourceKey' => 'race:human-phb', 'languageSlugs' => ['elvish']]],
        'expertiseChoices' => [['ruleId' => $existing['expertiseRules']['phb-2014-rogue-expertise-1']->getId(), 'proficiencyIds' => [$def('stealth'), $def('thieves-tools')]]],
    ];
    return compact('owner','campaign','definitions','classes','races','backgrounds','existing','catalogue','raceCatalogue','planner','plan','refs','repo','proficiencyResolver','resolver','service','make','rule','def','payload','id');
}

