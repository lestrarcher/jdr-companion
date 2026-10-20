<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\SpellCatalogue;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{JsonResponse, Request};
use Symfony\Component\HttpKernel\Exception\{BadRequestHttpException, HttpExceptionInterface};
use Symfony\Component\Routing\Attribute\Route;

#[Route('/reference/official/spells', name: 'api_official_spell_')]
final class OfficialSpellCatalogueController extends AbstractController
{
    public function __construct(private readonly SpellCatalogue $catalogue, private readonly \App\Service\SpellSubclassGrants $grants) {}

    #[Route('/grant-options', name: 'grant_options', methods: ['GET'])]
    public function grantOptions(): JsonResponse { return $this->respond(fn () => $this->grants->options($this->owner(), true)); }

    #[Route('/{id}/grants', name: 'grants', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function grants(int $id): JsonResponse { return $this->respond(fn () => ['grants' => $this->grants->list($id, $this->owner(), true)]); }

    #[Route('/{id}/grants', name: 'grant_create', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function createGrant(int $id, Request $request): JsonResponse { return $this->respond(fn () => ['grant' => $this->grants->save($id, null, $this->payload($request), $this->owner(), true)], 201); }

    #[Route('/{id}/grants/{grantId}', name: 'grant_patch', requirements: ['id' => '\d+', 'grantId' => '\d+'], methods: ['PATCH'])]
    public function patchGrant(int $id, int $grantId, Request $request): JsonResponse { return $this->respond(fn () => ['grant' => $this->grants->save($id, $grantId, $this->payload($request), $this->owner(), true)]); }

    #[Route('/{id}/grants/{grantId}', name: 'grant_delete', requirements: ['id' => '\d+', 'grantId' => '\d+'], methods: ['DELETE'])]
    public function deleteGrant(int $id, int $grantId): JsonResponse { return $this->respond(function () use ($id, $grantId): null { $this->grants->delete($id, $grantId, $this->owner(), true); return null; }, 204); }

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Request $request): JsonResponse { return $this->respond(fn () => $this->catalogue->search($request->query->all(), $this->owner(), true)); }

    #[Route('/{id}', name: 'get', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function get(int $id): JsonResponse { return $this->respond(fn () => ['spell' => $this->catalogue->get($id, $this->owner(), true)]); }

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(Request $request): JsonResponse { return $this->respond(fn () => ['spell' => $this->catalogue->create($this->payload($request), $this->owner(), true)], 201); }

    #[Route('/options', name: 'options', methods: ['GET'])]
    public function options(): JsonResponse { return $this->respond(fn () => ['classes' => $this->catalogue->classOptions($this->owner(), true), 'schools' => array_map(static fn ($school) => ['value' => $school->value, 'label' => $school->label()], \App\Enum\SpellSchool::cases())]); }

    #[Route('/{id}', name: 'patch', requirements: ['id' => '\d+'], methods: ['PATCH'])]
    public function patch(int $id, Request $request): JsonResponse { return $this->respond(fn () => ['spell' => $this->catalogue->patch($id, $this->payload($request), $this->owner(), true)]); }

    #[Route('/{id}', name: 'delete', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function delete(int $id): JsonResponse { return $this->respond(function () use ($id): null { $this->catalogue->delete($id, $this->owner(), true); return null; }, 204); }

    private function owner(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) throw $this->createAccessDeniedException();
        return $user;
    }

    private function payload(Request $request): array
    {
        if ($request->getContentTypeFormat() !== 'json') throw new BadRequestHttpException('Un objet JSON est requis.');
        try { $data = json_decode($request->getContent(), false, 512, JSON_THROW_ON_ERROR); }
        catch (\JsonException $error) { throw new BadRequestHttpException('JSON invalide.', $error); }
        if (!$data instanceof \stdClass) throw new BadRequestHttpException('Un objet JSON est requis.');
        return (array) $data;
    }

    private function respond(callable $operation, int $status = 200): JsonResponse
    {
        try { return $this->json($operation(), $status); }
        catch (HttpExceptionInterface $error) { return $this->json(['message' => $error->getMessage()], $error->getStatusCode()); }
    }
}
