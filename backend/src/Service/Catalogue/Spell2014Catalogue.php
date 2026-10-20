<?php

declare(strict_types=1);

namespace App\Service\Catalogue;

use App\Entity\SpellDefinition;
use App\Enum\SpellSchool;

/** Builds detached candidates only. No DB, HTTP, full-text fallback or price inference. */
final class Spell2014Catalogue
{
    private const SCHOOL_MAP = [
        'abjuration' => 'abjuration', 'invocation' => 'conjuration', 'conjuration' => 'conjuration',
        'divination' => 'divination', 'enchantement' => 'enchantment', 'enchantment' => 'enchantment',
        'évocation' => 'evocation', 'evocation' => 'evocation', 'illusion' => 'illusion',
        'nécromancie' => 'necromancy', 'necromancy' => 'necromancy', 'transmutation' => 'transmutation',
    ];

    public function prepare(array $data, array $review): array
    {
        $prepared = []; $errors = []; $excluded = []; $keys = []; $slugs = []; $blockers = [];
        if (($review['rightsStatus'] ?? null) !== 'verified' || empty($review['rightsEvidence']) || empty($review['attribution'])) $blockers[] = 'Droits de réutilisation du corpus non établis (résumés et traductions compris).';
        foreach ($data['spells'] as $index => $s) {
            $name = is_array($s) && is_string($s['name_fr'] ?? null) ? $s['name_fr'] : "index $index";
            // Exclusions are pinned by identity, not merely by an editable source label.
            if (is_array($s) && in_array($s['import_key'] ?? null, Spell2014ImportPlan::EXCLUDED_KEYS, true)) {
                $excluded[] = $name;
                continue;
            }
            try {
                if (!is_array($s)) throw new \InvalidArgumentException('Entrée non objet.');
                if (($s['origin'] ?? null) !== 'OFFICIAL' || ($s['ruleset'] ?? null) !== '2014') {
                    throw new \InvalidArgumentException('Origine ou édition inattendue.');
                }
                $key = trim((string) ($s['import_key'] ?? ''));
                $slug = trim((string) ($s['slug_en'] ?? ''));
                if (!preg_match('/^aidedd-2014:[a-z0-9]+(?:-[a-z0-9]+)*$/D', $key) || $slug === '' || $slug !== ($s['slug_en'] ?? null) || strtolower($slug) !== $slug) throw new \InvalidArgumentException('Identifiant invalide.');
                if (isset($keys[$key]) || isset($slugs[$slug])) throw new \InvalidArgumentException('Doublon import_key ou slug.');
                $keys[$key] = true; $slugs[$slug] = true;
                $schoolKey = self::SCHOOL_MAP[mb_strtolower(trim((string) ($s['school'] ?? '')))] ?? null;
                if ($schoolKey === null) throw new \InvalidArgumentException('École inconnue : ' . ($s['school'] ?? 'NULL'));
                // Never fall back to scraped full text, including higher-level text.
                $description = trim((string) ($s['description_short_fr'] ?? ''));
                if ($description === '') throw new \InvalidArgumentException('Description importable manquante.');
                if (!empty($s['description']) || !empty($s['higher_level'])) throw new \InvalidArgumentException('Texte intégral non autorisé dans ce pipeline.');
                if (!is_int($s['level'] ?? null) || !is_bool($s['concentration'] ?? null) || !is_bool($s['ritual'] ?? null)) throw new \InvalidArgumentException('Niveau ou indicateurs non typés.');
                $sourceCode = Spell2014ImportPlan::SOURCE_CODES[$s['source_book'] ?? ''] ?? throw new \InvalidArgumentException('Source inconnue.');
                $components = $s['components'] ?? [];
                if (!is_array($components) || !array_is_list($components) || !$components || count($components) !== count(array_unique($components))) throw new \InvalidArgumentException('Composantes invalides.');
                foreach ($components as $component) {
                    if (!in_array($component, ['V', 'S', 'M'], true)) throw new \InvalidArgumentException('Composante inconnue.');
                }
                $hasMaterial = in_array('M', $components, true);
                $materialDetail = null;
                $cost = null; $consumed = false;
                $detail = $s['components_detail'] ?? '';
                if (!is_string($detail) || explode(' (', $detail, 2)[0] !== implode(', ', $components)) throw new \InvalidArgumentException('Désaccord entre composantes et détail.');
                if ($hasMaterial) {
                    // The regex only removes the V/S/M wrapper; it never infers a price or consumption.
                    if (preg_match('/^[VSM, ]+\((.*)\)\.?$/su', $detail, $m)) $materialDetail = $m[1];
                    if (!$materialDetail) throw new \InvalidArgumentException('Description matérielle absente ou non analysable : ' . $detail);
                    $entry = $review['entries'][$key] ?? null;
                    if (!is_array($entry) || ($entry['componentsSha256'] ?? null) !== hash('sha256', $detail) || ($entry['slug'] ?? null) !== $slug
                        || !array_key_exists('materialCostCp', $entry) || ($entry['materialCostCp'] !== null && !is_int($entry['materialCostCp']))
                        || !is_bool($entry['materialConsumed'] ?? null) || !in_array($entry['status'] ?? null, ['reviewed', 'conditional-unrepresentable'], true)) throw new \InvalidArgumentException('Revue matérielle absente, périmée ou invalide.');
                    $cost = $entry['materialCostCp']; $consumed = $entry['materialConsumed'];
                    if (array_key_exists('materialDescriptionNote', $entry)) {
                        $note = $entry['materialDescriptionNote'];
                        if (!is_string($note) || trim($note) === '' || mb_strlen($note) > 2000 || $consumed) throw new \InvalidArgumentException('Précision de consommation particulière invalide.');
                        // Preserve the complete source description; append reviewed original wording.
                        $materialDetail .= "\n\n".$note;
                    }
                    if ($entry['status'] !== 'reviewed') $blockers[] = $name . ' : ' . $entry['note'];
                }
                if ((bool) preg_match('/^concentration\b/ui', (string) ($s['duration'] ?? '')) !== $s['concentration']) throw new \InvalidArgumentException('Concentration et durée incohérentes.');
                $url = $s['aidedd_url'] ?? null;
                // One reviewed accented URL alias; stable import identity is not renamed.
                if ($key === 'aidedd-2014:maelstrom' && $url === 'https://www.aidedd.org/dnd/sorts.php?vf=maelström') $url = 'https://www.aidedd.org/dnd/sorts.php?vf=maelstrom';
                if (!is_string($url) || !str_starts_with($url, 'https://www.aidedd.org/dnd/sorts.php?vf=') || substr($url, strlen('https://www.aidedd.org/dnd/sorts.php?vf=')) !== substr($key, strlen('aidedd-2014:'))) throw new \InvalidArgumentException('URL et identité incohérentes.');
                if (is_string($url)) {
                    $url = preg_replace_callback('/[^\x00-\x7F]+/u', static fn (array $m): string => rawurlencode($m[0]), $url);
                }
                $spell = new SpellDefinition(
                    $slug, (string) ($s['name_fr'] ?? ''), (int) ($s['level'] ?? -1), SpellSchool::from($schoolKey),
                    (string) (($s['casting_time_detail'] ?? null) ?: ($s['casting_time'] ?? '')),
                    (string) (($s['range_detail'] ?? null) ?: ($s['range'] ?? '')),
                    (string) ($s['duration'] ?? ''), $description
                );
                $spell->setOriginalName(isset($s['name_en']) ? (string) $s['name_en'] : null);
                $spell->setComponents(in_array('V', $components, true), in_array('S', $components, true), $hasMaterial, $materialDetail, $cost, $consumed);
                $spell->setConcentration(($s['concentration'] ?? false) === true);
                $spell->setRitual(($s['ritual'] ?? false) === true);
                $spell->setSource($sourceCode, (string) ($s['source_book'] ?? ''), $url);
                $spell->setImportKey($key);
                $spell->validateReferenceOwnership();
                $prepared[] = $spell;
            } catch (\Throwable $e) {
                $errors[] = $name . ' : ' . $e->getMessage();
            }
        }
        return compact('prepared', 'errors', 'excluded', 'blockers');
    }
}
