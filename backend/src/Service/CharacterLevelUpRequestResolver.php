<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\LevelAdvancementSelection;
use App\Entity\CharacterClass;
use App\Entity\CharacterSubclass;
use App\Entity\Feat;
use App\Enum\Ability;
use App\Enum\HitPointGainMethod;
use Doctrine\ORM\EntityManagerInterface;

final readonly class CharacterLevelUpRequestResolver
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{
     *     characterClass: CharacterClass,
     *     subclass: CharacterSubclass|null,
     *     advancement: LevelAdvancementSelection|null,
     *     hitPointGainMethod: HitPointGainMethod,
     *     hitPointGain: int|null
     * }
     */
    public function resolve(array $payload): array
    {
        $classId = $this->integer(
            $payload['classId'] ?? null,
        );

        if ($classId === null || $classId <= 0) {
            throw new \InvalidArgumentException(
                'La classe est obligatoire.',
            );
        }

        $characterClass = $this->entityManager
            ->getRepository(CharacterClass::class)
            ->find($classId);

        if (!$characterClass instanceof CharacterClass) {
            throw new \InvalidArgumentException(
                'La classe sélectionnée est introuvable.',
            );
        }

        $subclass = $this->resolveSubclass(
            $payload['subclassId'] ?? null,
        );

        $advancement = $this->resolveAdvancement(
            $payload['advancement'] ?? null,
        );

        $hitPoints = $this->resolveHitPoints(
            $payload['hitPoints'] ?? null,
            $characterClass,
        );

        return [
            'characterClass' => $characterClass,
            'subclass' => $subclass,
            'advancement' => $advancement,
            'hitPointGainMethod' => $hitPoints['method'],
            'hitPointGain' => $hitPoints['gain'],
        ];
    }

    /**
     * @return array{
     *     method: HitPointGainMethod,
     *     gain: int|null
     * }
     */
    private function resolveHitPoints(
        mixed $payload,
        CharacterClass $characterClass,
    ): array {
        if ($payload === null) {
            return [
                'method' => HitPointGainMethod::Average,
                'gain' => null,
            ];
        }

        if (!is_array($payload)) {
            throw new \InvalidArgumentException(
                'Le choix des points de vie est invalide.',
            );
        }

        $method = HitPointGainMethod::tryFrom(
            (string) ($payload['method'] ?? ''),
        );

        if (
            $method === null
            || $method === HitPointGainMethod::FirstLevel
        ) {
            throw new \InvalidArgumentException(
                'La méthode de gain de points de vie est invalide.',
            );
        }

        if ($method === HitPointGainMethod::Average) {
            return [
                'method' => $method,
                'gain' => null,
            ];
        }

        $gain = $this->integer(
            $payload['gain'] ?? null,
        );

        if (
            $gain === null
            || $gain < 1
            || $gain > $characterClass->getHitDie()
        ) {
            throw new \InvalidArgumentException(sprintf(
                'Le gain brut de PV doit être compris entre 1 et %d.',
                $characterClass->getHitDie(),
            ));
        }

        return [
            'method' => $method,
            'gain' => $gain,
        ];
    }

    private function resolveSubclass(
        mixed $subclassId,
    ): ?CharacterSubclass {
        if ($subclassId === null || $subclassId === '') {
            return null;
        }

        $subclassId = $this->integer($subclassId);

        if ($subclassId === null || $subclassId <= 0) {
            throw new \InvalidArgumentException(
                'La sous-classe sélectionnée est invalide.',
            );
        }

        $subclass = $this->entityManager
            ->getRepository(CharacterSubclass::class)
            ->find($subclassId);

        if (!$subclass instanceof CharacterSubclass) {
            throw new \InvalidArgumentException(
                'La sous-classe sélectionnée est introuvable.',
            );
        }

        return $subclass;
    }

    private function resolveAdvancement(
        mixed $payload,
    ): ?LevelAdvancementSelection {
        if ($payload === null) {
            return null;
        }

        if (!is_array($payload)) {
            throw new \InvalidArgumentException(
                'Le choix de progression est invalide.',
            );
        }

        return match ($payload['type'] ?? null) {
            'ability' =>
                $this->resolveAbilityAdvancement($payload),
            'feat' =>
                $this->resolveFeatAdvancement($payload),
            default =>
                throw new \InvalidArgumentException(
                    'Le choix doit être une augmentation de caractéristiques ou un don.',
                ),
        };
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolveAbilityAdvancement(
        array $payload,
    ): LevelAdvancementSelection {
        $increases = $payload['increases'] ?? null;

        if (!is_array($increases)) {
            throw new \InvalidArgumentException(
                'Les augmentations de caractéristiques sont invalides.',
            );
        }

        if (count($increases) === 1) {
            $increase = $increases[0] ?? null;

            if (
                !is_array($increase)
                || ($increase['value'] ?? null) !== 2
            ) {
                throw new \InvalidArgumentException(
                    'Une caractéristique unique doit recevoir un bonus de +2.',
                );
            }

            $ability = Ability::tryFrom(
                (string) ($increase['ability'] ?? ''),
            );

            if ($ability === null) {
                throw new \InvalidArgumentException(
                    'La caractéristique sélectionnée est invalide.',
                );
            }

            return LevelAdvancementSelection
                ::increaseOneAbility($ability);
        }

        if (count($increases) === 2) {
            $firstIncrease = $increases[0] ?? null;
            $secondIncrease = $increases[1] ?? null;

            if (
                !is_array($firstIncrease)
                || !is_array($secondIncrease)
                || ($firstIncrease['value'] ?? null) !== 1
                || ($secondIncrease['value'] ?? null) !== 1
            ) {
                throw new \InvalidArgumentException(
                    'Deux caractéristiques doivent chacune recevoir un bonus de +1.',
                );
            }

            $firstAbility = Ability::tryFrom(
                (string) ($firstIncrease['ability'] ?? ''),
            );

            $secondAbility = Ability::tryFrom(
                (string) ($secondIncrease['ability'] ?? ''),
            );

            if (
                $firstAbility === null
                || $secondAbility === null
            ) {
                throw new \InvalidArgumentException(
                    'Une caractéristique sélectionnée est invalide.',
                );
            }

            try {
                return LevelAdvancementSelection
                    ::increaseTwoAbilities(
                        $firstAbility,
                        $secondAbility,
                    );
            } catch (\LogicException $exception) {
                throw new \InvalidArgumentException(
                    $exception->getMessage(),
                    previous: $exception,
                );
            }
        }

        throw new \InvalidArgumentException(
            'Choisis une caractéristique à +2 ou deux caractéristiques à +1.',
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolveFeatAdvancement(
        array $payload,
    ): LevelAdvancementSelection {
        $featId = $this->integer(
            $payload['featId'] ?? null,
        );

        if ($featId === null || $featId <= 0) {
            throw new \InvalidArgumentException(
                'Le don est obligatoire.',
            );
        }

        $feat = $this->entityManager
            ->getRepository(Feat::class)
            ->find($featId);

        if (!$feat instanceof Feat) {
            throw new \InvalidArgumentException(
                'Le don sélectionné est introuvable.',
            );
        }

        $chosenAbility = null;

        if (($payload['ability'] ?? null) !== null) {
            $chosenAbility = Ability::tryFrom(
                (string) $payload['ability'],
            );

            if ($chosenAbility === null) {
                throw new \InvalidArgumentException(
                    'La caractéristique du don est invalide.',
                );
            }

            if (!$feat->allowsAbility($chosenAbility)) {
                throw new \InvalidArgumentException(
                    'Cette caractéristique n’est pas autorisée pour ce don.',
                );
            }
        }

        try {
            return LevelAdvancementSelection::feat(
                $feat,
                $chosenAbility,
            );
        } catch (\LogicException $exception) {
            throw new \InvalidArgumentException(
                $exception->getMessage(),
                previous: $exception,
            );
        }
    }

    private function integer(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (
            !is_string($value)
            && !is_float($value)
        ) {
            return null;
        }

        $result = filter_var(
            $value,
            FILTER_VALIDATE_INT,
        );

        return $result !== false
            ? $result
            : null;
    }
}
