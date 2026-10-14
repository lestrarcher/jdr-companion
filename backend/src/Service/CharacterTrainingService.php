<?php
declare(strict_types=1);
namespace App\Service;

use App\Entity\{Character, CharacterLanguageChoice, CharacterExpertiseChoice, CharacterManualGrant, CharacterProficiencySubstitution, User};

final readonly class CharacterTrainingService
{
    public function __construct(
        private CharacterTrainingReferences $references,
        private CharacterTrainingResolver $resolver,
        private CharacterProficiencyResolver $proficiencies,
        private CharacterProficiencyChoiceService $choices,
        private CharacterRaceMetadataResolver $raceMetadata,
    ) {}
    /** Restricted player/GM selection payload; authorization and transaction belong to the caller. */
    public function applyChoices(Character $character, array $payload): array
    {
        foreach (array_keys($payload) as $key) {
            if (!in_array($key, ['proficiencyChoices', 'languageChoices', 'expertiseChoices'], true)) {
                throw new \DomainException('Seuls les choix de formation sont autorisés.');
            }
        }
        return $this->apply($character, $payload, $character->getCampaign()->getOwner(), false);
    }
    /** Called on an ephemeral preview or inside the final builder transaction. */
    public function apply(Character $character, array $payload, User $actor, bool $complete = true): array
    {
        if ($character->getCampaign()->getOwner() !== $actor
            && ($actor->getId() === null || $character->getCampaign()->getOwner()->getId() !== $actor->getId())) throw new \DomainException('Seul le MJ peut attribuer ces choix.');
        foreach (array_keys($payload) as $key) if (!in_array($key, ['proficiencyChoices', 'languageChoices', 'substitutions', 'expertiseChoices', 'manualGrants'], true)) throw new \DomainException('Champ de formation inconnu : '.$key);
        $race = $character->getRace();
        if ($race && $race->getOrigin()->value === 'OFFICIAL' && $this->raceMetadata->resolve($race)['languages'] === []) {
            throw new \DomainException('Métadonnées de langues raciales manquantes pour '.$race->getSlug().' : exécuter app:dnd:import-training --update-existing après vérification avec --dry-run.');
        }
        $refs = $this->references->load($character);
        $previousExpertises = $this->resolver->resolve($character, $refs)['expertises'];
        $definitions = $languages = $rules = [];
        foreach ($refs['proficiencies'] as $definition) $definitions[$definition->getId()] = $definition;
        foreach ($refs['languages'] as $language) $languages[$language->getSlug()] = $language;
        foreach ($refs['proficiencyRules'] as $rule) {
            $rule->validateRule();
            if ($this->proficiencies->isApplicable($character, $rule)) $rules[$rule->getId()] = $rule;
        }
        $seen = [];
        foreach ($this->list($payload, 'proficiencyChoices') as $selection) {
            $ruleId = $this->id($selection, 'ruleId');
            if (!isset($rules[$ruleId]) || isset($seen[$ruleId]) || $rules[$ruleId]->getChoiceCount() === 0) throw new \DomainException('Règle de maîtrise absente, automatique ou répétée.');
            $seen[$ruleId] = true;
            $selected = [];
            foreach ($this->list($selection, 'proficiencyIds') as $id) {
                if (!is_int($id) || !isset($definitions[$id])) throw new \DomainException('Maîtrise inconnue ou invisible.');
                $selected[] = $definitions[$id];
            }
            $this->choices->replaceChoices($character, $rules[$ruleId], $selected);
        }
        if ($complete) foreach ($rules as $id => $rule) if ($rule->getChoiceCount() > 0 && !isset($seen[$id])) throw new \DomainException('Choix de maîtrises incomplets : '.$rule->getLabel());
        $base = $this->proficiencies->resolveBase($character);
        $replaced = $targets = [];
        foreach ($this->list($payload, 'substitutions') as $selection) {
            $ruleId = $this->id($selection, 'ruleId');
            $fromId = $this->id($selection, 'originalProficiencyId');
            $toId = $this->id($selection, 'replacementProficiencyId');
            $rule = $rules[$ruleId] ?? null; $from = $definitions[$fromId] ?? null; $to = $definitions[$toId] ?? null;
            $key = $ruleId.':'.$fromId;
            if (!$rule || !$from || !$to || isset($replaced[$key]) || isset($targets[$toId]) || $rule->getCharacterBackground() === null
                || !in_array($from->getType()->value, ['SKILL', 'TOOL'], true) || $from->getType() !== $to->getType()
                || count($base[$from->getSlug()]['grants'] ?? []) < 2 || !in_array($rule, $base[$from->getSlug()]['grants'] ?? [], true)
                || isset($base[$to->getSlug()])) throw new \DomainException('Substitution d’historique injustifiée ou incompatible.');
            $replaced[$key] = $targets[$toId] = true;
            $base[$from->getSlug()]['grants'] = array_values(array_filter($base[$from->getSlug()]['grants'], static fn ($r) => $r !== $rule));
            $character->addProficiencySubstitution(new CharacterProficiencySubstitution($character, $rule, $from, $to));
        }
        // Explicit GM grants are separate records, never fictitious rule choices.
        $manual = $this->list($payload, 'manualGrants');
        foreach ($manual as $selection) {
            if (!is_array($selection) || !in_array($selection['kind'] ?? null, ['PROFICIENCY', 'EXPERTISE', 'LANGUAGE'], true)
                || !is_string($selection['reason'] ?? null)) throw new \DomainException('Attribution manuelle invalide.');
            $kind = $selection['kind'];
            $definition = $language = null;
            if ($kind === 'LANGUAGE') {
                if (!is_string($selection['languageSlug'] ?? null) || !isset($languages[$selection['languageSlug']])) throw new \DomainException('Langue manuelle inconnue ou invisible.');
                $language = $languages[$selection['languageSlug']];
                if (isset($selection['proficiencyId'])) throw new \DomainException('Cible manuelle ambiguë.');
            } else {
                $id = $this->id($selection, 'proficiencyId');
                $definition = $definitions[$id] ?? null;
                if (!$definition || isset($selection['languageSlug'])) throw new \DomainException('Maîtrise manuelle inconnue ou ambiguë.');
                if ($kind === 'EXPERTISE' && !in_array($definition->getType()->value, ['SKILL', 'TOOL'], true)) throw new \DomainException('Expertise incompatible.');
            }
            $candidate = new CharacterManualGrant($character, $kind, $actor, $selection['reason'], $definition, $language);
            foreach ($character->getManualGrants() as $existing) {
                if ($existing->getKind() === $kind && $existing->getProficiencyDefinition() === $definition
                    && $existing->getLanguage() === $language && $existing->getGrantedBy() === $actor
                    && $existing->getReason() === $selection['reason']) continue 2;
            }
            $character->addManualGrant($candidate);
        }
        $effective = $this->proficiencies->resolve($character);
        foreach ($previousExpertises as $slug => $entry) {
            if (!isset($effective[$slug])) throw new \DomainException('Conflit de formation : la maîtrise '.$entry['definition']->getName().' est nécessaire à une expertise acquise.');
        }
        foreach ($character->getManualGrants() as $grant) {
            if ($grant->getKind() === 'EXPERTISE' && !isset($effective[$grant->getProficiencyDefinition()->getSlug()])) throw new \DomainException('Une expertise exige une maîtrise préalable.');
        }
        $requirements = $this->resolver->languageRequirements($character, $refs);
        $known = $seen = [];
        foreach ($requirements as $r) foreach ($r['automatic'] as $l) $known[$l->getSlug()] = true;
        foreach ($character->getManualGrants() as $grant) if ($grant->getKind() === 'LANGUAGE') $known[$grant->getLanguage()->getSlug()] = true;
        $languageSelections = $this->list($payload, 'languageChoices');
        $editedSources = array_column($languageSelections, 'sourceKey');
        foreach ($this->resolver->resolve($character, $refs)['languages'] as $slug => $entry) {
            if (array_diff($entry['sources'], $editedSources) !== []) $known[$slug] = true;
        }
        foreach ($languageSelections as $selection) {
            if (!is_array($selection) || !is_string($selection['sourceKey'] ?? null)) throw new \DomainException('Source de langue invalide.');
            $key = $selection['sourceKey']; $requirement = $requirements[$key] ?? null;
            if (!$requirement || isset($seen[$key]) || $requirement['choiceCount'] === 0) throw new \DomainException('Source de choix de langue absente ou répétée.');
            $seen[$key] = true;
            $selected = $this->list($selection, 'languageSlugs');
            if (count($selected) !== $requirement['choiceCount']) throw new \DomainException('Nombre de langues incorrect.');
            foreach ($selected as $slug) {
                if (!is_string($slug) || !isset($languages[$slug]) || isset($known[$slug]) || !in_array($languages[$slug], $requirement['options'], true)) throw new \DomainException('Langue indisponible ou déjà connue.');
                $known[$slug] = true;
            }
            $retained = [];
            foreach ($character->getLanguageChoices()->toArray() as $choice) {
                if ($choice->getSourceKey() !== $key) continue;
                $slug = $choice->getLanguage()->getSlug();
                if (in_array($slug, $selected, true) && !isset($retained[$slug])) $retained[$slug] = true;
                else $character->getLanguageChoices()->removeElement($choice);
            }
            foreach ($selected as $slug) if (!isset($retained[$slug])) $character->addLanguageChoice(new CharacterLanguageChoice($character, $key, $languages[$slug]));
        }
        if ($complete) {
            foreach ($requirements as $key => $requirement) if ($requirement['choiceCount'] > 0 && !isset($seen[$key])) throw new \DomainException('Choix de langues incomplets : '.$key);
        }
        $expertiseRules = [];
        foreach ($refs['expertiseRules'] as $rule) if ($this->resolver->applicable($character, $rule)) $expertiseRules[$rule->getId()] = $rule;
        $expertiseSeen = $expertiseTargets = [];
        $expertiseSelections = $this->list($payload, 'expertiseChoices');
        $editedKeys = [];
        foreach ($expertiseSelections as $selection) {
            $id = $this->id($selection, 'ruleId');
            if (isset($expertiseRules[$id])) $editedKeys[] = $expertiseRules[$id]->getImportKey();
        }
        foreach ($this->resolver->resolve($character, $refs)['expertises'] as $slug => $entry) {
            if (array_diff($entry['sources'], $editedKeys) !== []) $expertiseTargets[$slug] = true;
        }
        foreach ($expertiseSelections as $selection) {
            $id = $this->id($selection, 'ruleId'); $rule = $expertiseRules[$id] ?? null;
            if (!$rule || $rule->getChoiceCount() === 0 || isset($expertiseSeen[$id])) throw new \DomainException('Règle d’expertise absente, automatique ou répétée.');
            $expertiseSeen[$id] = true;
            $selected = $this->list($selection, 'proficiencyIds');
            if (count($selected) !== $rule->getChoiceCount()) throw new \DomainException('Nombre d’expertises incorrect.');
            $historicalTargets = [];
            foreach ($character->getExpertiseChoices() as $choice) {
                if ($choice->getGrantRule() === $rule) $historicalTargets[] = $choice->getProficiencyDefinition()->getId();
            }
            $selectionSeen = [];
            foreach ($selected as $target) {
                if (!is_int($target) || !isset($definitions[$target])) throw new \DomainException('Expertise inconnue.');
                $definition = $definitions[$target]; $slug = $definition->getSlug();
                if (!$rule->getOptions()->contains($definition) || !isset($effective[$slug]) || isset($selectionSeen[$slug])
                    || (isset($expertiseTargets[$slug]) && !in_array($target, $historicalTargets, true))) throw new \DomainException('Expertise indisponible, déjà acquise ou sans maîtrise.');
                $selectionSeen[$slug] = true;
                $expertiseTargets[$slug] = true;
            }
            $retained = [];
            foreach ($character->getExpertiseChoices()->toArray() as $choice) {
                if ($choice->getGrantRule() !== $rule) continue;
                $target = $choice->getProficiencyDefinition()->getId();
                if (in_array($target, $selected, true) && !isset($retained[$target])) $retained[$target] = true;
                else $character->getExpertiseChoices()->removeElement($choice);
            }
            foreach ($selected as $target) if (!isset($retained[$target])) $character->addExpertiseChoice(new CharacterExpertiseChoice($character, $rule, $definitions[$target]));
        }
        if ($complete) foreach ($expertiseRules as $id => $rule) if ($rule->getChoiceCount() > 0 && !isset($expertiseSeen[$id])) throw new \DomainException('Choix d’expertises incomplets : '.$rule->getLabel());
        return $this->resolver->resolve($character, $refs);
    }
    private function list(array $payload, string $key): array
    {
        $value = $payload[$key] ?? [];
        if (!is_array($value) || !array_is_list($value)) throw new \DomainException('Liste attendue : '.$key);
        return $value;
    }
    private function id(mixed $payload, string $key): int
    {
        if (!is_array($payload) || !is_int($payload[$key] ?? null) || $payload[$key] < 1) throw new \DomainException('Identifiant invalide : '.$key);
        return $payload[$key];
    }
}

