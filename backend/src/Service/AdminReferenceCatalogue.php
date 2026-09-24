<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\{CharacterClass, CharacterSubclass, CharacterRace, Feat, TrackableResourceDefinition, ProgressionDefinition, CharacterFeatureDefinition, CharacterFeatureRule, TrackableResourceRule, CharacterClassLevelRule, CharacterActionClassRule};
use Doctrine\ORM\EntityManagerInterface;

/** Explicit catalogue of the six editorial screens; no arbitrary entity hydration. */
final readonly class AdminReferenceCatalogue
{
    public const CATEGORIES = [
        'classes' => ['entity' => CharacterClass::class, 'label' => 'Classes', 'length' => 120],
        'subclasses' => ['entity' => CharacterSubclass::class, 'label' => 'Sous-classes', 'length' => 120],
        'races' => ['entity' => CharacterRace::class, 'label' => 'Races', 'length' => 120],
        'feats' => ['entity' => Feat::class, 'label' => 'Dons', 'length' => 120],
        'resources' => ['entity' => TrackableResourceDefinition::class, 'label' => 'Ressources', 'length' => 150],
        'progressions' => ['entity' => ProgressionDefinition::class, 'label' => 'Progressions', 'length' => 120],
    ];

    public function __construct(private EntityManagerInterface $em) {}

    public function counts(): array
    {
        $counts = ['features' => $this->em->getRepository(CharacterFeatureDefinition::class)->count([])];
        foreach (self::CATEGORIES as $key => $definition) $counts[$key] = $this->em->getRepository($definition['entity'])->count([]);
        return $counts;
    }

    public function find(string $category, int $id): ?object
    {
        return $this->em->find(self::CATEGORIES[$category]['entity'], $id);
    }

    public function filters(string $category): array
    {
        $custom = ['label' => 'Origine du contenu', 'choices' => ['0' => 'Référentiel', '1' => 'Personnalisé']];
        $filters = ['custom' => $custom];
        if ($category === 'subclasses') $filters['class'] = ['label' => 'Classe', 'choices' => $this->choices(CharacterClass::class)];
        if ($category === 'races') {
            $filters['parent'] = ['label' => 'Race parente', 'choices' => $this->choices(CharacterRace::class)];
            $filters['selectable'] = ['label' => 'Sélectionnable', 'choices' => ['1' => 'Oui', '0' => 'Non']];
        }
        if ($category === 'resources') {
            $filters['recharge'] = ['label' => 'Restauration', 'choices' => array_column(array_map(fn ($e) => ['value' => $e->value, 'label' => $e->value], \App\Enum\ResourceRechargeType::cases()), 'label', 'value')];
        }
        return $filters;
    }

    private function choices(string $entity): array
    {
        $rows = $this->em->createQueryBuilder()->select('e.id, e.name')->from($entity, 'e')->orderBy('e.name')->getQuery()->getArrayResult();
        return array_column($rows, 'name', 'id');
    }

    public function context(array $params, array $filters): array
    {
        $q = $params['q'] ?? '';
        if (!is_string($q) || mb_strlen($q) > 200 || str_contains($q, "\0")) throw new \InvalidArgumentException('Recherche invalide (200 caractères maximum).');
        $page = filter_var($params['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000000]]);
        if ($page === false) throw new \InvalidArgumentException('Pagination invalide.');
        $context = ['page' => $page];
        if (trim($q) !== '') $context['q'] = trim($q);
        foreach ($filters as $key => $filter) {
            if (!isset($params[$key]) || $params[$key] === '') continue;
            if (!is_string($params[$key]) || !array_key_exists($params[$key], $filter['choices'])) throw new \InvalidArgumentException('Filtre invalide : '.$filter['label'].'.');
            $context[$key] = $params[$key];
        }
        return $context;
    }

    public function search(string $category, array $context): array
    {
        $query = $this->em->createQueryBuilder()->from(self::CATEGORIES[$category]['entity'], 'e');
        if (isset($context['q'])) {
            $query->andWhere("(LOWER(e.name) LIKE :q ESCAPE '!' OR LOWER(e.slug) LIKE :q ESCAPE '!')")
                ->setParameter('q', '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($context['q'])).'%');
        }
        if (isset($context['custom'])) $query->andWhere('e.custom = :custom')->setParameter('custom', $context['custom'] === '1');
        if ($category === 'subclasses' && isset($context['class'])) $query->andWhere('e.characterClass = :class')->setParameter('class', (int) $context['class']);
        if ($category === 'races' && isset($context['parent'])) $query->andWhere('e.parentRace = :parent')->setParameter('parent', (int) $context['parent']);
        if ($category === 'races' && isset($context['selectable'])) $query->andWhere('e.selectable = :selectable')->setParameter('selectable', $context['selectable'] === '1');
        if ($category === 'resources' && isset($context['recharge'])) $query->andWhere('e.rechargeType = :recharge')->setParameter('recharge', $context['recharge']);
        $total = (int) (clone $query)->select('COUNT(e.id)')->getQuery()->getSingleScalarResult();
        $rows = $query->select('e')->orderBy('e.name')->addOrderBy('e.id')->setMaxResults(25)->setFirstResult(($context['page'] - 1) * 25)->getQuery()->getResult();
        return ['rows' => $rows, 'total' => $total, 'page' => $context['page']];
    }

    public function values(object $entity): array
    {
        $values = ['name' => $entity->getName(), 'description' => $entity->getDescription() ?? ''];
        if ($entity instanceof ProgressionDefinition) $values += ['gainLabel' => $entity->getGainLabel() ?? '', 'spendLabel' => $entity->getSpendLabel() ?? ''];
        return $values;
    }

    public function updateEditorial(string $category, object $entity, array $payload): void
    {
        $expected = self::CATEGORIES[$category]['entity'];
        if (!$entity instanceof $expected) throw new \LogicException('Catégorie incompatible.');
        $allowed = $category === 'progressions' ? ['name', 'description', 'gainLabel', 'spendLabel'] : ['name', 'description'];
        $unknown = array_diff(array_keys($payload), $allowed);
        if ($unknown) throw new \InvalidArgumentException('Champs non modifiables : '.implode(', ', $unknown).'.');
        foreach ($allowed as $field) {
            if (!isset($payload[$field]) || !is_string($payload[$field]) || str_contains($payload[$field], "\0")) throw new \InvalidArgumentException('Texte invalide : '.$field.'.');
        }
        if (trim($payload['name']) === '' || mb_strlen(trim($payload['name'])) > self::CATEGORIES[$category]['length']) throw new \InvalidArgumentException('Nom obligatoire, limité à '.self::CATEGORIES[$category]['length'].' caractères.');
        foreach (['gainLabel', 'spendLabel'] as $field) if (isset($payload[$field]) && mb_strlen(trim($payload[$field])) > 80) throw new \InvalidArgumentException('Libellé limité à 80 caractères.');
        // No setter is called until all fields have passed validation.
        $entity->setName($payload['name']);
        $entity->setDescription($payload['description']);
        if ($entity instanceof ProgressionDefinition) {
            $entity->setGainLabel($payload['gainLabel']);
            $entity->setSpendLabel($payload['spendLabel']);
        }
        $this->em->flush();
    }

    public function detail(string $category, object $entity): array
    {
        $fields = ['ID' => $entity->getId(), 'Slug' => $entity->getSlug(), 'Personnalisé (custom)' => $entity->isCustom(), 'Création' => $entity->getCreatedAt()->format('Y-m-d H:i:s'), 'Modification' => $entity->getUpdatedAt()->format('Y-m-d H:i:s')];
        $groups = [];
        $tables = [];
        switch ($category) {
            case 'classes':
                $fields += ['Dé de vie' => 'd'.$entity->getHitDie(), 'Sélection de sous-classe au niveau' => $entity->getSubclassSelectionLevel(), 'Progression magique' => $entity->getSpellcastingProgression()];
                $groups['Sous-classes'] = $this->links($this->em->getRepository(CharacterSubclass::class)->findBy(['characterClass' => $entity], ['name' => 'ASC']), 'subclasses');
                foreach ($this->em->getRepository(CharacterClassLevelRule::class)->findBy(['characterClass' => $entity], ['level' => 'ASC']) as $rule) {
                    $tables['Règles de niveau'][] = ['ID' => $rule->getId(), 'Niveau' => $rule->getLevel(), 'Choix' => $rule->getAdvancementChoice(), 'Notes' => $rule->getNotes()];
                }
                foreach ($this->em->getRepository(CharacterActionClassRule::class)->findBy(['characterClass' => $entity], ['unlockLevel' => 'ASC']) as $rule) {
                    $action = $rule->getActionDefinition();
                    $tables['Actions métier'][] = ['Règle / action ID' => $rule->getId().' / '.$action->getId(), 'Nom' => $action->getName(), 'Slug' => $action->getSlug(), 'Description' => $action->getDescription(), 'Niveau' => $rule->getUnlockLevel(), 'Traitement' => $action->getHandlerType(), 'Préparation requise' => $action->requiresPreparation(), 'Active' => $action->isActive(), 'Personnalisée' => $action->isCustom()];
                }
                break;
            case 'subclasses':
                $groups['Classe parente'] = [$this->link($entity->getCharacterClass(), 'classes')];
                $fields += ['Progression magique spécifique' => $entity->getSpellcastingProgression() ?? 'Héritée de la classe', 'Niveau de sélection (classe)' => $entity->getCharacterClass()->getSubclassSelectionLevel()];
                break;
            case 'races':
                if ($entity->getParentRace()) $groups['Race parente'] = [$this->link($entity->getParentRace(), 'races')];
                $groups['Variantes'] = $this->links($this->em->getRepository(CharacterRace::class)->findBy(['parentRace' => $entity], ['name' => 'ASC']), 'races');
                $fields += ['Sélectionnable' => $entity->isSelectable(), 'Tailles' => $entity->getSizeOptions(), 'Vitesse de marche' => $entity->getWalkingSpeed(), 'Autres déplacements' => $entity->getMovementSpeeds(), 'Langues' => $entity->getLanguages(), 'Choix de langues' => $entity->getLanguageChoiceCount(), 'Sens' => $entity->getSenses(), 'Résistances' => $entity->getDamageResistances(), 'Immunités aux dégâts' => $entity->getDamageImmunities(), 'Immunités aux états' => $entity->getConditionImmunities(), 'Choix de dons' => $entity->getFeatChoiceCount()];
                foreach ($entity->getAbilityModifiers() as $modifier) $tables['Modificateurs raciaux propres'][] = ['ID' => $modifier->getId(), 'Caractéristique' => $modifier->getAbility(), 'Valeur' => $modifier->getValue(), 'Clé de choix' => $modifier->getChoiceKey()];
                break;
            case 'feats':
                $fields += ['Répétable' => $entity->isRepeatable(), 'Choix de caractéristique' => $entity->requiresAbilityChoice(), 'Bonus choisi' => $entity->getChosenAbilityIncrease(), 'Caractéristiques autorisées' => $entity->getAllowedAbilities()];
                break;
            case 'resources':
                $fields += ['Restauration' => $entity->getRechargeType(), 'Type de maximum' => $entity->getMaximumType(), 'Maximum de base' => $entity->getBaseMaximum(), 'Multiplicateur' => $entity->getMultiplier(), 'Minimum du maximum' => $entity->getMinimumMaximum(), 'Caractéristique de calcul' => $entity->getScalingAbility()];
                $groups['Capacités utilisant la ressource'] = $this->links($this->em->getRepository(CharacterFeatureDefinition::class)->findBy(['resourceDefinition' => $entity], ['name' => 'ASC']), 'features');
                $providers = $this->em->createQueryBuilder()->select('r, f')->from(CharacterFeatureRule::class, 'r')->join('r.featureDefinition', 'f')
                    ->where('f.resourceDefinition = :resource')->setParameter('resource', $entity)->orderBy('r.id')->getQuery()->getResult();
                foreach ($providers as $rule) {
                    $sourceEntity = match ($rule->sourceType()) {
                        'class' => $rule->getCharacterClass(), 'subclass' => $rule->getCharacterSubclass(),
                        'race' => $rule->getCharacterRace(), 'feat' => $rule->getFeat(), 'progression' => $rule->getProgressionDefinition(),
                    };
                    $sourceCategory = ['class' => 'classes', 'subclass' => 'subclasses', 'race' => 'races', 'feat' => 'feats', 'progression' => 'progressions'][$rule->sourceType()];
                    $groups['Fournie par les attributions de capacités'][] = $this->link($sourceEntity, $sourceCategory, $rule->getFeatureDefinition()->getName().' · '.($rule->sourceType() === 'progression' ? 'Seuil : '.$rule->getProgressionThreshold() : 'Niveau : '.$rule->getUnlockLevel()));
                }
                foreach ($this->em->getRepository(TrackableResourceRule::class)->findBy(['resourceDefinition' => $entity], ['unlockLevel' => 'ASC', 'id' => 'ASC']) as $rule) $groups['Règles de maximum'][] = $this->resourceSource($rule);
                break;
            case 'progressions':
                $fields += ['Minimum' => $entity->getMinimumValue(), 'Maximum' => $entity->getMaximumValue(), 'Couleur' => $entity->getAccentColor(), 'Ajustement important activé' => $entity->isBulkAdjustmentEnabled()];
                foreach ($entity->getStages() as $stage) $tables['Paliers'][] = ['ID' => $stage->getId(), 'Libellé' => $stage->getLabel(), 'Description' => $stage->getDescription(), 'Minimum' => $stage->getMinimumValue(), 'Maximum' => $stage->getMaximumValue(), 'Icône (chemin)' => $stage->getIconUrl(), 'Ordre' => $stage->getDisplayOrder()];
                foreach ($entity->getAdjustmentRules() as $rule) $tables['Règles descriptives d’ajustement'][] = ['ID' => $rule->getId(), 'Direction' => $rule->getDirection(), 'Déclencheur' => $rule->getTriggerType(), 'Description' => $rule->getDescription(), 'Ajustement' => $rule->getAdjustmentLabel(), 'Ordre' => $rule->getDisplayOrder()];
                break;
        }
        $source = ['classes' => 'characterClass', 'subclasses' => 'characterSubclass', 'races' => 'characterRace', 'feats' => 'feat', 'progressions' => 'progressionDefinition'][$category] ?? null;
        if ($source) {
            foreach ($this->em->getRepository(CharacterFeatureRule::class)->findBy([$source => $entity], ['displayOrder' => 'ASC', 'id' => 'ASC']) as $rule) {
                $note = 'Règle #'.$rule->getId().' · '.($category === 'progressions' ? 'Seuil : '.$rule->getProgressionThreshold() : 'Niveau : '.$rule->getUnlockLevel());
                $feature = $rule->getFeatureDefinition();
                $groups['Capacités attribuées'][] = $this->link($feature, 'features', $note);
                if ($feature->getResourceDefinition()) $groups['Ressources via capacités'][] = $this->link($feature->getResourceDefinition(), 'resources', $feature->getName().' · '.$note);
            }
            if ($category !== 'progressions') {
                foreach ($this->em->getRepository(TrackableResourceRule::class)->findBy([$source => $entity], ['unlockLevel' => 'ASC', 'id' => 'ASC']) as $rule) $groups['Règles de ressource'][] = $this->link($rule->getResourceDefinition(), 'resources', $this->resourceNote($rule));
            }
        }
        $fields = array_map($this->text(...), $fields);
        foreach ($tables as &$rows) foreach ($rows as &$row) $row = array_map($this->text(...), $row);
        return ['fields' => $fields, 'groups' => $groups, 'tables' => $tables];
    }

    private function resourceNote(TrackableResourceRule $rule): string
    {
        return 'Règle #'.$rule->getId().' · Niveau : '.$rule->getUnlockLevel().' · Maximum imposé : '.($rule->getMaximumOverride() ?? 'Aucun').' · Bonus : '.$rule->getMaximumBonus();
    }

    private function resourceSource(TrackableResourceRule $rule): array
    {
        foreach (['classes' => $rule->getCharacterClass(), 'subclasses' => $rule->getCharacterSubclass(), 'races' => $rule->getCharacterRace(), 'feats' => $rule->getFeat()] as $category => $entity) {
            if ($entity) return $this->link($entity, $category, $this->resourceNote($rule));
        }
        throw new \LogicException('Règle sans origine.');
    }

    private function links(array $entities, string $category): array
    {
        return array_map(fn ($entity) => $this->link($entity, $category), $entities);
    }

    private function link(object $entity, string $category, string $note = ''): array
    {
        return ['category' => $category, 'id' => $entity->getId(), 'name' => $entity->getName(), 'note' => $note];
    }

    private function text(mixed $value): string
    {
        if ($value === null) return 'Non renseigné';
        if (is_bool($value)) return $value ? 'Oui' : 'Non';
        if ($value instanceof \BackedEnum) return $value->value;
        if (is_array($value)) {
            if (!$value) return 'Aucun';
            $items = [];
            foreach ($value as $key => $item) $items[] = (is_string($key) ? $key.' : ' : '').$this->text($item);
            return implode(' · ', $items);
        }
        return (string) $value;
    }
}
