<?php
declare(strict_types=1);
namespace App\Service;

use App\Dto\SpellcastingPolicy;
use App\Entity\{Character, CharacterClass, CharacterSubclass, SpellcastingPolicyDefinition, User};
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\LockMode;
use Symfony\Component\HttpKernel\Exception\{AccessDeniedHttpException, BadRequestHttpException, ConflictHttpException, NotFoundHttpException};

/** Standalone GM domain boundary. No player-token authorization, session writes or implicit choices. */
final readonly class CharacterSpellAcquisitions
{
    public function __construct(private EntityManagerInterface $em, private CharacterSpellAccessResolver $access, private CharacterAbilityCalculator $abilityCalculator = new CharacterAbilityCalculator()) {}

    /** Current resolved character values, not a reconstruction of historical ability scores. */
    private function spellStatistics(Character $character, SpellcastingPolicy $policy): array
    {
        foreach ($character->getAbilityScores() as $score) {
            if ($score->getAbility() !== $policy->ability) continue;
            $attack = $character->getProficiencyBonus()
                + $this->abilityCalculator->calculate($character, $policy->ability)->modifier();
            return ['spellSaveDc' => 8 + $attack, 'spellAttackBonus' => $attack];
        }

        // Do not call getAbilityScore(): its legacy fallback creates a default score.
        return ['spellSaveDc' => null, 'spellAttackBonus' => null];
    }

    /** Explicit reference administration, never binds a policy by a class name. */
    public function definePolicy(CharacterClass|CharacterSubclass $source, SpellcastingPolicy $policy, User $actor): SpellcastingPolicyDefinition
    {
        $owner = $source->getOwner();
        if ($source->getOrigin()->value === 'OFFICIAL') {
            if (!in_array(SpellCatalogue::ADMIN_ROLE, $actor->getRoles(), true)) throw new AccessDeniedHttpException();
        } elseif ($owner?->getId() !== $actor->getId()) throw new NotFoundHttpException();
        if ($source instanceof CharacterSubclass && !ReferenceVisibility::allows($source->getCharacterClass(), $owner)) throw new NotFoundHttpException();
        return $this->em->wrapInTransaction(function () use ($source, $policy) {
            $this->em->lock($source, LockMode::PESSIMISTIC_WRITE);
            $field = $source instanceof CharacterClass ? 'characterClass' : 'subclass';
            $prior = $this->em->getRepository(SpellcastingPolicyDefinition::class)->findBy([$field => $source], ['version' => 'DESC']);
            if ($prior && $policy->version <= $prior[0]->policy()->version) throw new ConflictHttpException('Une nouvelle version strictement supérieure est requise.');
            $definition = new SpellcastingPolicyDefinition($source, $policy);
            $this->em->persist($definition);
            return $definition;
        });
    }

    /** Rules are supplied as typed references, never identifiers hidden in JSON. */
    public function defineAdvancedPolicy(CharacterClass|CharacterSubclass $source, SpellcastingPolicy $policy, User $actor, array $rules, ?SpellcastingPolicyDefinition $base=null): SpellcastingPolicyDefinition
    {
        if ($source->getOrigin()->value==='OFFICIAL') {
            if (!in_array(SpellCatalogue::ADMIN_ROLE,$actor->getRoles(),true)) throw new AccessDeniedHttpException();
        } elseif ($source->getOwner()?->getId()!==$actor->getId()) throw new NotFoundHttpException();
        if ($source instanceof CharacterSubclass && !ReferenceVisibility::allows($source->getCharacterClass(),$source->getOwner())) throw new NotFoundHttpException();
        if (!$rules || !array_is_list($rules)) throw new BadRequestHttpException('Règles explicites requises.');
        return $this->em->wrapInTransaction(function () use ($source,$policy,$rules,$base) {
            $this->em->lock($source,LockMode::PESSIMISTIC_WRITE);
            $field=$source instanceof CharacterClass?'characterClass':'subclass';
            $prior=$this->em->getRepository(SpellcastingPolicyDefinition::class)->findBy([$field=>$source],['version'=>'DESC'],1);
            if ($prior && $policy->version<=$prior[0]->policy()->version) throw new ConflictHttpException('Version strictement supérieure requise.');
            $definition=new SpellcastingPolicyDefinition($source,$policy,$base,2); $this->em->persist($definition);
            foreach ($rules as $r) {
                if (!is_array($r) || array_diff(array_keys($r),['key','parameters','listClass','replacementListClass','castingClass','requiredSpell']) || !is_string($r['key']??null) || !($r['parameters']??null) instanceof \App\Dto\SpellChoiceParameters) throw new BadRequestHttpException('Règle invalide.');
                $this->em->persist(new \App\Entity\SpellPolicyChoiceRule($definition,$r['key'],$r['parameters'],$r['listClass']??null,$r['replacementListClass']??null,$r['castingClass']??null,$r['requiredSpell']??null));
            }
            $this->em->flush();
            (new AdvancedSpellChoices($this->em,$this->access))->rules($definition);
            return $definition;
        });
    }

    private function authorize(Character $character, User $actor): void
    {
        if ($actor->getId() === null || $character->getId() === null || $character->getCampaign()->getOwner()->getId() !== $actor->getId()) throw new NotFoundHttpException('Personnage indisponible.');
    }

    /** Qualifies a previously declared route, without claiming a reconstructed level history. */
    public function qualifyChoice(Character $character,int $policyId,int $acquisitionId,string $reason,User $actor): void
    {
        $this->authorize($character,$actor);
        if (trim($reason)==='' || mb_strlen($reason)>10000) throw new BadRequestHttpException('Motif de qualification requis.');
        $db=$this->em->getConnection();
        $db->transactional(function () use ($db,$character,$policyId,$acquisitionId,$reason,$actor) {
            $this->em->lock($character,LockMode::PESSIMISTIC_WRITE); $this->em->refresh($character); $this->authorize($character,$actor);
            [$policy]=$this->context($character,$policyId);
            if ($policy->getFormatVersion()!==2) throw new BadRequestHttpException('Transition explicite requise avant qualification avancée.');
            $row=$db->fetchAssociative('SELECT c.* FROM character_spell_choice c JOIN character_spell_acquisition a ON a.id=c.acquisition_id WHERE a.id=? AND a.character_id=? AND a.policy_definition_id=? AND a.retirement_id IS NULL AND NOT EXISTS (SELECT 1 FROM character_spell_acquisition n WHERE n.replaces_id=a.id) FOR UPDATE OF c',[$acquisitionId,$character->getId(),$policyId]);
            if (!$row) throw new NotFoundHttpException('Choix indisponible.');
            if (filter_var($row['origin_known'],FILTER_VALIDATE_BOOL)) throw new ConflictHttpException('Choix déjà qualifié.');
            $db->update('character_spell_choice',['origin_known'=>1,'decided_by_id'=>$actor->getId(),'reason'=>trim($reason),'decided_at'=>date('Y-m-d H:i:s')],['acquisition_id'=>$acquisitionId]);
        });
    }

    private function context(Character $character, int $policyId): array
    {
        $definition = $this->em->find(SpellcastingPolicyDefinition::class, $policyId);
        if (!$definition || !ReferenceVisibility::allows($definition->getSource(), $character->getCampaign()->getOwner())
            || !ReferenceVisibility::allows($definition->getCharacterClass(), $character->getCampaign()->getOwner())) throw new NotFoundHttpException('Politique indisponible.');
        $class = $definition->getCharacterClass();
        $levels = [];
        foreach ($character->getClassLevels() as $level) if ($level->getCharacterClass() === $class) $levels[] = $level;
        usort($levels, static fn ($a, $b) => $a->getPosition() <=> $b->getPosition());
        if (!$levels) throw new BadRequestHttpException('Classe absente du personnage.');
        $selected = [];
        foreach ($levels as $level) if ($level->getSubclass() !== null) $selected[$level->getSubclass()->getId()] = $level->getSubclass();
        if (count($selected) > 1 || ($definition->getSubclass() !== null && ($selected[$definition->getSubclass()->getId()] ?? null) !== $definition->getSubclass())) throw new ConflictHttpException('Sous-classe absente ou ambiguë.');
        // Never switch budgets after decisions, nor silently mix a class and subclass policy.
        $other = $this->em->getConnection()->fetchOne('SELECT 1 FROM character_spell_acquisition a JOIN spellcasting_policy_definition p ON p.id = a.policy_definition_id LEFT JOIN character_subclass s ON s.id = p.subclass_id WHERE a.retirement_id IS NULL AND a.character_id = ? AND COALESCE(p.character_class_id, s.character_class_id) = ? AND p.id <> ? LIMIT 1', [$character->getId(), $class->getId(), $policyId]);
        if ($other) throw new ConflictHttpException('Une autre politique a déjà été utilisée pour cette classe.');
        $used = $this->em->getConnection()->fetchOne('SELECT 1 FROM character_spell_acquisition WHERE retirement_id IS NULL AND character_id = ? AND policy_definition_id = ? LIMIT 1', [$character->getId(), $policyId]);
        $revisedPolicy = $this->revisedPolicy($character, $class);
        if ($revisedPolicy !== null && $revisedPolicy !== $policyId) throw new ConflictHttpException('La politique a été remplacée par une décision du MJ.');
        if ($revisedPolicy === $policyId) $used = true;
        if (!$used) {
            $candidates = $this->policiesFor($character, $class);
            if (count($candidates) !== 1 || $candidates[0]->getId() !== $policyId) throw new ConflictHttpException('Politique absente, ambiguë ou remplacée par une version plus récente.');
        }
        return [$definition, $levels];
    }

    /** Latest explicit versions; ambiguity is reported rather than resolved by a name or inheritance guess. */
    private function policiesFor(Character $character, CharacterClass $class): array
    {
        $repo = $this->em->getRepository(SpellcastingPolicyDefinition::class);
        $result = $repo->findBy(['characterClass' => $class], ['version' => 'DESC'], 1);
        $subclass = $character->getSubclassFor($class);
        if ($subclass !== null && ReferenceVisibility::allows($subclass, $character->getCampaign()->getOwner())) {
            $result = [...$result, ...$repo->findBy(['subclass' => $subclass], ['version' => 'DESC'], 1)];
        }
        if (count($result)===2 && $result[1]->getBasePolicy()!==null) return [$result[1]];
        return $result;
    }

    private function revisedPolicy(Character $character, CharacterClass $class): ?int
    {
        $id = $this->em->getConnection()->fetchOne('SELECT r.target_policy_id FROM character_spell_revision r JOIN spellcasting_policy_definition p ON p.id=r.target_policy_id LEFT JOIN character_subclass s ON s.id=p.subclass_id WHERE r.character_id=? AND COALESCE(p.character_class_id,s.character_class_id)=? ORDER BY r.id DESC LIMIT 1', [$character->getId(),$class->getId()]);
        return $id === false ? null : (int)$id;
    }

    public function status(Character $character, User $actor): array
    {
        $this->authorize($character, $actor);
        $result = [];
        foreach ($character->getClassLevels() as $level) {
            $class = $level->getCharacterClass();
            if (isset($result[$class->getId()]) || !ReferenceVisibility::allows($class, $actor)) continue;
            $policies = $this->policiesFor($character, $class);
            $usedIds = $this->em->getConnection()->fetchFirstColumn('SELECT DISTINCT p.id FROM character_spell_acquisition a JOIN spellcasting_policy_definition p ON p.id = a.policy_definition_id LEFT JOIN character_subclass s ON s.id = p.subclass_id WHERE a.retirement_id IS NULL AND a.character_id = ? AND COALESCE(p.character_class_id, s.character_class_id) = ?', [$character->getId(), $class->getId()]);
            $revisionPolicy = $this->revisedPolicy($character,$class);
            if ($revisionPolicy !== null) $usedIds = [$revisionPolicy];
            if ($usedIds !== []) $policies = array_values(array_filter(array_map(fn ($id) => $this->em->find(SpellcastingPolicyDefinition::class, (int) $id), $usedIds), fn ($p) => $p !== null && ReferenceVisibility::allows($p->getSource(), $actor)));
            $result[$class->getId()] = ['classId' => $class->getId(), 'className' => $class->getName(), 'status' => count($policies) === 0 ? 'policy-to-configure' : (count($policies) > 1 ? 'policy-ambiguous' : 'policy-configured'), 'policyIds' => array_map(static fn ($p) => $p->getId(), $policies)];
        }
        return array_values($result);
    }

    private function rows(Character $character, int $policyId): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative('SELECT a.*, s.level AS spell_level, s.name AS spell_name, COALESCE(r.override_rules,false) AS override_rules FROM character_spell_acquisition a JOIN spell_definition s ON s.id = a.spell_id LEFT JOIN character_spell_revision r ON r.id=a.revision_id WHERE a.retirement_id IS NULL AND a.character_id = ? AND a.policy_definition_id = ? ORDER BY a.id', [$character->getId(), $policyId]);
        foreach ($rows as &$row) if ($row['copy_details'] !== null) $row['copy_details'] = json_decode($row['copy_details'], true, 512, JSON_THROW_ON_ERROR);
        return $rows;
    }

    /** Options do not constitute a permission to cast or a daily preparation. */
    public function options(Character $character, int $policyId, User $actor, ?int $classLevelId = null): array
    {
        $this->authorize($character, $actor);
        [$definition, $levels] = $this->context($character, $policyId);
        $index = count($levels) - 1;
        if ($classLevelId !== null) {
            $index = array_search($classLevelId, array_map(static fn ($l) => $l->getId(), $levels), true);
            if ($index === false) throw new BadRequestHttpException('Niveau étranger à cette classe.');
        }
        $policy = $definition->policy();
        $statistics = $this->spellStatistics($character, $policy);
        if ($definition->getFormatVersion()===2) return (new AdvancedSpellChoices($this->em,$this->access))->options($character,$definition,$levels,$index) + $statistics;
        $limits = $policy->atLevel($index + 1);
        $paths = $this->access->resolve($character,[],$levels[$index]->getPosition());
        $available = []; $automatic = []; $blocked = [];
        $position = $levels[$index]->getPosition();
        foreach ($paths as $path) {
            // A list extension changes eligibility in its target list; learning still
            // spends the selected acquisition policy. Automatic grants belong to
            // their granting class, independently of casting class or ability.
            $acquisitionClassId = in_array($path->mode, ['class-list', 'add-to-list'], true)
                ? ($path->castingClass['id'] ?? null) : $path->source['classId'];
            if ($acquisitionClassId !== $definition->getCharacterClass()->getId()) continue;
            if ($path->source['type'] === 'subclass-grant') {
                $sourceLevel = 0; $chosen = false;
                foreach ($character->getClassLevels() as $level) {
                    if ($level->getPosition() > $position || $level->getCharacterClass()->getId() !== $path->source['classId']) continue;
                    ++$sourceLevel;
                    if ($level->getSubclass()?->getId() === $path->source['subclassId']) $chosen = true;
                }
                if (!$chosen || $sourceLevel < $path->source['unlockLevel']) continue;
            }
            if (in_array($path->mode, ['class-list', 'add-to-list'], true)) {
                if ($path->spell['level'] <= $limits['maxSpellLevel']) $available[$path->spell['id']] = $path->spell;
                continue;
            }
            if (!in_array($path->mode, ['grant-known', 'grant-always-prepared'], true)) continue;
            // Use the acquisition's pinned version, not a newer source policy.
            $bucket = $policy->grantQuota($path->mode, $path->spell['level']);
            if ($bucket !== null && $path->quotaImpact === 'unspecified') {
                $blocked[] = ['key' => $path->key, 'bucket' => $bucket, 'reason' => 'quota-impact-unspecified'];
            } elseif ($bucket === null && $path->quotaImpact === 'counts-toward-quota') {
                $blocked[] = ['key' => $path->key, 'bucket' => 'source-policy', 'reason' => 'quota-not-defined-for-mode'];
            }
            $automatic[] = ['key' => $path->key, 'spellId' => $path->spell['id'], 'acquisitionClassId' => $acquisitionClassId, 'quotaClassId' => $acquisitionClassId, 'castingClassId' => $path->castingClass['id'] ?? null, 'bucket' => $bucket, 'impact' => $path->quotaImpact];
        }
        $levelPositions = [];
        foreach ($levels as $level) $levelPositions[$level->getId()] = $level->getPosition();
        $rows = array_values(array_filter($this->rows($character, $policyId), static fn ($r) => isset($levelPositions[$r['class_level_id']]) && $levelPositions[$r['class_level_id']] <= $position));
        $replaced = array_column(array_filter($rows, static fn ($r) => $r['replaces_id'] !== null), 'replaces_id');
        $active = array_values(array_filter($rows, static fn ($r) => !in_array($r['id'], $replaced, false)));
        $used = ['cantrips' => 0, 'known' => 0, 'spellbook' => 0, 'preparation' => 0];
        $occupied = [];
        $catalogue = new SpellCatalogue($this->em->getConnection());
        foreach ($active as $row) {
            $catalogue->get((int) $row['spell_id'], $actor);
            if (!isset($used[$row['quota_bucket']]) || ((int)$row['spell_level']===0) !== ($row['quota_bucket']==='cantrips') || ((int)$row['spell_level'] > $limits['maxSpellLevel'] && !filter_var($row['override_rules'], FILTER_VALIDATE_BOOL))) throw new ConflictHttpException('Acquisition existante incohérente.');
            if (isset($occupied[$row['quota_bucket']][$row['spell_id']])) throw new ConflictHttpException('Acquisitions existantes dupliquées.');
            $occupied[$row['quota_bucket']][$row['spell_id']] = true;
            ++$used[$row['quota_bucket']];
        }
        $counted = [];
        foreach ($automatic as $grant) if ($grant['impact'] === 'counts-toward-quota' && isset($used[$grant['bucket'] ?? ''])) $counted[$grant['bucket']][$grant['spellId']] = true;
        foreach ($counted as $bucket => $ids) $used[$bucket] += count(array_diff_key($ids, $occupied[$bucket] ?? []));
        $remaining = ['cantrips' => max(0, $limits['cantrips'] - $used['cantrips']), 'known' => max(0, $limits['known'] - $used['known'])];
        $bookBudget = array_sum(array_column(array_slice($policy->levels, 0, $index + 1), 'bookAdditions'));
        $copied = count(array_filter($active, static fn ($r) => $r['provenance'] === 'spellbook-copy'));
        $remaining['spellbook'] = max(0, $bookBudget - ($used['spellbook'] - $copied));
        $overQuota = $used['cantrips'] > $limits['cantrips'] || $used['known'] > $limits['known'];
        return ['policyId' => $policyId, 'acquisitionClassId' => $definition->getCharacterClass()->getId(), 'quotaClassId' => $definition->getCharacterClass()->getId(), 'classLevelId' => $levels[$index]->getId(), 'classLevel' => $index + 1,
            'ability' => $policy->ability->value, 'learningStyle' => $policy->learningStyle,
            'limits' => $limits, 'remaining' => $remaining, 'used' => $used, 'available' => array_values($available),
            'automatic' => $automatic, 'blocked' => $blocked, 'acquisitions' => $active,
            'preparation' => ['base' => $limits['preparationBase'], 'automaticCounted' => $used['preparation'], 'dailyChoicesManaged' => false],
            'status' => $blocked ? 'quota-configuration-required' : ($overQuota ? 'quota-exceeded' : (array_sum($remaining) > 0 ? 'choices-to-complete' : 'complete')),
            'limitations' => $policy->limitations, 'canCast' => null] + $statistics;
    }

    /**
     * Batch decisions are atomic. Each choice: spellId, classLevelId, optional replacesId.
     * Reconciliation is GM-only and explicitly does not validate historical budgets.
     * No automatic grant can create a persistent choice through this command.
     */
    public function acquire(Character $character, int $policyId, array $choices, string $provenance, string $note, User $actor): array
    {
        if (!in_array($provenance, ['level-history', 'manual-reconciliation'], true)) throw new BadRequestHttpException('Provenance invalide.');
        return $this->record($character, $policyId, $choices, $provenance, $note, $actor);
    }

    public function copyQuote(Character $character, int $policyId, int $spellId, User $actor): array
    {
        $options = $this->options($character, $policyId, $actor);
        if ($options['learningStyle'] !== 'spellbook') throw new BadRequestHttpException('Cette politique ne permet pas la copie dans un grimoire.');
        $spell = array_column($options['available'], null, 'id')[$spellId] ?? null;
        if ($spell === null || $spell['level'] === 0) throw new BadRequestHttpException('Sort non éligible à la copie.');
        foreach ($options['acquisitions'] as $row) if ((int) $row['spell_id'] === $spellId) throw new ConflictHttpException('Sort déjà inscrit au grimoire.');
        return ['spellId' => $spellId, 'classLevelId' => $options['classLevelId'], 'spellLevel' => $spell['level'], ...\App\Dto\SpellbookCopyDecision::quote($spell['level']), 'walletDebited' => false];
    }

    public function copy(Character $character, int $policyId, int $spellId, array $decision, User $actor): int
    {
        // A quote is informational. Validation and quote comparison are repeated under locks.
        $this->authorize($character, $actor);
        $db = $this->em->getConnection();
        return $db->transactional(function () use ($db, $character, $policyId, $spellId, $decision, $actor): int {
            $this->em->lock($character, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($character);
            $db->executeStatement('LOCK TABLE spellcasting_policy_definition, character_class, character_subclass, character_class_level, spell_definition, spell_class_list_entry, spell_subclass_grant IN SHARE MODE');
            $quote = $this->copyQuote($character, $policyId, $spellId, $actor);
            try { \App\Dto\SpellbookCopyDecision::validate($decision, $quote['spellLevel']); }
            catch (\InvalidArgumentException $e) { throw new BadRequestHttpException($e->getMessage(), $e); }
            return $this->record($character, $policyId, [['spellId' => $spellId, 'classLevelId' => $quote['classLevelId']]], 'spellbook-copy', 'Copie confirmée : '.$decision['source'], $actor, $decision)[0];
        });
    }

    /** Complete current selection for one acquisition class; previous decisions remain linked. */
    public function revise(Character $character, int $sourcePolicyId, int $targetPolicyId, array $spellIds, array $expectedAcquisitionIds, string $reason, bool $overrideRules, bool $confirmed, User $actor, array $ruleChoices=[]): array
    {
        $this->authorize($character,$actor);
        if (!$confirmed || trim($reason)==='' || mb_strlen($reason)>10000) throw new BadRequestHttpException('Révision explicitement confirmée et motivée requise.');
        foreach ([$spellIds,$expectedAcquisitionIds] as $ids) {
            if (!array_is_list($ids) || count($ids)>100 || count(array_unique($ids,SORT_REGULAR))!==count($ids)) throw new BadRequestHttpException('Liste de révision invalide.');
            foreach ($ids as $id) if (!is_int($id) || $id<1) throw new BadRequestHttpException('Identifiant invalide.');
        }
        $db=$this->em->getConnection();
        return $db->transactional(function () use ($db,$character,$sourcePolicyId,$targetPolicyId,$spellIds,$expectedAcquisitionIds,$reason,$overrideRules,$actor,$ruleChoices) {
            $this->em->lock($character,LockMode::PESSIMISTIC_WRITE); $this->em->refresh($character); $this->authorize($character,$actor);
            $db->executeStatement('LOCK TABLE spellcasting_policy_definition, character_class, character_subclass, character_class_level, spell_definition, spell_class_list_entry, spell_subclass_grant IN SHARE MODE');
            [$source,$levels]=$this->context($character,$sourcePolicyId);
            $target=$this->em->find(SpellcastingPolicyDefinition::class,$targetPolicyId);
            if (!$target || !ReferenceVisibility::allows($target->getSource(),$actor) || !ReferenceVisibility::allows($target->getCharacterClass(),$actor)) throw new NotFoundHttpException('Politique indisponible.');
            if ($target->getCharacterClass()!==$source->getCharacterClass()) throw new BadRequestHttpException('Une révision ne transfère pas les quotas entre classes.');
            if ($target->getSubclass()!==null && $character->getSubclassFor($target->getCharacterClass())!==$target->getSubclass()) throw new BadRequestHttpException('Sous-classe cible non choisie.');
            $rows=$this->rows($character,$sourcePolicyId);
            $replaced=array_column(array_filter($rows,static fn ($r)=>$r['replaces_id']!==null),'replaces_id');
            $current=array_map(static fn ($r)=>(int)$r['id'],array_filter($rows,static fn ($r)=>!in_array($r['id'],$replaced,false)));
            sort($current); sort($expectedAcquisitionIds);
            if ($current!==$expectedAcquisitionIds) throw new ConflictHttpException('Les acquisitions ont changé : relire la sélection avant de confirmer.');
            $revisionId=(int)$db->fetchOne('INSERT INTO character_spell_revision (character_id,source_policy_id,target_policy_id,decided_by_id,reason,override_rules,decided_at) VALUES (?,?,?,?,?,?,CURRENT_TIMESTAMP) RETURNING id',[$character->getId(),$sourcePolicyId,$targetPolicyId,$actor->getId(),trim($reason),$overrideRules?1:0]);
            $db->executeStatement('UPDATE character_spell_acquisition SET retirement_id=? WHERE character_id=? AND policy_definition_id=? AND retirement_id IS NULL',[$revisionId,$character->getId(),$sourcePolicyId]);
            // The complete new snapshot is explicitly current-state reconciliation,
            // not a retroactive claim that historical level budgets were satisfied.
            $choices=array_map(static fn ($id)=>['spellId'=>$id,'classLevelId'=>$levels[array_key_last($levels)]->getId()],$spellIds);
            if ($target->getFormatVersion()===2) {
                if (!array_is_list($ruleChoices) || count($ruleChoices)!==count($choices)) throw new BadRequestHttpException('Règle explicite requise pour chaque choix de transition.');
                foreach ($choices as $i=>&$choice) {
                    if (!is_int($ruleChoices[$i])) throw new BadRequestHttpException('Règle invalide.');
                    $choice['ruleId']=$ruleChoices[$i];
                }
                unset($choice);
            } elseif ($ruleChoices) throw new BadRequestHttpException('Règles avancées interdites pour le format initial.');
            $ids=$choices===[]?[]:$this->record($character,$targetPolicyId,$choices,'manual-reconciliation',$reason,$actor,null,$revisionId,$overrideRules);
            // Even an empty revision must validate the target context.
            $this->context($character,$targetPolicyId);
            return ['revisionId'=>$revisionId,'acquisitionIds'=>$ids,'historicalValidation'=>false,'overrideRules'=>$overrideRules];
        });
    }

    public function revisions(Character $character, User $actor): array
    {
        $this->authorize($character,$actor);
        return $this->em->getConnection()->fetchAllAssociative('SELECT r.*, (SELECT COUNT(*) FROM character_spell_acquisition a WHERE a.retirement_id=r.id) AS retired_count, (SELECT COUNT(*) FROM character_spell_acquisition a WHERE a.revision_id=r.id) AS created_count FROM character_spell_revision r WHERE r.character_id=? ORDER BY r.id',[$character->getId()]);
    }

    public function revisionOptions(Character $character, int $policyId, User $actor): array
    {
        $this->authorize($character,$actor);
        [$source]=$this->context($character,$policyId);
        $repo=$this->em->getRepository(SpellcastingPolicyDefinition::class);
        $targets=$repo->findBy(['characterClass'=>$source->getCharacterClass()],['version'=>'DESC']);
        $subclass=$character->getSubclassFor($source->getCharacterClass());
        if ($subclass!==null && ReferenceVisibility::allows($subclass,$actor)) $targets=[...$targets,...$repo->findBy(['subclass'=>$subclass],['version'=>'DESC'])];
        $rows=$this->rows($character,$policyId);
        $replaced=array_column(array_filter($rows,static fn ($r)=>$r['replaces_id']!==null),'replaces_id');
        $active=array_values(array_filter($rows,static fn ($r)=>!in_array($r['id'],$replaced,false)));
        foreach ($active as &$row) {
            try { (new SpellCatalogue($this->em->getConnection()))->get((int)$row['spell_id'],$actor); }
            catch (NotFoundHttpException) { $row['spell_name']='Référence indisponible'; $row['spell_id']=null; $row['spell_level']=null; }
        }
        return ['policyId'=>$policyId,'acquisitions'=>$active,'targets'=>array_map(static fn ($p)=>['id'=>$p->getId(),'label'=>$p->getSource()->getName().' — '.$p->policy()->key.' v'.$p->policy()->version],$targets),'history'=>$this->revisions($character,$actor)];
    }

    private function record(Character $character, int $policyId, array $choices, string $provenance, string $note, User $actor, ?array $copyDetails = null, ?int $revisionId = null, bool $overrideRules = false): array
    {
        $this->authorize($character, $actor);
        if (trim($note) === '' || mb_strlen($note) > 10000
            || !array_is_list($choices) || !$choices || count($choices) > 100) throw new BadRequestHttpException('Décision invalide.');
        $db = $this->em->getConnection();
        return $db->transactional(function () use ($db, $character, $policyId, $choices, $provenance, $note, $actor, $copyDetails, $revisionId, $overrideRules) {
            $this->em->lock($character, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($character);
            $this->authorize($character, $actor);
            // Short reference snapshot: edits cannot change eligibility between validation and insert.
            $db->executeStatement('LOCK TABLE spellcasting_policy_definition, character_class, character_subclass, character_class_level, spell_definition, spell_class_list_entry, spell_subclass_grant IN SHARE MODE');
            [$definition, $levels] = $this->context($character, $policyId);
            if ($definition->getFormatVersion()===2) {
                return (new AdvancedSpellChoices($this->em,$this->access))->recordLocked($character,$definition,$levels,$choices,$provenance,$note,$actor,$revisionId,$overrideRules,$copyDetails);
            }
            $policy = $definition->policy();
            $rows = $this->rows($character, $policyId);
            if ($provenance === 'manual-reconciliation') foreach ($rows as $row) if ($row['provenance'] !== $provenance) throw new ConflictHttpException('Une reconstruction historique a déjà commencé.');
            $ids = [];
            foreach ($choices as $choice) {
                if (!is_array($choice) || array_diff(array_keys($choice), ['spellId', 'classLevelId', 'replacesId'])
                    || !is_int($choice['spellId'] ?? null) || !is_int($choice['classLevelId'] ?? null)
                    || (isset($choice['replacesId']) && !is_int($choice['replacesId']))) throw new BadRequestHttpException('Choix invalide.');
                $options = $this->options($character, $policyId, $actor, $choice['classLevelId']);
                if (!$overrideRules && $options['status'] === 'quota-exceeded') throw new ConflictHttpException('Les acquisitions existantes dépassent le quota actuel.');
                if ($provenance === 'level-history') foreach ($rows as $row) {
                    if ($row['provenance'] === 'manual-reconciliation' && array_search((int) $row['class_level_id'], array_map(static fn ($l) => $l->getId(), $levels), true) >= $options['classLevel'] - 1) throw new ConflictHttpException('L’historique antérieur à la reprise reste non vérifié.');
                }
                if ($provenance === 'manual-reconciliation' && $options['classLevel'] !== count($levels)) throw new BadRequestHttpException('La reprise utilise le niveau actuel de classe.');
                $spell = array_column($options['available'], null, 'id')[$choice['spellId']] ?? null;
                if ($spell === null && $overrideRules) $spell = (new SpellCatalogue($db))->get($choice['spellId'],$actor);
                if ($spell === null) throw new BadRequestHttpException('Sort non éligible dans cette voie à ce niveau.');
                $bucket = $spell['level'] === 0 ? 'cantrips' : match ($policy->learningStyle) {
                    'known' => 'known', 'spellbook' => 'spellbook', default => throw new BadRequestHttpException('Une liste préparée ne crée pas d’acquisition de sort de niveau.'),
                };
                foreach ($options['blocked'] as $block) if ($block['bucket'] === $bucket || $block['bucket'] === 'source-policy') throw new ConflictHttpException('Impact de quota à configurer.');
                foreach ($options['automatic'] as $grant) if ($grant['spellId'] === $spell['id'] && $grant['bucket'] === $bucket) throw new ConflictHttpException('Sort déjà accordé automatiquement.');
                foreach ($options['acquisitions'] as $row) if ((int) $row['spell_id'] === $spell['id']) throw new ConflictHttpException('Sort déjà acquis dans cette voie.');
                $replaces = $choice['replacesId'] ?? null;
                $rows = $this->rows($character, $policyId);
                if ($replaces !== null) {
                    $old = array_column($options['acquisitions'], null, 'id')[$replaces] ?? null;
                    if ($provenance !== 'level-history' || $bucket !== 'known' || !$old || $old['quota_bucket'] !== 'known'
                        || array_search((int) $old['class_level_id'], array_map(static fn ($l) => $l->getId(), $levels), true) >= $options['classLevel'] - 1) throw new BadRequestHttpException('Remplacement invalide.');
                    $spent = count(array_filter($rows, static fn ($r) => (int) $r['class_level_id'] === $choice['classLevelId'] && $r['replaces_id'] !== null));
                    if ($spent >= $options['limits']['replacements']) throw new ConflictHttpException('Budget de remplacement épuisé.');
                } elseif (!$overrideRules && $options['remaining'][$bucket] === 0 && !(in_array($provenance, ['manual-reconciliation', 'spellbook-copy'], true) && $bucket === 'spellbook')) throw new ConflictHttpException('Quota atteint.');
                if ($provenance === 'level-history') {
                    $indices = array_flip(array_map(static fn ($l) => $l->getId(), $levels));
                    foreach ($rows as $row) if ($row['provenance'] !== 'spellbook-copy' && $indices[$row['class_level_id']] >= $options['classLevel']) throw new ConflictHttpException('Reconstruction attendue dans l’ordre des niveaux.');
                    if ($replaces === null) {
                        $spent = count(array_filter($rows, static fn ($r) => $r['provenance'] !== 'spellbook-copy' && (int) $r['class_level_id'] === $choice['classLevelId'] && $r['quota_bucket'] === $bucket && $r['replaces_id'] === null));
                        if ($spent >= $policy->additionsAtLevel($options['classLevel'], $bucket)) throw new ConflictHttpException('Budget de ce niveau épuisé.');
                    }
                }
                $ids[] = (int) $db->fetchOne('INSERT INTO character_spell_acquisition (character_id, spell_id, policy_definition_id, class_level_id, replaces_id, decided_by_id, provenance, quota_bucket, known, in_spellbook, historical_validation, decision_note, decided_at, copy_details, revision_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, ?, ?) RETURNING id',
                    [$character->getId(), $spell['id'], $policyId, $choice['classLevelId'], $replaces, $actor->getId(), $provenance, $bucket, $bucket !== 'spellbook' ? 1 : 0, $bucket === 'spellbook' ? 1 : 0, $provenance === 'level-history' ? 1 : 0, trim($note), $copyDetails === null ? null : json_encode($copyDetails, JSON_THROW_ON_ERROR), $revisionId]);
            }
            return $ids;
        });
    }
}
