<?php

declare(strict_types=1);
namespace App\Service;

use App\Entity\{Character, CharacterClassLevel, User};
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;


/** Mutations own a transaction except when explicitly participating in level-up's transaction. */
final readonly class CharacterFeatureChoices
{
    public function __construct(private EntityManagerInterface $em, private FeatureChoiceReader $reader,
        private CharacterFeatureResolver $features, private CharacterFeatureSpellService $spells,
        private CharacterSessionStateSynchronizer $states, private CharacterLevelUpOptionsService $levelOptions) {}

    /** Read-only projected rights using the same class/subclass eligibility as level-up. */
    public function previewLevel(Character $character, string $classKey, ?string $subclassKey): array
    {
        $options = $this->levelOptions->getOptions($character);
        $selected = null;
        foreach ($options['classes'] as $option) if ($option['slug'] === $classKey) $selected = $option;
        if ($selected === null || !$selected['eligible']) throw new \DomainException('Classe indisponible.');
        $class = $this->em->find(\App\Entity\CharacterClass::class, $selected['id']);
        $subclass = $character->getSubclassFor($class);
        if ($selected['subclassRequired']) {
            $subclass = null;
            foreach ($selected['subclasses'] as $option) if ($option['slug'] === $subclassKey) $subclass = $this->em->find(\App\Entity\CharacterSubclass::class,$option['id']);
            if ($subclass === null) throw new \DomainException('Sous-classe requise.');
        } elseif ($subclassKey !== null && $subclass?->getSlug() !== $subclassKey) {
            throw new \DomainException('Sous-classe incompatible.');
        }
        $before = $this->preview($character);
        $level = new CharacterClassLevel($character,$class,$character->getNextLevelPosition(),$subclass);
        $character->addClassLevel($level);
        try {
            $result = $this->preview($character);
            $new = array_diff(array_column($result['grants'],'key'),array_column($before['grants'],'key'));
            foreach ($result['grants'] as &$grant) {
                $grant['newAtLevel'] = in_array($grant['key'],$new,true);
                $grant['replacementAvailable'] = $grant['classKey'] === $classKey && ($grant['replacement'] === 'class-level'
                    || ($grant['replacement'] === 'grant-level' && array_filter($result['grants'],static fn ($g) => $g['group_key'] === $grant['group_key'] && in_array($g['key'],$new,true))));
            }
            unset($grant);
            $result['baseFingerprint'] = $before['fingerprint'];
            $result['projectedPosition'] = $level->getPosition();
            return $result;
        } finally {
            $character->getClassLevels()->removeElement($level);
        }
    }

    public function preview(Character $character): array
    {
        $result = $this->reader->evaluate($character, $this->features->applicableRules($character, [], false));
        $history = $this->reader->history($character);
        $grants = array_values($result['grants']);
        foreach ($grants as &$grant) {
            $grant['options'] = array_values($grant['options']);
            $grant['replacementAvailable'] = false; // Only a new class-level event or an explicit GM correction opens this.
        }
        unset($grant);
        return ['fingerprint'=>hash('sha256', json_encode([$grants,$history,$character->getTotalLevel()], JSON_THROW_ON_ERROR)),
            'grants'=>$grants, 'history'=>$history,
            'missing'=>array_values(array_map(static fn ($g) => $g['key'], array_filter($grants, static fn ($g) => $g['remaining'] > 0))),
            'issues'=>array_values(array_filter($result['selections'], static fn ($row, $index) => !isset($result['valid'][$index]), ARRAY_FILTER_USE_BOTH))];
    }

    public static function selections(mixed $payload): array
    {
        if (!is_array($payload) || !array_is_list($payload) || count($payload) > 100) throw new \InvalidArgumentException('Liste de choix invalide.');
        foreach ($payload as $row) {
            if (!is_array($row) || array_diff(array_keys($row), ['grantKey','optionKey','slot','replacesKey'])
                || !is_string($row['grantKey'] ?? null) || !is_string($row['optionKey'] ?? null)
                || strlen($row['grantKey']) > 100 || strlen($row['optionKey']) > 100
                || !is_int($row['slot'] ?? null) || $row['slot'] < 1 || $row['slot'] > 20
                || (isset($row['replacesKey']) && (!is_string($row['replacesKey']) || !preg_match('/^[a-f0-9]{32}$/D', $row['replacesKey'])))) throw new \InvalidArgumentException('Sélection invalide.');
        }
        return $payload;
    }

    /** Explicit GM regularization. Nothing is inferred from existing manual assignments. */
    public function regularize(Character $character, User $actor, string $fingerprint, array $selections, string $reason): array
    {
        if ($actor->getId() !== $character->getCampaign()->getOwner()->getId()) throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException();
        if (trim($reason) === '') throw new \InvalidArgumentException('Motif de régularisation requis.');
        return $this->em->wrapInTransaction(function () use ($character,$actor,$fingerprint,$selections,$reason) {
            $this->em->lock($character, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($character);
            if (!hash_equals($this->preview($character)['fingerprint'], $fingerprint)) throw new ConflictHttpException('Les choix ont changé.');
            $this->write($character, $selections, $actor, $reason, null, null);
            $this->spells->synchronize($character);
            foreach ($this->em->getRepository(\App\Entity\CharacterSessionState::class)->findBy(['character'=>$character]) as $state) {
                $this->em->lock($state, LockMode::PESSIMISTIC_WRITE);
                $this->em->refresh($state);
                $state->setState($this->states->synchronizeResources($character, $state->getState()));
            }
            $this->em->flush();
            return $this->preview($character);
        });
    }

    /** Called after the level is added, before resource/spell synchronization, under the character lock. */
    public function applyLevel(Character $character, CharacterClassLevel $level, array $before, array $selections): void
    {
        if ($character->getId() === null && $selections !== []) $this->em->flush();
        $old = array_column($before['grants'], null, 'key');
        $now = $this->preview($character);
        $newKeys = [];
        foreach ($now['grants'] as $grant) if (!isset($old[$grant['key']])) $newKeys[] = $grant['key'];
        $this->write($character, $selections, null, null, $level, $newKeys);
        $after = $this->preview($character);
        foreach ($after['grants'] as $grant) {
            if (in_array($grant['key'], $newKeys, true) && ($grant['remaining'] !== 0 || array_filter($grant['selected'], static fn ($c) => !$c['valid']))) {
                throw new \DomainException('Choix de capacité obligatoire incomplet : '.$grant['key']);
            }
        }
    }

    private function write(Character $character, array $selections, ?User $actor, ?string $reason, ?CharacterClassLevel $level, ?array $newKeys): void
    {
        if (!$this->em->getConnection()->isTransactionActive()) throw new \LogicException('Transaction requise.');
        $selections = self::selections($selections);
        $baseRules = $this->features->applicableRules($character, [], false);
        $current = $this->reader->evaluate($character, $baseRules);
        $rows = $current['selections'];
        $oldRows = array_column($rows, null, 'key');
        $changed = $retired = $events = [];
        $previouslyValid = [];
        foreach ($current['valid'] as $index => $_) $previouslyValid[$current['selections'][$index]['key']] = true;
        foreach ($selections as $selection) {
            $grant = $current['grants'][$selection['grantKey']] ?? null;
            if ($grant === null || !isset($grant['options'][$selection['optionKey']]) || $selection['slot'] > $grant['choice_count']) throw new \DomainException('Droit ou option indisponible.');
            $event = null;
            $replaces = $selection['replacesKey'] ?? null;
            if ($replaces !== null) {
                $old = $oldRows[$replaces] ?? null;
                if ($old === null || isset($retired[$replaces]) || $old['grant_key'] !== $grant['key'] || (int)$old['slot'] !== $selection['slot'] || $old['option_key'] === $selection['optionKey']) throw new \DomainException('Remplacement invalide.');
                if ($level !== null) {
                    $allowed = $grant['classKey'] === $level->getCharacterClass()->getSlug()
                        && ($grant['replacement'] === 'class-level' || ($grant['replacement'] === 'grant-level' && array_filter($current['grants'], static fn ($g) => $g['group_key'] === $grant['group_key'] && in_array($g['key'], $newKeys, true))));
                    if (!$allowed) throw new \DomainException('Remplacement non autorisé à ce niveau.');
                    $event = 'replace:'.$grant['group_key'].':'.$level->getPosition();
                    if (isset($events[$event]) || $this->em->getConnection()->fetchOne('SELECT 1 FROM character_feature_choice WHERE character_id=? AND event_key=?', [$character->getId(),$event])) throw new \DomainException('Droit de remplacement déjà utilisé.');
                    $events[$event] = true;
                }
                $retired[$replaces] = true;
                $rows = array_values(array_filter($rows, static fn ($r) => $r['key'] !== $replaces));
            } elseif ($newKeys !== null && !in_array($grant['key'], $newKeys, true)) {
                throw new \DomainException('Choix historique : régularisation MJ requise.');
            }
            foreach ($rows as $row) if ($row['grant_key'] === $grant['key'] && (int)$row['slot'] === $selection['slot']) throw new \DomainException('Emplacement de choix occupé.');
            $key = bin2hex(random_bytes(16));
            $row = ['key'=>$key,'grant_key'=>$grant['key'],'option_key'=>$selection['optionKey'],'slot'=>$selection['slot'],
                'acquired_position'=>$level?->getPosition() ?? max(1,$character->getTotalLevel()),
                'acquired_class_level'=>$grant['classLevel'], 'replaces_key'=>$replaces,'event_key'=>$event];
            $rows[] = $row; $changed[$key] = $row;
        }
        $evaluated = $this->reader->evaluate($character, $baseRules, $rows);
        $unique = [];
        foreach ($rows as $index => $row) {
            if (!isset($evaluated['valid'][$index])) {
                if (isset($changed[$row['key']]) || isset($previouslyValid[$row['key']])) throw new \DomainException('Prérequis non satisfaits ou capacité dépendante invalidée.');
                continue;
            }
            $option = $evaluated['valid'][$index];
            $grant = $evaluated['grants'][$row['grant_key']];
            foreach ($unique as [$previousGrant,$previousFeature]) {
                if ($previousFeature === $option['featureKey'] && ($grant['uniqueness'] === 'character' || $previousGrant['uniqueness'] === 'character'
                    || ($grant['group_key'] === $previousGrant['group_key'] && $grant['uniqueness'] === 'group'))) throw new \DomainException('Option déjà choisie.');
            }
            $unique[] = [$grant,$option['featureKey']];
        }
        $db = $this->em->getConnection();
        foreach ($retired as $key => $_) $db->update('character_feature_choice', ['active'=>false], ['key'=>$key], ['active'=>'boolean']);
        foreach ($changed as $row) {
            $grant = $current['grants'][$row['grant_key']];
            $db->insert('character_feature_choice', [...$row, 'character_id'=>$character->getId(), 'actor_id'=>$actor?->getId(), 'reason'=>$reason,
                'provenance'=>json_encode(['grantKey'=>$grant['key'],'groupKey'=>$grant['group_key'],'sourceFeature'=>$grant['sourceFeature'],
                    'classKey'=>$grant['classKey'],'unlockLevel'=>$grant['unlockLevel']], JSON_THROW_ON_ERROR)]);
        }
    }
}
