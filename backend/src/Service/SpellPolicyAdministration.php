<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\{SpellcastingPolicy, SpellChoiceParameters};
use App\Entity\{CharacterClass, CharacterSubclass, SpellDefinition, SpellSubclassGrant, SpellcastingPolicyDefinition, SpellGrantSubstitutionRule, User};
use App\Enum\Ability;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\LockMode;
use Symfony\Component\HttpKernel\Exception\{AccessDeniedHttpException, BadRequestHttpException, ConflictHttpException, NotFoundHttpException};

/** Shared by the official admin and the owner's personal API. Versions are append-only. */
final readonly class SpellPolicyAdministration
{
    public function __construct(private EntityManagerInterface $em, private CharacterSpellAcquisitions $acquisitions) {}
    private function scope(User $actor, bool $official): void
    {
        if ($official && !in_array(SpellCatalogue::ADMIN_ROLE, $actor->getRoles(), true)) throw new AccessDeniedHttpException();
    }
    private function reference(string $type, mixed $id, User $actor): object
    {
        if (!is_int($id) || $id < 1) throw new BadRequestHttpException('Identifiant positif requis.');
        $ref = $this->em->find($type, $id);
        if (!$ref || !ReferenceVisibility::allows($ref instanceof SpellSubclassGrant ? $ref->getSubclass() : $ref, $actor)) throw new NotFoundHttpException('Référence indisponible.');
        return $ref;
    }
    private function source(array $data, User $actor, bool $official): CharacterClass|CharacterSubclass
    {
        $this->scope($actor, $official);
        $type = match ($data['sourceType'] ?? null) {
            'class' => CharacterClass::class,
            'subclass' => CharacterSubclass::class,
            default => throw new BadRequestHttpException('Source invalide.')
        };
        $source = $this->reference($type, $data['sourceId'] ?? null, $actor);
        if ($source->getOrigin()->value !== ($official ? 'OFFICIAL' : 'CUSTOM')) throw new AccessDeniedHttpException('Utilisez le parcours adapté à cette origine.');
        return $source;
    }
    public function options(User $actor, bool $official = false): array
    {
        $this->scope($actor, $official);
        $summarize = static fn($r) => ['id' => $r->getId(), 'name' => $r->getName(), 'origin' => $r->getOrigin()->value];
        $classes = ReferenceVisibility::choices($this->em, CharacterClass::class, $official ? null : $actor);
        $subclasses = ReferenceVisibility::choices($this->em, CharacterSubclass::class, $official ? null : $actor);
        $spells = [];
        $page = 1;
        $catalogue = new SpellCatalogue($this->em->getConnection());
        do {
            $batch = $catalogue->search(['page' => $page++, 'pageSize' => 100], $actor, $official);
            foreach ($batch['spells'] as $spell) $spells[] = ['id' => $spell['id'], 'name' => $spell['name'], 'origin' => $spell['origin']];
        } while (($page - 1) * 100 < $batch['total']);
        $policies = [];
        foreach ($this->em->getRepository(SpellcastingPolicyDefinition::class)->findBy([], ['id' => 'DESC']) as $p) {
            if (!ReferenceVisibility::allows($p->getSource(), $official ? null : $actor)) continue;
            $policy = $p->policy();
            $rules = [];
            foreach ($this->em->getRepository(\App\Entity\SpellPolicyChoiceRule::class)->findBy(['policy' => $p], ['id' => 'ASC']) as $rule) $rules[] = ['key' => $rule->getKey(), 'parameters' => $rule->parameters()->values, 'listClassId' => $rule->getListClass()?->getId(), 'replacementListClassId' => $rule->getReplacementListClass()?->getId(), 'castingClassId' => $rule->getCastingClass()?->getId(), 'requiredSpellId' => $rule->getRequiredSpell()?->getId()];
            $policies[] = ['id' => $p->getId(), 'sourceId' => $p->getSource()->getId(), 'sourceType' => $p->getSubclass() ? 'subclass' : 'class', 'origin' => $p->getSource()->getOrigin()->value, 'sourceName' => $p->getSource()->getName(), 'version' => $policy->version, 'basePolicyId' => $p->getBasePolicy()?->getId(), 'formatVersion' => $p->getFormatVersion(), 'policy' => ['key' => $policy->key, 'version' => $policy->version, 'ability' => $policy->ability->value, 'learningStyle' => $policy->learningStyle, 'levels' => $policy->levels, 'provenance' => $policy->provenance, 'limitations' => $policy->limitations]];
            if ($p->getFormatVersion() === 2) $policies[array_key_last($policies)]['rules'] = $rules;
            $last = array_key_last($policies);
            $policies[$last]['document'] = array_intersect_key($policies[$last], array_flip(['sourceType', 'sourceId', 'policy', 'rules', 'basePolicyId']));
        }
        $ready = $this->em->getConnection()->fetchOne("SELECT to_regclass('spell_grant_substitution_rule')") !== null;
        $substitutions = [];
        $grants = [];
        foreach ($this->em->getRepository(SpellSubclassGrant::class)->findBy([], ['id' => 'ASC']) as $g) {
            if (!ReferenceVisibility::allows($g->getSubclass(), $official ? null : $actor)) continue;
            $grants[] = ['id' => $g->getId(), 'name' => $g->getSubclass()->getName() . ' — ' . $g->getSpell()->getName(), 'origin' => $g->getOrigin()->value];
        }
        if ($ready) foreach ($this->em->getRepository(SpellGrantSubstitutionRule::class)->findBy([], ['id' => 'DESC']) as $r) {
            if (!ReferenceVisibility::allows($r->getGrant()->getSubclass(), $official ? null : $actor)) continue;
            $substitutions[] = ['id' => $r->getId(), 'grantId' => $r->getGrant()->getId(), 'origin' => $r->getGrant()->getOrigin()->value, 'version' => $r->getVersion(), 'budgetKey' => $r->getBudgetKey(), 'schools' => $r->getSchools(), 'opportunities' => $r->getOpportunities(), 'classIds' => array_map(static fn($c) => $c->getId(), $r->getLists()), 'provenance' => $r->getProvenance()];
        }
        foreach ($substitutions as &$r) $r['document'] = array_diff_key($r, ['id' => true, 'origin' => true]);
        unset($r);
        $example = ['sourceType' => 'class', 'sourceId' => null, 'policy' => ['key' => 'nouvelle-politique', 'version' => 1, 'ability' => 'intelligence', 'learningStyle' => 'known', 'levels' => array_fill(0, 20, ['maxSpellLevel' => 0, 'cantrips' => 0, 'known' => 0, 'bookAdditions' => 0, 'replacements' => 0, 'preparationBase' => null]), 'provenance' => 'À renseigner : source et règles vérifiées', 'limitations' => []]];
        return ['classes' => array_map($summarize, $classes), 'subclasses' => array_map($summarize, $subclasses), 'spells' => $spells, 'policies' => $policies, 'grants' => $grants, 'substitutions' => $substitutions, 'migrationRequired' => !$ready, 'example' => $example];
    }
    public function create(array $data, User $actor, bool $official = false): int
    {
        $this->scope($actor, $official);
        $this->requireMigration();
        if (array_diff(array_keys($data), ['sourceType', 'sourceId', 'policy', 'rules', 'basePolicyId'])) throw new BadRequestHttpException('Champ inconnu.');
        $source = $this->source($data, $actor, $official);
        $p = $data['policy'] ?? null;
        if (!is_array($p) || array_diff(array_keys($p), ['key', 'version', 'ability', 'learningStyle', 'levels', 'provenance', 'limitations']) || !is_string($p['key'] ?? null) || !is_int($p['version'] ?? null) || !is_string($p['ability'] ?? null) || !is_string($p['learningStyle'] ?? null) || !is_array($p['levels'] ?? null) || !is_string($p['provenance'] ?? null) || !is_array($p['limitations'] ?? [])) throw new BadRequestHttpException('Politique invalide.');
        try {
            $ability = Ability::tryFrom($p['ability']);
            if ($ability === null) throw new \InvalidArgumentException('Caractéristique invalide.');
            $dto = new SpellcastingPolicy($p['key'], $p['version'], $ability, $p['learningStyle'], $p['levels'], $p['provenance'], $p['limitations'] ?? []);
            if (!isset($data['rules'])) {
                if (isset($data['basePolicyId'])) throw new BadRequestHttpException('Composition réservée au format avancé.');
                return $this->acquisitions->definePolicy($source, $dto, $actor)->getId();
            }
            if (!is_array($data['rules']) || !array_is_list($data['rules']) || count($data['rules']) > 100) throw new BadRequestHttpException('Règles invalides.');
            $rules = [];
            foreach ($data['rules'] as $r) {
                if (!is_array($r) || array_diff(array_keys($r), ['key', 'parameters', 'listClassId', 'replacementListClassId', 'castingClassId', 'requiredSpellId']) || !is_string($r['key'] ?? null) || !is_array($r['parameters'] ?? null)) throw new BadRequestHttpException('Règle invalide.');
                $rule = ['key' => $r['key'], 'parameters' => new SpellChoiceParameters($r['parameters'])];
                foreach (['listClass' => CharacterClass::class, 'replacementListClass' => CharacterClass::class, 'castingClass' => CharacterClass::class, 'requiredSpell' => SpellDefinition::class] as $field => $type) $rule[$field] = isset($r[$field . 'Id']) ? $this->reference($type, $r[$field . 'Id'], $actor) : null;
                $rules[] = $rule;
            }
            $base = null;
            if (isset($data['basePolicyId'])) {
                if (!is_int($data['basePolicyId'])) throw new BadRequestHttpException('Version de base invalide.');
                $base = $this->em->find(SpellcastingPolicyDefinition::class, $data['basePolicyId']);
                if (!$base || !ReferenceVisibility::allows($base->getSource(), $actor)) throw new NotFoundHttpException();
            }
            return $this->acquisitions->defineAdvancedPolicy($source, $dto, $actor, $rules, $base)->getId();
        } catch (\InvalidArgumentException $e) {
            throw new BadRequestHttpException($e->getMessage(), $e);
        }
    }
    /** Preview only: explicit selection never assigns a policy to existing references. */

public function preview(array $data, User $actor, bool $official = false): array
{
    if (
        array_diff(array_keys($data), ['sourceId', 'preset', 'tasha'])
        || !is_string($data['preset'] ?? null)
        || !is_bool($data['tasha'] ?? false)
    ) {
        throw new BadRequestHttpException('Préréglage invalide.');
    }

    $optional = $data['tasha'] ?? false;
    $eldritchKnight = $data['preset'] === 'eldritch-knight';

    if ($eldritchKnight) {
        if ($optional) {
            throw new BadRequestHttpException(
                'Le préréglage Chevalier occulte utilise les règles de base 2014.'
            );
        }

        // La politique appartient à la sous-classe, pas au Guerrier.
        $source = $this->source([
            'sourceType' => 'subclass',
            'sourceId' => $data['sourceId'] ?? null,
        ], $actor, $official);

        if ($source->getName() !== 'Chevalier occulte') {
            throw new BadRequestHttpException(
                'Sélectionnez la sous-classe Chevalier occulte.'
            );
        }

        // Le Chevalier occulte utilise la liste des sorts du Magicien.
        $wizard = $this->em
            ->getRepository(CharacterClass::class)
            ->findOneBy(['name' => 'Magicien']);

        if (!$wizard) {
            throw new BadRequestHttpException(
                'Classe Magicien introuvable.'
            );
        }

        $preset = Dnd2014AdvancedSpellPolicies::thirdCaster(
            $wizard,
            false
        );
    } else {
        $source = $this->source([
            'sourceType' => 'class',
            'sourceId' => $data['sourceId'] ?? null,
        ], $actor, $official);

        if ($data['preset'] === 'artificer' && $optional) {
            throw new BadRequestHttpException(
                'Le préréglage Artificier contient ses règles de base, sans option supplémentaire.'
            );
        }

        $preset = match ($data['preset']) {
            'artificer' => Dnd2014AdvancedSpellPolicies::artificer(
                $source
            ),

            'warlock' => Dnd2014AdvancedSpellPolicies::warlock(
                $source,
                $optional
            ),

            'druid', 'sorcerer' => Dnd2014AdvancedSpellPolicies::ordinary(
                $source,
                Dnd2014SpellPolicies::get($data['preset'] . '-2014'),
                $optional
            ),

            default => throw new BadRequestHttpException(
                'Préréglage inconnu.'
            ),
        };
    }

    $p = $preset['policy'];
    $rules = [];

    foreach ($preset['rules'] as $r) {
        $row = [
            'key' => $r['key'],
            'parameters' => $r['parameters']->values,
        ];

        foreach (
            ['listClass', 'replacementListClass', 'castingClass', 'requiredSpell']
            as $key
        ) {
            $row[$key . 'Id'] = isset($r[$key])
                ? $r[$key]->getId()
                : null;
        }

        $rules[] = $row;
    }

    return [
        'sourceType' => $eldritchKnight ? 'subclass' : 'class',
        'sourceId' => $source->getId(),
        'policy' => [
            'key' => $p->key,
            'version' => $p->version,
            'ability' => $p->ability->value,
            'learningStyle' => $p->learningStyle,
            'levels' => $p->levels,
            'provenance' => $p->provenance,
            'limitations' => $p->limitations,
        ],
        'rules' => $rules,
    ];
}

    public function defineSubstitution(array $data, User $actor, bool $official = false): int
    {
        $this->scope($actor, $official);
        $this->requireMigration();
        if (array_diff(array_keys($data), ['grantId', 'version', 'budgetKey', 'schools', 'opportunities', 'classIds', 'provenance']) || !is_int($data['version'] ?? null) || !is_string($data['budgetKey'] ?? null) || !is_array($data['schools'] ?? null) || !is_array($data['opportunities'] ?? null) || !is_array($data['classIds'] ?? null) || !array_is_list($data['classIds']) || !is_string($data['provenance'] ?? null)) throw new BadRequestHttpException('Règle de substitution invalide.');
        $grant = $this->reference(SpellSubclassGrant::class, $data['grantId'] ?? null, $actor);
        if ($grant->getOrigin()->value !== ($official ? 'OFFICIAL' : 'CUSTOM')) throw new AccessDeniedHttpException();
        $lists = array_map(fn($id) => $this->reference(CharacterClass::class, $id, $actor), $data['classIds']);
        try {
            $rule = new SpellGrantSubstitutionRule($grant, $data['version'], $data['budgetKey'], $data['schools'], $data['opportunities'], $lists, $data['provenance']);
        } catch (\InvalidArgumentException $e) {
            throw new BadRequestHttpException($e->getMessage(), $e);
        }
        return $this->em->wrapInTransaction(function () use ($rule, $grant) {
            // Same order as grant editing: grant first, then its source.
            // A competing editor must observe the new version before changing mechanics.
            $this->em->lock($grant, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($grant);
            $this->em->lock($grant->getSubclass(), LockMode::PESSIMISTIC_WRITE);
            $rule->validate();
            $prior = $this->em->getRepository(SpellGrantSubstitutionRule::class)->findBy(['grant' => $grant], ['version' => 'DESC'], 1);
            if ($prior && $prior[0]->getVersion() >= $rule->getVersion()) throw new ConflictHttpException('Version strictement croissante requise.');
            if ($prior && $prior[0]->getBudgetKey() !== $rule->getBudgetKey()) throw new ConflictHttpException('L’identité du budget partagé reste stable entre versions.');
            foreach ($this->em->getRepository(SpellGrantSubstitutionRule::class)->findBy(['budgetKey' => $rule->getBudgetKey()]) as $sibling) if ($sibling->getGrant()->getSubclass() === $grant->getSubclass() && $sibling->getOpportunities() !== $rule->getOpportunities()) throw new ConflictHttpException('Budget partagé contradictoire.');
            $this->em->persist($rule);
            $this->em->flush();
            return $rule->getId();
        });
    }
    private function requireMigration(): void
    {
        if ($this->em->getConnection()->fetchOne("SELECT to_regclass('spell_grant_substitution_rule')") === null) throw new \Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException(null, 'Administration 13C en attente de la migration validée.');
    }
}
