<?php
declare(strict_types=1);
namespace App\Controller;

use App\Entity\User;
use App\Service\CustomReferenceCatalogueService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request,JsonResponse};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpKernel\Exception\{BadRequestHttpException,HttpExceptionInterface};

#[Route('/reference/{scope}/{category}/editor',requirements:['scope'=>'custom|official','category'=>'classes|subclasses|feats|progressions'])]
final class ReferenceCatalogueEditorController extends AbstractController
{
    public function __construct(private readonly CustomReferenceCatalogueService $catalogue) {}

    private function actor(): User
    {
        $actor=$this->getUser();
        if (!$actor instanceof User) throw $this->createAccessDeniedException();
        return $actor;
    }

    private function payload(Request $request): array
    {
        if ($request->getContentTypeFormat()!=='json') throw new BadRequestHttpException('Objet JSON requis.');
        try { $data=json_decode($request->getContent(),false,512,JSON_THROW_ON_ERROR); }
        catch (\JsonException $e) { throw new BadRequestHttpException('JSON invalide.',$e); }
        if (!$data instanceof \stdClass) throw new BadRequestHttpException('Objet JSON requis.');
        return json_decode($request->getContent(),true,512,JSON_THROW_ON_ERROR);
    }

    private function respond(callable $operation,int $status=200): JsonResponse
    {
        try { return $this->json($operation(),$status); }
        catch (HttpExceptionInterface $e) { return $this->json(['message'=>$e->getMessage()],$e->getStatusCode()); }
        catch (\InvalidArgumentException|\DomainException $e) { return $this->json(['message'=>$e->getMessage()],400); }
        catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) { return $this->json(['message'=>'Identité déjà utilisée.'],409); }
    }

    #[Route('',name:'api_reference_catalogue_editor_list',methods:['GET'])]
    public function list(string $scope,string $category): JsonResponse
    {
        return $this->respond(fn()=>$this->catalogue->editorList($category,$this->actor(),$scope==='official'));
    }

    #[Route('',name:'api_reference_catalogue_editor_create',methods:['POST'])]
    public function create(string $scope,string $category,Request $request): JsonResponse
    {
        return $this->respond(fn()=>['item'=>$this->catalogue->saveEditor($category,null,$this->payload($request),$this->actor(),$scope==='official')],201);
    }

    #[Route('/{id}',name:'api_reference_catalogue_editor_patch',requirements:['id'=>'\d+'],methods:['PATCH'])]
    public function patch(string $scope,string $category,int $id,Request $request): JsonResponse
    {
        return $this->respond(fn()=>['item'=>$this->catalogue->saveEditor($category,$id,$this->payload($request),$this->actor(),$scope==='official')]);
    }

    #[Route('/{id}/{kind}',name:'api_reference_progression_child_create',requirements:['category'=>'progressions','id'=>'\d+','kind'=>'stages|adjustment-rules'],methods:['POST'])]
    #[Route('/{id}/{kind}/{childId}',name:'api_reference_progression_child_mutate',requirements:['category'=>'progressions','id'=>'\d+','childId'=>'\d+','kind'=>'stages|adjustment-rules'],methods:['PATCH','DELETE'])]
    public function child(string $scope,int $id,string $kind,Request $request,?int $childId=null): JsonResponse
    {
        return $this->respond(fn()=>['progression'=>$this->catalogue->progressionChild($id,$kind,$childId,$request->isMethod('DELETE')?[]:$this->payload($request),$this->actor(),$scope==='official',$request->isMethod('DELETE'))],$request->isMethod('POST')?201:200);
    }
}
