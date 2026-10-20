<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\ResolvedSpellAccess;
use App\Entity\Character;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Read-only reference resolution. No session lookup, flush, slot or acquisition logic. */
final readonly class CharacterSpellAccessResolver
{
    public function __construct(
        private SpellCatalogue $catalogue,
        private SpellSubclassGrants $grants,
        private CharacterResourceResolver $resources,
    ) {}

    /**
     * @param array<string, int> $progressionValues Explicit context for existing resource resolution only.
     * @return list<ResolvedSpellAccess> One item per list entry or grant; never grouped by spell.
     */
    public function resolve(Character $character, array $progressionValues = [], ?int $throughPosition = null): array
    {
        $owner = $character->getCampaign()->getOwner();
        $visibleClasses = array_column($this->catalogue->classOptions($owner), null, 'id');
        $classes = []; $subclasses = [];
        foreach ($character->getClassLevels() as $level) {
            $class = $level->getCharacterClass();
            $id = $class->getId();
            if ($id === null || isset($classes[$id]) || !isset($visibleClasses[$id]) || !ReferenceVisibility::allows($class, $owner)) continue;
            $classes[$id] = ['id' => $id, 'name' => $visibleClasses[$id]['name'], 'characterLevel' => $character->getLevelInClass($class)];
            // Ambiguous legacy selections must not silently choose one subclass's grants.
            $choices = [];
            foreach ($character->getClassLevels() as $classLevel) {
                if ($classLevel->getCharacterClass() === $class && $classLevel->getSubclass() !== null) $choices[spl_object_id($classLevel->getSubclass())] = true;
            }
            $subclass = $character->getSubclassFor($class);
            if (count($choices) !== 1 || $subclass === null || $subclass->getId() === null
                || $subclass->getCharacterClass() !== $class || !ReferenceVisibility::allows($subclass, $owner)) continue;
            $subclasses[$subclass->getId()] = $id;
        }
        if ($classes === []) return [];

        $result = []; $spells = [];
        foreach ($this->catalogue->entriesForClasses(array_keys($classes), $owner) as $entry) {
            $spell = $entry['spell']; $spells[$spell['id']] = $spell;
            $class = $classes[$entry['classId']];
            $result[] = new ResolvedSpellAccess(
                key: 'class-list:'.$entry['id'], spell: $this->spellSummary($spell), mode: 'class-list',
                source: ['type' => 'class-list', 'id' => $entry['id'], 'origin' => $spell['origin'], 'classId' => $class['id'], 'className' => $class['name'], 'classLevel' => $class['characterLevel'], 'subclassId' => null, 'subclassName' => null, 'unlockLevel' => null],
                castingClass: $class,
            );
        }

        $resourceDefinitions = null;
        foreach ($this->grants->forSubclasses(array_keys($subclasses), $owner) as $grant) {
            $sourceClassId = $subclasses[$grant['subclassId']];
            // Both the in-memory character and the stored source must agree on the parent.
            if ($sourceClassId !== $grant['activationClassId'] || $classes[$sourceClassId]['characterLevel'] < $grant['unlockLevel']) continue;
            $spellId = $grant['spellId'];
            if (!array_key_exists($spellId, $spells)) {
                try { $spells[$spellId] = $this->catalogue->get($spellId, $owner); }
                catch (NotFoundHttpException) { $spells[$spellId] = null; }
            }
            if ($spells[$spellId] === null) continue;
            try { $substitution=$this->grants->substitution($character->getId(),$grant['id'],$owner,$throughPosition); }
            catch (NotFoundHttpException) { continue; }
            $castingClassId = $grant['castingClassId'];
            $castingClass = $castingClassId === null ? null : ['id' => $castingClassId, 'name' => $grant['castingClassName'], 'characterLevel' => $classes[$castingClassId]['characterLevel'] ?? 0];
            $resource = null;
            if ($grant['resourceId'] !== null) {
                if ($resourceDefinitions === null) {
                    $resourceDefinitions = [];
                    foreach ($this->resources->resolve($character, $progressionValues) as $resolved) {
                        $definition = $resolved->getDefinition();
                        if (ReferenceVisibility::allows($definition, $owner)) $resourceDefinitions[$definition->getId()] = $resolved;
                    }
                }
                $resolved = $resourceDefinitions[$grant['resourceId']] ?? null;
                $resource = ['id' => $grant['resourceId'], 'name' => $grant['resourceName'], 'cost' => $grant['resourceCost'], 'resolvedForCharacter' => $resolved !== null, 'maximum' => $resolved?->getMaximum(), 'current' => null];
            }
            $result[] = new ResolvedSpellAccess(
                key: 'subclass-grant:'.$grant['id'], spell: $this->spellSummary($substitution['spell']??$spells[$spellId]), mode: $grant['mode'],
                source: ['type' => 'subclass-grant', 'id' => $grant['id'], 'origin' => $grant['origin'], 'classId' => $sourceClassId, 'className' => $grant['activationClassName'], 'classLevel' => $classes[$sourceClassId]['characterLevel'], 'subclassId' => $grant['subclassId'], 'subclassName' => $grant['subclassName'], 'unlockLevel' => $grant['unlockLevel']],
                castingClass: $castingClass, slotUsage: $grant['slotUsage'], restrictions: $grant['restrictions'], resource: $resource,
                quotaImpact: $grant['quotaImpact'],
                substitution: $substitution===null?null:array_diff_key($substitution,['spell'=>true]),
            );
        }
        usort($result, static fn (ResolvedSpellAccess $a, ResolvedSpellAccess $b): int => [$a->spell['id'], $a->source['type'], $a->source['id']] <=> [$b->spell['id'], $b->source['type'], $b->source['id']]);
        return $result;
    }

    private function spellSummary(array $spell): array
    {
        return array_intersect_key($spell, array_flip(['id', 'slug', 'name', 'level', 'school', 'edition', 'origin']));
    }
}
