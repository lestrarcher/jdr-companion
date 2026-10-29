<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\CustomReferenceCatalogueService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{JsonResponse, Request};
use Symfony\Component\HttpKernel\Exception\{BadRequestHttpException, HttpExceptionInterface};
use Symfony\Component\Routing\Attribute\Route;

#[Route('/reference/custom/{category}', name: 'api_custom_reference_catalogue_', requirements: ['category'=>'classes|subclasses|races|feats|progressions'])]
final class CustomReferenceCatalogueController extends AbstractController
{
    public function __construct(private readonly CustomReferenceCatalogueService $catalogue) {}

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(string $category): JsonResponse
    {
        return $this->respond(fn () => ['items'=>$this->catalogue->list($category, $this->owner())]);
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(string $category, Request $request): JsonResponse
    {
        return $this->respond(fn () => ['item'=>$this->catalogue->create($category, $this->payload($request), $this->owner())], 201);
    }

    #[Route('/{id}', name: 'get', requirements: ['id'=>'\d+'], methods: ['GET'])]
    public function get(string $category, int $id): JsonResponse
    {
        return $this->respond(fn () => ['item'=>$this->catalogue->get($category, $id, $this->owner())]);
    }

    #[Route('/{id}', name: 'patch', requirements: ['id'=>'\d+'], methods: ['PATCH'])]
    public function patch(string $category, int $id, Request $request): JsonResponse
    {
        return $this->respond(fn () => ['item'=>$this->catalogue->patch($category, $id, $this->payload($request), $this->owner())]);
    }

    private function owner(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) throw $this->createAccessDeniedException();
        return $user;
    }

    private function payload(Request $request): array
    {
        if ($request->getContentTypeFormat() !== 'json') throw new BadRequestHttpException('Content-Type application/json requis.');
        try { $payload = json_decode($request->getContent(), false, 512, JSON_THROW_ON_ERROR); }
        catch (\JsonException $e) { throw new BadRequestHttpException('JSON invalide.', $e); }
        if (!$payload instanceof \stdClass) throw new BadRequestHttpException('Objet JSON requis.');
        return json_decode($request->getContent(),true,512,JSON_THROW_ON_ERROR);
    }

    private function respond(callable $operation, int $status = 200): JsonResponse
    {
        try { return $this->json($operation(), $status); }
        catch (HttpExceptionInterface $e) {
            if (!in_array($e->getStatusCode(), [400, 404, 409], true)) throw $e;
            return $this->json(['message'=>$e->getMessage()], $e->getStatusCode());
        }
        catch (\InvalidArgumentException|\DomainException $e) {
            return $this->json(['message'=>$e->getMessage()], 400);
        }
    }
}
