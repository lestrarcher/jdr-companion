<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\CustomFeatureChoiceEditorService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{JsonResponse, Request};
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/reference/custom/features/{featureId}/choices', name: 'api_custom_feature_choices_', requirements: ['featureId' => '\\d+'])]
final class CustomFeatureChoiceEditorController extends AbstractController
{
    public function __construct(private readonly CustomFeatureChoiceEditorService $editor) {}

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(int $featureId): JsonResponse
    {
        return $this->json(['choices' => $this->editor->get($featureId, $this->owner())]);
    }

    #[Route('', name: 'save', methods: ['POST'])]
    public function save(int $featureId, Request $request): JsonResponse
    {
        if ($request->getContentTypeFormat() !== 'json') throw new BadRequestHttpException('JSON requis.');
        try { $payload = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR); }
        catch (\JsonException $e) { throw new BadRequestHttpException('JSON invalide.', $e); }
        if (!is_array($payload) || array_is_list($payload)) throw new BadRequestHttpException('Objet JSON requis.');
        return $this->json(['choice' => $this->editor->save($featureId, $this->owner(), $payload)]);
    }

    private function owner(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) throw $this->createAccessDeniedException();
        return $user;
    }
}
