<?php
declare(strict_types=1);
namespace App\Controller;

use App\Entity\User;
use App\Service\FeatureProficiencyEditorService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{JsonResponse, Request};
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/reference/custom/feature-proficiencies', name: 'api_feature_proficiencies_')]
final class FeatureProficiencyEditorController extends AbstractController
{
    public function __construct(private readonly FeatureProficiencyEditorService $editor) {}

    #[Route('/definitions', name: 'definitions', methods: ['GET'])]
    public function definitions(): JsonResponse { return $this->json(['definitions'=>$this->editor->definitions($this->owner())]); }

    #[Route('/{featureId}', name: 'list', requirements: ['featureId'=>'\\d+'], methods: ['GET'])]
    public function list(int $featureId): JsonResponse { return $this->json(['rules'=>$this->editor->get($featureId,$this->owner())]); }

    #[Route('/{featureId}', name: 'save', requirements: ['featureId'=>'\\d+'], methods: ['POST'])]
    public function save(int $featureId, Request $request): JsonResponse
    {
        try { $data=json_decode($request->getContent(),true,512,JSON_THROW_ON_ERROR); }
        catch (\JsonException $e) { throw new BadRequestHttpException('JSON invalide.', $e); }
        if (!is_array($data) || array_is_list($data)) throw new BadRequestHttpException('Objet JSON requis.');
        return $this->json(['rule'=>$this->editor->save($featureId,$this->owner(),$data)]);
    }

    private function owner(): User
    {
        $user=$this->getUser();
        if (!$user instanceof User) throw $this->createAccessDeniedException();
        return $user;
    }
}
