<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Character;
use App\Enum\SpellcastingProgressionType;

final readonly class CharacterSpellSlotCalculator
{
    /**
     * @var array<int, array<int, int>>
     */
    private const SLOT_TABLE = [
        1 => [1 => 2],
        2 => [1 => 3],
        3 => [1 => 4, 2 => 2],
        4 => [1 => 4, 2 => 3],
        5 => [1 => 4, 2 => 3, 3 => 2],
        6 => [1 => 4, 2 => 3, 3 => 3],
        7 => [1 => 4, 2 => 3, 3 => 3, 4 => 1],
        8 => [1 => 4, 2 => 3, 3 => 3, 4 => 2],
        9 => [1 => 4, 2 => 3, 3 => 3, 4 => 3, 5 => 1],
        10 => [1 => 4, 2 => 3, 3 => 3, 4 => 3, 5 => 2],
        11 => [1 => 4, 2 => 3, 3 => 3, 4 => 3, 5 => 2, 6 => 1],
        12 => [1 => 4, 2 => 3, 3 => 3, 4 => 3, 5 => 2, 6 => 1],
        13 => [1 => 4, 2 => 3, 3 => 3, 4 => 3, 5 => 2, 6 => 1, 7 => 1],
        14 => [1 => 4, 2 => 3, 3 => 3, 4 => 3, 5 => 2, 6 => 1, 7 => 1],
        15 => [1 => 4, 2 => 3, 3 => 3, 4 => 3, 5 => 2, 6 => 1, 7 => 1, 8 => 1],
        16 => [1 => 4, 2 => 3, 3 => 3, 4 => 3, 5 => 2, 6 => 1, 7 => 1, 8 => 1],
        17 => [1 => 4, 2 => 3, 3 => 3, 4 => 3, 5 => 2, 6 => 1, 7 => 1, 8 => 1, 9 => 1],
        18 => [1 => 4, 2 => 3, 3 => 3, 4 => 3, 5 => 3, 6 => 1, 7 => 1, 8 => 1, 9 => 1],
        19 => [1 => 4, 2 => 3, 3 => 3, 4 => 3, 5 => 3, 6 => 2, 7 => 1, 8 => 1, 9 => 1],
        20 => [1 => 4, 2 => 3, 3 => 3, 4 => 3, 5 => 3, 6 => 2, 7 => 2, 8 => 1, 9 => 1],
    ];

    /**
     * @return array<int, int>
     *
     * Exemple :
     * [
     *     1 => 4,
     *     2 => 3,
     *     3 => 2,
     * ]
     */
    public function calculate(Character $character): array
    {
        $spellcastingClasses = $this->spellcastingClasses($character);

        if ($spellcastingClasses === []) {
            return [];
        }

        /*
         * Si le personnage ne possède qu'une seule classe contribuant
         * aux emplacements classiques, on reproduit la progression
         * propre de cette classe.
         *
         * C'est important pour Rôdeur/Paladin et les tiers-lanceurs :
         * les arrondis de la table de classe ne sont pas les mêmes
         * que ceux des règles de multiclassage.
         */
        if (count($spellcastingClasses) === 1) {
            $class = reset($spellcastingClasses);

            $casterLevel = $this->singleClassCasterLevel(
                $class['level'],
                $class['progression'],
            );

            return self::SLOT_TABLE[$casterLevel] ?? [];
        }

        /*
         * Multiclassage :
         * - full : niveau entier ;
         * - half : moitié arrondie vers le bas ;
         * - artificer : moitié arrondie vers le haut ;
         * - third : tiers arrondi vers le bas ;
         * - pact : exclu, ses emplacements restent indépendants.
         */
        $casterLevel = 0;

        foreach ($spellcastingClasses as $class) {
            $casterLevel += $this->multiclassContribution(
                $class['level'],
                $class['progression'],
            );
        }

        $casterLevel = min(20, $casterLevel);

        return self::SLOT_TABLE[$casterLevel] ?? [];
    }

    /**
     * @return array<int, array{
     *     level: int,
     *     progression: SpellcastingProgressionType
     * }>
     */
    private function spellcastingClasses(Character $character): array
    {
        $classes = [];

        foreach ($character->getClassLevels() as $level) {
            $characterClass = $level->getCharacterClass();
            $classId = $characterClass->getId();

            if ($classId === null) {
                continue;
            }

            if (!isset($classes[$classId])) {
                $subclass = $character->getSubclassFor($characterClass);

                $progression =
                    $subclass?->getSpellcastingProgression()
                    ?? $characterClass->getSpellcastingProgression();

                /*
                 * Pacte n'entre jamais dans la table commune.
                 */
                if (
                    $progression === SpellcastingProgressionType::None
                    || $progression === SpellcastingProgressionType::Pact
                ) {
                    continue;
                }

                $classes[$classId] = [
                    'level' => 0,
                    'progression' => $progression,
                ];
            }

            ++$classes[$classId]['level'];
        }

        return $classes;
    }

    private function singleClassCasterLevel(
        int $classLevel,
        SpellcastingProgressionType $progression,
    ): int {
        return match ($progression) {
            SpellcastingProgressionType::Full =>
                $classLevel,

            SpellcastingProgressionType::Half =>
                $classLevel >= 2
                    ? (int) ceil($classLevel / 2)
                    : 0,

            SpellcastingProgressionType::Artificer =>
                (int) ceil($classLevel / 2),

            SpellcastingProgressionType::Third =>
                $classLevel >= 3
                    ? (int) ceil($classLevel / 3)
                    : 0,

            SpellcastingProgressionType::None,
            SpellcastingProgressionType::Pact => 0,
        };
    }

    private function multiclassContribution(
        int $classLevel,
        SpellcastingProgressionType $progression,
    ): int {
        return match ($progression) {
            SpellcastingProgressionType::Full =>
                $classLevel,

            SpellcastingProgressionType::Half =>
                intdiv($classLevel, 2),

            SpellcastingProgressionType::Artificer =>
                (int) ceil($classLevel / 2),

            SpellcastingProgressionType::Third =>
                intdiv($classLevel, 3),

            SpellcastingProgressionType::None,
            SpellcastingProgressionType::Pact => 0,
        };
    }
}
