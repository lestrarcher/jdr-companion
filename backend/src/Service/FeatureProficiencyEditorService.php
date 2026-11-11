<?php
declare(strict_types=1);
namespace App\Service;

use App\Entity\User;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpKernel\Exception\{BadRequestHttpException, ConflictHttpException, NotFoundHttpException};

/** Manage custom proficiency grants attached to an official or custom feature. */
final readonly class FeatureProficiencyEditorService
{
    public function __construct(private Connection $db) {}

    public function get(int $featureId, User $owner): array
    {
        $this->assertFeature($featureId, $owner);
        $rules = $this->db->fetchAllAssociative('SELECT id, label, choice_count AS "choiceCount" FROM proficiency_grant_rule WHERE character_feature_definition_id=? AND owner_id=? ORDER BY id', [$featureId, $owner->getId()]);
        foreach ($rules as &$rule) {
            $rule['id'] = (int) $rule['id'];
            $rule['choiceCount'] = (int) $rule['choiceCount'];
            $rule['options'] = $this->db->fetchAllAssociative('SELECT p.id, p.name, p.slug, p.type FROM proficiency_grant_rule_option o JOIN proficiency_definition p ON p.id=o.proficiency_definition_id WHERE o.proficiency_grant_rule_id=? ORDER BY p.type, p.name', [$rule['id']]);
        }
        unset($rule);
        return $rules;
    }

    public function definitions(User $owner): array
    {
        return $this->db->fetchAllAssociative("SELECT id, name, slug, type FROM proficiency_definition WHERE (origin='OFFICIAL' AND owner_id IS NULL) OR (origin='CUSTOM' AND owner_id=?) ORDER BY type, name", [$owner->getId()]);
    }

    public function save(int $featureId, User $owner, array $data): array
    {
        $this->assertFeature($featureId, $owner);
        $label = $data['label'] ?? null;
        $count = $data['choiceCount'] ?? null;
        $options = $data['optionIds'] ?? null;
        $id = $data['id'] ?? null;
        if (!is_string($label) || trim($label)==='' || mb_strlen($label)>120 || !is_int($count) || $count<0 || !is_array($options) || !$options || count($options)>100 || ($id!==null && !is_int($id))) {
            throw new BadRequestHttpException('Règle de maîtrise invalide.');
        }
        if (count(array_unique($options))!==count($options) || $count>count($options)) throw new BadRequestHttpException('Nombre de choix ou options invalides.');
        foreach ($options as $option) if (!is_int($option) || $option<1) throw new BadRequestHttpException('Identifiant de maîtrise invalide.');
        return $this->db->transactional(function () use ($featureId, $owner, $label, $count, $options, $id): array {
            $visible = (int) $this->db->fetchOne("SELECT count(*) FROM proficiency_definition WHERE id IN (".implode(',',array_fill(0,count($options),'?')).") AND ((origin='OFFICIAL' AND owner_id IS NULL) OR (origin='CUSTOM' AND owner_id=?))", [...$options,$owner->getId()]);
            if ($visible!==count($options)) throw new BadRequestHttpException('Maîtrise inaccessible.');
            if ($id !== null) {
                $row = $this->db->fetchAssociative('SELECT id FROM proficiency_grant_rule WHERE id=? AND character_feature_definition_id=? AND owner_id=? FOR UPDATE', [$id,$featureId,$owner->getId()]);
                if (!$row) throw new NotFoundHttpException('Règle introuvable.');
                if ($this->db->fetchOne('SELECT 1 FROM character_proficiency_choice WHERE grant_rule_id=? LIMIT 1', [$id])) throw new ConflictHttpException('Des personnages ont déjà choisi cette maîtrise : modification bloquée.');
                $this->db->update('proficiency_grant_rule', ['label'=>trim($label),'choice_count'=>$count], ['id'=>$id]);
                $this->db->delete('proficiency_grant_rule_option', ['proficiency_grant_rule_id'=>$id]);
            } else {
                $id = (int) $this->db->fetchOne(
                    'INSERT INTO proficiency_grant_rule (label, choice_count, character_feature_definition_id, owner_id, origin, unlock_level, acquisition_mode) VALUES (?, ?, ?, ?, ?, ?, ?) RETURNING id',
                    [trim($label), $count, $featureId, $owner->getId(), 'CUSTOM', 1, 'ANY']
                );
            }
            foreach ($options as $option) $this->db->insert('proficiency_grant_rule_option', ['proficiency_grant_rule_id'=>$id,'proficiency_definition_id'=>$option]);
            return ['id'=>$id,'label'=>trim($label),'choiceCount'=>$count,'optionIds'=>$options];
        });
    }

    private function assertFeature(int $id, User $owner): void
    {
        if (!$this->db->fetchOne("SELECT 1 FROM character_feature_definition WHERE id=? AND ((origin='OFFICIAL' AND owner_id IS NULL) OR (origin='CUSTOM' AND owner_id=?))", [$id,$owner->getId()])) throw new NotFoundHttpException('Capacité inaccessible.');
    }
}
