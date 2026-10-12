<?php
declare(strict_types=1);
namespace App\Service\Catalogue;

use App\Entity\{LanguageDefinition, LanguageGrantRule, ExpertiseGrantRule};
use App\Enum\ReferenceOrigin;

final class TrainingImportPlanner
{
    /** Pure plan. Mechanical changes to existing rules require manual reconciliation. */
    public function plan(array $catalogue, array $raceCatalogue, array $existing, bool $update = false): array
    {
        if (($catalogue['edition'] ?? null) !== '2014') throw new \DomainException('Catalogue 2014 attendu.');
        $plan = ['languages' => [], 'languageRules' => [], 'expertiseRules' => [], 'races' => []];
        $languages = $existing['languages'];
        foreach ($this->entries($catalogue, 'languages') as $row) {
            if (!is_array($row) || !is_string($row['slug'] ?? null) || !is_string($row['name'] ?? null) || !is_string($row['category'] ?? null)) throw new \DomainException('Langue invalide.');
            $candidate = new LanguageDefinition($row['slug'], $row['name'], $row['category']);
            $key = $candidate->getSlug();
            if (isset($plan['languages'][$key])) throw new \DomainException('Langue dupliquée.');
            $old = $existing['languages'][$key] ?? null;
            if ($old) {
                $this->official($old);
                if ($old->getCategory() !== $candidate->getCategory()) throw new \DomainException('Changement de catégorie de langue refusé.');
            }
            $plan['languages'][$key] = ['action' => $this->action($old, $old && $old->getName() !== $candidate->getName(), $update), 'existing' => $old, 'candidate' => $candidate];
            $languages[$key] = $old ?? $candidate;
        }
        foreach (['languageRules' => LanguageGrantRule::class, 'expertiseRules' => ExpertiseGrantRule::class] as $kind => $type) {
            foreach ($this->entries($catalogue, $kind) as $row) {
                if (!is_array($row) || !is_string($row['key'] ?? null) || !is_string($row['label'] ?? null)
                    || !in_array($row['sourceType'] ?? null, ['class', 'background'], true) || !is_string($row['sourceSlug'] ?? null)
                    || !is_int($row['unlockLevel'] ?? null) || !is_int($row['choiceCount'] ?? null)
                    || !is_array($row['options'] ?? null) || !array_is_list($row['options'])) throw new \DomainException('Règle de formation invalide.');
                $key = $row['key'];
                if (isset($plan[$kind][$key])) throw new \DomainException('Clé dupliquée.');
                $source = $existing[$row['sourceType'] === 'class' ? 'classes' : 'backgrounds'][$row['sourceSlug']] ?? null;
                if (!$source) throw new \DomainException('Source introuvable : '.$row['sourceSlug']);
                $this->official($source);
                $candidate = new $type($key, $row['label'], $row['unlockLevel'], $row['choiceCount']);
                if ($row['sourceType'] === 'class') $candidate->setCharacterClass($source); else $candidate->setCharacterBackground($source);
                $options = $kind === 'languageRules' ? $languages : $existing['proficiencies'];
                $seen = [];
                foreach ($row['options'] as $slug) {
                    if (!is_string($slug) || isset($seen[$slug]) || !isset($options[$slug])) throw new \DomainException('Option inconnue ou dupliquée.');
                    $this->official($options[$slug]); $seen[$slug] = true;
                    if ($kind === 'languageRules' && $row['sourceType'] === 'background' && $options[$slug]->getCategory() === 'SECRET') throw new \DomainException('Un historique ne propose pas de langue secrète.');
                    $candidate->addOption($options[$slug]);
                }
                $candidate->validate();
                $old = $existing[$kind][$key] ?? null;
                if ($old) {
                    $this->official($old);
                    $old->validate();
                    if ($old->getCharacterClass() !== $candidate->getCharacterClass() || $old->getCharacterBackground() !== $candidate->getCharacterBackground()
                        || $this->mechanics($old) !== $this->mechanics($candidate)) throw new \DomainException('Modification mécanique refusée : '.$key);
                }
                foreach ($existing[$kind] as $other) {
                    if ($other !== $old && $other->getCharacterClass() === $candidate->getCharacterClass()
                        && $other->getCharacterBackground() === $candidate->getCharacterBackground()
                        && $this->mechanics($other) === $this->mechanics($candidate)) throw new \DomainException('Règle préexistante ambiguë : '.$key);
                }
                foreach ($plan[$kind] as $item) {
                    $other = $item['candidate'];
                    if ($other->getCharacterClass() === $candidate->getCharacterClass() && $other->getCharacterBackground() === $candidate->getCharacterBackground()
                        && $this->mechanics($other) === $this->mechanics($candidate)) throw new \DomainException('Règle dupliquée sous une autre clé : '.$key);
                }
                $plan[$kind][$key] = ['action' => $this->action($old, $old && $old->getLabel() !== $candidate->getLabel(), $update), 'existing' => $old, 'candidate' => $candidate];
            }
        }
        foreach ($this->entries($raceCatalogue, 'entries') as $row) {
            $metadata = $row['metadata'] ?? [];
            if (!array_key_exists('languages', $metadata)) continue;
            $slug = $row['slug'] ?? null;
            if (!is_string($slug) || isset($plan['races'][$slug]) || !isset($existing['races'][$slug])) throw new \DomainException('Race absente ou répétée.');
            $race = $existing['races'][$slug]; $this->official($race);
            if ($race->getParentRace()?->getSlug() !== ($row['parentSlug'] ?? null)) throw new \DomainException('Parenté raciale incompatible.');
            $fixed = $metadata['languages']; $count = $metadata['languageChoiceCount'] ?? null;
            if (!is_array($fixed) || !array_is_list($fixed) || ($count !== null && (!is_int($count) || $count < 0))) throw new \DomainException('Langues raciales invalides.');
            $seen = [];
            foreach ($fixed as $language) {
                if (!is_string($language) || !isset($languages[$language]) || isset($seen[$language])) throw new \DomainException('Langue raciale inconnue ou répétée.');
                $this->official($languages[$language]); $seen[$language] = true;
            }
            if (($race->getLanguages() !== null && $race->getLanguages() !== $fixed)
                || ($race->getLanguageChoiceCount() !== null && $race->getLanguageChoiceCount() !== $count)) throw new \DomainException('Métadonnées existantes différentes : '.$slug);
            $changed = $race->getLanguages() !== $fixed || $race->getLanguageChoiceCount() !== $count;
            $plan['races'][$slug] = ['action' => $this->action($race, $changed, $update), 'existing' => $race, 'candidate' => ['languages' => $fixed, 'languageChoiceCount' => $count]];
        }
        return $plan;
    }
    private function official(object $reference): void
    {
        if ($reference->getOrigin() !== ReferenceOrigin::Official || $reference->getOwner() !== null || (method_exists($reference, 'isCustom') && $reference->isCustom())) throw new \DomainException('Référence personnalisée protégée.');
    }
    private function entries(array $data, string $key): array
    {
        $entries = $data[$key] ?? null;
        if (!is_array($entries) || !array_is_list($entries) || $entries === []) throw new \DomainException('Liste non vide attendue : '.$key);
        return $entries;
    }
    private function action(?object $old, bool $different, bool $update): string { return !$old ? 'create' : (!$different ? 'unchanged' : ($update ? 'update' : 'keep')); }
    private function mechanics(object $rule): array
    {
        $slugs = array_map(static fn ($o) => $o->getSlug(), $rule->getOptions()->toArray()); sort($slugs);
        return [$rule->getUnlockLevel(), $rule->getChoiceCount(), $slugs];
    }
    public function applyUpdate(string $kind, array $item): void
    {
        $old = $item['existing']; $candidate = $item['candidate'];
        if ($kind === 'languages') $old->revise($candidate->getName(), $candidate->getCategory());
        elseif ($kind === 'races') $old->setLanguages($candidate['languages'])->setLanguageChoiceCount($candidate['languageChoiceCount']);
        else $old->setLabel($candidate->getLabel());
    }
}

