<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Character;

/** Session-only wild shape state. Character's normal hit points remain untouched. */
final readonly class WildShapeSessionService
{
    public const KEY = 'wildShape';

    public function __construct(private WildShapeRules2014 $rules) {}

    /** @return array{level:int,moon:bool} */
    public function druidProfile(Character $character): array
    {
        $level = 0;
        $moon = false;
        foreach ($character->getClassLevels() as $classLevel) {
            $class = $classLevel->getCharacterClass();
            $className = mb_strtolower($class->getName());
            if (!in_array($className, ['druide', 'druid'], true)) continue;
            ++$level;
            $subclass = $classLevel->getSubclass();
            if ($subclass !== null && preg_match('/(?:cercle de la lune|circle of the moon)/iu', $subclass->getName())) $moon = true;
        }
        return ['level' => $level, 'moon' => $moon];
    }

    /** @param array<string,mixed> $state @param array<string,mixed> $beast @return array<string,mixed> */
    public function transform(array $state, array $beast, int $level, bool $moon, bool $seen, string $equipment, ?int $rolledHitPoints = null): array
    {
        $wild = $state[self::KEY] ?? [];
        if (!is_array($wild)) $wild = [];
        // Une nouvelle utilisation peut remplacer directement une forme active.
        // Les PV humanoïdes restent inchangés ; seule la forme animale est remplacée.
        $uses = (int) ($wild['usesRemaining'] ?? 2);
        $active = $this->rules->start($beast, $level, $moon, $seen, $uses, $equipment, $rolledHitPoints);
        $state[self::KEY] = ['usesRemaining' => $uses - 1, 'active' => $active];
        return $state;
    }

    /** @param array<string,mixed> $state @return array<string,mixed> */
    public function revert(array $state): array
    {
        if (!is_array($state[self::KEY]['active'] ?? null)) throw new \DomainException('Aucune forme sauvage active.');
        $state[self::KEY]['active'] = null;
        return $state;
    }

    /** @param array<string,mixed> $state @return array<string,mixed> */
    public function damage(array $state, int $amount): array
    {
        $active = $state[self::KEY]['active'] ?? null;
        if (!is_array($active)) throw new \DomainException('Aucune forme sauvage active.');
        $result = $this->rules->damage($active, $amount);
        $state[self::KEY]['active'] = $result['form'];
        if ($result['overflow'] > 0) {
            $state['hitPoints']['current'] = max(0, (int) ($state['hitPoints']['current'] ?? 0) - $result['overflow']);
        }
        return $state;
    }

    /** @param array<string,mixed> $state @return array<string,mixed> */
    public function heal(array $state, int $amount): array
    {
        $active = $state[self::KEY]['active'] ?? null;
        if (!is_array($active)) throw new \DomainException('Aucune forme sauvage active.');
        $state[self::KEY]['active'] = $this->rules->heal($active, $amount);
        return $state;
    }

    /** @param array<string,mixed> $state @return array<string,mixed> */
    public function advanceTime(array $state, int $minutes): array
    {
        $active = $state[self::KEY]['active'] ?? null;
        if (!is_array($active)) throw new \DomainException('Aucune forme sauvage active.');
        $state[self::KEY]['active'] = $this->rules->advanceTime($active, $minutes);
        return $state;
    }

    /** @param array<string,mixed> $state @return array<string,mixed> */
    public function afterRest(array $state): array
    {
        $wild = $state[self::KEY] ?? [];
        if (!is_array($wild)) $wild = [];
        $wild['usesRemaining'] = 2;
        $state[self::KEY] = $wild;
        return $state;
    }
}
