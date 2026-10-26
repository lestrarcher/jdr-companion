<?php
declare(strict_types=1);
namespace App\Service;

use App\Entity\Character;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/** Read-only interpretation of catalogue rules, not permission or availability. */
final readonly class CharacterFeatureSpellCastingResolver
{
    public function __construct(private Connection $db, private CharacterFeatureResolver $features) {}

    public function resolve(Character $character, int $grantId, array $progressionValues = []): array
    {
        $result=['status'=>'unknown','reason'=>null,'grantId'=>$grantId,'provenance'=>null,
            'castingAbility'=>null,'abilityReason'=>'provenance_unresolved','configurationVersion'=>null,'methods'=>[],'restrictions'=>null,'requiresRestrictionReview'=>false];
        $grant=$this->db->fetchAssociative('SELECT g.*,a.spell_id AS acquired_spell_id,s.level AS acquired_level,d.origin AS feature_origin,d.owner_id AS feature_owner FROM character_feature_spell a JOIN feature_spell_grant g ON g.id=a.grant_id JOIN spell_definition s ON s.id=a.spell_id JOIN character_feature_definition d ON d.id=g.feature_id WHERE a.character_id=? AND a.grant_id=? AND a.active=true',[$character->getId(),$grantId]);
        if (!$grant) return array_replace($result,['reason'=>'acquisition_missing']);
        try { $rawRules=$grant['casting_rules']===null?null:json_decode($grant['casting_rules'],true,512,JSON_THROW_ON_ERROR); }
        catch (\JsonException) { $rawRules=null; }
        if (is_array($rawRules) && is_string($rawRules['restrictions']??null)) {
            $result['restrictions']=$rawRules['restrictions'];
            $result['requiresRestrictionReview']=true;
        }
        if ((int)$grant['unlock_level']>$character->getTotalLevel()) return array_replace($result,['reason'=>'grant_not_applicable']);
        if ($grant['mode']==='fixed' ? (int)$grant['spell_id']!==(int)$grant['acquired_spell_id'] : (int)$grant['spell_level']!==(int)$grant['acquired_level']) {
            return array_replace($result,['reason'=>'acquisition_inconsistent']);
        }
        $origins=[];
        foreach ($this->features->applicableRules($character,$progressionValues) as $rule) {
            if ($rule->getFeatureDefinition()->getId()!==(int)$grant['feature_id']) continue;
            foreach (['feat'=>$rule->getFeat(),'class'=>$rule->getCharacterClass(),'subclass'=>$rule->getCharacterSubclass(),
                'race'=>$rule->getCharacterRace(),'progression'=>$rule->getProgressionDefinition()] as $type=>$source) {
                if ($source===null) continue;
                $key=$type.':'.$source->getId();
                $origins[$key] ??= ['type'=>$type,'id'=>$source->getId(),'ruleIds'=>[],'characterFeatId'=>null];
                $origins[$key]['ruleIds'][]=$rule->getId();
            }
        }
        if (count($origins)!==1) return array_replace($result,['reason'=>$origins===[]?'provenance_missing':'provenance_ambiguous']);
        $origin=array_values($origins)[0]; sort($origin['ruleIds']);
        $assignments=[];
        if ($origin['type']==='feat') {
            foreach ($character->getFeats() as $assignment) if ($assignment->getFeat()->getId()===$origin['id']) $assignments[]=$assignment;
            if (count($assignments)!==1) return array_replace($result,['reason'=>$assignments===[]?'provenance_missing':'provenance_ambiguous']);
            $origin['characterFeatId']=$assignments[0]->getId();
        }
        $result['provenance']=$origin;
        if ($grant['casting_ability']==='CHOICE') {
            $ability=$assignments===[] ? null : $assignments[0]->getChosenAbility();
            $abilityReason=$origin['type']==='feat'?'ability_choice_missing':'ability_choice_unsupported';
        } else {
            $ability=CharacterFeatureSpellService::castingAbility($grant['casting_ability']);
            $abilityReason=$grant['casting_ability']===null?'ability_not_configured':'ability_invalid';
        }
        $result['castingAbility']=$ability?->value;
        $result['abilityReason']=$ability===null?$abilityReason:null;
        try {
            $rules=$grant['casting_rules']===null ? null : json_decode($grant['casting_rules'],true,512,JSON_THROW_ON_ERROR);
            $resourceId=$grant['resource_definition_id']===null?null:(int)$grant['resource_definition_id'];
            $rules=FeatureSpellCastingRules::validate($rules,$resourceId,(int)$grant['acquired_level']);
            if ($rules===null) return array_replace($result,['reason'=>'casting_rules_missing']);
            $result['configurationVersion']=$rules['version'];
            if ($resourceId!==null) {
                $resource=$this->db->fetchAssociative('SELECT origin,owner_id FROM trackable_resource_definition WHERE id=?',[$resourceId]);
                if (!$resource || !($resource['origin']==='OFFICIAL' && $resource['owner_id']===null)
                    && !($grant['feature_origin']==='CUSTOM' && $resource['origin']==='CUSTOM' && (int)$resource['owner_id']===(int)$grant['feature_owner'])) {
                    return array_replace($result,['reason'=>'resource_definition_unresolved']);
                }
            }
        } catch (\JsonException|BadRequestHttpException) {
            return array_replace($result,['reason'=>'casting_rules_invalid']);
        }
        $result['restrictions']=$rules['restrictions']??null;
        $result['requiresRestrictionReview']=$result['restrictions']!==null;
        if ($ability===null) return array_replace($result,['reason'=>$abilityReason]);
        foreach ($rules['methods'] as $method) {
            $result['methods'][]=$method['kind']==='resource'
                ? ['kind'=>'resource','resourceDefinitionId'=>$resourceId,'cost'=>$method['cost'],'castingLevel'=>$method['castingLevel']]
                : ['kind'=>'standard-slot','minimumCastingLevel'=>(int)$grant['acquired_level']];
        }
        return array_replace($result,['status'=>'resolved','reason'=>null]);
    }
}
