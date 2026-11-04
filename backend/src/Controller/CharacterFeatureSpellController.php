<?php
declare(strict_types=1);
namespace App\Controller;

use App\Entity\{Character,User};
use App\Service\CharacterFeatureSpellService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{JsonResponse,Request};
use Symfony\Component\HttpKernel\Exception\{NotFoundHttpException,BadRequestHttpException};
use Symfony\Component\Routing\Attribute\Route;

#[Route('/characters/{id}/feature-spells',requirements:['id'=>'\d+'])]
final class CharacterFeatureSpellController extends AbstractController
{
    private function owner(Character $character): User {
        $actor=$this->getUser();
        if (!$actor instanceof User || $actor->getId()!==$character->getCampaign()->getOwner()->getId()) throw new NotFoundHttpException('Personnage indisponible.');
        return $actor;
    }
    #[Route('/ability-choices',name:'api_character_feature_spell_ability_choices',methods:['GET'])]
    public function abilityChoices(Character $character, \App\Service\CharacterFeatureSpellAbilityChoice $choices): JsonResponse {
        $this->owner($character); return $this->json(['choices'=>$choices->list($character)]);
    }
    #[Route('/{grantId}/ability',name:'api_character_feature_spell_ability',requirements:['grantId'=>'\d+'],methods:['PUT'])]
    public function chooseAbility(Character $character,int $grantId,Request $request,\App\Service\CharacterFeatureSpellAbilityChoice $choices): JsonResponse {
        $this->owner($character); return $this->json(['choices'=>$choices->choose($character,$grantId,$request->toArray()['ability']??null)]);
    }
    #[Route('',name:'api_character_feature_spells',methods:['GET'])]
    public function status(Character $character,CharacterFeatureSpellService $service): JsonResponse {
        $this->owner($character); return $this->json($service->status($character));
    }
    #[Route('/synchronize',name:'api_character_feature_spells_sync',methods:['POST'])]
    public function sync(Character $character,CharacterFeatureSpellService $service): JsonResponse {
        $this->owner($character); return $this->json($service->synchronize($character));
    }
    #[Route('/{grantId}/options',name:'api_character_feature_spells_options',requirements:['grantId'=>'\d+'],methods:['GET'])]
    public function options(Character $character,int $grantId,CharacterFeatureSpellService $service): JsonResponse {
        return $this->json(['spells'=>$service->options($character,$grantId,$this->owner($character)->getId())]);
    }
    #[Route('/{grantId}/choose',name:'api_character_feature_spells_choose',requirements:['grantId'=>'\d+'],methods:['POST'])]
    public function choose(Character $character,int $grantId,Request $request,CharacterFeatureSpellService $service): JsonResponse {
        $actor=$this->owner($character);
        $data=$request->toArray(); $spellId=$data['spellId']??null;
        if (!is_int($spellId)||$spellId<1) throw new BadRequestHttpException('spellId invalide.');
        return $this->json($service->choose($character,$grantId,$spellId,$actor->getId()));
    }
}
