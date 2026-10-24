<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Character;
use App\Enum\Ability;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/** Preparation is session state, never an acquisition or a spell-slot expenditure. */
final readonly class CharacterSpellPreparation
{
    public function __construct(private CharacterSpellAcquisitions $acquisitions, private CharacterAbilityCalculator $abilities) {}

    public function plan(Character $character, array $options, array $state): ?array
    {
        $base = $options['preparation']['base'];
        if ($base === null || !in_array($options['learningStyle'], ['spellbook', 'prepared'], true)) return null;
        $statistics = $this->abilities->spellStatistics($character, Ability::from($options['ability']));
        $missingAbility = $statistics['spellAttackBonus'] === null;
        $maximum = $missingAbility ? 0 : max(1, $base + $statistics['spellAttackBonus'] - $character->getProficiencyBonus());
        $maximum = max(0, $maximum - $options['preparation']['automaticCounted']);
        $automatic = array_column(array_filter($options['automatic'], static fn(array $grant): bool => $grant['bucket'] === 'preparation'), 'spellId');
        $candidates = $options['learningStyle'] === 'spellbook'
            ? array_map(static fn(array $row): array => ['id'=>(int)$row['spell_id'], 'name'=>$row['spell_name'], 'level'=>(int)$row['spell_level']],
                array_values(array_filter($options['acquisitions'], static fn(array $row): bool => filter_var($row['in_spellbook'], FILTER_VALIDATE_BOOL))))
            : $options['available'];
        $candidates = array_values(array_filter($candidates, static fn(array $spell): bool => $spell['level'] > 0
            && $spell['level'] <= $options['limits']['maxSpellLevel'] && !in_array($spell['id'], $automatic, true)));
        $selected = $state['preparedSpells'][(string)$options['policyId']] ?? [];
        $selected = array_values(array_intersect(is_array($selected) ? $selected : [], array_column($candidates, 'id')));
        return ['policyId'=>$options['policyId'], 'ability'=>$options['ability'], 'learningStyle'=>$options['learningStyle'],
            'maximum'=>$maximum, 'selected'=>$selected, 'available'=>$candidates,
            'blocked'=>$missingAbility || $options['blocked'] !== [] || $options['status'] === 'quota-exceeded'];
    }

    public function update(Character $character, int $policyId, array $spellIds, array $state): array
    {
        $this->requireOpen($state);
        foreach ($spellIds as $id) if (!is_int($id) || $id < 1) throw new UnprocessableEntityHttpException('Identifiant de sort invalide.');
        if (!array_is_list($spellIds) || count($spellIds) !== count(array_unique($spellIds, SORT_REGULAR))) throw new UnprocessableEntityHttpException('Liste de préparation invalide.');
        $options = $this->acquisitions->options($character, $policyId, $character->getCampaign()->getOwner());
        $plan = $this->plan($character, $options, $state);
        if ($plan === null || $plan['blocked']) throw new UnprocessableEntityHttpException('Préparation indisponible pour cette politique.');
        if (count($spellIds) > $plan['maximum'] || array_diff($spellIds, array_column($plan['available'], 'id'))) throw new UnprocessableEntityHttpException('Limite de préparation dépassée ou sort non éligible.');
        $state['preparedSpells'][(string)$policyId] = $spellIds;
        return $state;
    }

    /** Caller holds the character/session locks. Return a new state only after every validation succeeds. */
    public function confirm(Character $character, array $selections, array $state): array
    {
        $this->requireOpen($state);
        if (!array_is_list($selections)) throw new UnprocessableEntityHttpException('Une liste de sélections est requise.');
        $submitted = [];
        foreach ($selections as $selection) {
            if (!is_array($selection) || count($selection) !== 2
                || !is_int($selection['policyId'] ?? null) || $selection['policyId'] < 1
                || !is_array($selection['spellIds'] ?? null) || count($selection['spellIds']) > 100
                || isset($submitted[$selection['policyId']])) {
                throw new UnprocessableEntityHttpException('Sélection invalide ou politique dupliquée.');
            }
            $submitted[$selection['policyId']] = $selection['spellIds'];
        }

        // Resolve every class explicitly: the public view may omit misconfigured policies with a warning.
        $owner = $character->getCampaign()->getOwner();
        $classes = []; $required = [];
        foreach ($character->getClassLevels() as $level) {
            $class = $level->getCharacterClass();
            if (isset($classes[$class->getId()])) continue;
            $classes[$class->getId()] = true;
            $status = $this->acquisitions->levelUpStatus($character, $class, $owner);
            if ($status['status'] === 'no-spellcasting') continue;
            if ($status['status'] !== 'policy-configured') throw new ConflictHttpException('Configuration de magie incomplète ou ambiguë.');
            $policyId = $status['policyIds'][0];
            $options = $this->acquisitions->options($character, $policyId, $owner);
            if ($this->plan($character, $options, $state) !== null) $required[] = $policyId;
        }
        if (array_diff($required, array_keys($submitted)) || array_diff(array_keys($submitted), $required)) {
            throw new UnprocessableEntityHttpException('Toutes les politiques de préparation applicables, et elles seules, sont requises.');
        }
        foreach ($submitted as $policyId => $spellIds) {
            $state = $this->update($character, $policyId, $spellIds, $state);
        }
        $state['spellPreparationAllowed'] = false;
        return $state;
    }

    private function requireOpen(array $state): void
    {
        if (($state['spellPreparationAllowed'] ?? true) !== true) {
            throw new ConflictHttpException('La préparation des sorts est verrouillée jusqu’au prochain repos long.');
        }
    }
}
