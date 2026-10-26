<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\{Character, CharacterSessionState, SpellcastingPolicyDefinition};
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\{BadRequestHttpException, ConflictHttpException};

/** Public projection and constrained commands over the existing acquisition engines. */
final readonly class PlayerSpellManagement
{
    public function __construct(
        private EntityManagerInterface $em,
        private CharacterSpellAcquisitions $acquisitions,
        private CharacterFeatureSpellService $features,
        private CharacterSpellPreparation $preparation,
        private PlayerSpellDirectory $directory,
    ) {}

    public function view(CharacterSessionState $session): array
    {
        $character = $session->getCharacter();
        $owner = $character->getCampaign()->getOwner();
        $choices = []; $preparations = []; $warnings = [];
        foreach ($this->acquisitions->status($character, $owner) as $status) {
            $class = $character->getClassLevels()->filter(static fn($level) => $level->getCharacterClass()->getId() === $status['classId'])->first()->getCharacterClass();
            $status = $this->acquisitions->levelUpStatus($character, $class, $owner);
            if ($status['status'] === 'no-spellcasting') continue;
            if ($status['status'] !== 'policy-configured') { $warnings[] = $status['className'].' : configuration de magie à vérifier avec le MJ.'; continue; }
            try {
                $options = $this->acquisitions->options($character, $status['policyIds'][0], $owner);
                $choices = [...$choices, ...$this->routes($character, $options, $status['className'])];
                $plan = $this->preparation->plan($character, $options, $session->getState());
                if ($plan !== null) $preparations[] = $plan + ['source'=>$status['className']];
                if ($options['blocked'] || $options['status'] === 'quota-exceeded') $warnings[] = $status['className'].' : quotas à vérifier avec le MJ.';
            } catch (ConflictHttpException $error) { $warnings[] = $status['className'].' : '.$error->getMessage(); }
        }
        foreach ($this->features->status($character)['pending'] as $grant) {
            $choices[] = ['key'=>'feature:'.$grant['grantId'], 'kind'=>'feature', 'grantId'=>$grant['grantId'], 'policyId'=>null, 'ruleId'=>null,
                'source'=>$grant['featureName'], 'label'=>$grant['spellLevel'] === 0 ? 'Tour de magie octroyé' : 'Sort octroyé de niveau '.$grant['spellLevel'],
                'remaining'=>1, 'ability'=>CharacterFeatureSpellService::castingAbility($grant['castingAbility'])?->value, 'available'=>$this->features->options($character, $grant['grantId'], $owner->getId())];
        }
        // Public options expose only the information needed for player choices.
        foreach ($choices as &$choice) $choice['available'] = array_map(self::candidate(...), $choice['available']);
        unset($choice);
        foreach ($preparations as &$plan) $plan['available'] = array_map(self::candidate(...), $plan['available']);
        unset($plan);
        return ['revision'=>$session->getRevision(), 'editable'=>$session->isParticipating() && $session->getGameSession()->getStatus() === 'live',
            // Legacy session states allow their first preparation without a data migration.
            'spellPreparationAllowed'=>$session->getState()['spellPreparationAllowed'] ?? true,
            'spells'=>$this->directory->forCharacter($character, $session->getState()), 'choices'=>$choices, 'preparations'=>$preparations, 'warnings'=>$warnings];
    }

    private static function candidate(array $spell): array
    {
        return ['id'=>(int)$spell['id'], 'name'=>$spell['name'], 'level'=>(int)$spell['level'], 'school'=>$spell['school'] ?? null];
    }

    public function historicalView(CharacterSessionState $session): array
    {
        $character = $session->getCharacter();
        $owner = $character->getCampaign()->getOwner();
        $classes = [];
        foreach ($this->acquisitions->status($character, $owner) as $status) {
            if ($status['status'] !== 'policy-configured') {
                $classes[] = ['className'=>$status['className'], 'policyId'=>null, 'categories'=>[], 'expectedAcquisitionIds'=>[], 'warning'=>'Politique à vérifier avec le MJ.'];
                continue;
            }
            try {
                $preview = $this->acquisitions->historicalPreview($character, $status['policyIds'][0], $owner);
                // Explicit public projection: no actor, decision notes or internal acquisition rows.
                $categories = array_map(static fn ($category) => array_replace($category, ['available'=>array_map(self::candidate(...), $category['available'])]), $preview['categories']);
                $classes[] = ['className'=>$status['className'], 'policyId'=>$status['policyIds'][0], 'categories'=>$categories,
                    'expectedAcquisitionIds'=>$preview['expectedAcquisitionIds'], 'warning'=>$preview['warning'], 'limitations'=>$preview['limitations'] ?? []];
            } catch (ConflictHttpException $error) {
                $classes[] = ['className'=>$status['className'], 'policyId'=>$status['policyIds'][0], 'categories'=>[], 'expectedAcquisitionIds'=>[], 'warning'=>$error->getMessage()];
            }
        }
        return ['revision'=>$session->getRevision(), 'editable'=>$session->isParticipating() && $session->getGameSession()->getStatus() === 'live', 'classes'=>$classes];
    }

    public function completeHistorical(Character $character, int $policyId, array $choice): array
    {
        return $this->acquisitions->completeHistorical($character, $policyId, $choice, $character->getCampaign()->getOwner());
    }

    private function routes(Character $character, array $options, string $source): array
    {
        if ($options['blocked'] || $options['status'] === 'quota-exceeded') return [];
        // A manual reconciliation at this level is not a new player opportunity.
        foreach ($options['acquisitions'] as $row) if ($row['provenance'] === 'manual-reconciliation' && (int)$row['class_level_id'] === $options['classLevelId']) return [];
        $routes = $options['choices'] ?? [];
        if (($options['formatVersion'] ?? 1) === 1) {
            $policy = $this->em->find(SpellcastingPolicyDefinition::class, $options['policyId'])->policy();
            foreach (['cantrips', 'known', 'spellbook'] as $bucket) {
                $spent = count(array_filter($options['acquisitions'], static fn(array $row): bool => (int)$row['class_level_id'] === $options['classLevelId']
                    && $row['quota_bucket'] === $bucket && $row['provenance'] !== 'spellbook-copy' && $row['replaces_id'] === null));
                $routes[] = ['ruleId'=>null, 'key'=>$bucket, 'quota'=>$bucket, 'remaining'=>max(0, $policy->additionsAtLevel($options['classLevel'], $bucket) - $spent),
                    'available'=>array_values(array_filter($options['available'], static fn(array $spell): bool => $bucket === 'cantrips' ? $spell['level'] === 0 : $spell['level'] > 0))];
            }
        }
        $acquired = array_map('intval', array_column($options['acquisitions'], 'spell_id'));
        $result = [];
        foreach ($routes as $route) {
            $automatic = array_filter($options['automatic'], static fn(array $grant): bool => ($options['formatVersion'] ?? 1) === 2
                ? $grant['bucket'] !== null : $grant['bucket'] === $route['quota']);
            $occupied = [...$acquired, ...array_column($automatic, 'spellId')];
            $remaining = min($route['remaining'], $options['remaining'][$route['quota']] ?? $route['remaining']);
            if ($remaining < 1) continue;
            $result[] = ['key'=>'class:'.$options['policyId'].':'.($route['ruleId'] ?? $route['key']), 'kind'=>'class', 'grantId'=>null,
                'policyId'=>$options['policyId'], 'ruleId'=>$route['ruleId'], 'source'=>$source, 'label'=>match ($route['quota']) {
                    'cantrips'=>'Tours de magie', 'spellbook'=>'Ajouts au grimoire', default=>'Sorts à apprendre',
                }, 'remaining'=>$remaining, 'ability'=>$options['ability'],
                'available'=>array_values(array_filter($route['available'], static fn(array $spell): bool => !in_array($spell['id'], $occupied, true)))];
        }
        return $result;
    }

    /** Caller holds the character/session locks. No replacements or GM overrides accepted. */
    public function learn(Character $character, int $policyId, int $spellId, ?int $ruleId): void
    {
        $owner = $character->getCampaign()->getOwner();
        $options = $this->acquisitions->options($character, $policyId, $owner);
        $allowed = false;
        foreach ($this->routes($character, $options, '') as $route) {
            if ($route['ruleId'] === $ruleId && in_array($spellId, array_column($route['available'], 'id'), true)) $allowed = true;
        }
        if (!$allowed) throw new BadRequestHttpException('Choix non disponible à ce niveau.');
        $choice = ['spellId'=>$spellId, 'classLevelId'=>$options['classLevelId']];
        if ($ruleId !== null) $choice['ruleId'] = $ruleId;
        $this->acquisitions->acquire($character, $policyId, [$choice], 'level-history',
            'Choix via accès joueur au niveau actuel ; propriétaire enregistré comme acteur technique.', $owner);
    }
}
