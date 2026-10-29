<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\SpellcastingPolicy;
use App\Dto\SpellChoiceParameters;
use App\Entity\CharacterClass;
use App\Entity\SpellDefinition;
use App\Enum\SpellSchool;

final class SpellPolicyTemplateFactory
{
    /**
     * Modèle générique de tiers-lanceur à écoles restreintes.
     *
     * Retourne une politique et ses règles, sans rien enregistrer.
     */
    public function restrictedThirdCaster(
        CharacterClass $spellList,
        string $key,
        array $schools,
        array $unrestrictedLevels = [3, 8, 14, 20],
        ?SpellDefinition $requiredCantrip = null,
        int $version = 1,
    ): array {
        if (
            !preg_match('/^[a-z0-9][a-z0-9-]{0,79}$/D', $key)
            || $version < 1
        ) {
            throw new \InvalidArgumentException(
                'Identifiant ou version de modèle invalide.'
            );
        }

        if (
            $schools === []
            || !array_is_list($schools)
            || count($schools) !== count(array_unique($schools))
        ) {
            throw new \InvalidArgumentException(
                'Sélectionnez au moins une école distincte.'
            );
        }

        foreach ($schools as $school) {
            if (
                !is_string($school)
                || SpellSchool::tryFrom($school) === null
            ) {
                throw new \InvalidArgumentException(
                    'École de magie inconnue.'
                );
            }
        }

        if (
            !array_is_list($unrestrictedLevels)
            || count($unrestrictedLevels)
                !== count(array_unique($unrestrictedLevels))
        ) {
            throw new \InvalidArgumentException(
                'Niveaux d’exception invalides.'
            );
        }

        // Réutilisation des règles officielles existantes.
        $template = Dnd2014AdvancedSpellPolicies::thirdCaster(
            $spellList,
            $requiredCantrip !== null,
            $requiredCantrip,
        );

        $policy = $template['policy'];

        $known = array_column($policy->levels, 'known');
        $previous = 0;
        $gains = [];

        foreach ($known as $total) {
            $gains[] = $total - $previous;
            $previous = $total;
        }

        $unrestricted = array_fill(0, 20, 0);

        foreach ($unrestrictedLevels as $level) {
            if (
                !is_int($level)
                || $level < 3
                || $level > 20
                || $gains[$level - 1] < 1
            ) {
                throw new \InvalidArgumentException(
                    'Une exception doit correspondre à un niveau '
                    .'accordant au moins un nouveau sort.'
                );
            }

            $unrestricted[$level - 1] = 1;
        }

        $restricted = [];

        foreach ($gains as $index => $gain) {
            $restrictedGain = $gain - $unrestricted[$index];

            if ($restrictedGain < 0) {
                throw new \InvalidArgumentException(
                    'Le nombre d’exceptions dépasse les sorts disponibles.'
                );
            }

            $restricted[] = $restrictedGain;
        }

        foreach ($template['rules'] as &$rule) {
            if (!in_array(
                $rule['key'],
                ['restricted', 'unrestricted'],
                true
            )) {
                continue;
            }

            $values = $rule['parameters']->values;

            if ($rule['key'] === 'restricted') {
                $values['schools'] = $schools;
                $values['replacementSchools'] = $schools;
                $values['opportunities'] = $restricted;
            } else {
                $values['opportunities'] = $unrestricted;
            }

            $rule['parameters'] = new SpellChoiceParameters(
                $values
            );
        }

        unset($rule);

        $template['policy'] = new SpellcastingPolicy(
            $key,
            $version,
            $policy->ability,
            $policy->learningStyle,
            $policy->levels,
            'Modèle personnalisable de tiers-lanceur.',
            [
                'Progression de tiers-lanceur 2014.',
                'Écoles et niveaux d’exception configurables.',
                'Les règles de remplacement restent celles '
                .'du modèle de base.',
            ],
        );

        return $template;
    }
}
