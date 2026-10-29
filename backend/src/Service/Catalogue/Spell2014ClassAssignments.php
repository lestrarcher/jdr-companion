<?php

declare(strict_types=1);

namespace App\Service\Catalogue;

/** Provenance stays in the reviewed file; the current relation stores only the pair. */
final class Spell2014ClassAssignments
{
    public const CLASSES = ['Barde'=>'bard', 'Clerc'=>'cleric', 'Druide'=>'druid', 'Ensorceleur'=>'sorcerer', 'Magicien'=>'wizard', 'Occultiste'=>'warlock', 'Paladin'=>'paladin', 'Rôdeur'=>'ranger'];

    public function prepare(array $document, string $sha256, array $spells): array
    {
        if (($document['schema_version'] ?? null) !== 1 || ($document['source_sha256'] ?? null) !== $sha256 || !is_array($document['entries'] ?? null)) {
            throw new \InvalidArgumentException('Associations : version ou empreinte source invalide.');
        }
        $expected = [];
        foreach ($spells as $spell) $expected[$spell->getImportKey()] = true;
        if (array_diff_key($expected, $document['entries']) || array_diff_key($document['entries'], $expected)) {
            throw new \InvalidArgumentException('Associations : les identités doivent correspondre exactement aux sorts admissibles.');
        }
        $rows = [];
        foreach ($document['entries'] as $key => $entry) {
            $url = is_string($entry['url'] ?? null) ? rawurldecode($entry['url']) : null;
            // Same reviewed alias as the definition importer; never change the stable key.
            if ($key === 'aidedd-2014:maelstrom' && $url === 'https://www.aidedd.org/dnd/sorts.php?vf=maelström') $url = 'https://www.aidedd.org/dnd/sorts.php?vf=maelstrom';
            if (!is_array($entry) || ($entry['status'] ?? null) !== 'ok' || !is_string($entry['url'] ?? null)
                || $url !== 'https://www.aidedd.org/dnd/sorts.php?vf='.substr($key, strlen('aidedd-2014:'))
                || !is_array($entry['classes'] ?? null) || !array_is_list($entry['classes']) || !$entry['classes']) {
                throw new \InvalidArgumentException('Association invalide : '.$key);
            }
            $seen = [];
            foreach ($entry['classes'] as $class) {
                $name = $class['name'] ?? null; $source = $class['source'] ?? null;
                if (!is_string($name) || !isset(self::CLASSES[$name]) || !in_array($source, ['base','TCoE'], true)
                    || ($class['label'] ?? null) !== $name.($source === 'TCoE' ? ' [TCoE]' : '') || isset($seen[$name])) {
                    throw new \InvalidArgumentException('Classe, provenance ou doublon invalide : '.$key);
                }
                $seen[$name] = true;
                $rows[] = ['key'=>$key, 'classSlug'=>self::CLASSES[$name], 'source'=>$source];
            }
        }
        return $rows;
    }
}
