<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\CustomReferenceResourceRuleService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{JsonResponse, Request};
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/reference/custom/resource-rules', name: 'api_custom_reference_resource_rule_')]
final class CustomReferenceResourceRuleController extends AbstractController
{
    public function __construct(private readonly CustomReferenceResourceRuleService $rules) {}

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        return $this->respond(fn () => ['rules' => $this->rules->list($this->owner())]);
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $owner = $this->owner();
        return $this->respond(fn () => ['rule' => $this->rules->create($this->payload($request), $owner)], 201);
    }

    #[Route('/{id}', name: 'get', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function get(int $id): JsonResponse
    {
        return $this->respond(fn () => ['rule' => $this->rules->get($id, $this->owner())]);
    }

    #[Route('/{id}', name: 'patch', requirements: ['id' => '\\d+'], methods: ['PATCH'])]
    public function patch(int $id, Request $request): JsonResponse
    {
        $owner = $this->owner();
        return $this->respond(function () use ($id, $request, $owner): array {
            $this->rules->get($id, $owner);
            return ['rule' => $this->rules->patch($id, $this->payload($request), $owner)];
        });
    }

    #[Route('/{id}', name: 'delete', requirements: ['id' => '\\d+'], methods: ['DELETE'])]
    public function delete(int $id): JsonResponse
    {
        return $this->respond(function () use ($id): null {
            $this->rules->delete($id, $this->owner());
            return null;
        }, 204);
    }

    private function respond(callable $operation, int $status = 200): JsonResponse
    {
        try {
            return $this->json($operation(), $status);
        } catch (HttpExceptionInterface $error) {
            if (!in_array($error->getStatusCode(), [400, 404, 409], true)) throw $error;
            return $this->json(['message' => $error->getMessage()], $error->getStatusCode());
        }
    }

    private function owner(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) throw $this->createAccessDeniedException();
        return $user;
    }

    private function payload(Request $request): array
    {
        if ($request->getContentTypeFormat() !== 'json') {
            throw new BadRequestHttpException('Le contenu doit être de type application/json.');
        }
        try {
            $payload = json_decode($request->getContent(), false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new BadRequestHttpException('JSON invalide.', $error);
        }
        if (!$payload instanceof \stdClass) throw new BadRequestHttpException('Un objet JSON est requis.');
        return (array) $payload;
    }
}
