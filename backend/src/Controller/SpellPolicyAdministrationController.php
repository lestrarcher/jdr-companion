<?php
declare(strict_types=1);
namespace App\Controller;
use App\Entity\User;
use App\Service\SpellPolicyAdministration;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request,Response,JsonResponse};
use Symfony\Component\HttpKernel\Exception\{BadRequestHttpException,HttpExceptionInterface};
use Symfony\Component\Routing\Attribute\Route;

final class SpellPolicyAdministrationController extends AbstractController
{
    private function actor(): User
    { $user=$this->getUser(); if (!$user instanceof User) throw $this->createAccessDeniedException(); return $user; }
    #[Route('/reference/custom/spell-policies',name:'api_personal_spell_policies',methods:['GET','POST'])]
    public function personal(Request $request,SpellPolicyAdministration $service): JsonResponse
    {
        $actor=$this->actor();
        if ($request->isMethod('GET')) return $this->json($service->options($actor));
        return $this->json(['id'=>$service->create($this->payload($request),$actor)],201);
    }
    #[Route('/reference/custom/spell-policies/substitution-rules',name:'api_personal_spell_substitution_rules',methods:['POST'])]
    public function substitution(Request $request,SpellPolicyAdministration $service): JsonResponse
    { return $this->json(['id'=>$service->defineSubstitution($this->payload($request),$this->actor())],201); }
    #[Route('/reference/custom/spell-policies/preview',name:'api_personal_spell_policy_preview',methods:['POST'])]
    public function preview(Request $request,SpellPolicyAdministration $service): JsonResponse
    { return $this->json(['document'=>$service->preview($this->payload($request),$this->actor())]); }
    private function payload(Request $request): array
    {
        if ($request->getContentTypeFormat()!=='json' || !str_starts_with(ltrim($request->getContent()),'{')) throw new BadRequestHttpException('Objet JSON requis.');
        return $request->toArray();
    }
    #[Route('/admin/reference/spell-policies',name:'admin_spell_policies',methods:['GET','POST'])]
    public function official(Request $request,SpellPolicyAdministration $service): Response
    {
        $actor=$this->actor(); $options=$service->options($actor,true); // Includes the role check on GET.
        $document=json_encode($options['example'],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE); $kind='policy'; $error=null; $status=200;
        if ($request->isMethod('GET') && $request->query->has('preset')) {
            $sourceId=filter_var($request->query->get('sourceId'),FILTER_VALIDATE_INT);
            if (!$sourceId || !in_array($request->query->get('tasha',''),['','1'],true)) throw new BadRequestHttpException('Sélection de préréglage invalide.');
            $document=json_encode($service->preview(['sourceId'=>$sourceId,'preset'=>$request->query->get('preset'),'tasha'=>$request->query->get('tasha')==='1'],$actor,true),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
        }
        if ($request->isMethod('POST')) try {
            $form=$request->request->all();
            if (!is_string($form['_token']??null) || !$this->isCsrfTokenValid('spell_policies',$form['_token'])) throw $this->createAccessDeniedException('Jeton CSRF invalide.');
            if (array_diff(array_keys($form),['_token','document','kind']) || !is_string($form['document']??null) || !in_array($form['kind']??null,['policy','substitution'],true)) throw new BadRequestHttpException('Formulaire invalide.');
            $document=$form['document']; $kind=$form['kind'];
            try { $data=json_decode($document,true,512,JSON_THROW_ON_ERROR); } catch (\JsonException) { throw new BadRequestHttpException('JSON invalide.'); }
            if (!is_array($data) || !str_starts_with(ltrim($document),'{')) throw new BadRequestHttpException('Objet requis.');
            if ($kind==='policy') $service->create($data,$actor,true); else $service->defineSubstitution($data,$actor,true);
            $this->addFlash('success','Nouvelle version enregistrée. Les personnages existants ne sont pas migrés.');
            return $this->redirectToRoute('admin_spell_policies',status:303);
        } catch (HttpExceptionInterface $e) { $error=$e->getMessage(); $status=$e->getStatusCode(); }
        return $this->render('admin/spell-policies.html.twig',compact('options','document','kind','error'),new Response(status:$status));
    }
}
