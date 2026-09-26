<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\ORM\QueryBuilder;

/** Neighbours in the same database order as the reference lists: name, then ID. */
final class AdminReferenceNavigation
{
    public static function resolve(QueryBuilder $filtered, string $alias, int $id, string $name): array
    {
        $matches = (int) (clone $filtered)->select("COUNT(DISTINCT $alias.id)")
            ->andWhere("$alias.id = :currentId")->setParameter('currentId', $id)
            ->getQuery()->getSingleScalarResult() > 0;
        $neighbours = ['previous' => null, 'next' => null, 'matches' => $matches];
        foreach (['previous' => ['<', 'DESC'], 'next' => ['>', 'ASC']] as $key => [$operator, $direction]) {
            $row = (clone $filtered)->select("DISTINCT $alias.id, $alias.name")
                ->andWhere("($alias.name $operator :currentName OR ($alias.name = :currentName AND $alias.id $operator :currentId))")
                ->setParameter('currentName', $name)->setParameter('currentId', $id)
                ->orderBy("$alias.name", $direction)->addOrderBy("$alias.id", $direction)
                ->setMaxResults(1)->getQuery()->getOneOrNullResult();
            $neighbours[$key] = $row === null ? null : (int) $row['id'];
        }
        // A corrected item may have left the filter (or moved after a rename).
        // Only then, wrap to the first remaining result when no later one exists.
        if (!$matches && $neighbours['next'] === null) {
            $row = (clone $filtered)->select("DISTINCT $alias.id, $alias.name")
                ->orderBy("$alias.name", 'ASC')->addOrderBy("$alias.id", 'ASC')
                ->setMaxResults(1)->getQuery()->getOneOrNullResult();
            $neighbours['next'] = $row === null ? null : (int) $row['id'];
        }

        return $neighbours;
    }
}
