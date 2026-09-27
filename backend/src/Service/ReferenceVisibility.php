<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\{CharacterClass, CharacterSubclass, CharacterRace, Feat, ProgressionDefinition, RaceAbilityModifier, User};
use App\Enum\ReferenceOrigin;
use Doctrine\ORM\EntityManagerInterface;

/** Selection policy only: callers supply the campaign owner, never the authenticated visitor. */
final class ReferenceVisibility
{
    /** Unpaginated choice lists. Dependencies are checked before anything is serialized. */
    public static function choices(EntityManagerInterface $em, string $type, ?User $owner, array $criteria = []): array
    {
        if (!in_array($type, [CharacterClass::class, CharacterSubclass::class, CharacterRace::class, Feat::class, ProgressionDefinition::class], true)) {
            throw new \LogicException('Unsupported reference catalogue.');
        }
        $query = $em->createQueryBuilder()->select('r')->from($type, 'r')
            ->where($owner === null ? 'r.origin = :official' : '(r.origin = :official OR (r.origin = :custom AND r.owner = :owner))')
            ->setParameter('official', ReferenceOrigin::Official->value)
            ->orderBy('r.name', 'ASC')->addOrderBy('r.id', 'ASC');
        if ($owner !== null) $query->setParameter('custom', ReferenceOrigin::Custom->value)->setParameter('owner', $owner);
        foreach ($criteria as $field => $value) {
            if ($field !== 'characterClass' || $type !== CharacterSubclass::class) throw new \LogicException('Unsupported choice criterion.');
            $query->andWhere('r.characterClass = :class')->setParameter('class', $value);
        }
        return array_values(array_filter($query->getQuery()->getResult(), static fn ($reference) => self::allows($reference, $owner)));
    }

    public static function allows(CharacterClass|CharacterSubclass|CharacterRace|Feat|ProgressionDefinition|RaceAbilityModifier $reference, ?User $owner, array $visited = []): bool
    {
        if ($reference instanceof RaceAbilityModifier) return self::allows($reference->getRace(), $owner, $visited);
        $key = spl_object_id($reference);
        if (isset($visited[$key])) return false;
        $visited[$key] = true;
        if ($reference->getOrigin() === ReferenceOrigin::Custom
            && ($owner === null || ($reference->getOwner() !== $owner && ($owner->getId() === null || $reference->getOwner()?->getId() !== $owner->getId())))) return false;
        $parent = match (true) {
            $reference instanceof CharacterSubclass => $reference->getCharacterClass(),
            $reference instanceof CharacterRace => $reference->getParentRace(),
            default => null,
        };
        // OFFICIAL cannot inherit CUSTOM, even if that CUSTOM belongs to the viewer's campaign.
        return $parent === null || self::allows($parent, $reference->getOrigin() === ReferenceOrigin::Official ? null : $reference->getOwner(), $visited);
    }

    public static function requireVisible(CharacterClass|CharacterSubclass|CharacterRace|Feat|ProgressionDefinition|RaceAbilityModifier $reference, User $owner): void
    {
        if (!self::allows($reference, $owner)) throw new \InvalidArgumentException('La référence sélectionnée est indisponible.');
    }
}
