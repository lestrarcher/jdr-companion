<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Enum\FeatureActivationType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\HttpKernel\Exception\{BadRequestHttpException, ConflictHttpException, NotFoundHttpException};

/** Personal feature definitions only. Assignments and runtime remain separate. */
final class CustomReferenceFeatureService
{
    private const FIELDS = ['name' => 'name', 'description' => 'description', 'resourceDefinitionId' => 'resource_definition_id'];

    public function __construct(private readonly Connection $db) {}

    public function editorList(User $actor,bool $official): array
    {
        if ($official) $this->requireAdmin($actor);
        $rows=$this->db->fetchAllAssociative("SELECT f.*,r.id linked_id,r.name linked_name,r.slug linked_slug,r.origin linked_origin FROM character_feature_definition f LEFT JOIN trackable_resource_definition r ON r.id=f.resource_definition_id WHERE ((f.origin='OFFICIAL' AND f.owner_id IS NULL)".($official?'':" OR (f.origin='CUSTOM' AND f.owner_id=:owner)").") AND (f.resource_definition_id IS NULL OR (r.origin='OFFICIAL' AND r.owner_id IS NULL) OR (f.origin='CUSTOM' AND r.origin='CUSTOM' AND r.owner_id=f.owner_id)) ORDER BY f.name,f.id",$official?[]:['owner'=>$actor->getId()]);
        $resources=(new CustomReferenceResourceService($this->db))->editorList($actor,$official)['resources'];
        $indexed=[]; foreach($resources as $r) $indexed[$r['id']]=$r;
        return ['features'=>array_map(function($r) use($official,$indexed) { $view=$this->editorView($r,$official); $view['resourceDefinition']=$r['linked_id']===null?null:($indexed[$r['linked_id']]??null); return $view; },$rows), 'resources'=>$resources,
            'activationTypes'=>array_map(static fn($a)=>['value'=>$a->value,'label'=>$a->label()],FeatureActivationType::cases())];
    }

    private function requireAdmin(User $actor): void
    {
        if (!in_array(SpellCatalogue::ADMIN_ROLE,$actor->getRoles(),true)) throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException('Administration officielle requise.');
    }

    private function editorView(array $row,bool $official): array
    {
        return $this->serialize($row)+['origin'=>$row['origin'],'custom'=>$row['origin']==='CUSTOM','editable'=>$official?$row['origin']==='OFFICIAL':$row['origin']==='CUSTOM','activationType'=>$row['activation_type'],'activationLabel'=>FeatureActivationType::from($row['activation_type'])->label(),'visible'=>(bool)$row['visible']];
    }

