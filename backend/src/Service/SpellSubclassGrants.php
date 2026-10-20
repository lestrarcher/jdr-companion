<?php
declare(strict_types=1);
namespace App\Service;

use App\Entity\{SpellSubclassGrant, User};
use App\Enum\SpellGrantMode;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\{UniqueConstraintViolationException, ForeignKeyConstraintViolationException};
use Symfony\Component\HttpKernel\Exception\{AccessDeniedHttpException, BadRequestHttpException, ConflictHttpException, NotFoundHttpException};

/** Source-owned reference rules; never changes a spell or character state. */
final readonly class SpellSubclassGrants
{
    public function __construct(private Connection $db, private SpellCatalogue $spells) {}

    public function substitution(int $characterId,int $grantId,User $owner,?int $throughPosition=null): ?array
    {
        // Keeps the 13B database usable while the separately authorized 13C DDL is pending.
        if ($this->db->fetchOne("SELECT to_regclass('character_spell_substitution')")===null) return null;
        $row=$this->db->fetchAssociative('SELECT s.*,r.grant_id,r.schools,r.id AS substitution_rule_id,l.position FROM character_spell_substitution s JOIN spell_grant_substitution_rule r ON r.id=s.rule_id JOIN character_class_level l ON l.id=s.class_level_id JOIN character c ON c.id=s.character_id JOIN campaign ca ON ca.id=c.campaign_id JOIN spell_subclass_grant g ON g.id=r.grant_id JOIN character_subclass sc ON sc.id=g.subclass_id WHERE s.character_id=? AND r.grant_id=? AND ca.owner_id=? AND l.character_id=s.character_id AND l.character_class_id=sc.character_class_id AND COALESCE(s.effective_position,l.position)<=? ORDER BY COALESCE(s.effective_position,l.position) DESC,s.id DESC LIMIT 1',[$characterId,$grantId,$owner->getId(),$throughPosition??2147483647]);
        if (!$row) return null;
        $spell=$this->spells->get((int)$row['spell_id'],$owner);
        $grant=$this->db->fetchAssociative('SELECT * FROM spell_subclass_grant WHERE id=?',[$grantId]);
        $this->dependencies($grant);
        $original=$this->spells->get((int)$grant['spell_id'],$owner);
        $schools=json_decode($row['schools'],true,512,JSON_THROW_ON_ERROR);
        $classes=$this->db->fetchFirstColumn('SELECT class_id FROM spell_grant_substitution_list WHERE rule_id=?',[$row['rule_id']]);
        foreach ($classes as $classId) $this->reference('character_class',(int)$classId,$grant['owner_id']===null?null:(int)$grant['owner_id']);
        $originalRestored=in_array($row['decision_kind'],['revocation','transition'],true) && $spell['id']===$original['id'];
        if (!$originalRestored && ($spell['level']!==$original['level'] || ($schools && !in_array($spell['school'],$schools,true)) || !array_intersect($classes,array_column($spell['classes'],'id')))) throw new NotFoundHttpException('Substitution incohérente.');
        return ['spell'=>$spell,'decisionId'=>(int)$row['id'],'ruleId'=>(int)$row['rule_id'],'originalSpellId'=>(int)$grant['spell_id'],'decisionKind'=>$row['decision_kind']];
    }

    private function scope(User $user, bool $official): void
    {
        if ($official && !$this->spells->canManageOfficial($user)) throw new AccessDeniedHttpException();
    }

    private function reference(string $table, int $id, ?int $owner, bool $lock = false): array
    {
        $row = $this->db->fetchAssociative("SELECT * FROM $table WHERE id = ?".($lock ? ' FOR SHARE' : ''), [$id]);
        if (!$row || !(($row['origin'] === 'OFFICIAL' && $row['owner_id'] === null) || ($row['origin'] === 'CUSTOM' && $owner !== null && (int) $row['owner_id'] === $owner))) throw new NotFoundHttpException('Référence indisponible.');
        if ($table === 'character_subclass') $this->reference('character_class', (int) $row['character_class_id'], $row['origin'] === 'OFFICIAL' ? null : $owner, $lock);
        if ($table === 'spell_definition') {
            foreach ($this->db->fetchFirstColumn('SELECT character_class_id FROM spell_class_list_entry WHERE spell_id = ?', [$id]) as $classId) $this->reference('character_class', (int) $classId, $row['origin'] === 'OFFICIAL' ? null : $owner, $lock);
        }
        return $row;
    }

    private function dependencies(array $row, bool $lock = false): array
    {
        $owner = $row['owner_id'] === null ? null : (int) $row['owner_id'];
        $source = $this->reference('character_subclass', (int) $row['subclass_id'], $owner, $lock);
        if ($source['origin'] !== $row['origin'] || $source['owner_id'] != $row['owner_id']) throw new NotFoundHttpException('Provenance incohérente.');
        $this->reference('spell_definition', (int) $row['spell_id'], $owner, $lock);
        if ($row['casting_class_id'] !== null) $this->reference('character_class', (int) $row['casting_class_id'], $owner, $lock);
        if ($row['resource_id'] !== null) $this->reference('trackable_resource_definition', (int) $row['resource_id'], $owner, $lock);
        return $source;
    }

    public function options(User $user, bool $official = false): array
    {
        $this->scope($user, $official);
        $sources = []; $resources = [];
        foreach ($this->db->fetchAllAssociative('SELECT id FROM character_subclass WHERE '.($official ? "origin = 'OFFICIAL' AND owner_id IS NULL" : "origin = 'CUSTOM' AND owner_id = ?").' ORDER BY name, id', $official ? [] : [$user->getId()]) as $row) {
            try { $source = $this->reference('character_subclass', (int) $row['id'], $official ? null : $user->getId()); }
            catch (NotFoundHttpException) { continue; }
            if ($source['origin'] !== ($official ? 'OFFICIAL' : 'CUSTOM')) continue;
            $sources[] = ['id' => (int) $source['id'], 'name' => $source['name'], 'origin' => $source['origin'], 'classId' => (int) $source['character_class_id'], 'className' => $this->db->fetchOne('SELECT name FROM character_class WHERE id = ?', [$source['character_class_id']])];
        }
        foreach ($this->db->fetchAllAssociative("SELECT id FROM trackable_resource_definition WHERE (origin = 'OFFICIAL' AND owner_id IS NULL)".($official ? '' : " OR (origin = 'CUSTOM' AND owner_id = ?)").' ORDER BY name, id', $official ? [] : [$user->getId()]) as $row) {
            try { $resource = $this->reference('trackable_resource_definition', (int) $row['id'], $official ? null : $user->getId()); }
            catch (NotFoundHttpException) { continue; }
            $resources[] = ['id' => (int) $resource['id'], 'name' => $resource['name'], 'origin' => $resource['origin']];
        }
        return ['subclasses' => $sources, 'resources' => $resources, 'classes' => $this->spells->classOptions($user, $official), 'modes' => array_map(static fn ($m) => ['value' => $m->value, 'label' => $m->label()], SpellGrantMode::cases())];
    }

    public function list(int $spellId, User $user, bool $official = false): array
    {
        $this->spells->get($spellId, $user, $official);
        $rows = $this->db->fetchAllAssociative("SELECT * FROM spell_subclass_grant WHERE spell_id = ? AND ((origin = 'OFFICIAL' AND owner_id IS NULL)".($official ? ')' : " OR (origin = 'CUSTOM' AND owner_id = ?))").' ORDER BY subclass_id, unlock_level, id', $official ? [$spellId] : [$spellId, $user->getId()]);
        return $this->visibleRows($rows, $official);
    }

    /** Runtime configuration read: owner is always supplied by the character's campaign. */
    public function forSubclasses(array $subclassIds, User $owner): array
    {
        if ($subclassIds === []) return [];
        $rows = $this->db->fetchAllAssociative(
            "SELECT * FROM spell_subclass_grant WHERE subclass_id IN (?) AND ((origin = 'OFFICIAL' AND owner_id IS NULL) OR (origin = 'CUSTOM' AND owner_id = ?)) ORDER BY id",
            [$subclassIds, $owner->getId()], [\Doctrine\DBAL\ArrayParameterType::INTEGER, \Doctrine\DBAL\ParameterType::INTEGER],
        );
        return $this->visibleRows($rows, false);
    }

    private function visibleRows(array $rows, bool $official): array
    {
        $result = [];
        foreach ($rows as $row) {
            try { $source = $this->dependencies($row); } catch (NotFoundHttpException) { continue; }
            // Defensive reads also reject malformed configuration written outside the service.
            if (\App\Enum\SpellQuotaImpact::tryFrom($row['quota_impact'] ?? 'unspecified') === null) continue;
            $mode = SpellGrantMode::tryFrom($row['mode']);
            if ($mode === null) continue;
            try { SpellSubclassGrant::validateValues($mode, (int) $row['unlock_level'], $row['casting_class_id'] !== null, $row['slot_usage'], $row['restrictions'], $row['resource_id'] !== null, $row['resource_cost'] === null ? null : (int) $row['resource_cost']); }
            catch (\InvalidArgumentException) { continue; }
            $result[] = $this->serialize($row, $source, $official);
        }
        return $result;
    }

    private function serialize(array $r, array $source, bool $official): array
    {
        return [
            'id' => (int) $r['id'], 'subclassId' => (int) $r['subclass_id'], 'subclassName' => $source['name'],
            'activationClassId' => (int) $source['character_class_id'],
            'activationClassName' => $this->db->fetchOne('SELECT name FROM character_class WHERE id = ?', [$source['character_class_id']]),
            'spellId' => (int) $r['spell_id'], 'origin' => $r['origin'], 'mode' => $r['mode'], 'unlockLevel' => (int) $r['unlock_level'],
            'castingClassId' => $r['casting_class_id'] === null ? null : (int) $r['casting_class_id'],
            'castingClassName' => $r['casting_class_id'] === null ? null : $this->db->fetchOne('SELECT name FROM character_class WHERE id = ?', [$r['casting_class_id']]),
            'quotaImpact' => $r['quota_impact'] ?? 'unspecified',
            'slotUsage' => $r['slot_usage'], 'restrictions' => $r['restrictions'],
            'resourceId' => $r['resource_id'] === null ? null : (int) $r['resource_id'],
            'resourceName' => $r['resource_id'] === null ? null : $this->db->fetchOne('SELECT name FROM trackable_resource_definition WHERE id = ?', [$r['resource_id']]),
            'resourceCost' => $r['resource_cost'] === null ? null : (int) $r['resource_cost'],
            'editable' => $r['origin'] === ($official ? 'OFFICIAL' : 'CUSTOM'),
        ];
    }

    public function save(int $spellId, ?int $id, array $input, User $user, bool $official = false): array
    {
        $this->scope($user, $official);
        try {
            return $this->db->transactional(function () use ($spellId, $id, $input, $user, $official) {
                $this->spells->get($spellId, $user, $official);
                $old = $id === null ? null : $this->writable($spellId, $id, $user, $official);
                $fields = ['quotaImpact' => 'quota_impact', 'mode' => 'mode', 'unlockLevel' => 'unlock_level', 'castingClassId' => 'casting_class_id', 'slotUsage' => 'slot_usage', 'restrictions' => 'restrictions', 'resourceId' => 'resource_id', 'resourceCost' => 'resource_cost'];
                if (array_diff(array_keys($input), [...array_keys($fields), ...($id === null ? ['subclassId'] : [])])) throw new BadRequestHttpException('Champ inconnu ou identité immuable.');
                $row = $old ?? ['spell_id' => $spellId, 'subclass_id' => $input['subclassId'] ?? null, 'origin' => $official ? 'OFFICIAL' : 'CUSTOM', 'owner_id' => $official ? null : $user->getId(), 'mode' => null, 'unlock_level' => null, 'casting_class_id' => null, 'slot_usage' => null, 'restrictions' => null, 'resource_id' => null, 'resource_cost' => null];
                $row['quota_impact'] ??= 'unspecified';
                foreach ($fields as $key => $column) if (array_key_exists($key, $input)) $row[$column] = $input[$key];
                if ($old!==null && $this->db->fetchOne("SELECT to_regclass('spell_grant_substitution_rule')")!==null && $this->db->fetchOne('SELECT 1 FROM spell_grant_substitution_rule WHERE grant_id=?',[$id])) {
                    foreach ($fields as $column) if ($row[$column]!==$old[$column]) throw new ConflictHttpException('Attribution liée à une règle versionnée : mécaniques verrouillées.');
                }
                if (!is_string($row['quota_impact']) || \App\Enum\SpellQuotaImpact::tryFrom($row['quota_impact']) === null) throw new BadRequestHttpException('Impact sur le quota invalide.');
                foreach (['subclass_id', 'unlock_level', 'casting_class_id', 'resource_id', 'resource_cost'] as $key) {
                    if ($row[$key] === null && !in_array($key, ['subclass_id', 'unlock_level'], true)) continue;
                    if (!is_int($row[$key]) || $row[$key] < 1 || $row[$key] > 2147483647) throw new BadRequestHttpException('Entier positif requis : '.$key);
                }
                foreach (['mode', 'slot_usage', 'restrictions'] as $key) if ($row[$key] !== null && !is_string($row[$key])) throw new BadRequestHttpException('Texte invalide.');
                $mode = is_string($row['mode']) ? SpellGrantMode::tryFrom($row['mode']) : null;
                if (!$mode) throw new BadRequestHttpException('Mode invalide.');
                if (is_string($row['restrictions'])) $row['restrictions'] = trim($row['restrictions']) ?: null;
                try { SpellSubclassGrant::validateValues($mode, $row['unlock_level'], $row['casting_class_id'] !== null, $row['slot_usage'], $row['restrictions'], $row['resource_id'] !== null, $row['resource_cost']); }
                catch (\InvalidArgumentException $e) { throw new BadRequestHttpException($e->getMessage()); }
                $source = $this->dependencies($row, true);
                unset($row['id']);
                if ($id === null) $id = (int) $this->db->fetchOne('INSERT INTO spell_subclass_grant ('.implode(', ', array_keys($row)).') VALUES ('.implode(', ', array_fill(0, count($row), '?')).') RETURNING id', array_values($row));
                else $this->db->update('spell_subclass_grant', $row, ['id' => $id]);
                return $this->serialize(['id' => $id] + $row, $source, $official);
            });
        } catch (UniqueConstraintViolationException) { throw new ConflictHttpException('Cette attribution existe déjà pour ce mode et ce niveau.'); }
        catch (ForeignKeyConstraintViolationException) { throw new ConflictHttpException('Une référence a changé. Rechargez le formulaire.'); }
    }

    private function writable(int $spellId, int $id, User $user, bool $official): array
    {
        $row = $this->db->fetchAssociative('SELECT * FROM spell_subclass_grant WHERE id = ? AND spell_id = ? FOR UPDATE', [$id, $spellId]);
        if (!$row || ($row['origin'] === 'CUSTOM' && ($official || (int) $row['owner_id'] !== $user->getId()))) throw new NotFoundHttpException('Attribution introuvable.');
        if ($row['origin'] !== ($official ? 'OFFICIAL' : 'CUSTOM')) throw new AccessDeniedHttpException('Utilisez l’administration officielle.');
        // Authorization follows the real source, including for deletion of a malformed rule.
        $source = $this->reference('character_subclass', (int) $row['subclass_id'], $official ? null : $user->getId(), true);
        if ($source['origin'] !== $row['origin'] || $source['owner_id'] != $row['owner_id']) throw new NotFoundHttpException('Provenance incohérente.');
        return $row;
    }

    public function delete(int $spellId, int $id, User $user, bool $official = false): void
    {
        $this->scope($user, $official);
        $this->spells->get($spellId, $user, $official);
        try { $this->db->transactional(function () use ($spellId, $id, $user, $official) { $this->writable($spellId, $id, $user, $official); $this->db->delete('spell_subclass_grant', ['id' => $id]); }); }
        catch (ForeignKeyConstraintViolationException) { throw new ConflictHttpException('Attribution utilisée.'); }
    }
}
