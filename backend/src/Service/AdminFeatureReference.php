<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\{CharacterClass, CharacterSubclass, CharacterRace, Feat, ProgressionDefinition, CharacterFeatureDefinition, CharacterFeatureRule, TrackableResourceRule};
use Doctrine\ORM\EntityManagerInterface;

final readonly class AdminFeatureReference
{
    public const SOURCES = [
        'class' => 'characterClass', 'subclass' => 'characterSubclass',
        'race' => 'characterRace', 'feat' => 'feat', 'progression' => 'progressionDefinition',
    ];

    public function __construct(private EntityManagerInterface $em) {}

    public function find(int $id): ?CharacterFeatureDefinition
    {
        return $this->em->find(CharacterFeatureDefinition::class, $id);
    }

    public function updateEditorial(CharacterFeatureDefinition $feature, array $payload): void
    {
        $unknown = array_diff(array_keys($payload), ['name', 'description']);
        if ($unknown !== []) throw new \InvalidArgumentException('Champs non modifiables : '.implode(', ', $unknown).'.');
        $name = $payload['name'] ?? null;
        $description = $payload['description'] ?? null;
        if (!is_string($name) || trim($name) === '' || mb_strlen(trim($name)) > 150 || str_contains($name, "\0")) {
            throw new \InvalidArgumentException('Le nom doit contenir entre 1 et 150 caractères et aucun caractère nul.');
        }
        if (!array_key_exists('description', $payload) || !is_string($description) || str_contains($description, "\0")) {
            throw new \InvalidArgumentException('La description doit être un texte sans caractère nul.');
        }
        // Validate both fields before changing the managed definition.
        $feature->setName($name);
        $feature->setDescription($description);
        $this->em->flush();
    }

    public function search(string $search, array $filters, int $page, int $pageSize): array
    {
        $query = $this->em->createQueryBuilder()
            ->from(CharacterFeatureDefinition::class, 'f')
            ->leftJoin(CharacterFeatureRule::class, 'r', 'WITH', 'r.featureDefinition = f')
            ->leftJoin('r.characterSubclass', 'sub');
        if ($search !== '') {
            // Treat LIKE metacharacters as literal search text.
            $query->andWhere("(LOWER(f.name) LIKE :search ESCAPE '!' OR LOWER(f.slug) LIKE :search ESCAPE '!')")
                ->setParameter('search', '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search)).'%');
        }
        foreach (self::SOURCES as $type => $field) {
            if (isset($filters[$type])) {
                $condition = $type === 'class'
                    ? '(r.characterClass = :class OR sub.characterClass = :class)'
                    : 'r.'.$field.' = :'.$type;
                $query->andWhere($condition)->setParameter($type, $filters[$type]);
            }
        }
        if (isset($filters['sourceType'])) {
            $query->andWhere('r.'.self::SOURCES[$filters['sourceType']].' IS NOT NULL');
        }
        $total = (int) (clone $query)->select('COUNT(DISTINCT f.id)')->getQuery()->getSingleScalarResult();
        // Paginate definitions, never joined attribution rows.
        $rows = $query->select('DISTINCT f.id, f.name')->orderBy('f.name', 'ASC')->addOrderBy('f.id', 'ASC')
            ->setFirstResult(($page - 1) * $pageSize)->setMaxResults($pageSize)->getQuery()->getArrayResult();
        $ids = array_column($rows, 'id');
        $features = $ids === [] ? [] : $this->em->getRepository(CharacterFeatureDefinition::class)->findBy(['id' => $ids], ['name' => 'ASC', 'id' => 'ASC']);
        $rules = $this->rules($ids);
        return [
            'features' => array_map(fn ($f) => [
                'id' => $f->getId(), 'name' => $f->getName(), 'slug' => $f->getSlug(),
                'origins' => $rules[$f->getId()] ?? [],
            ], $features),
            'total' => $total, 'page' => $page, 'pageSize' => $pageSize,
        ];
    }

    public function filterOptions(): array
    {
        $options = [];
        foreach (['class' => CharacterClass::class, 'subclass' => CharacterSubclass::class, 'race' => CharacterRace::class, 'feat' => Feat::class, 'progression' => ProgressionDefinition::class] as $type => $entity) {
            $query = $this->em->createQueryBuilder()->select('s.id, s.name')->from($entity, 's')->orderBy('s.name', 'ASC')->addOrderBy('s.id', 'ASC');
            if ($type === 'subclass') {
                $query->addSelect('IDENTITY(s.characterClass) AS classId, c.name AS className')->join('s.characterClass', 'c');
            }
            $options[$type] = $query->getQuery()->getArrayResult();
        }
        return $options;
    }

    public function detail(CharacterFeatureDefinition $feature): array
    {
        $resource = $feature->getResourceDefinition();
        $resourceData = null;
        if ($resource !== null) {
            $rules = $this->em->getRepository(TrackableResourceRule::class)->findBy(['resourceDefinition' => $resource], ['unlockLevel' => 'ASC', 'id' => 'ASC']);
            $resourceData = [
                'id' => $resource->getId(), 'slug' => $resource->getSlug(), 'name' => $resource->getName(),
                'rechargeType' => $resource->getRechargeType()->value,
                'maximumType' => $resource->getMaximumType()->value,
                'baseMaximum' => $resource->getBaseMaximum(), 'multiplier' => $resource->getMultiplier(),
                'minimumMaximum' => $resource->getMinimumMaximum(), 'scalingAbility' => $resource->getScalingAbility()?->value,
                'rules' => array_map(fn ($r) => [
                    ...$this->origin($r), 'maximumOverride' => $r->getMaximumOverride(), 'maximumBonus' => $r->getMaximumBonus(),
                ], $rules),
            ];
        }
        return [
            'id' => $feature->getId(), 'slug' => $feature->getSlug(), 'name' => $feature->getName(),
            'description' => $feature->getDescription(), 'activationLabel' => $feature->getActivationType()->label(),
            'visible' => $feature->isVisible(), 'custom' => $feature->isCustom(),
            'origins' => $this->rules([$feature->getId()])[$feature->getId()] ?? [],
            'resource' => $resourceData,
        ];
    }

    private function rules(array $ids): array
    {
        if ($ids === []) return [];
        $query = $this->em->createQueryBuilder()->select('r, c, s, sc, race, feat, p')
            ->from(CharacterFeatureRule::class, 'r')
            ->leftJoin('r.characterClass', 'c')->leftJoin('r.characterSubclass', 's')->leftJoin('s.characterClass', 'sc')
            ->leftJoin('r.characterRace', 'race')->leftJoin('r.feat', 'feat')->leftJoin('r.progressionDefinition', 'p')
            ->where('r.featureDefinition IN (:ids)')->setParameter('ids', $ids)
            ->orderBy('r.displayOrder', 'ASC')->addOrderBy('r.unlockLevel', 'ASC')->addOrderBy('r.id', 'ASC');
        $result = [];
        foreach ($query->getQuery()->getResult() as $rule) {
            $result[$rule->getFeatureDefinition()->getId()][] = $this->origin($rule);
        }
        return $result;
    }

    private function origin(CharacterFeatureRule|TrackableResourceRule $rule): array
    {
        $ref = static fn ($entity) => $entity === null ? null : ['id' => $entity->getId(), 'name' => $entity->getName()];
        $progression = $rule instanceof CharacterFeatureRule ? $rule->getProgressionDefinition() : null;
        $subclass = $rule->getCharacterSubclass();
        return [
            'id' => $rule->getId(),
            'class' => $ref($rule->getCharacterClass() ?? $subclass?->getCharacterClass()),
            'subclass' => $ref($subclass), 'race' => $ref($rule->getCharacterRace()), 'feat' => $ref($rule->getFeat()),
            'progression' => $ref($progression),
            'sourceType' => $rule instanceof CharacterFeatureRule ? $rule->sourceType() : match (true) {
                $rule->getCharacterClass() !== null => 'class', $subclass !== null => 'subclass',
                $rule->getCharacterRace() !== null => 'race', default => 'feat',
            },
            'unlockLevel' => $progression === null ? $rule->getUnlockLevel() : null,
            'progressionThreshold' => $rule instanceof CharacterFeatureRule ? $rule->getProgressionThreshold() : null,
        ];
    }
}
