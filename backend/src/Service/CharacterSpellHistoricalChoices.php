<?php
declare(strict_types=1);
namespace App\Service;

use App\Entity\{Character, User, SpellcastingPolicyDefinition, SpellPolicyChoiceRule};
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Current-list reconciliation, with a non-persisted progression witness. All writes stay in the existing engine. */
final readonly class CharacterSpellHistoricalChoices
{
    public const NOTE = 'Réconciliation additive v1 : ';
    public function __construct(private EntityManagerInterface $em, private CharacterSpellAcquisitions $acquisitions) {}

    public static function trace(array $row): ?array
    {
        if ($row['provenance'] !== 'manual-reconciliation' || !str_starts_with($row['decision_note'], self::NOTE)) return null;
        $trace = json_decode(substr($row['decision_note'], strlen(self::NOTE)), true);
        return is_array($trace) && isset($trace['command'], $trace['witness']) ? $trace : null;
    }

    public function rows(Character $character, int $policyId): array
    {
        return $this->em->getConnection()->fetchAllAssociative('SELECT a.*, c.rule_id, c.opportunity_level_id, c.origin_known FROM character_spell_acquisition a LEFT JOIN character_spell_choice c ON c.acquisition_id=a.id WHERE a.character_id=? AND a.policy_definition_id=? AND a.retirement_id IS NULL ORDER BY a.id', [$character->getId(), $policyId]);
    }

    private static function key(?int $ruleId, string $bucket): string { return $ruleId === null ? $bucket : 'rule:'.$ruleId; }
    private static function rowKey(array $row): string { return self::key(($row['rule_id'] ?? null) === null ? null : (int)$row['rule_id'], $row['quota_bucket']); }

    public function preview(Character $character, int $policyId, User $actor): array
    {
        $current = $this->acquisitions->options($character, $policyId, $actor);
        $definition = $this->em->find(SpellcastingPolicyDefinition::class, $policyId);
        $rows = $this->rows($character, $policyId);
        $replaced = array_map('intval', array_filter(array_column($rows, 'replaces_id'), static fn ($id) => $id !== null));
        $warning = '';
        foreach ($rows as $row) {
            if ($row['revision_id'] !== null || ($row['provenance'] === 'manual-reconciliation' && self::trace($row) === null)
                || (self::trace($row) !== null && in_array((int)$row['id'], $replaced, true))) {
                $warning = 'Historique ambigu : une intervention du MJ est nécessaire. Aucun sort existant ne sera modifié.';
            }
        }
        if ($current['blocked'] || $current['status'] === 'quota-exceeded') $warning = 'Quotas incohérents ou non configurés : intervention du MJ nécessaire.';
        $routes = $current['choices'] ?? [];
        if ($definition->getFormatVersion() === 1) {
            foreach (['cantrips','known','spellbook'] as $bucket) {
                $routes[] = ['ruleId'=>null, 'key'=>$bucket, 'quota'=>$bucket,
                    'available'=>array_values(array_filter($current['available'], static fn ($s) => ($s['level'] === 0) === ($bucket === 'cantrips')))];
            }
        }
        $occupied = array_map('intval', array_column($current['acquisitions'], 'spell_id'));
        foreach ($current['automatic'] as $grant) if ($grant['bucket'] !== null) $occupied[] = (int)$grant['spellId'];
        $categories = [];
        foreach ($routes as $route) {
            $ruleId = $route['ruleId']; $bucket = $route['quota']; $key = self::key($ruleId, $bucket);
            $quota = 0;
            for ($n=1; $n <= $current['classLevel']; ++$n) $quota += $ruleId === null
                ? $definition->policy()->additionsAtLevel($n, $bucket)
                : $this->em->find(SpellPolicyChoiceRule::class, $ruleId)->parameters()->at('opportunities', $n);
            if ($quota === 0) continue;
            $spent = count(array_filter($rows, static fn ($row) => self::rowKey($row) === $key && $row['replaces_id'] === null && $row['provenance'] !== 'spellbook-copy'));
            if ($spent > $quota) $warning = 'Opportunités déjà dépassées : intervention du MJ nécessaire.';
            $remaining = max(0, $quota - $spent);
            if ($bucket !== 'spellbook') $remaining = min($remaining, $current['remaining'][$bucket] ?? $remaining);
            $eligible = array_column([...$route['available'], ...($route['replacementAvailable'] ?? [])], null, 'id');
            $known = array_values(array_map(static fn ($r) => ['id'=>(int)$r['spell_id'], 'name'=>$r['spell_name']], array_filter($current['acquisitions'],
                static fn ($r) => $ruleId === null ? $r['quota_bucket'] === $bucket : (int)($r['rule_id'] ?? 0) === $ruleId)));
            $categories[] = ['ruleId'=>$ruleId, 'key'=>$key, 'label'=>$route['key'], 'quota'=>$bucket, 'maximum'=>$quota, 'remaining'=>$remaining,
                'known'=>$known, 'available'=>array_values(array_filter($eligible, static fn ($s) => !in_array((int)$s['id'], $occupied, true)))];
        }
        if ($warning !== '') foreach ($categories as &$category) { $category['remaining'] = 0; $category['available'] = []; }
        return ['policyId'=>$policyId, 'className'=>$definition->getCharacterClass()->getName(), 'classLevel'=>$current['classLevel'],
            'classLevelId'=>$current['classLevelId'], 'categories'=>$categories, 'warning'=>$warning,
            'expectedAcquisitionIds'=>array_map('intval', array_column($current['acquisitions'], 'id')),
            'limitations'=>['La sélection est vérifiée avec les possibilités d’apprentissage et de remplacement de la politique. Les cas indémontrables nécessitent une intervention du MJ.'],
            'readOnly'=>true];
    }

    /** Returns a witness, not fabricated acquisition history. Optional replacement variants are never silently enabled. */
    public function validate(Character $character, int $policyId, array $choices, User $actor): array
    {
        $preview = $this->preview($character, $policyId, $actor);
        if ($preview['warning'] !== '') throw new ConflictHttpException($preview['warning']);
        $categories = array_column($preview['categories'], null, 'key'); $used = []; $targets = [];
        foreach ($choices as $choice) {
            $matches = array_values(array_filter($categories, static fn ($c) => $c['ruleId'] === $choice['ruleId'] && in_array($choice['spellId'], array_column($c['available'], 'id'), true)));
            if (count($matches) !== 1) throw new ConflictHttpException('Sort ou règle non éligible à cette classe.');
            $category = $matches[0]; $key = $category['key']; $used[$key] = ($used[$key] ?? 0) + 1;
            if ($used[$key] > $category['remaining']) throw new ConflictHttpException('La sélection dépasse le quota disponible.');
            $targets[] = ['spellId'=>$choice['spellId'], 'key'=>$key, 'deadline'=>$preview['classLevel'] - 1];
        }
        $inventory = $this->acquisitions->historicalInventory($character, $policyId, $actor);
        $levels = $inventory['levels']; $positions = array_flip(array_column($levels, 'classLevelId'));
        $rows = $this->rows($character, $policyId); $pinned = []; $reservedSpells = [];
        foreach ($rows as $row) {
            $reservedSpells[(int)$row['spell_id']] = true;
            if (!isset($positions[$row['class_level_id']])) throw new ConflictHttpException('Niveau d’origine absent : intervention du MJ nécessaire.');
            if (self::trace($row) !== null) {
                $targets[] = ['spellId'=>(int)$row['spell_id'], 'key'=>self::rowKey($row), 'deadline'=>$positions[$row['class_level_id']]];
            } else {
                if ($row['provenance'] !== 'spellbook-copy' && (!filter_var($row['historical_validation'], FILTER_VALIDATE_BOOL)
                    || ($inventory['formatVersion'] === 2 && ($row['rule_id'] === null || !filter_var($row['origin_known'], FILTER_VALIDATE_BOOL))))) {
                    throw new ConflictHttpException('Origine historique à qualifier par le MJ.');
                }
                $pinned[] = $row;
            }
        }
        foreach ($targets as $target) $reservedSpells[$target['spellId']] = true;
        $notBefore = []; $byId = array_column($rows, null, 'id'); $successors = [];
        foreach ($pinned as $row) if ($row['replaces_id'] !== null) {
            $old = $byId[$row['replaces_id']] ?? null;
            if (!$old || isset($successors[$row['replaces_id']]) || self::rowKey($old) !== self::rowKey($row)
                || $positions[$old['class_level_id']] >= $positions[$row['class_level_id']]
                || $old['opportunity_level_id'] !== $row['opportunity_level_id']) throw new ConflictHttpException('Chaîne de remplacement ambiguë : intervention du MJ nécessaire.');
            $successors[$row['replaces_id']] = $positions[$row['class_level_id']];
        }
        foreach ($pinned as $row) $notBefore[(int)$row['spell_id']] = max($notBefore[(int)$row['spell_id']] ?? 0, $successors[$row['id']] ?? count($levels));
        $roots = []; $replacementLimits = []; $capacities = []; $routes = [];
        foreach ($levels as $index => $level) {
            foreach ($level['automatic'] as $grant) $reservedSpells[(int)$grant['spellId']] = true;
            if ($level['blocked'] || $level['status'] === 'quota-exceeded') throw new ConflictHttpException('Quota historique incohérent : intervention du MJ nécessaire.');
            $capacities[$index] = $level['remaining'];
            // Current-list rows are assigned anew in the witness, so remove their temporary quota reservation.
            foreach ($level['acquisitions'] as $row) if (self::trace($row) !== null) {
                $bucket = $categories[self::rowKey($row)]['quota'] ?? $row['quota_bucket'];
                if (isset($capacities[$index][$bucket])) ++$capacities[$index][$bucket];
            }
            foreach ($level['rules'] as $rule) {
                $key = self::key($rule['ruleId'], $rule['quota']); $routes[$index][$key] = $rule;
                $spent = count(array_filter($pinned, static fn ($r) => self::rowKey($r) === $key && (int)$r['class_level_id'] === $level['classLevelId'] && $r['replaces_id'] === null && $r['provenance'] !== 'spellbook-copy'));
                if ($spent > $rule['opportunities']) throw new ConflictHttpException('Opportunités historiques dépassées : intervention du MJ nécessaire.');
                for ($i=$spent; $i<$rule['opportunities']; ++$i) $roots[] = ['key'=>$key, 'quota'=>$rule['quota'], 'level'=>$index, 'available'=>$rule['available']];
                $replacementLimits[$index][$rule['replacementBudget']] = max($replacementLimits[$index][$rule['replacementBudget']] ?? 0, $rule['replacements']);
            }
            foreach ($pinned as $row) if ($row['replaces_id'] !== null && (int)$row['class_level_id'] === $level['classLevelId']) {
                $route = $routes[$index][self::rowKey($row)] ?? null;
                if ($route === null) throw new ConflictHttpException('Remplacement de règle inconnue : intervention du MJ nécessaire.');
                --$replacementLimits[$index][$route['replacementBudget']];
            }
        }
        // Concrete candidates for a direct gain, or one replacement of a distinct eligible placeholder.
        // More complicated histories may be legal, but are never accepted without a witness.
        $plans = [];
        foreach ($targets as $target) {
            $options = [];
            foreach ($roots as $rootId => $root) {
                if ($root['key'] !== $target['key'] || $root['level'] > $target['deadline']) continue;
                if ($root['level'] >= ($notBefore[$target['spellId']] ?? 0) && in_array($target['spellId'], array_column($root['available'], 'id'), true)) {
                    $options[] = ['root'=>$rootId, 'at'=>$root['level'], 'group'=>null, 'placeholder'=>null];
                    continue;
                }
                for ($at=$root['level']+1; $at <= $target['deadline']; ++$at) {
                    $rule = $routes[$at][$root['key']]; $group = $rule['replacementBudget'];
                    if ($rule['replacements'] < 1 || $at < ($notBefore[$target['spellId']] ?? 0) || ($replacementLimits[$at][$group] ?? 0) < 1 || !in_array($target['spellId'], array_column($rule['replacementAvailable'], 'id'), true)) continue;
                    $fillers = array_values(array_filter($root['available'], static fn ($s) => $s['level'] >= $rule['replacementMinimumSpellLevel'] && !isset($reservedSpells[$s['id']])));
                    foreach ($fillers as $filler) $options[] = ['root'=>$rootId, 'at'=>$at, 'group'=>$group, 'placeholder'=>$filler['id']];
                }
            }
            // Later roots preserve early capacity; direct learning preserves replacement budgets.
            usort($options, static fn ($a,$b) => ($a['group'] !== null) <=> ($b['group'] !== null) ?: $roots[$b['root']]['level'] <=> $roots[$a['root']]['level']);
            $plans[] = $target + ['options'=>$options];
        }
        $steps = 0; $taken = []; $fillers = []; $budgetUsed = []; $occupancy = []; $witness = [];
        $fits = static function (array $option) use (&$taken, &$fillers, &$budgetUsed, &$occupancy, $roots, $levels, $capacities, $replacementLimits): bool {
            $root = $roots[$option['root']]; $bucket = $root['quota'];
            if (isset($taken[$option['root']]) || ($option['placeholder'] !== null && isset($fillers[$option['placeholder']]))
                || ($option['group'] !== null && ($budgetUsed[$option['at']][$option['group']] ?? 0) >= $replacementLimits[$option['at']][$option['group']])) return false;
            for ($n=$root['level']; $n<count($levels); ++$n) if (in_array($bucket, ['known','cantrips'], true) && ($occupancy[$n][$bucket] ?? 0) >= $capacities[$n][$bucket]) return false;
            return true;
        };
        $search = function (array $remaining) use (&$search, &$steps, &$taken, &$fillers, &$budgetUsed, &$occupancy, &$witness, $plans, $roots, $levels, $fits): bool {
            if (++$steps > 20000) return false;
            if ($remaining === []) return true;
            // Fail immediately if any target has lost every witness. Prioritize scarce learning
            // levels, then root/replacement choices: filler synonyms do not add temporal freedom.
            $best = null; $selected = null; $options = [];
            foreach ($remaining as $index) {
                $available = []; $dates = []; $routes = [];
                foreach ($plans[$index]['options'] as $option) if ($fits($option)) {
                    $available[] = $option;
                    $dates[$option['at']] = true;
                    $routes[$option['root'].':'.$option['at'].':'.$option['group']] = true;
                }
                if ($available === []) return false;
                $score = [count($dates), count($routes)];
                if ($best === null || $score < $best) { $best = $score; $selected = $index; $options = $available; }
            }
            $plan = $plans[$selected];
            $next = array_values(array_filter($remaining, static fn ($index) => $index !== $selected));
            foreach ($options as $option) {
                if ($steps >= 20000) return false;
                $rootId = $option['root']; $root = $roots[$rootId]; $bucket = $root['quota']; $group = $option['group']; $at = $option['at']; $filler = $option['placeholder'];
                $taken[$rootId] = true;
                if ($filler !== null) $fillers[$filler] = true;
                if ($group !== null) $budgetUsed[$at][$group] = ($budgetUsed[$at][$group] ?? 0) + 1;
                for ($n=$root['level']; $n<count($levels); ++$n) $occupancy[$n][$bucket] = ($occupancy[$n][$bucket] ?? 0) + 1;
                $witness[$plan['spellId']] = ['originLevel'=>$root['level']+1, 'learnedAt'=>$at+1, 'placeholderSpellId'=>$filler, 'replacementBudget'=>$group];
                if ($search($next)) return true;
                unset($taken[$rootId], $witness[$plan['spellId']]);
                if ($filler !== null) unset($fillers[$filler]);
                if ($group !== null) --$budgetUsed[$at][$group];
                for ($n=$root['level']; $n<count($levels); ++$n) --$occupancy[$n][$bucket];
            }
            return false;
        };
        $found = $search(array_keys($plans));
        if (!$found && $steps >= 20000) throw new ConflictHttpException('Recherche historique interrompue : limite de calcul atteinte. La légalité de la sélection reste indéterminée ; vérification par le MJ nécessaire.');
        if (!$found) throw new ConflictHttpException('Sélection incompatible ou progression non démontrable avec les apprentissages et remplacements autorisés. Ajustez les niveaux de sorts ou demandez une vérification au MJ.');
        return $witness;
    }
}