    public function saveEditor(?int $id,array $payload,User $actor,bool $official): array
    {
        if ($official) $this->requireAdmin($actor);
        return $this->db->transactional(function() use($id,$payload,$actor,$official) {
            $this->lockUsage();
            $row=$id===null?null:$this->db->fetchAssociative('SELECT * FROM character_feature_definition WHERE id=?',[$id]);
            if ($id!==null) {
                if (!$row || ($row['origin']==='CUSTOM' && (int)$row['owner_id']!==$actor->getId()) || ($official && $row['origin']!=='OFFICIAL')) throw new NotFoundHttpException('Capacité indisponible.');
                if (!$official && $row['origin']!=='CUSTOM') throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException('Capacité officielle en lecture seule.');
            }
            $slug=$payload['slug']??null;
            if ($id===null) {
                if (!is_string($slug) || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D',$slug) || strlen($slug)>100) throw new BadRequestHttpException('Slug invalide.');
                unset($payload['slug']);
            }
            $activation=array_key_exists('activationType',$payload)?$payload['activationType']:($row['activation_type']??'passive');
            $visible=array_key_exists('visible',$payload)?$payload['visible']:($row?(bool)$row['visible']:true);
            if (!is_string($activation) || FeatureActivationType::tryFrom($activation)===null || !is_bool($visible)) throw new BadRequestHttpException('Activation ou visibilité invalide.');
            unset($payload['activationType'],$payload['visible']);
            $current=['name'=>$row['name']??'','description'=>$row['description']??null,'resourceDefinitionId'=>isset($row['resource_definition_id'])?(int)$row['resource_definition_id']:null];
            $data=$this->validate($payload,$current);
            $this->validateResource($data['resourceDefinitionId'],$actor);
            if ($official && $data['resourceDefinitionId']!==null && !$this->db->fetchOne("SELECT 1 FROM trackable_resource_definition WHERE id=? AND origin='OFFICIAL' AND owner_id IS NULL",[$data['resourceDefinitionId']])) throw new BadRequestHttpException('Une capacité officielle exige une ressource officielle.');
            $columns=['name'=>$data['name'],'description'=>$data['description'],'resource_definition_id'=>$data['resourceDefinitionId'],'activation_type'=>$activation,'visible'=>$visible?'true':'false','updated_at'=>$this->now()];
            try {
                if ($row) $this->db->update('character_feature_definition',$columns,['id'=>$id]);
                else {
                    $this->db->insert('character_feature_definition',$columns+['slug'=>$slug,'origin'=>$official?'OFFICIAL':'CUSTOM','owner_id'=>$official?null:$actor->getId(),'custom'=>$official?'false':'true','created_at'=>$this->now()]);
                    $id=(int)$this->db->lastInsertId();
                }
            } catch (UniqueConstraintViolationException $e) { throw new ConflictHttpException('Identité déjà utilisée.',$e); }
            foreach($this->editorList($actor,$official)['features'] as $feature) if ($feature['id']===$id) return $feature;
            throw new NotFoundHttpException('Capacité indisponible.');
        });
    }

    public function list(User $owner): array
    {
        return array_map($this->serialize(...), $this->db->fetchAllAssociative(
            $this->ownedQuery().' ORDER BY f.name, f.id', ['owner' => $owner->getId()],
        ));
    }

    public function get(int $id, User $owner): array
    {
        return $this->serialize($this->owned($id, $owner));
    }

    public function create(array $payload, User $owner): array
    {
        $data = $this->validate($payload, ['name' => '', 'description' => null, 'resourceDefinitionId' => null]);
        try {
            return $this->db->transactional(function () use ($data, $owner): array {
                $this->lockUsage();
                $this->validateResource($data['resourceDefinitionId'], $owner);
                $id = $this->db->fetchOne(
                    'INSERT INTO character_feature_definition
                     (slug, name, description, activation_type, visible, custom, origin, owner_id, resource_definition_id, created_at, updated_at)
                     VALUES (?, ?, ?, ?, true, true, ?, ?, ?, ?, ?) RETURNING id',
                    ['custom-'.bin2hex(random_bytes(16)), $data['name'], $data['description'],
                        FeatureActivationType::Passive->value, 'CUSTOM', $owner->getId(),
                        $data['resourceDefinitionId'], $this->now(), $this->now()],
                );
                return $this->get((int) $id, $owner);
            });
        } catch (UniqueConstraintViolationException $error) {
            throw new ConflictHttpException('La création a rencontré un conflit. Réessayez.', $error);
        }
    }

    public function patch(int $id, array $payload, User $owner): array
    {
        return $this->db->transactional(function () use ($id, $payload, $owner): array {
            $this->owned($id, $owner);
            $this->lockUsage();
            $row = $this->owned($id, $owner);
            $current = [
                'name' => $row['name'], 'description' => $row['description'],
                'resourceDefinitionId' => $row['resource_definition_id'] === null ? null : (int) $row['resource_definition_id'],
            ];
            $data = $this->validate($payload, $current);
            $this->validateResource($data['resourceDefinitionId'], $owner);


            $this->db->update('character_feature_definition', [
                'name' => $data['name'], 'description' => $data['description'],
                'resource_definition_id' => $data['resourceDefinitionId'], 'updated_at' => $this->now(),
            ], ['id' => $id]);
            return $this->get($id, $owner);
        });
    }

    public function delete(int $id, User $owner): void
    {
        $this->db->transactional(function () use ($id, $owner): void {
            $this->owned($id, $owner);
            $this->lockUsage();
            $this->owned($id, $owner);
            if ($this->isUsed($id)) {
                throw new ConflictHttpException('Cette capacité est utilisée et ne peut pas être supprimée.');
            }
            $this->db->delete('character_feature_definition', ['id' => $id]);
        });
    }

    private function ownedQuery(): string
    {
        // Same resource visibility matrix as ReferenceVisibility. Validate the dependency
        // in the same SELECT: even a corrupt foreign link must not leak through reads.
        return "SELECT f.*, r.id AS linked_id, r.name AS linked_name, r.slug AS linked_slug, r.origin AS linked_origin
                FROM character_feature_definition f
                LEFT JOIN trackable_resource_definition r ON r.id = f.resource_definition_id
                  AND ((r.origin = 'OFFICIAL' AND r.owner_id IS NULL) OR (r.origin = 'CUSTOM' AND r.owner_id = :owner))
                WHERE f.origin = 'CUSTOM' AND f.owner_id = :owner
                  AND (f.resource_definition_id IS NULL OR r.id IS NOT NULL)";
    }

    private function owned(int $id, User $owner): array
    {
        $row = $this->db->fetchAssociative($this->ownedQuery().' AND f.id = :id', ['id' => $id, 'owner' => $owner->getId()]);
        if ($row === false) throw new NotFoundHttpException('Capacité introuvable.');
        return $row;
    }

    private function validateResource(?int $id, User $owner): void
    {
        if ($id === null) return;
        if (!$this->db->fetchOne(
            "SELECT id FROM trackable_resource_definition WHERE id = ?
             AND ((origin = 'OFFICIAL' AND owner_id IS NULL) OR (origin = 'CUSTOM' AND owner_id = ?))",
            [$id, $owner->getId()],
        )) {
            throw new BadRequestHttpException('La ressource sélectionnée est indisponible.');
        }
    }

    private function lockUsage(): void
    {
        // Same parent-first lock order as Resource CRUD; prevents a resource deletion
        // between relation validation and INSERT/PATCH, and a rule insertion during DELETE.
        $this->db->executeStatement(
            'LOCK TABLE trackable_resource_definition, character_feature_definition, character_feature_rule IN SHARE ROW EXCLUSIVE MODE',
        );
    }

    private function isUsed(int $id): bool
    {
        // Only actual incoming feature usage; never infer it from the resource's state.
        // Also block inconsistent OFFICIAL/foreign-owner assignments without disclosing them.
        return (bool) $this->db->fetchOne('SELECT 1 FROM character_feature_rule WHERE feature_definition_id = ? LIMIT 1', [$id]);
    }

    private function validate(array $payload, array $current): array
    {
        if (array_diff(array_keys($payload), array_keys(self::FIELDS)) !== []) {
            throw new BadRequestHttpException('Le payload contient un champ inconnu ou interdit.');
        }
        $data = array_replace($current, $payload);
        if (!is_string($data['name']) || trim($data['name']) === '' || mb_strlen(trim($data['name'])) > 150
            || str_contains($data['name'], "\0")) {
            throw new BadRequestHttpException('Le nom doit contenir entre 1 et 150 caractères valides.');
        }
        $data['name'] = trim($data['name']);
        if ($data['description'] !== null) {
            if (!is_string($data['description']) || str_contains($data['description'], "\0")) {
                throw new BadRequestHttpException('La description doit être une chaîne ou null.');
            }
            $data['description'] = trim($data['description']);
            if ($data['description'] === '') $data['description'] = null;
        }
        $resource = $data['resourceDefinitionId'];
        if ($resource !== null && (!is_int($resource) || $resource <= 0 || $resource > 2147483647)) {
            throw new BadRequestHttpException('La ressource doit être identifiée par un entier positif ou null.');
        }
        return $data;
    }

    private function serialize(array $row): array
    {
        return [
            'id' => (int) $row['id'], 'name' => $row['name'], 'description' => $row['description'], 'slug' => $row['slug'],
            'resourceDefinition' => $row['linked_id'] === null ? null : [
                'id' => (int) $row['linked_id'], 'name' => $row['linked_name'],
                'slug' => $row['linked_slug'], 'origin' => $row['linked_origin'],
            ],
        ];
    }

    private function now(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d H:i:s');
    }
}
