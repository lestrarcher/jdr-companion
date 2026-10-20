<?php
declare(strict_types=1);
namespace App\Controller;

use App\Entity\User;
use App\Service\{CustomReferenceFeatureService,CustomReferenceResourceService};
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request,JsonResponse};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpKernel\Exception\{BadRequestHttpException,HttpExceptionInterface};

#[Route('/reference/{scope}/{category}/editor',requirements:['scope'=>'custom|official','category'=>'features|resources'])]
final class ReferenceDefinitionEditorController extends AbstractController
{
    public function __construct(private readonly CustomReferenceFeatureService $features,private readonly CustomReferenceResourceService $resources) {}
    private function actor(): User { $actor=$this->getUser(); if (!$actor instanceof User) throw $this->createAccessDeniedException(); return $actor; }
    private function service(string $category): CustomReferenceFeatureService|CustomReferenceResourceService { return $category==='features'?$this->features:$this->resources; }
    private function payload(Request $request): array {
        if ($request->getContentTypeFormat()!=='json') throw new BadRequestHttpException('Objet JSON requis.');
        try { $value=json_decode($request->getContent(),false,512,JSON_THROW_ON_ERROR); } catch (\JsonException $e) { throw new BadRequestHttpException('JSON invalide.',$e); }
        if (!$value instanceof \stdClass) throw new BadRequestHttpException('Objet JSON requis.');
        return (array)$value;
    }
    private function respond(callable $operation,int $status=200): JsonResponse {
        try { return $this->json($operation(),$status); }
        catch (HttpExceptionInterface $e) { return $this->json(['message'=>$e->getMessage()],$e->getStatusCode()); }
    }
    #[Route('',name:'api_reference_definition_editor_list',methods:['GET'])]
    public function list(string $scope,string $category): JsonResponse { return $this->respond(fn()=>$this->service($category)->editorList($this->actor(),$scope==='official')); }
    #[Route('',name:'api_reference_definition_editor_create',methods:['POST'])]
    public function create(string $scope,string $category,Request $request): JsonResponse { return $this->respond(fn()=>[($category==='features'?'feature':'resource')=>$this->service($category)->saveEditor(null,$this->payload($request),$this->actor(),$scope==='official')],201); }
    #[Route('/{id}',name:'api_reference_definition_editor_patch',requirements:['id'=>'\d+'],methods:['PATCH'])]
    public function patch(string $scope,string $category,int $id,Request $request): JsonResponse { return $this->respond(fn()=>[($category==='features'?'feature':'resource')=>$this->service($category)->saveEditor($id,$this->payload($request),$this->actor(),$scope==='official')]); }
}
