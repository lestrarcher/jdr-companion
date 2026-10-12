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
    /** Called on an ephemeral preview or inside the final builder transaction. */
    public function apply(Character $character, array $payload, User $actor, bool $complete = true): array
    {
        if ($character->getCampaign()->getOwner() !== $actor
            && ($actor->getId() === null || $character->getCampaign()->getOwner()->getId() !== $actor->getId())) throw new \DomainException('Seul le MJ peut attribuer ces choix.');
        foreach (array_keys($payload) as $key) if (!in_array($key, ['proficiencyChoices', 'languageChoices', 'substitutions', 'expertiseChoices', 'manualGrants'], true)) throw new \DomainException('Champ de formation inconnu : '.$key);
        $refs = $this->references->load($character);
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
        $manualSeen = [];
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
            $key = $kind.':'.($language?->getSlug() ?? $definition->getSlug());
            if (isset($manualSeen[$key])) throw new \DomainException('Attribution manuelle répétée.');
            $manualSeen[$key] = true;
            $character->addManualGrant(new CharacterManualGrant($character, $kind, $actor, $selection['reason'], $definition, $language));
        }
        $effective = $this->proficiencies->resolve($character);
        foreach ($character->getManualGrants() as $grant) {
            if ($grant->getKind() === 'EXPERTISE' && !isset($effective[$grant->getProficiencyDefinition()->getSlug()])) throw new \DomainException('Une expertise exige une maîtrise préalable.');
        }
        $requirements = $this->resolver->languageRequirements($character, $refs);
        $known = $seen = [];
        foreach ($requirements as $r) foreach ($r['automatic'] as $l) $known[$l->getSlug()] = true;
        foreach ($character->getManualGrants() as $grant) if ($grant->getKind() === 'LANGUAGE') $known[$grant->getLanguage()->getSlug()] = true;
        foreach ($this->list($payload, 'languageChoices') as $selection) {
            if (!is_array($selection) || !is_string($selection['sourceKey'] ?? null)) throw new \DomainException('Source de langue invalide.');
            $key = $selection['sourceKey']; $requirement = $requirements[$key] ?? null;
            if (!$requirement || isset($seen[$key]) || $requirement['choiceCount'] === 0) throw new \DomainException('Source de choix de langue absente ou répétée.');
            $seen[$key] = true;
            $selected = $this->list($selection, 'languageSlugs');
            if (count($selected) !== $requirement['choiceCount']) throw new \DomainException('Nombre de langues incorrect.');
            foreach ($selected as $slug) {
                if (!is_string($slug) || !isset($languages[$slug]) || isset($known[$slug]) || !in_array($languages[$slug], $requirement['options'], true)) throw new \DomainException('Langue indisponible ou déjà connue.');
                $known[$slug] = true;
                $character->addLanguageChoice(new CharacterLanguageChoice($character, $key, $languages[$slug]));
            }
        }
        if ($complete) {
            foreach ($requirements as $key => $requirement) if ($requirement['choiceCount'] > 0 && !isset($seen[$key])) throw new \DomainException('Choix de langues incomplets : '.$key);
            $race = $character->getRace();
            if ($race && $race->getOrigin()->value === 'OFFICIAL' && $this->raceMetadata->resolve($race)['languages'] === []) throw new \DomainException('Métadonnées de langues raciales manquantes : importer le catalogue avant création.');
        }
        $expertiseRules = [];
        foreach ($refs['expertiseRules'] as $rule) if ($this->resolver->applicable($character, $rule)) $expertiseRules[$rule->getId()] = $rule;
        $expertiseSeen = $expertiseTargets = [];
        foreach ($this->resolver->resolve($character, $refs)['expertises'] as $slug => $entry) $expertiseTargets[$slug] = true;
        foreach ($this->list($payload, 'expertiseChoices') as $selection) {
            $id = $this->id($selection, 'ruleId'); $rule = $expertiseRules[$id] ?? null;
            if (!$rule || $rule->getChoiceCount() === 0 || isset($expertiseSeen[$id])) throw new \DomainException('Règle d’expertise absente, automatique ou répétée.');
            $expertiseSeen[$id] = true;
            $selected = $this->list($selection, 'proficiencyIds');
            if (count($selected) !== $rule->getChoiceCount()) throw new \DomainException('Nombre d’expertises incorrect.');
            foreach ($selected as $target) {
                if (!is_int($target) || !isset($definitions[$target])) throw new \DomainException('Expertise inconnue.');
                $definition = $definitions[$target]; $slug = $definition->getSlug();
                if (!$rule->getOptions()->contains($definition) || !isset($effective[$slug]) || isset($expertiseTargets[$slug])) throw new \DomainException('Expertise indisponible, déjà acquise ou sans maîtrise.');
                $expertiseTargets[$slug] = true;
                $character->addExpertiseChoice(new CharacterExpertiseChoice($character, $rule, $definition));
            }
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

