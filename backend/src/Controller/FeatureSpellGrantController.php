<?php
declare(strict_types=1);
namespace App\Controller;

use App\Entity\User;
use App\Service\SpellCatalogue;
use App\Service\CharacterFeatureSpellService;
use App\Service\FeatureSpellCastingRules;
use App\Repository\CharacterRepository;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, JsonResponse};
use Symfony\Component\HttpKernel\Exception\{BadRequestHttpException, NotFoundHttpException, AccessDeniedHttpException};
use Symfony\Component\Routing\Attribute\Route;

#[Route('/reference/{scope}/features/{featureId}/spell-grants', requirements: ['scope'=>'custom|official','featureId'=>'\d+'])]
final class FeatureSpellGrantController extends AbstractController
{
    public function __construct(private readonly Connection $db, private readonly ?\App\Service\FeatureSpellGrantConfiguration $configuration = null, private readonly ?\App\Service\FeatureSpellGrantReconciler $reconciler = null) {}

    private function feature(int $id, string $scope): array
    {
        $actor=$this->getUser();
        if (!$actor instanceof User) throw new AccessDeniedHttpException();
        if ($scope==='official' && !in_array(SpellCatalogue::ADMIN_ROLE,$actor->getRoles(),true)) throw new AccessDeniedHttpException();
        $row=$this->db->fetchAssociative('SELECT id,origin,owner_id FROM character_feature_definition WHERE id=?',[$id]);
        if (!$row || ($scope==='official' && $row['origin']!=='OFFICIAL') || ($scope==='custom' && $row['origin']!=='CUSTOM' && $row['origin']!=='OFFICIAL') || ($row['origin']==='CUSTOM' && (int)$row['owner_id']!==$actor->getId())) throw new NotFoundHttpException('Capacité introuvable.');
        return $row;
    }
    private function editable(array $feature,string $scope): void
    {
        if (($scope==='official')!==($feature['origin']==='OFFICIAL')) throw new AccessDeniedHttpException('Capacité en lecture seule.');
    }
    private function rows(int $featureId): array
    {
        $rows=$this->db->fetchAllAssociative('SELECT g.*,s.name AS spell_name,c.name AS class_name FROM feature_spell_grant g LEFT JOIN spell_definition s ON s.id=g.spell_id LEFT JOIN character_class c ON c.id=g.class_id WHERE g.feature_id=? ORDER BY g.id',[$featureId]);
        return array_map(function(array $r):array {
            $resource = $r['resource_definition_id'] === null ? false : $this->db->fetchAssociative('SELECT base_maximum,recharge_type FROM trackable_resource_definition WHERE id=?', [$r['resource_definition_id']]);
            return ['enabled'=>(bool)$r['enabled'], 'managedResourceId'=>$r['managed_resource_id'] === null ? null : (int)$r['managed_resource_id'],
                'freeUses'=>$resource ? (int)$resource['base_maximum'] : 0, 'rechargeType'=>$resource ? $resource['recharge_type'] : 'long-rest',
                'id'=>(int)$r['id'],'mode'=>$r['mode'],'spellId'=>$r['spell_id']===null?null:(int)$r['spell_id'],'spellName'=>$r['spell_name'],'spellLevel'=>$r['spell_level']===null?null:(int)$r['spell_level'],'classId'=>$r['class_id']===null?null:(int)$r['class_id'],'className'=>$r['class_name'],'schools'=>$r['schools']===null?[]:json_decode($r['schools'],true),'unlockLevel'=>(int)$r['unlock_level'],'castingAbility'=>$r['casting_ability'], 'castingRules'=>$r['casting_rules']===null?null:json_decode($r['casting_rules'],true,512,JSON_THROW_ON_ERROR), 'resourceDefinitionId'=>$r['resource_definition_id']===null?null:(int)$r['resource_definition_id']];},$rows);
    }
    private function allowsChoice(int $featureId): bool
    {
        return !$this->db->fetchOne('SELECT 1 FROM character_feature_rule WHERE feature_definition_id=? AND progression_definition_id IS NOT NULL', [$featureId]);
    }

