<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Character;
use App\Enum\Ability;
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
        if (!array_is_list($spellIds) || count($spellIds) !== count(array_unique($spellIds, SORT_REGULAR))) throw new UnprocessableEntityHttpException('Liste de préparation invalide.');
        foreach ($spellIds as $id) if (!is_int($id) || $id < 1) throw new UnprocessableEntityHttpException('Identifiant de sort invalide.');
        $options = $this->acquisitions->options($character, $policyId, $character->getCampaign()->getOwner());
        $plan = $this->plan($character, $options, $state);
        if ($plan === null || $plan['blocked']) throw new UnprocessableEntityHttpException('Préparation indisponible pour cette politique.');
        if (count($spellIds) > $plan['maximum'] || array_diff($spellIds, array_column($plan['available'], 'id'))) throw new UnprocessableEntityHttpException('Limite de préparation dépassée ou sort non éligible.');
        $state['preparedSpells'][(string)$policyId] = $spellIds;
        return $state;
    }
}
