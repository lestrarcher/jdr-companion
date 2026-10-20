<?php
declare(strict_types=1);
namespace App\Controller;
use App\Entity\{Character,User};
use App\Service\CharacterSpellSubstitutions;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request,JsonResponse};
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class CharacterSpellSubstitutionController extends AbstractController
{
    #[Route('/characters/{id}/spell-substitutions',name:'api_character_spell_substitutions',requirements:['id'=>'\d+'],methods:['GET','POST'])]
    public function handle(Character $character,Request $request,CharacterSpellSubstitutions $service): JsonResponse
    {
        $actor=$this->getUser();
        if (!$actor instanceof User || $character->getCampaign()->getOwner()->getId()!==$actor->getId()) throw $this->createNotFoundException();
        if ($request->isMethod('GET')) return $this->json($service->options($character,$actor));
        if ($request->getContentTypeFormat()!=='json' || !str_starts_with(ltrim($request->getContent()),'{')) throw new BadRequestHttpException('Objet JSON requis.');
        $d=$request->toArray();
        if (array_diff(array_keys($d),['ruleId','classLevelId','spellId','expectedPrevious','reason','confirmed','kind']) || !is_int($d['ruleId']??null) || !is_int($d['classLevelId']??null) || !is_int($d['spellId']??null) || !array_key_exists('expectedPrevious',$d) || ($d['expectedPrevious']!==null&&!is_int($d['expectedPrevious'])) || !is_string($d['reason']??null) || !is_bool($d['confirmed']??null) || !is_string($d['kind']??'substitution')) throw new BadRequestHttpException('Substitution invalide.');
        return $this->json(['id'=>$service->substitute($character,$d['ruleId'],$d['classLevelId'],$d['spellId'],$d['expectedPrevious'],$d['reason'],$d['confirmed'],$actor,$d['kind']??'substitution')],201);
    }
}
