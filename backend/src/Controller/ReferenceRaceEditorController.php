<?php
declare(strict_types=1);
namespace App\Controller;

use App\Entity\User;
use App\Service\CustomReferenceCatalogueService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request,JsonResponse};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpKernel\Exception\{BadRequestHttpException,HttpExceptionInterface};

#[Route('/reference/{scope}/races/editor',requirements:['scope'=>'custom|official'])]
final class ReferenceRaceEditorController extends AbstractController
{
    public function __construct(private readonly CustomReferenceCatalogueService $catalogue) {}
    private function actor(): User { $user=$this->getUser(); if (!$user instanceof User) throw $this->createAccessDeniedException(); return $user; }
    private function payload(Request $request): array {
        if ($request->getContentTypeFormat()!=='json') throw new BadRequestHttpException('Objet JSON requis.');
        try { $data=json_decode($request->getContent(),false,512,JSON_THROW_ON_ERROR); } catch (\JsonException $e) { throw new BadRequestHttpException('JSON invalide.',$e); }
        if (!$data instanceof \stdClass) throw new BadRequestHttpException('Objet JSON requis.'); return json_decode($request->getContent(),true,512,JSON_THROW_ON_ERROR);
    }
    private function respond(callable $operation,int $status=200): JsonResponse {
        try { return $this->json($operation(),$status); }
        catch (HttpExceptionInterface $e) { return $this->json(['message'=>$e->getMessage()],$e->getStatusCode()); }
        catch (\InvalidArgumentException|\DomainException $e) { return $this->json(['message'=>$e->getMessage()],400); }
        catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException $e) { return $this->json(['message'=>'Identité déjà utilisée.'],409); }
    }
    #[Route('',name:'api_reference_race_editor_list',methods:['GET'])]
    public function list(string $scope): JsonResponse { return $this->respond(fn ()=>$this->catalogue->races($this->actor(),$scope==='official')); }
    #[Route('',name:'api_reference_race_editor_create',methods:['POST'])]
    public function create(string $scope,Request $request): JsonResponse { return $this->respond(fn ()=>['race'=>$this->catalogue->saveRace(null,$this->payload($request),$this->actor(),$scope==='official')],201); }
    #[Route('/{id}',name:'api_reference_race_editor_patch',requirements:['id'=>'\d+'],methods:['PATCH'])]
    public function patch(string $scope,int $id,Request $request): JsonResponse { return $this->respond(fn ()=>['race'=>$this->catalogue->saveRace($id,$this->payload($request),$this->actor(),$scope==='official')]); }
    #[Route('/{id}/ability-modifiers',name:'api_reference_race_editor_modifier',requirements:['id'=>'\d+'],methods:['POST'])]
    public function modifier(string $scope,int $id,Request $request): JsonResponse { return $this->respond(fn ()=>['race'=>$this->catalogue->addRaceModifier($id,$this->payload($request),$this->actor(),$scope==='official')],201); }
}
