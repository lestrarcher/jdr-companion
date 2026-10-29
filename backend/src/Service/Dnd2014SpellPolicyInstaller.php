<?php
declare(strict_types=1);
namespace App\Service;

use App\Entity\{CharacterClass, CharacterSubclass, SpellDefinition, SpellcastingPolicyDefinition, SpellPolicyChoiceRule, SpellSubclassGrant, SpellGrantSubstitutionRule};
use App\Enum\{ReferenceOrigin, SpellGrantMode, SpellQuotaImpact, SpellcastingProgressionType};
use Doctrine\ORM\EntityManagerInterface;

/** Explicit reference bootstrap. Never writes character acquisitions or session state. */
final readonly class Dnd2014SpellPolicyInstaller
{
    public function __construct(private EntityManagerInterface $em) {}

    public function run(bool $apply = false): array
    {
        $db = $this->em->getConnection();
        $db->beginTransaction();
        try {
            // Serialize with policy administration (which locks its source).
            if ($apply) {
                $db->executeQuery('SELECT id FROM character_class ORDER BY id FOR UPDATE');
                $db->executeQuery('SELECT id FROM character_subclass ORDER BY id FOR UPDATE');
            }
            $report = ['policies'=>[], 'grants'=>[], 'substitutions'=>[], 'progressions'=>[], 'missing'=>[], 'conflicts'=>[]];
            $pending = [];
            $progressionUpdates = [];
            $reference = function (string $type, string $slug) use (&$report): ?object {
                $rows = $this->em->getRepository($type)->findBy(['slug'=>$slug, 'origin'=>ReferenceOrigin::Official, 'owner'=>null]);
                if (count($rows) !== 1) {
                    $report['missing'][] = $type.':'.$slug;
                    return null;
                }
                return $rows[0];
            };
            $classes = [];
            $bases = [];
            $install = function ($source, array $template, ?SpellcastingPolicyDefinition $base = null) use (&$report, &$pending): ?SpellcastingPolicyDefinition {
                $field = $source instanceof CharacterClass ? 'characterClass' : 'subclass';
                $existing = $this->em->getRepository(SpellcastingPolicyDefinition::class)->findBy([$field=>$source], ['version'=>'DESC'], 1);
                if ($existing) {
                    $report['policies'][] = ['source'=>$source->getSlug(), 'action'=>'preserved', 'id'=>$existing[0]->getId()];
                    return $existing[0];
                }
                $definition = new SpellcastingPolicyDefinition($source, $template['policy'], $base, 2);
                $pending[] = $definition;
                foreach ($template['rules'] as $rule) $pending[] = new SpellPolicyChoiceRule($definition, $rule['key'], $rule['parameters'], $rule['listClass'] ?? null, $rule['replacementListClass'] ?? null, $rule['castingClass'] ?? null, $rule['requiredSpell'] ?? null);
                $report['policies'][] = ['source'=>$source->getSlug(), 'action'=>'create', 'key'=>$template['policy']->key];
                return $definition;
            };
            foreach (['bard','cleric','paladin','ranger','artificer','druid','wizard','sorcerer','warlock'] as $slug) {
                $class = $reference(CharacterClass::class, $slug);
                if (!$class) continue;
                $classes[$slug] = $class;
                $template = match ($slug) {
                    'bard' => Dnd2014AdvancedSpellPolicies::bard($class),
                    'artificer' => Dnd2014AdvancedSpellPolicies::artificer($class),
                    'warlock' => Dnd2014AdvancedSpellPolicies::warlock($class),
                    default => Dnd2014AdvancedSpellPolicies::ordinary($class, Dnd2014SpellPolicies::get($slug.'-2014')),
                };
                $bases[$slug] = $install($class, $template);
            }
            foreach (['eldritch-knight'=>['fighter',false], 'arcane-trickster'=>['rogue',true]] as $slug=>[$parent,$trickster]) {
                $sub = $reference(CharacterSubclass::class, $slug);
                $hand = $trickster ? $reference(SpellDefinition::class, 'mage-hand') : null;
                if (!$sub || !isset($classes['wizard']) || ($trickster && !$hand)) continue;
                if ($sub->getCharacterClass()->getSlug() !== $parent) { $report['conflicts'][] = $slug.': parent'; continue; }
                if ($sub->getSpellcastingProgression() === null) {
                    $progressionUpdates[] = $sub;
                    $report['progressions'][] = ['source'=>$slug, 'from'=>null, 'to'=>'third'];
                } elseif ($sub->getSpellcastingProgression() !== SpellcastingProgressionType::Third) {
                    $report['conflicts'][] = $slug.': incompatible spell-slot progression';
                }
                $install($sub, Dnd2014AdvancedSpellPolicies::thirdCaster($classes['wizard'], $trickster, $hand));
            }
            foreach (['college-of-lore'=>'bard','circle-of-the-land'=>'druid','nature-domain'=>'cleric'] as $slug=>$parent) {
                $sub = $reference(CharacterSubclass::class, $slug);
                if (!$sub || !isset($bases[$parent]) || ($parent !== 'bard' && !isset($classes['druid']))) continue;
                $base = $bases[$parent];
                if ($sub->getCharacterClass() !== $classes[$parent] || $base->getFormatVersion() !== 2) { $report['conflicts'][] = $slug.': incompatible base'; continue; }
                $rules = $parent === 'bard' ? Dnd2014AdvancedSpellPolicies::lore($classes[$parent]) : [Dnd2014AdvancedSpellPolicies::landCircleCantrip($classes['druid'])];
                if ($parent === 'cleric') {
                    $rules[0]['key'] = 'nature-domain-cantrip';
                    $values = $rules[0]['parameters']->values;
                    $values['opportunities'] = [1, ...array_fill(0, 19, 0)];
                    $rules[0]['parameters'] = new \App\Dto\SpellChoiceParameters($values);
                }
                $install($sub, ['policy'=>$base->policy(), 'rules'=>$rules], $base);
            }
            foreach (Dnd2014SubclassSpellCatalogue::entries() as [$parent,$slug,$level,$spellSlug,$mode]) {
                $sub = $reference(CharacterSubclass::class, $slug);
                $spell = $reference(SpellDefinition::class, $spellSlug);
                if (!$sub || !$spell || !isset($classes[$parent])) continue;
                if ($sub->getCharacterClass() !== $classes[$parent]) { $report['conflicts'][] = $slug.': parent'; continue; }
                $grantMode = SpellGrantMode::from($mode);
                $existing = $this->em->getRepository(SpellSubclassGrant::class)->findBy(['subclass'=>$sub, 'spell'=>$spell]);
                if ($existing) {
                    $same = count($existing) === 1 && $existing[0]->getMode() === $grantMode
                        && $existing[0]->getUnlockLevel() === $level && $existing[0]->getQuotaImpact() === SpellQuotaImpact::Free
                        && $existing[0]->getCastingClass() === $classes[$parent] && $existing[0]->getRestrictions() === null;
                    if (!$same) { $report['conflicts'][] = $slug.':'.$spellSlug; continue; }
                    $grant = $existing[0];
                } else {
                    $grant = new SpellSubclassGrant($sub, $spell, $grantMode, $level, $classes[$parent]);
                    $grant->setQuotaImpact(SpellQuotaImpact::Free);
                    $pending[] = $grant;
                    $report['grants'][] = $slug.':'.$level.':'.$spellSlug;
                }
                if (in_array($slug, ['aberrant-mind','clockwork-soul'], true) && isset($classes['wizard'],$classes['warlock'])) {
                    $prior = $grant->getId() === null ? [] : $this->em->getRepository(SpellGrantSubstitutionRule::class)->findBy(['grant'=>$grant]);
                    if (!$prior) {
                        $feature = $slug === 'aberrant-mind' ? 'psionic-spells' : 'clockwork-magic';
                        $lists = [$classes['sorcerer'],$classes['warlock'],$classes['wizard']];
                        $spec = Dnd2014AdvancedSpellPolicies::sorcererSubstitution($feature, $grant->getId() ?? 1, array_map(static fn($c)=>$c->getId(), $lists));
                        $pending[] = new SpellGrantSubstitutionRule($grant,1,$feature,$spec['schools'],$spec['opportunities'],$lists,$spec['provenance']);
                        $report['substitutions'][] = $slug.':'.$spellSlug;
                    }
                }
            }
            $report['missing'] = array_values(array_unique($report['missing']));
            $report['conflicts'] = array_values(array_unique($report['conflicts']));
            $report['applied'] = $apply && !$report['missing'] && !$report['conflicts'];
            if ($report['applied']) {
                foreach ($progressionUpdates as $subclass) $subclass->setSpellcastingProgression(SpellcastingProgressionType::Third);
                foreach ($pending as $entity) $this->em->persist($entity);
                $this->em->flush();
                $db->commit();
            } else $db->rollBack();
            return $report;
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }
}