    private function validateChoiceSource(int $featureId, string $mode): void
    {
        if ($mode === 'choice' && !$this->allowsChoice($featureId)) {
            throw new BadRequestHttpException('Une progression ne peut accorder que des sorts fixes.');
        }
    }
    private function castingConfiguration(array $feature, mixed $rules, mixed $resourceId, int $baseLevel, ?int $grantId = null): array
    {
        $rules = FeatureSpellCastingRules::validate($rules, $resourceId, $baseLevel);
        if ($resourceId !== null) {
            if ($this->db->fetchOne('SELECT 1 FROM feature_spell_grant WHERE managed_resource_id=? AND id<>?', [$resourceId,$grantId ?? 0])) throw new BadRequestHttpException('Cette ressource appartient à un autre octroi.');
            $resource = $this->db->fetchAssociative('SELECT origin,owner_id FROM trackable_resource_definition WHERE id=?', [$resourceId]);
            if (!$resource || !($resource['origin'] === 'OFFICIAL' && $resource['owner_id'] === null)
                && !($feature['origin'] === 'CUSTOM' && $resource['origin'] === 'CUSTOM' && (int)$resource['owner_id'] === (int)$feature['owner_id'])) {
                throw new BadRequestHttpException('Ressource inaccessible.');
            }
        }
        return ['casting_rules'=>$rules === null ? null : json_encode($rules, JSON_THROW_ON_ERROR), 'resource_definition_id'=>$resourceId];
    }
    #[Route('/options', methods:['GET'])]
    public function options(string $scope,int $featureId): JsonResponse
    {
        $this->feature($featureId,$scope);
        $actor=$this->getUser();
        $classes=$this->db->fetchAllAssociative("SELECT id,name FROM character_class WHERE origin='OFFICIAL' OR owner_id=? ORDER BY name",[$actor->getId()]);
        $spells=$this->db->fetchAllAssociative("SELECT id,name,level FROM spell_definition WHERE origin='OFFICIAL' OR owner_id=? ORDER BY level,name",[$actor->getId()]);
        return $this->json(['choiceAllowed'=>$this->allowsChoice($featureId),'classes'=>array_map(static fn(array $r)=>['id'=>(int)$r['id'],'name'=>$r['name']],$classes),'spells'=>array_map(static fn(array $r)=>['id'=>(int)$r['id'],'name'=>$r['name'],'level'=>(int)$r['level']],$spells)]);
    }
    #[Route('', methods:['GET'])]
    public function list(string $scope,int $featureId): JsonResponse
    { $this->feature($featureId,$scope); return $this->json(['grants'=>$this->rows($featureId)]); }

