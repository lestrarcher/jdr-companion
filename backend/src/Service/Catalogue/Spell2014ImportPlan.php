<?php

declare(strict_types=1);

namespace App\Service\Catalogue;

use App\Entity\SpellDefinition;
use App\Enum\ReferenceOrigin;
use Doctrine\ORM\EntityManagerInterface;

/** Insert-only: an existing identity is retained even if an editor changed its contents. */
final readonly class Spell2014ImportPlan
{
    public const EXCLUDED_KEYS = ['aidedd-2014:invocation-d-ombres', 'aidedd-2014:projectile-elementaire', 'aidedd-2014:vents-contraires'];
    public const SOURCE_CODES = [
        'Player´s Handbook (SRD)' => 'PHB', 'Player´s Handbook (BR+)' => 'PHB', 'Player´s Handbook' => 'PHB',
        'Xanathar´s Guide to Everything' => 'XGtE', 'Tasha´s Cauldron of Everything' => 'TCoE',
        'Fizban´s Treasury of Dragons' => 'FToD', 'Settings (Strixhaven)' => 'SCC', 'Settings (Planescape)' => 'SatO',
    ];

    public function __construct(private EntityManagerInterface $em) {}

    /** @param list<SpellDefinition> $spells */
    public function plan(array $spells, array $associations = []): array
    {
        $create = []; $keep = 0; $conflicts = []; $keys = []; $slugs = [];
        foreach ($spells as $spell) {
            $key = $spell->getImportKey(); $slug = $spell->getSlug();
            if ($spell->getOrigin() !== ReferenceOrigin::Official || $spell->getOwner() !== null || $spell->getEdition() !== '2014'
                || $key === null || in_array($key, self::EXCLUDED_KEYS, true) || isset($keys[$key]) || isset($slugs[$slug])) {
                $conflicts[] = 'Candidat incompatible ou dupliqué : '.$slug;
                continue;
            }
            $keys[$key] = $slugs[$slug] = true;
            $rows = $this->em->getConnection()->fetchAllAssociative(
                'SELECT id, slug, import_key, edition, origin, owner_id FROM spell_definition WHERE slug = ? OR (edition = ? AND import_key = ?)',
                [$slug, '2014', $key],
            );
            if (!$rows) { $create[] = $spell; continue; }
            if (count($rows) === 1 && $rows[0]['slug'] === $slug && $rows[0]['import_key'] === $key
                && $rows[0]['edition'] === '2014' && $rows[0]['origin'] === 'OFFICIAL' && $rows[0]['owner_id'] === null) {
                ++$keep;
            } else {
                $conflicts[] = 'Collision d’identité (aucune donnée existante modifiée) : '.$slug;
            }
        }
        $associationCreate = []; $associationKeep = 0; $seen = []; $classes = [];
        $db = $this->em->getConnection();
        foreach ($associations as $association) {
            $key = $association['key'] ?? ''; $slug = $association['classSlug'] ?? '';
            if (!isset($keys[$key]) || !in_array($slug, Spell2014ClassAssignments::CLASSES, true)
                || !in_array($association['source'] ?? null, ['base','TCoE'], true) || isset($seen[$key][$slug])) {
                $conflicts[] = 'Association incompatible ou dupliquée : '.$key;
                continue;
            }
            $seen[$key][$slug] = true;
            $classes[$slug] ??= $db->fetchFirstColumn("SELECT id FROM character_class WHERE slug = ? AND origin = 'OFFICIAL' AND owner_id IS NULL", [$slug]);
            if (count($classes[$slug]) !== 1) {
                $conflicts[] = 'Classe OFFICIAL absente ou ambiguë : '.$slug;
                continue;
            }
            $classId = (int)$classes[$slug][0];
            $spellId = $db->fetchOne("SELECT id FROM spell_definition WHERE import_key = ? AND edition = '2014' AND origin = 'OFFICIAL' AND owner_id IS NULL", [$key]);
            if ($spellId && $db->fetchOne('SELECT id FROM spell_class_list_entry WHERE spell_id = ? AND character_class_id = ?', [$spellId, $classId])) {
                ++$associationKeep;
            } elseif ($spellId && $db->fetchOne('SELECT id FROM character_spell_acquisition WHERE spell_id = ? LIMIT 1', [$spellId])) {
                $conflicts[] = 'Liste verrouillée pour un sort acquis : '.$key;
            } else {
                $associationCreate[] = ['key'=>$key, 'classId'=>$classId];
            }
        }
        return ['create' => $create, 'keep' => $keep, 'conflicts' => $conflicts,
            'associationCreate'=>$associationCreate, 'associationKeep'=>$associationKeep];
    }

    /** Caller must validate the corpus and rights first. Tests use only synthetic temporary rows. */
    public function insertMissing(array $spells, array $associations = []): array
    {
        $db = $this->em->getConnection();
        return $db->transactional(function () use ($db, $spells, $associations): array {
            // Replan under a write-serializing lock, including concurrent editor/import changes.
            $db->executeStatement('LOCK TABLE spell_definition IN SHARE ROW EXCLUSIVE MODE');
            if ($associations) {
                $db->executeStatement('LOCK TABLE character_class IN SHARE MODE');
                $db->executeStatement('LOCK TABLE spell_class_list_entry IN SHARE ROW EXCLUSIVE MODE');
            }
            $plan = $this->plan($spells, $associations);
            if ($plan['conflicts']) throw new \RuntimeException(implode('; ', $plan['conflicts']));
            $metadata = $this->em->getClassMetadata(SpellDefinition::class);
            foreach ($plan['create'] as $spell) {
                $spell->validateReferenceOwnership();
                $row = []; $types = [];
                foreach ($metadata->getFieldNames() as $field) {
                    if ($field === 'id') continue;
                    $column = $db->getDatabasePlatform()->quoteIdentifier($metadata->getColumnName($field));
                    $value = $metadata->getFieldValue($spell, $field);
                    $row[$column] = $value instanceof \BackedEnum ? $value->value : $value;
                    $types[$column] = $metadata->getTypeOfField($field);
                }
                // DBAL avoids flushing unrelated managed entities or advancing their UnitOfWork.
                $row['owner_id'] = null;
                $db->insert('spell_definition', $row, $types);
            }
            foreach ($plan['associationCreate'] as $association) {
                $spellId = $db->fetchOne("SELECT id FROM spell_definition WHERE import_key = ? AND edition = '2014' AND origin = 'OFFICIAL' AND owner_id IS NULL", [$association['key']]);
                $db->insert('spell_class_list_entry', ['spell_id'=>$spellId, 'character_class_id'=>$association['classId']]);
            }
            $result = ['created' => count($plan['create']), 'kept' => $plan['keep']];
            if ($associations) $result += ['associationsCreated'=>count($plan['associationCreate']), 'associationsKept'=>$plan['associationKeep']];
            return $result;
        });
    }
}
