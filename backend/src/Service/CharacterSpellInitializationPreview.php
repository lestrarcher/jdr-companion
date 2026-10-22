<?php
declare(strict_types=1);
namespace App\Service;

use App\Entity\{Character, SpellcastingPolicyDefinition, User};
use Doctrine\ORM\EntityManagerInterface;

/** Current, persisted levels only. This projection never reconstructs missing history. */
final readonly class CharacterSpellInitializationPreview
{
    public function __construct(
        private EntityManagerInterface $em,
        private CharacterSpellAcquisitions $acquisitions,
        private CharacterSpellAccessResolver $access,
    ) {}

    public function preview(Character $character, User $actor): array
    {
        // Authorize before reading any catalogue or character data.
        $statuses = $this->acquisitions->status($character, $actor);
        $classes = [];
        foreach ($statuses as $initialStatus) {
            $levels = array_values(array_filter($character->getClassLevels()->toArray(),
                static fn ($level) => $level->getCharacterClass()->getId() === $initialStatus['classId']));
            $class = $levels[0]->getCharacterClass();
            $status = $this->acquisitions->levelUpStatus($character, $class, $actor);
            $subclasses = [];
            foreach ($levels as $level) {
                $sub = $level->getSubclass();
                if ($sub !== null && ReferenceVisibility::allows($sub, $actor)) {
                    $subclasses[$sub->getId()] = ['id'=>$sub->getId(), 'name'=>$sub->getName()];
                }
            }
            $policies = [];
            foreach ($status['policyIds'] as $id) {
                $policy = $this->em->find(SpellcastingPolicyDefinition::class, $id);
                $policies[] = ['id'=>$id, 'version'=>$policy->policy()->version,
                    'formatVersion'=>$policy->getFormatVersion(), 'sourceName'=>$policy->getSource()->getName(),
                    'subclassId'=>$policy->getSubclass()?->getId(), 'basePolicyId'=>$policy->getBasePolicy()?->getId()];
            }
            $options = null;
            $restrictions = [];
            if ($status['status'] === 'policy-configured') {
                $options = $this->acquisitions->options($character, $status['policyIds'][0], $actor);
                $policy = $this->em->find(SpellcastingPolicyDefinition::class, $status['policyIds'][0]);
                if ($policy->getFormatVersion() === 2) {
                    foreach ((new AdvancedSpellChoices($this->em, $this->access))->rules($policy) as $id => $rule) {
                        $restrictions[] = ['ruleId'=>$id, 'key'=>$rule->getKey(), 'parameters'=>$rule->parameters()->values];
                    }
                }
            }
            $classes[] = $status + ['classLevel'=>count($levels), 'subclasses'=>array_values($subclasses),
                'policies'=>$policies, 'options'=>$options, 'restrictions'=>$restrictions,
                'optionsScope'=>'current-persisted-class-level'];
        }
        $grants = [];
        $listAccess = [];
        foreach ($this->access->resolve($character) as $path) {
            if (in_array($path->mode, ['grant-known', 'grant-always-prepared', 'grant-cast'], true)) {
                $grants[] = get_object_vars($path);
            } else {
                $listAccess[] = get_object_vars($path);
            }
        }
        return ['characterId'=>$character->getId(), 'classes'=>$classes, 'grants'=>$grants,
            'listAccess'=>$listAccess, 'historicalChoicesReconstructed'=>false, 'canCast'=>null];
    }
}
