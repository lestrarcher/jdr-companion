<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CharacterSessionState;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/** Validates the portal snapshot as an authorized partial merge, before any mutation. */
final readonly class PlayerCharacterStateUpdater
{
    public function __construct(private CharacterSessionStateSynchronizer $synchronizer)
    {
    }

    public function merge(CharacterSessionState $session, array $patch): array
    {
        $this->keys($patch, ['hitPoints', 'hitDice', 'resources', 'progressions']);
        $character = $session->getCharacter();
        $state = $session->getState();
        $before = $this->synchronizer->snapshot($character, $this->synchronizer->extractProgressionValues($state));

        if (array_key_exists('progressions', $patch)) {
            $assigned = [];
            foreach ($character->getProgressions() as $assignment) {
                $definition = $assignment->getProgressionDefinition();
                $assigned[$definition->getSlug()] = $definition;
            }
            foreach ($this->entries($patch['progressions']) as $entry) {
                $this->keys($entry, ['id', 'currentValue']);
                $definition = $assigned[$entry['id']] ?? null;
                if ($definition === null) $this->invalid('Progression inconnue.');
                $this->integer($entry['currentValue'] ?? null, $definition->getMinimumValue(), $definition->getMaximumValue());
                $state['progressions'] = $this->mergeEntry($state['progressions'] ?? [], $entry);
            }
        }

        $after = $this->synchronizer->snapshot($character, $this->synchronizer->extractProgressionValues($state));
        if (array_key_exists('hitPoints', $patch)) {
            $hp = $patch['hitPoints'];
            if (!is_array($hp)) $this->invalid('Points de vie invalides.');
            $this->keys($hp, ['current', 'temporary']);
            foreach ($hp as $field => $value) {
                if ($field === 'current' && $after['hitPoints'] === null) $this->invalid('Maximum de PV incomplet.');
                // Temporary HP has no game-defined maximum; it is a nonnegative integer.
                $this->integer($value, 0, $field === 'current' ? $after['hitPoints'] : null);
                $state['hitPoints'][$field] = $value;
            }
        }

        if (array_key_exists('hitDice', $patch)) {
            foreach ($this->entries($patch['hitDice']) as $entry) {
                $this->keys($entry, ['id', 'current']);
                $maximum = $after['hitDice'][$entry['id']] ?? null;
                if ($maximum === null) $this->invalid('Pool de dés de vie inconnu.');
                $this->integer($entry['current'] ?? null, 0, $maximum);
                $old = $this->find($state['hitDice'] ?? [], $entry['id']);
                if ($entry['current'] > ($old['current'] ?? 0)) $this->forbidden('La récupération des dés de vie nécessite un repos.');
                $state['hitDice'] = $this->mergeEntry($state['hitDice'] ?? [], $entry);
            }
        }

        $configuration = [];
        foreach ($character->getDefinition()['resources'] ?? [] as $resource) {
            $configuration[$resource['id']] = $resource;
        }
        if (array_key_exists('resources', $patch)) {
            foreach ($this->entries($patch['resources']) as $entry) {
                $this->keys($entry, ['id', 'currentValue', 'notes', 'storedValues']);
                $id = $entry['id'];
                $old = $this->find($state['resources'] ?? [], $id);
                $maximum = $after['resources'][$id] ?? null;
                if ($maximum === null) {
                    // A full portal snapshot can echo a resource while crossing below
                    // its threshold. Accept only unchanged fields of that old active pool.
                    if ($old !== null && isset($before['resources'][$id])) {
                        foreach ($entry as $key => $value) {
                            if (!array_key_exists($key, $old) || $old[$key] !== $value) $this->forbidden('Ressource devenue inactive.');
                        }
                        continue;
                    }
                    $this->invalid('Ressource inconnue ou inactive.');
                }
                $config = $configuration[$id] ?? [];
                $this->integer($entry['currentValue'] ?? null, 0, $maximum);
                if (array_key_exists('notes', $entry)) {
                    if (!is_string($entry['notes'])) $this->invalid('Notes invalides.');
                    if (($config['notesEditable'] ?? false) !== true && $entry['notes'] !== ($old['notes'] ?? null)) {
                        $this->forbidden('Notes non modifiables.');
                    }
                }
                $storedConfig = $config['storedValuesConfig'] ?? null;
                if ($storedConfig !== null) {
                    if (array_key_exists('storedValues', $entry) && !is_array($entry['storedValues'])) {
                        $this->invalid('Valeurs stockées invalides.');
                    }
                    $values = $entry['storedValues'] ?? ($old['storedValues'] ?? null);
                    if ($values !== null) {
                        if (!is_array($values) || !array_is_list($values)) $this->invalid('Valeurs stockées invalides.');
                        foreach ($values as $value) $this->integer($value, $storedConfig['minimumValue'], $storedConfig['maximumValue']);
                        if (isset($old['storedValues'])) {
                            // Consumption can only remove existing results, never reroll them.
                            $remaining = $old['storedValues'];
                            foreach ($values as $value) {
                                $index = array_search($value, $remaining, true);
                                if ($index === false) $this->forbidden('Relance des valeurs stockées interdite.');
                                unset($remaining[$index]);
                            }
                        } elseif (count($values) !== $storedConfig['requiredCount']) {
                            $this->invalid('Nombre de valeurs stockées invalide.');
                        }
                        if ($entry['currentValue'] !== count($values)) $this->invalid('Compteur incohérent avec les valeurs stockées.');
                    } elseif ($entry['currentValue'] !== ($old['currentValue'] ?? $maximum)) {
                        $this->forbidden('Utiliser les valeurs stockées pour cette ressource.');
                    }
                } else {
                    if (array_key_exists('storedValues', $entry)) $this->invalid('Cette ressource ne stocke pas de valeurs.');
                    $manual = ($config['allowManualIncrease'] ?? false) === true || ($config['resetPeriod'] ?? null) === 'manual';
                    if (!$manual && $entry['currentValue'] > ($old['currentValue'] ?? $maximum)) {
                        $this->forbidden('La récupération de cette ressource nécessite une action autorisée.');
                    }
                }
                $state['resources'] = $this->mergeEntry($state['resources'] ?? [], $entry);
            }
        }

        // Keep first activation initialization, without clamping or refilling existing
        // omitted pools. The synchronizer remains the source of initialization policy.
        $initialized = $this->synchronizer->synchronizeResources($character, $state);
        foreach ($initialized['resources'] as $entry) {
            if ($this->find($state['resources'] ?? [], $entry['id']) === null) $state['resources'][] = $entry;
        }
        return $state;
    }

    private function keys(array $value, array $allowed): void
    {
        foreach (array_keys($value) as $key) {
            if (!in_array($key, $allowed, true)) $this->invalid('Champ non autorisé : ' . $key);
        }
    }

    private function entries(mixed $entries): array
    {
        if (!is_array($entries) || !array_is_list($entries)) $this->invalid('Une liste est requise.');
        $seen = [];
        foreach ($entries as $entry) {
            if (!is_array($entry) || !is_string($entry['id'] ?? null) || $entry['id'] === '') $this->invalid('Identifiant invalide.');
            if (isset($seen[$entry['id']])) $this->invalid('Identifiant dupliqué.');
            $seen[$entry['id']] = true;
        }
        return $entries;
    }

    private function integer(mixed $value, int $minimum, ?int $maximum): void
    {
        if (!is_int($value) || $value < $minimum || ($maximum !== null && $value > $maximum)) $this->invalid('Valeur entière hors limites.');
    }

    private function find(array $entries, string $id): ?array
    {
        foreach ($entries as $entry) {
            if (($entry['id'] ?? null) === $id) return $entry;
        }
        return null;
    }

    private function mergeEntry(array $entries, array $update): array
    {
        foreach ($entries as $index => $entry) {
            if (($entry['id'] ?? null) === $update['id']) {
                $entries[$index] = array_replace($entry, $update);
                return $entries;
            }
        }
        $entries[] = $update;
        return $entries;
    }

    private function invalid(string $message): never
    {
        throw new UnprocessableEntityHttpException($message);
    }

    private function forbidden(string $message): never
    {
        throw new AccessDeniedHttpException($message);
    }
}
