<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\{CharacterClass, CharacterSubclass, CharacterRace, Feat, ProgressionDefinition, RaceAbilityModifier, User};
use App\Entity\{CharacterFeatureDefinition, TrackableResourceDefinition, CharacterActionDefinition, CharacterFeatureRule, TrackableResourceRule, CharacterActionClassRule};
use App\Enum\ReferenceOrigin;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

/** Callers supply the campaign owner, never the authenticated visitor. */
final class ReferenceVisibility
{
    /** Limit root references before hydration; dependencies still require allows(). */
    public static function scope(QueryBuilder $query, string $alias, ?User $owner): QueryBuilder
    {
        $query->setParameter('referenceOfficial', ReferenceOrigin::Official->value);
        if ($owner === null || $owner->getId() === null) {
            return $query->andWhere("$alias.origin = :referenceOfficial");
        }

        return $query->andWhere("($alias.origin = :referenceOfficial OR ($alias.origin = :referenceCustom AND $alias.owner = :referenceOwner))")
            ->setParameter('referenceCustom', ReferenceOrigin::Custom->value)
            ->setParameter('referenceOwner', $owner);
    }

    /** Unpaginated choice lists. Dependencies are checked before anything is serialized. */
    public static function choices(EntityManagerInterface $em, string $type, ?User $owner, array $criteria = []): array
    {
        if (!in_array($type, [CharacterClass::class, CharacterSubclass::class, CharacterRace::class, Feat::class, ProgressionDefinition::class], true)) {
            throw new \LogicException('Unsupported reference catalogue.');
        }
        $query = self::scope($em->createQueryBuilder()->select('r')->from($type, 'r'), 'r', $owner)
            ->orderBy('r.name', 'ASC')->addOrderBy('r.id', 'ASC');
        foreach ($criteria as $field => $value) {
            if ($field !== 'characterClass' || $type !== CharacterSubclass::class) throw new \LogicException('Unsupported choice criterion.');
            $query->andWhere('r.characterClass = :class')->setParameter('class', $value);
        }
        return array_values(array_filter($query->getQuery()->getResult(), static fn ($reference) => self::allows($reference, $owner)));
    }

    public static function allows(CharacterClass|CharacterSubclass|CharacterRace|Feat|ProgressionDefinition|RaceAbilityModifier|CharacterFeatureDefinition|TrackableResourceDefinition|CharacterActionDefinition|CharacterFeatureRule|TrackableResourceRule|CharacterActionClassRule $reference, ?User $owner, array $visited = []): bool
    {
        if ($reference instanceof RaceAbilityModifier) return self::allows($reference->getRace(), $owner, $visited);
        $key = spl_object_id($reference);
        if (isset($visited[$key])) return false;
        $visited[$key] = true;
        if ($reference->getOrigin() === ReferenceOrigin::Custom
            && ($owner === null || ($reference->getOwner() !== $owner && ($owner->getId() === null || $reference->getOwner()?->getId() !== $owner->getId())))) return false;
        $dependencies = match (true) {
            $reference instanceof CharacterSubclass => [$reference->getCharacterClass()],
            $reference instanceof CharacterRace => [$reference->getParentRace()],
            $reference instanceof CharacterFeatureDefinition => [$reference->getResourceDefinition()],
            $reference instanceof CharacterFeatureRule => [
                $reference->getFeatureDefinition(), $reference->getCharacterClass(),
                $reference->getCharacterSubclass(), $reference->getCharacterRace(),
                $reference->getFeat(), $reference->getProgressionDefinition(),
            ],
            $reference instanceof TrackableResourceRule => [
                $reference->getResourceDefinition(), $reference->getCharacterClass(),
                $reference->getCharacterSubclass(), $reference->getCharacterRace(), $reference->getFeat(),
            ],
            $reference instanceof CharacterActionClassRule => [$reference->getActionDefinition(), $reference->getCharacterClass()],
            default => [],
        };
        // OFFICIAL cannot depend on CUSTOM, even if it belongs to the campaign owner.
        $dependencyOwner = $reference->getOrigin() === ReferenceOrigin::Official ? null : $reference->getOwner();
        foreach ($dependencies as $dependency) {
            if ($dependency !== null && !self::allows($dependency, $dependencyOwner, $visited)) return false;
        }
        return true;
    }

    public static function requireVisible(CharacterClass|CharacterSubclass|CharacterRace|Feat|ProgressionDefinition|RaceAbilityModifier $reference, User $owner): void
    {
        if (!self::allows($reference, $owner)) throw new \InvalidArgumentException('La référence sélectionnée est indisponible.');
    }
}
