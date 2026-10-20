<?php
declare(strict_types=1);
namespace App\Dto;

use App\Enum\Ability;

/** Immutable, explicit policy data. No inference from class identity or slot progression. */
final readonly class SpellcastingPolicy
{
    /**
     * Each of the 20 rows explicitly defines maxSpellLevel, cantrips, known,
     * bookAdditions, replacements and preparationBase. Null preparationBase
     * means no preparation quota; otherwise add the casting ability modifier.
     * Tables describe ordinary choices only; special choice budgets need their own policy.
     */
    public function __construct(
        public string $key,
        public int $version,
        public Ability $ability,
        public string $learningStyle,
        public array $levels,
        public string $provenance,
        public array $limitations = [],
    ) {
        if (!preg_match('/^[a-z0-9][a-z0-9-]{0,79}$/D', $key) || $version < 1
            || !in_array($learningStyle, ['known', 'prepared', 'spellbook'], true)
            || trim($provenance) === '' || count($levels) !== 20 || !array_is_list($levels)) {
            throw new \InvalidArgumentException('Politique de magie invalide.');
        }
        $previous = ['cantrips' => 0, 'known' => 0, 'maxSpellLevel' => 0];
        foreach ($levels as $row) {
            $keys = ['maxSpellLevel', 'cantrips', 'known', 'bookAdditions', 'replacements', 'preparationBase'];
            if (!is_array($row) || array_diff($keys, array_keys($row)) || array_diff(array_keys($row), $keys)) {
                throw new \InvalidArgumentException('Table de niveaux incomplète.');
            }
            foreach ($keys as $field) {
                if ($field === 'preparationBase' && $row[$field] === null) continue;
                if (!is_int($row[$field]) || $row[$field] < 0 || $row[$field] > ($field === 'maxSpellLevel' ? 9 : 100)) {
                    throw new \InvalidArgumentException('Valeur de politique invalide : '.$field);
                }
            }
            foreach ($previous as $field => $value) if ($row[$field] < $value) throw new \InvalidArgumentException('Les budgets cumulés doivent être croissants.');
            if (($learningStyle !== 'known' && ($row['known'] !== 0 || $row['replacements'] !== 0))
                || ($learningStyle !== 'spellbook' && $row['bookAdditions'] !== 0)
                || ($learningStyle === 'known' && $row['preparationBase'] !== null)) {
                throw new \InvalidArgumentException('Budgets incompatibles avec le fonctionnement de la politique.');
            }
            $previous = array_intersect_key($row, $previous);
        }
        foreach ($limitations as $limitation) if (!is_string($limitation) || trim($limitation) === '') throw new \InvalidArgumentException('Limite invalide.');
    }

    public function atLevel(int $level): array
    {
        if ($level < 1 || $level > 20) throw new \InvalidArgumentException('Niveau de classe invalide.');
        return $this->levels[$level - 1];
    }

    public function additionsAtLevel(int $level, string $bucket): int
    {
        $row = $this->atLevel($level);
        return match ($bucket) {
            'cantrips', 'known' => $row[$bucket] - ($level === 1 ? 0 : $this->atLevel($level - 1)[$bucket]),
            'spellbook' => $row['bookAdditions'],
            default => throw new \InvalidArgumentException('Budget d’acquisition invalide.'),
        };
    }

    public function grantQuota(string $mode, int $spellLevel): ?string
    {
        return match ($mode) {
            'grant-known' => $spellLevel === 0 ? 'cantrips' : ($this->learningStyle === 'known' ? 'known' : null),
            'grant-always-prepared' => 'preparation',
            'add-to-list', 'grant-cast' => null,
            default => throw new \InvalidArgumentException('Mode inconnu.'),
        };
    }
}
