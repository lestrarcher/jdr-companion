<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;

/** Read-only release gate. Never repairs or imports reference data. */
final class DndReferenceReadiness
{
    public function __construct(private readonly Connection $connection, private readonly string $projectDir) {}

    /** @return array<string, array{column: string, keys: list<string>}> */
    public function requirements(): array
    {
        $result = [];
        $add = static function (string $table, string $column, array $keys) use (&$result): void {
            $result[$table] ??= ['column' => $column, 'keys' => []];
            $result[$table]['keys'] = array_values(array_unique([...$result[$table]['keys'], ...$keys]));
        };
        $add('proficiency_definition', 'slug', array_column($this->catalogue('proficiencies')['proficiencies'], 'slug'));
        $training = $this->catalogue('training');
        $add('language_definition', 'slug', array_column($training['languages'], 'slug'));
        $add('language_grant_rule', 'import_key', array_column($training['languageRules'], 'key'));
        $add('expertise_grant_rule', 'import_key', array_column($training['expertiseRules'], 'key'));
        $add('character_class', 'slug', array_column($this->catalogue('class-features')['classes'], 'slug'));
        $add('character_race', 'slug', array_column($this->catalogue('races')['entries'], 'slug'));
        foreach (['class-proficiencies', 'race-proficiencies', 'feature-proficiencies'] as $file) {
            $add('proficiency_grant_rule', 'import_key', array_column($this->catalogue($file)['rules'], 'key'));
        }
        foreach (['backgrounds', 'backgrounds-supplements'] as $file) {
            foreach ($this->catalogue($file)['backgrounds'] as $entry) {
                $add('character_background', 'slug', [$entry['slug']]);
                $add('proficiency_grant_rule', 'import_key', array_column($entry['rules'], 'key'));
                $add('language_grant_rule', 'import_key', array_column($entry['languageRules'] ?? [], 'key'));
            }
        }
        return $result;
    }

    /** @return list<string> Missing/protected references, not an editorial catalogue diff. */
    public function check(): array
    {
        $errors = [];
        foreach ($this->requirements() as $table => $requirement) {
            // Identifiers come exclusively from the fixed manifest above, never from input.
            $rows = $this->connection->fetchAllAssociative("SELECT * FROM $table");
            $byKey = array_column($rows, null, $requirement['column']);
            foreach ($requirement['keys'] as $key) {
                $row = $byKey[$key] ?? null;
                if ($row === null) {
                    $errors[] = "$table : absent $key";
                } elseif (!$this->official($row)) {
                    $errors[] = "$table : référence protégée/incompatible $key (aucune correction automatique)";
                }
            }
        }

        // Include historical and private races too: each fixed language must be visible
        // to the race owner. This catches the actual 'common' runtime failure.
        $languages = array_column($this->connection->fetchAllAssociative('SELECT * FROM language_definition'), null, 'slug');
        $races = $this->connection->fetchAllAssociative('SELECT slug, languages, language_choice_count, origin, owner_id, custom FROM character_race');
        $raceMetadata = array_column($this->catalogue('races')['entries'], 'metadata', 'slug');
        foreach ($races as $race) {
            $expected = $this->official($race) ? ($raceMetadata[$race['slug']] ?? []) : [];
            foreach (['languages' => 'languages', 'languageChoiceCount' => 'language_choice_count'] as $key => $column) {
                if (isset($expected[$key]) && $race[$column] === null) {
                    $errors[] = 'Métadonnée raciale manquante : '.$race['slug'].'.'.$key.' (examiner import-training --update-existing --dry-run).';
                }
            }
            $fixed = $race['languages'] === null ? [] : json_decode($race['languages'], true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($fixed) || !array_is_list($fixed)) {
                $errors[] = 'Langues raciales invalides : '.$race['slug'];
                continue;
            }
            foreach ($fixed as $slug) {
                if (!is_string($slug) || strlen($slug) > 80 || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug)) {
                    $errors[] = 'Langue raciale invalide : '.$race['slug'];
                    continue;
                }
                $language = $languages[$slug] ?? null;
                $visible = $language !== null && ($this->official($language)
                    || ($race['origin'] === 'CUSTOM' && $race['owner_id'] !== null
                        && $language['origin'] === 'CUSTOM' && $language['owner_id'] === $race['owner_id']));
                if (!$visible) $errors[] = 'Langue raciale inconnue ou invisible : '.$race['slug'].' → '.$slug;
            }
        }

        $tinkering = $this->connection->fetchAssociative("SELECT r.* FROM character_feature_definition f
            JOIN trackable_resource_definition r ON r.id = f.resource_definition_id
            WHERE f.slug = 'magical-tinkering' AND f.origin = 'OFFICIAL' AND f.owner_id IS NULL AND f.custom = false");
        if (!$tinkering || !$this->official($tinkering) || $tinkering['slug'] !== 'magical-tinkering'
            || $tinkering['recharge_type'] !== 'none' || $tinkering['maximum_type'] !== 'ability-modifier'
            || $tinkering['scaling_ability'] !== 'intelligence' || (int) $tinkering['base_maximum'] !== 0
            || (int) $tinkering['multiplier'] !== 1 || (int) $tinkering['minimum_maximum'] !== 1) {
            $errors[] = 'Bricolages magiques : liaison/configuration absente ou incompatible (Version20261014120000).';
        }
        return $errors;
    }

    private function official(array $row): bool
    {
        return $row['origin'] === 'OFFICIAL' && $row['owner_id'] === null
            && !filter_var($row['custom'] ?? false, FILTER_VALIDATE_BOOL);
    }

    private function catalogue(string $name): array
    {
        $data = json_decode((string) file_get_contents($this->projectDir.'/data/reference/dnd-2014-'.$name.'.json'), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) throw new \RuntimeException('Catalogue invalide : '.$name);
        return $data;
    }
}
