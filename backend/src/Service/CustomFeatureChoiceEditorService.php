<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpKernel\Exception\{BadRequestHttpException, ConflictHttpException, NotFoundHttpException};

/** Edits owner-scoped choice groups for official or personal mother features. */
final readonly class CustomFeatureChoiceEditorService
{
    public function __construct(private Connection $db) {}

    public function get(int $featureId, User $owner): array
    {
        $this->mother($featureId, $owner);
        $rows = $this->db->fetchAllAssociative(
            'SELECT g.key, g.name, g.uniqueness, g.replacement, g.replacement_windows, g.replacement_event_key, g.enabled, a.key AS grant_key, a.rule_id, a.choice_count FROM feature_choice_group g JOIN feature_choice_grant a ON a.group_key=g.key JOIN character_feature_rule r ON r.id=a.rule_id WHERE r.feature_definition_id=? AND g.owner_id=? ORDER BY a.key',
            [$featureId, $owner->getId()]
        );
        foreach ($rows as &$row) {
            $row['options'] = $this->db->fetchAllAssociative(
                'SELECT o.key, o.feature_id AS "featureId", f.name FROM feature_choice_option o JOIN character_feature_definition f ON f.id=o.feature_id WHERE o.group_key=? ORDER BY f.name',
                [$row['key']]
            );
        }
        unset($row);
        return $rows;
    }

    public function save(int $featureId, User $owner, array $data): array
    {
        $this->mother($featureId, $owner);
        $ruleId = $data['ruleId'] ?? null;
        $count = $data['choiceCount'] ?? null;
        $name = $data['name'] ?? null;
        $uniqueness = $data['uniqueness'] ?? 'group';
        $replacement = $data['replacement'] ?? 'none';
        $options = $data['options'] ?? null;
        if (!is_int($ruleId) || !is_int($count) || $count < 1 || $count > 20 || !is_string($name) || trim($name) === '' || mb_strlen($name) > 150 || !in_array($uniqueness, ['group', 'character', 'none'], true) || !in_array($replacement, ['none', 'class-level', 'grant-level'], true) || !is_array($options) || count($options) < 1 || count($options) > 100) {
            throw new BadRequestHttpException('Configuration des choix invalide.');
        }
        if (count($options) < $count || count(array_unique($options, SORT_REGULAR)) !== count($options)) {
            throw new BadRequestHttpException('Options insuffisantes ou dupliquées.');
        }
        return $this->db->transactional(function () use ($featureId, $owner, $ruleId, $count, $name, $uniqueness, $replacement, $options): array {
            $rule = $this->db->fetchAssociative('SELECT * FROM character_feature_rule WHERE id=? FOR UPDATE', [$ruleId]);
            if (!$rule || (int)$rule['feature_definition_id'] !== $featureId || !($rule['origin'] === 'OFFICIAL' && $rule['owner_id'] === null || $rule['origin'] === 'CUSTOM' && (int)$rule['owner_id'] === $owner->getId()) || ($rule['character_class_id'] === null && $rule['character_subclass_id'] === null && $rule['character_race_id'] === null)) {
                throw new BadRequestHttpException('Une attribution de classe, sous-classe ou race accessible est requise.');
            }
            $existing = $this->db->fetchAssociative('SELECT a.*, g.owner_id FROM feature_choice_grant a JOIN feature_choice_group g ON g.key=a.group_key WHERE a.rule_id=? FOR UPDATE OF a', [$ruleId]);
            if ($existing && (int)$existing['owner_id'] !== $owner->getId()) throw new ConflictHttpException('Cette attribution possède déjà un groupe officiel ou inaccessible. Impossible de le remplacer.');
            if ($existing && $this->db->fetchOne('SELECT 1 FROM character_feature_choice WHERE grant_key=? LIMIT 1', [$existing['key']])) {
                throw new ConflictHttpException('Des personnages ont déjà choisi : modification bloquée pour préserver leurs sélections.');
            }
            $features = [];
            foreach ($options as $featureOptionId) {
                if (!is_int($featureOptionId) || $featureOptionId === $featureId) throw new BadRequestHttpException('Option invalide ou identique à la capacité mère.');
                $feature = $this->db->fetchAssociative('SELECT id FROM character_feature_definition WHERE id=? AND ((origin=\'OFFICIAL\' AND owner_id IS NULL) OR (origin=\'CUSTOM\' AND owner_id=?))', [$featureOptionId, $owner->getId()]);
                if (!$feature) throw new BadRequestHttpException('Capacité optionnelle inaccessible : '.$featureOptionId);
                $features[] = $featureOptionId;
            }
            if ($existing) {
                $groupKey = $existing['group_key'];
                $grantKey = $existing['key'];
                $this->db->executeStatement('DELETE FROM feature_choice_option WHERE group_key=?', [$groupKey]);
                $this->db->update('feature_choice_group', ['name'=>trim($name), 'uniqueness'=>$uniqueness, 'replacement'=>$replacement], ['key'=>$groupKey]);
                $this->db->update('feature_choice_grant', ['choice_count'=>$count], ['key'=>$grantKey]);
            } else {
                $groupKey = 'custom-group-'.$owner->getId().'-'.$ruleId;
                $grantKey = 'custom-grant-'.$owner->getId().'-'.$ruleId;
                $this->db->insert('feature_choice_group', ['key'=>$groupKey, 'name'=>trim($name), 'owner_id'=>$owner->getId(), 'uniqueness'=>$uniqueness, 'replacement'=>$replacement, 'replacement_windows'=>'[]', 'enabled'=>true]);
                $this->db->insert('feature_choice_grant', ['key'=>$grantKey, 'group_key'=>$groupKey, 'rule_id'=>$ruleId, 'choice_count'=>$count, 'enabled'=>true]);
            }
            foreach ($features as $optionId) {
                $this->db->insert('feature_choice_option', ['key'=>'custom-option-'.$ruleId.'-'.$optionId, 'group_key'=>$groupKey, 'feature_id'=>$optionId, 'prerequisites'=>'{}', 'enabled'=>true]);
            }
            return ['groupKey'=>$groupKey, 'grantKey'=>$grantKey, 'ruleId'=>$ruleId, 'choiceCount'=>$count, 'name'=>trim($name), 'options'=>$features];
        });
    }

    private function mother(int $id, User $owner): void
    {
        if (!$this->db->fetchOne("SELECT 1 FROM character_feature_definition WHERE id=? AND ((origin='OFFICIAL' AND owner_id IS NULL) OR (origin='CUSTOM' AND owner_id=?))", [$id, $owner->getId()])) {
            throw new NotFoundHttpException('Capacité mère introuvable ou inaccessible.');
        }
    }
}
