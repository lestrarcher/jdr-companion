<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Pure 2014 Wild Shape rules. No persistence or character-sheet mutations.
 * All durations are expressed in in-game minutes, not wall-clock time.
 */
final class WildShapeRules2014
{
    public function maxChallengeRating(int $druidLevel, bool $moonCircle): float
    {
        if ($druidLevel < 2) return 0.0;
        if ($moonCircle) return $druidLevel < 6 ? 1.0 : (float) intdiv($druidLevel, 3);
        return $druidLevel < 4 ? 0.25 : ($druidLevel < 8 ? 0.5 : 1.0);
    }

    public function challengeRating(string $rating): ?float
    {
        if (!preg_match('/^(0|[1-9][0-9]*|1\/[248])$/', $rating)) return null;
        if (!str_contains($rating, '/')) return (float) $rating;
        [$numerator, $denominator] = array_map('intval', explode('/', $rating));
        return $numerator / $denominator;
    }

    /** @param array<string,mixed> $beast */
    public function unavailableReason(array $beast, int $druidLevel, bool $moonCircle, bool $seen): ?string
    {
        if ($druidLevel < 2) return 'Forme sauvage disponible à partir du niveau 2.';
        if (!$seen) return 'Cette bête n’a pas encore été observée.';
        $block = $beast['statBlock'] ?? null;
        if (!is_array($block)) return 'Fiche de statistiques manquante.';
        $cr = $this->challengeRating((string) ($beast['challengeRating'] ?? ''));
        if ($cr === null) return 'Facteur de puissance invalide.';
        if ($cr > $this->maxChallengeRating($druidLevel, $moonCircle)) return 'Facteur de puissance trop élevé.';
        $speed = $block['speed'] ?? [];
        if (!is_array($speed)) return 'Vitesses invalides.';
        if ($druidLevel < 4 && (float) ($speed['swim'] ?? 0) > 0) return 'Nage accessible au niveau 4.';
        if ($druidLevel < 8 && (float) ($speed['fly'] ?? 0) > 0) return 'Vol accessible au niveau 8.';
        if (!is_int($block['hitPoints'] ?? null) || $block['hitPoints'] < 1) return 'Points de vie invalides.';
        return null;
    }

    public function durationMinutes(int $druidLevel): int
    {
        return max(0, intdiv($druidLevel, 2) * 60);
    }

    /**
     * @param array<string,mixed> $beast
     * @return array<string,mixed>
     */
    public function start(array $beast, int $druidLevel, bool $moonCircle, bool $seen, int $usesRemaining, string $equipment, ?int $rolledHitPoints = null): array
    {
        $reason = $this->unavailableReason($beast, $druidLevel, $moonCircle, $seen);
        if ($reason !== null) throw new \DomainException($reason);
        if ($usesRemaining < 1) throw new \DomainException('Aucune utilisation de Forme sauvage restante.');
        if (!in_array($equipment, ['merge', 'drop', 'wear'], true)) throw new \DomainException('Choix d’équipement invalide.');
        $block = $beast['statBlock'];
        $maximum = (int) $block['hitPoints'];
        if ($rolledHitPoints !== null) {
            $dice = (string) ($block['hitDice'] ?? '');
            if (!preg_match('/^(\d+)d(\d+)(?:([+-])(\d+))?$/', $dice, $matches)) {
                throw new \DomainException('Dés de vie non disponibles pour cette bête.');
            }
            $count = (int) $matches[1];
            $faces = (int) $matches[2];
            $modifier = isset($matches[4]) ? (int) $matches[4] * (($matches[3] ?? '+') === '-' ? -1 : 1) : 0;
            if ($count < 1 || $count > 100 || $faces < 2 || $faces > 1000) {
                throw new \DomainException('Dés de vie invalides.');
            }
            $minimumRoll = $count;
            $maximumRoll = $count * $faces;
            if ($rolledHitPoints < $minimumRoll || $rolledHitPoints > $maximumRoll) {
                throw new \DomainException(sprintf('Somme des dés invalide : attendue entre %d et %d (hors modificateur).', $minimumRoll, $maximumRoll));
            }
            $maximum = max(1, $rolledHitPoints + $modifier);
        }
        return [
            'beastId' => (int) $beast['id'],
            'beastSlug' => (string) $beast['slug'],
            'beastName' => (string) ($beast['name'] ?? $beast['slug']),
            'hitPoints' => ['current' => $maximum, 'maximum' => $maximum],
            'hitDice' => $block['hitDice'] ?? null,
            'armorClass' => $block['armorClass'],
            'abilities' => $block['abilities'],
            'speed' => $block['speed'],
            'actions' => $block['actions'] ?? [],
            'traits' => $block['traits'] ?? [],
            'proficiencies' => $block['proficiencies'] ?? [],
            'senses' => $block['senses'] ?? [],
            'equipment' => $equipment,
            'durationMinutes' => $this->durationMinutes($druidLevel),
            'elapsedMinutes' => 0,
            'moonCircle' => $moonCircle,
        ];
    }

    /**
     * Apply damage to beast HP and return overflow to apply to normal-form HP.
     * Damage cannot be negative; healing is a separate operation.
     * @param array<string,mixed> $form
     * @return array{form: ?array, overflow: int, reverted: bool}
     */
    public function damage(array $form, int $amount): array
    {
        if ($amount < 0) throw new \DomainException('Les dégâts ne peuvent pas être négatifs.');
        $current = (int) ($form['hitPoints']['current'] ?? 0);
        if ($current < 1) throw new \DomainException('La forme animale est déjà à zéro PV.');
        $remaining = $current - $amount;
        if ($remaining > 0) {
            $form['hitPoints']['current'] = $remaining;
            return ['form' => $form, 'overflow' => 0, 'reverted' => false];
        }
        return ['form' => null, 'overflow' => max(0, -$remaining), 'reverted' => true];
    }

    /** @param array<string,mixed> $form */
    public function heal(array $form, int $amount): array
    {
        if ($amount < 0) throw new \DomainException('Les soins ne peuvent pas être négatifs.');
        $form['hitPoints']['current'] = min(
            (int) $form['hitPoints']['maximum'],
            (int) $form['hitPoints']['current'] + $amount
        );
        return $form;
    }

    /** @param array<string,mixed> $form */
    public function advanceTime(array $form, int $minutes): ?array
    {
        if ($minutes < 0) throw new \DomainException('La durée ne peut pas être négative.');
        $form['elapsedMinutes'] = (int) ($form['elapsedMinutes'] ?? 0) + $minutes;
        return $form['elapsedMinutes'] >= (int) $form['durationMinutes'] ? null : $form;
    }
}
