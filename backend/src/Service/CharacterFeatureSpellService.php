<?php
declare(strict_types=1);
namespace App\Service;

use App\Entity\Character;
use App\Enum\Ability;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/** Persisted, source-specific spell knowledge. Does not handle spell uses or class quotas. */
final readonly class CharacterFeatureSpellService
{
    public function __construct(private Connection $db, private CharacterFeatureResolver $features, private CharacterAbilityCalculator $abilityCalculator = new CharacterAbilityCalculator()) {}

    /**
     * Propagate one persisted fixed grant, never replacing existing acquisitions.
     * No implicit session: progression-only eligibility requires a separate explicit context.
     * @param iterable<Character> $characters
     * @return array{eligible:int, missing:int, created:int}
     */
    public function propagateFixedGrant(int $grantId, iterable $characters, bool $dryRun = false): array
    {
        $run = function () use ($grantId, $characters, $dryRun): array {
            $result = ['eligible'=>0, 'missing'=>0, 'created'=>0];
            if ($this->db->fetchOne('SELECT mode FROM feature_spell_grant WHERE id=?', [$grantId]) !== 'fixed') return $result;
            $ordered = [];
            foreach ($characters as $character) $ordered[$character->getId()] = $character;
            ksort($ordered, SORT_NUMERIC);
            foreach ($ordered as $id => $character) {
                if (!$dryRun) $this->db->fetchOne('SELECT id FROM character WHERE id=? FOR UPDATE', [$id]);
                if (!isset($this->applicable($character)[$grantId])) continue;
                ++$result['eligible'];
                // Even an inactive or inconsistent historical row is not ours to change.
                if ($this->db->fetchOne('SELECT id FROM character_feature_spell WHERE character_id=? AND grant_id=?', [$id, $grantId]) !== false) continue;
                ++$result['missing'];
                if (!$dryRun) {
                    $this->synchronize($character, $grantId);
                    ++$result['created'];
                }
            }
            return $result;
        };
        return $dryRun ? $run() : $this->db->transactional($run);
    }

    /** Reference grants store abbreviations; class policies store enum values. */
    public static function castingAbility(?string $value): ?Ability
    {
        return match ($value) {
            'STR'=>Ability::Strength, 'DEX'=>Ability::Dexterity, 'CON'=>Ability::Constitution,
            'INT'=>Ability::Intelligence, 'WIS'=>Ability::Wisdom, 'CHA'=>Ability::Charisma,
            default=>$value === null ? null : Ability::tryFrom($value),
        };
    }

    /** @param array<string,int> $progressionValues
     *  @return array<int, array<string,mixed>> */
    private function applicable(Character $character, array $progressionValues = []): array
    {
        $featureIds = [];
        foreach ($this->features->resolve($character, $progressionValues) as $rule) {
            $id = $rule->getFeatureDefinition()->getId();
            if ($id !== null) $featureIds[] = $id;
        }
        if ($featureIds === []) return [];
        $params = implode(',', array_fill(0, count($featureIds), '?'));
        $rows = $this->db->fetchAllAssociative(
            "SELECT g.*, d.name AS feature_name FROM feature_spell_grant g JOIN character_feature_definition d ON d.id=g.feature_id WHERE g.feature_id IN ($params) AND g.unlock_level <= ? ORDER BY g.id",
            [...$featureIds, $character->getTotalLevel()]
        );
        return array_column($rows, null, 'id');
    }

    /**
     * Call on character changes, or explicitly to reconcile pre-existing characters.
     * A grant ID restricts reconciliation to that applicable fixed grant only;
     * choices, other grants and inapplicable historical acquisitions are preserved.
     */
    public function synchronize(Character $character, ?int $grantId = null): array
    {
        $id = $character->getId();
        $this->db->beginTransaction();
        try {
            // Lock the parent even when no acquisition exists yet, as choose() does.
            $this->db->fetchOne('SELECT id FROM character WHERE id=? FOR UPDATE', [$id]);
            $grants = $this->applicable($character);
            if ($grantId !== null) {
                // Targeted reconciliation never retires unrelated or session-specific grants.
                $grant = $grants[$grantId] ?? null;
                if ($grant === null || $grant['mode'] !== 'fixed') {
                    $this->db->commit();
                    return $this->status($character);
                }
                $grants = [$grantId => $grant];
            }
            $existing = $this->db->fetchAllAssociative(
                'SELECT * FROM character_feature_spell WHERE character_id=?'.($grantId === null ? '' : ' AND grant_id=?').' FOR UPDATE',
                $grantId === null ? [$id] : [$id, $grantId],
            );
            $byGrant = array_column($existing, null, 'grant_id');
            foreach ($existing as $row) {
                $grant = $grants[$row['grant_id']] ?? null;
                $valid = $grant !== null && ($grant['mode'] === 'choice' || (int)$grant['spell_id'] === (int)$row['spell_id']);
                if (!$valid && $row['active']) $this->db->update('character_feature_spell', ['active'=>false,'retired_at'=>date('Y-m-d H:i:s')], ['id'=>$row['id']], ['active'=>ParameterType::BOOLEAN]);
            }
            foreach ($grants as $grant) {
                if ($grant['mode'] !== 'fixed') continue;
                $old = $byGrant[$grant['id']] ?? null;
                if ($old === null) {
                    $this->db->insert('character_feature_spell', ['character_id'=>$id,'grant_id'=>$grant['id'],'spell_id'=>$grant['spell_id']]);
                } elseif ((int)$old['spell_id'] !== (int)$grant['spell_id'] || !$old['active']) {
                    $this->db->update('character_feature_spell', ['spell_id'=>$grant['spell_id'],'active'=>true,'retired_at'=>null], ['id'=>$old['id']], ['active'=>ParameterType::BOOLEAN]);
                }
            }
            $this->db->commit();
        } catch (\Throwable $e) { $this->db->rollBack(); throw $e; }
        return $this->status($character);
    }

    /** @param array<string,int> $progressionValues Explicit session context for read-only resolution. */
    public function status(Character $character, array $progressionValues = []): array
    {
        $grants = $this->applicable($character, $progressionValues);
        $rows = $this->db->fetchAllAssociative('SELECT a.grant_id,a.spell_id,s.name AS spell_name,g.casting_ability,g.feature_id,d.name AS feature_name FROM character_feature_spell a JOIN spell_definition s ON s.id=a.spell_id JOIN feature_spell_grant g ON g.id=a.grant_id JOIN character_feature_definition d ON d.id=g.feature_id WHERE a.character_id=? AND a.active=true ORDER BY d.name,s.name',[$character->getId()]);
        // Only show grants still applicable to the character. Persisted rows are
        // retired by synchronize(); this guard also protects reads before that event.
        $rows = array_values(array_filter($rows, static fn(array $row): bool => isset($grants[$row['grant_id']])));
        $byGrant = array_column($rows,null,'grant_id');
        $pending=[];
        foreach ($grants as $g) if ($g['mode']==='choice' && !isset($byGrant[$g['id']])) {
            $pending[]=['grantId'=>(int)$g['id'],'featureName'=>$g['feature_name'],'spellLevel'=>(int)$g['spell_level'],'classId'=>$g['class_id']===null?null:(int)$g['class_id'],'schools'=>$g['schools']===null?[]:json_decode($g['schools'],true),'castingAbility'=>$g['casting_ability']];
        }
        $castingResolver = new CharacterFeatureSpellCastingResolver($this->db, $this->features);
        return ['known'=>array_map(function ($r) use ($character, $progressionValues, $castingResolver) {
            $casting = $castingResolver->resolve($character, (int)$r['grant_id'], $progressionValues);
            $ability = self::castingAbility($casting['castingAbility']);
            $stats = $ability === null ? ['spellSaveDc'=>null,'spellAttackBonus'=>null] : $this->abilityCalculator->spellStatistics($character, $ability);
            return ['grantId'=>(int)$r['grant_id'],'spellId'=>(int)$r['spell_id'],'spellName'=>$r['spell_name'],'featureName'=>$r['feature_name'],'castingAbility'=>$r['casting_ability'],'castingRulesResolution'=>$casting,...$stats];
        },$rows),'pending'=>$pending];
    }

    public function options(Character $character,int $grantId,int $ownerId): array
    {
        $grant=$this->applicable($character)[$grantId]??null;
        if (!$grant || $grant['mode']!=='choice') throw new BadRequestHttpException('Choix non disponible.');
        $params=[$grant['spell_level'],$ownerId];
        $sql="SELECT s.id,s.name,s.level,s.school FROM spell_definition s WHERE s.level=? AND (s.origin='OFFICIAL' OR s.owner_id=?)";
        if ($grant['class_id']!==null) { $sql.=' AND EXISTS (SELECT 1 FROM spell_class_list_entry e WHERE e.spell_id=s.id AND e.character_class_id=?)'; $params[]=$grant['class_id']; }
        $schools=$grant['schools']===null?[]:json_decode($grant['schools'],true);
        if ($schools!==[]) { $sql.=' AND s.school IN ('.implode(',',array_fill(0,count($schools),'?')).')'; $params=[...$params,...$schools]; }
        // A spell may be granted by several different features, but one feature
        // should not offer the same spell twice through separate choice slots.
        $sql .= ' AND NOT EXISTS (SELECT 1 FROM character_feature_spell a JOIN feature_spell_grant used ON used.id=a.grant_id WHERE a.character_id=? AND a.active=true AND used.feature_id=? AND a.spell_id=s.id AND used.id<>?)';
        $params=[...$params,$character->getId(),$grant['feature_id'],$grantId];
        return array_map(static fn($r)=>['id'=>(int)$r['id'],'name'=>$r['name'],'level'=>(int)$r['level'],'school'=>$r['school']],$this->db->fetchAllAssociative($sql.' ORDER BY s.name,s.id',$params));
    }

    public function choose(Character $character,int $grantId,int $spellId,int $ownerId): array
    {
        $this->db->beginTransaction();
        try {
            // Serialize simultaneous choices for this character before validating options.
            $this->db->fetchOne('SELECT id FROM character WHERE id=? FOR UPDATE', [$character->getId()]);
            $options=$this->options($character,$grantId,$ownerId);
            if (!in_array($spellId,array_column($options,'id'),true)) throw new BadRequestHttpException('Sort non autorisé ou déjà choisi pour cette capacité.');
            $existing=$this->db->fetchAssociative('SELECT * FROM character_feature_spell WHERE character_id=? AND grant_id=? FOR UPDATE',[$character->getId(),$grantId]);
            if ($existing && $existing['active']) throw new BadRequestHttpException('Ce choix a déjà été effectué.');
            if ($existing) $this->db->update('character_feature_spell',['spell_id'=>$spellId,'active'=>true,'retired_at'=>null],['id'=>$existing['id']], ['active'=>ParameterType::BOOLEAN]);
            else $this->db->insert('character_feature_spell',['character_id'=>$character->getId(),'grant_id'=>$grantId,'spell_id'=>$spellId]);
            $this->db->commit();
        } catch (\Throwable $e) { $this->db->rollBack(); throw $e; }
        return $this->status($character);
    }
}