    #[Route('', methods:['POST'])]
    public function create(string $scope,int $featureId,Request $request, CharacterFeatureSpellService $featureSpells, CharacterRepository $characters): JsonResponse
    {
        $feature=$this->feature($featureId,$scope); $this->editable($feature,$scope);
        try { $v=json_decode($request->getContent(),true,512,JSON_THROW_ON_ERROR); } catch (\JsonException) { throw new BadRequestHttpException('JSON invalide.'); }
        if (!is_array($v)) throw new BadRequestHttpException('Objet attendu.');
        $mode=$v['mode']??null; $level=$v['unlockLevel']??1;
        if (!in_array($mode,['fixed','choice'],true) || !is_int($level) || $level<1 || $level>20) throw new BadRequestHttpException('Mode ou niveau invalide.');
        $ability=$v['castingAbility']??null;
        if ($ability!==null && !in_array($ability,['INT','WIS','CHA','STR','DEX','CON','CHOICE'],true)) throw new BadRequestHttpException('Caractéristique d’incantation invalide.');
        $spellId=null; $spellLevel=null; $classId=null; $schools=null;
        if ($mode==='fixed') {
            $spellId=$v['spellId']??null;
            if (!is_int($spellId) || !$this->db->fetchOne("SELECT 1 FROM spell_definition WHERE id=? AND (origin='OFFICIAL' OR owner_id=?)",[$spellId,$this->getUser()->getId()])) throw new BadRequestHttpException('Sort inaccessible.');
        } else {
            $spellLevel=$v['spellLevel']??null; $classId=$v['classId']??null; $schools=$v['schools']??[];
            if (!is_int($spellLevel) || $spellLevel<0 || $spellLevel>9 || ($classId!==null && (!is_int($classId) || !$this->db->fetchOne("SELECT 1 FROM character_class WHERE id=? AND (origin='OFFICIAL' OR owner_id=?)",[$classId,$this->getUser()->getId()]))) || !is_array($schools) || count($schools)>8) throw new BadRequestHttpException('Filtres invalides.');
            foreach ($schools as $school) if (!is_string($school) || !in_array($school,array_column(\App\Enum\SpellSchool::cases(),'value'),true)) throw new BadRequestHttpException('École invalide.');
            $schools=$schools===[]?null:json_encode(array_values(array_unique($schools)),JSON_THROW_ON_ERROR);
        }
        $baseLevel = $mode === 'fixed' ? (int)$this->db->fetchOne('SELECT level FROM spell_definition WHERE id=?', [$spellId]) : $spellLevel;
        $configuration = $this->castingConfiguration($feature, $v['castingRules'] ?? null, $v['resourceDefinitionId'] ?? null, $baseLevel);
        if (isset($v['casting']) && (array_key_exists('castingRules',$v) || array_key_exists('resourceDefinitionId',$v))) throw new BadRequestHttpException('Configurations incompatibles.');
        $this->db->transactional(function () use ($featureId, $mode, $spellId, $spellLevel, $classId, $schools, $level, $ability, $featureSpells, $characters, $configuration, $v, $feature, $baseLevel): void {
            $this->validateChoiceSource($featureId, $mode);
            $this->db->insert('feature_spell_grant',['feature_id'=>$featureId,'mode'=>$mode,'spell_id'=>$spellId,'spell_level'=>$spellLevel,'class_id'=>$classId,'schools'=>$schools,'unlock_level'=>$level,'casting_ability'=>$ability,...$configuration]);
            $grantId = (int)$this->db->lastInsertId();
            if (array_key_exists('casting', $v)) {
                $grant = $this->db->fetchAssociative('SELECT * FROM feature_spell_grant WHERE id=?', [$grantId]);
                $this->db->update('feature_spell_grant', $this->configuration->configure($feature, $grant, $v['casting'], $baseLevel), ['id'=>$grantId]);
            }
            if ($this->reconciler) $this->reconciler->reconcile([$featureId], $grantId);
            elseif ($mode === 'fixed') $featureSpells->propagateFixedGrant($grantId, $characters->findBy([], ['id'=>'ASC']));
            if (array_key_exists('casting', $v)) $this->configuration->synchronize($grantId);
        });
        return $this->json(['grants'=>$this->rows($featureId)],201);
    }
    #[Route('/{id}',requirements:['id'=>'\d+'],methods:['PATCH'])]
    public function configure(string $scope, int $featureId, int $id, Request $request, CharacterFeatureSpellService $featureSpells, CharacterRepository $characters): JsonResponse
    {
        $feature=$this->feature($featureId,$scope); $this->editable($feature,$scope);
        $payload=$request->toArray();
        if ($payload === [] || array_is_list($payload) || array_diff(array_keys($payload), ['castingRules','resourceDefinitionId','casting','castingAbility','enabled','spellId','spellLevel','classId','schools','unlockLevel'])) throw new BadRequestHttpException('Champs de configuration invalides.');
        if (array_key_exists('casting',$payload) && (array_key_exists('castingRules',$payload) || array_key_exists('resourceDefinitionId',$payload))) throw new BadRequestHttpException('Configurations incompatibles.');
        $this->db->transactional(function () use ($feature,$featureId,$id,$payload,$featureSpells,$characters): void {
            $candidates = $this->reconciler?->prepare([$featureId]);
            $this->configuration?->lockOwners($id);
            $grant=$this->db->fetchAssociative('SELECT * FROM feature_spell_grant WHERE id=? AND feature_id=? FOR UPDATE', [$id,$featureId]);
            if (!$grant) throw new NotFoundHttpException('Octroi introuvable.');
            $this->validateChoiceSource($featureId, $grant['mode']);
            $structural = [];
            if (array_key_exists('unlockLevel', $payload)) {
                if (!is_int($payload['unlockLevel']) || $payload['unlockLevel'] < 1 || $payload['unlockLevel'] > 20) throw new BadRequestHttpException('Niveau invalide.');
                $structural['unlock_level'] = $payload['unlockLevel'];
            }
            if (array_key_exists('spellId', $payload)) {
                if ($grant['mode'] !== 'fixed' || !is_int($payload['spellId']) || !$this->db->fetchOne("SELECT 1 FROM spell_definition WHERE id=? AND (origin='OFFICIAL' OR owner_id=?)", [$payload['spellId'],$this->getUser()->getId()])) throw new BadRequestHttpException('Sort inaccessible.');
                $structural['spell_id'] = $payload['spellId'];
            }
            foreach (['spellLevel','classId','schools'] as $field) {
                if (!array_key_exists($field, $payload)) continue;
                if ($grant['mode'] !== 'choice') throw new BadRequestHttpException('Filtres réservés aux choix.');
                $value = $payload[$field];
                if ($field === 'spellLevel') {
                    if (!is_int($value) || $value < 0 || $value > 9) throw new BadRequestHttpException('Niveau de sort invalide.');
                    $structural['spell_level'] = $value;
                } elseif ($field === 'classId') {
                    if ($value !== null && (!is_int($value) || !$this->db->fetchOne("SELECT 1 FROM character_class WHERE id=? AND (origin='OFFICIAL' OR owner_id=?)", [$value,$this->getUser()->getId()]))) throw new BadRequestHttpException('Classe inaccessible.');
                    $structural['class_id'] = $value;
                } else {
                    if (!is_array($value) || !array_is_list($value) || count($value) > 8) throw new BadRequestHttpException('Écoles invalides.');
                    foreach ($value as $school) if (!in_array($school,array_column(\App\Enum\SpellSchool::cases(),'value'),true)) throw new BadRequestHttpException('École invalide.');
                    $structural['schools'] = $value === [] ? null : json_encode(array_values(array_unique($value)),JSON_THROW_ON_ERROR);
                }
            }
            $grant = array_replace($grant, $structural);
            $rules=array_key_exists('castingRules',$payload) ? $payload['castingRules'] : ($grant['casting_rules']===null ? null : json_decode($grant['casting_rules'],true,512,JSON_THROW_ON_ERROR));
            $resourceId=array_key_exists('resourceDefinitionId',$payload) ? $payload['resourceDefinitionId'] : ($grant['resource_definition_id']===null ? null : (int)$grant['resource_definition_id']);
            $baseLevel=$grant['mode']==='fixed' ? (int)$this->db->fetchOne('SELECT level FROM spell_definition WHERE id=?',[$grant['spell_id']]) : (int)$grant['spell_level'];
            $changes = array_key_exists('casting', $payload)
                ? $this->configuration->configure($feature, $grant, $payload['casting'], $baseLevel)
                : $this->castingConfiguration($feature,$rules,$resourceId,$baseLevel,$id);
            if ($grant['managed_resource_id'] !== null && !array_key_exists('casting',$payload) && (array_key_exists('castingRules',$payload) || array_key_exists('resourceDefinitionId',$payload))) throw new BadRequestHttpException('Utiliser le formulaire des méthodes pour cette ressource automatique.');
            if (array_key_exists('castingAbility',$payload)) {
                if ($payload['castingAbility'] !== null && !in_array($payload['castingAbility'], ['STR','DEX','CON','INT','WIS','CHA','CHOICE'], true)) throw new BadRequestHttpException('Caractéristique invalide.');
                $changes['casting_ability'] = $payload['castingAbility'];
            }
            if (array_key_exists('enabled',$payload)) {
                if (!is_bool($payload['enabled'])) throw new BadRequestHttpException('Booléen attendu.');
                $changes['enabled'] = $payload['enabled'] ? 1 : 0;
            }
            $this->db->update('feature_spell_grant',$changes + $structural,['id'=>$id]);
            $this->reconciler?->reconcile([$featureId], $id, $candidates);
            $this->configuration?->synchronize($id);
        });
        return $this->json(['grants'=>$this->rows($featureId)]);
    }
    #[Route('/{id}',requirements:['id'=>'\d+'],methods:['DELETE'])]
    public function delete(string $scope,int $featureId,int $id): JsonResponse
    {
        $feature=$this->feature($featureId,$scope); $this->editable($feature,$scope);
        $count=$this->db->transactional(function () use ($id,$featureId): int {
            $candidates = $this->reconciler?->prepare([$featureId]);
            $this->configuration?->lockOwners($id);
            $count = $this->db->executeStatement('UPDATE feature_spell_grant SET enabled=false WHERE id=? AND feature_id=?', [$id,$featureId]);
            $this->reconciler?->reconcile([$featureId], $id, $candidates);
            return $count;
        });
        if (!$count) throw new NotFoundHttpException('Octroi introuvable.');
        return $this->json(['grants'=>$this->rows($featureId)]);
    }
}
