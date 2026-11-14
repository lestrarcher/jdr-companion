<?php

declare(strict_types=1);

namespace App\Service\Catalogue;

use App\Entity\SpellDefinition;
use App\Enum\SpellSchool;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/** Explicit reviewed data only. Never infer targeting from names or descriptions at import time. */
final readonly class Spell2014TargetingImport
{
    public const FIELDS = ['target_type', 'multiple_targets', 'multiple_targets_progression', 'multiple_targets_threshold'];

    public function __construct(private Connection $db) {}

    /** Tolerate whitespace/line-ending differences, not changes to spell mechanics. */
    public static function fingerprint(array $row): string
    {
        $parts = [(int) $row['level']];
        foreach (['range', 'description', 'higher_levels'] as $field) {
            $parts[] = trim(preg_replace('/\s+/u', ' ', $row[$field] ?? ''));
        }
        return hash('sha256', json_encode($parts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    public function validate(array $document): array
    {
        if (($document['schemaVersion'] ?? null) !== 1 || !is_array($document['spells'] ?? null)
            || !array_is_list($document['spells']) || $document['spells'] === []
            || ($document['count'] ?? null) !== count($document['spells'])) throw new \InvalidArgumentException('Référence de ciblage invalide ou incomplète.');
        $seen = [];
        foreach ($document['spells'] as $entry) {
            $required = ['edition', 'import_key', 'name', 'source_sha256', 'status', 'reason', ...self::FIELDS];
            if (!is_array($entry) || array_diff($required, array_keys($entry)) || array_diff(array_keys($entry), $required)
                || $entry['edition'] !== '2014' || !is_string($entry['import_key'])
                || !preg_match('/^aidedd-2014:[a-z0-9]+(?:-[a-z0-9]+)*$/D', $entry['import_key'])
                || in_array($entry['import_key'], Spell2014ImportPlan::EXCLUDED_KEYS, true)
                || isset($seen[$entry['import_key']]) || !is_string($entry['source_sha256'])
                || !preg_match('/^[a-f0-9]{64}$/D', $entry['source_sha256'])
                || !is_string($entry['name']) || trim($entry['name']) === ''
                || !is_string($entry['reason']) || trim($entry['reason']) === '') throw new \InvalidArgumentException('Entrée de ciblage invalide, non reconnue ou dupliquée.');
            $seen[$entry['import_key']] = true;
            if ($entry['status'] === 'review') {
                foreach (self::FIELDS as $field) if ($entry[$field] !== null) throw new \InvalidArgumentException('Une revue ne doit proposer aucune mécanique.');
                continue;
            }
            if ($entry['status'] !== 'ready' || !is_string($entry['target_type']) || !is_bool($entry['multiple_targets'])
                || ($entry['multiple_targets_progression'] !== null && !is_string($entry['multiple_targets_progression']))
                || ($entry['multiple_targets_threshold'] !== null && !is_int($entry['multiple_targets_threshold']))) throw new \InvalidArgumentException('Propriétés de ciblage invalides.');
            // Reuse precisely the entity's targeting contract; no second schema of game rules.
            (new SpellDefinition('targeting-validation', 'Validation', 0, SpellSchool::Abjuration, '-', '-', '-', '-'))
                ->setTargeting($entry['target_type'], $entry['multiple_targets'], $entry['multiple_targets_progression'], $entry['multiple_targets_threshold']);
        }
        return $document['spells'];
    }

    public function run(array $document, bool $apply = false): array
    {
        $entries = $this->validate($document); // Entire file validated before touching the database.
        if (!$apply) return $this->plan($entries);
        return $this->db->transactional(function () use ($entries): array {
            // Serializes with catalogue edits/imports, including edits of the reviewed descriptions.
            $this->db->executeStatement('LOCK TABLE spell_definition IN SHARE ROW EXCLUSIVE MODE');
            $report = $this->plan($entries);
            if ($report['errors'] !== []) return $report;
            foreach ($report['changes'] as $change) {
                $this->db->update('spell_definition', $change['after'], ['edition'=>'2014', 'import_key'=>$change['import_key'], 'origin'=>'OFFICIAL', 'owner_id'=>null],
                    ['multiple_targets'=>ParameterType::BOOLEAN]);
            }
            $report['applied'] = count($report['changes']);
            return $report;
        });
    }

    private function plan(array $entries): array
    {
        $report = ['officialTotal'=>0, 'customIgnored'=>0, 'changes'=>[], 'unchanged'=>[], 'review'=>[], 'errors'=>[], 'applied'=>0];
        $rows = $this->db->fetchAllAssociative("SELECT * FROM spell_definition WHERE edition='2014'");
        $byKey = [];
        foreach ($rows as $row) {
            if ($row['origin'] === 'CUSTOM') { ++$report['customIgnored']; continue; }
            ++$report['officialTotal'];
            if ($row['origin'] !== 'OFFICIAL' || $row['owner_id'] !== null || $row['import_key'] === null) {
                $report['errors'][] = $row['name'].' : référence officielle sans identité valide.';
                continue;
            }
            $byKey[$row['import_key']][] = $row;
        }
        $known = array_column($entries, null, 'import_key');
        foreach ($byKey as $key=>$matches) if (!isset($known[$key])) $report['errors'][] = $key.' : référence officielle non reconnue par le fichier.';
        foreach ($entries as $entry) {
            $identity = ['import_key'=>$entry['import_key'], 'name'=>$entry['name']];
            $matches = $byKey[$entry['import_key']] ?? [];
            if (count($matches) !== 1) {
                $report['errors'][] = $entry['import_key'].(count($matches) === 0 ? ' : référence absente.' : ' : identité ambiguë.');
                continue;
            }
            $row = $matches[0];
            $before = array_combine(self::FIELDS, array_map(static fn(string $field) => $row[$field], self::FIELDS));
            $before['multiple_targets'] = filter_var($before['multiple_targets'], FILTER_VALIDATE_BOOL);
            $before['multiple_targets_threshold'] = $before['multiple_targets_threshold'] === null ? null : (int) $before['multiple_targets_threshold'];
            $configured = $before !== ['target_type'=>null, 'multiple_targets'=>false, 'multiple_targets_progression'=>null, 'multiple_targets_threshold'=>null];
            if ($entry['status'] === 'review') {
                $report['review'][] = $identity + ['reason'=>$entry['reason'], 'existingPreserved'=>$configured];
                continue;
            }
            if (!hash_equals($entry['source_sha256'], self::fingerprint($row))) {
                $report['errors'][] = $entry['import_key'].' : texte, portée ou niveau différent de la référence analysée ; revue nécessaire.';
                continue;
            }
            $after = array_combine(self::FIELDS, array_map(static fn(string $field) => $entry[$field], self::FIELDS));
            if ($before === $after) { $report['unchanged'][] = $identity; continue; }
            if ($configured) {
                $report['errors'][] = $entry['import_key'].' : configuration existante différente, conservée ; arbitrage manuel requis.';
                continue;
            }
            $report['changes'][] = $identity + ['before'=>$before, 'after'=>$after, 'reason'=>$entry['reason']];
        }
        return $report;
    }
}
