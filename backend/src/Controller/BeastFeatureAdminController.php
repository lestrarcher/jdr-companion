<?php

declare(strict_types=1);
namespace App\Controller;

use App\Entity\{Beast, BeastHabitat, CharacterFeatureDefinition, User};
use App\Repository\BeastRepository;
use App\Service\{BeastEditor, BeastSerializer, MediaStorageService};
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\LockMode;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{JsonResponse, Request};
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\Exception\{BadRequestHttpException, ConflictHttpException, NotFoundHttpException};
use Symfony\Component\Routing\Attribute\Route;

#[Route('/dnd/beast-features', name: 'api_dnd_beast_features_')]
final class BeastFeatureAdminController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly BeastRepository $beasts,
        private readonly BeastEditor $editor,
        private readonly BeastSerializer $serializer,
        private readonly MediaStorageService $storage,
    ) {}

    #[Route('', name: 'list', methods: ['GET'])]
    #[Route('/scope/{scope}', name: 'scoped_list', requirements: ['scope' => 'custom|official'], methods: ['GET'])]
    public function list(string $scope = 'all'): JsonResponse
    {
        if ($scope === 'all') $this->requireEditor();
        elseif (!$this->getUser() instanceof User) throw $this->createAccessDeniedException();
        $beasts = $links = [];
        foreach ($this->beasts->catalogue() as $beast) {
            if (!$beast->isAccessibleTo($this->getUser())) continue;
            if ($scope !== 'all' && $beast->getOrigin()->value !== strtoupper($scope)) continue;
            $beasts[] = ['id' => $beast->getId(), 'slug' => $beast->getSlug(), 'name' => $beast->getName() ?? $beast->getSlug(), 'imageFilename' => $beast->getImageFilename(), 'origin' => $beast->getOrigin()->value];
            foreach ($beast->getFeatures() as $link) $links[] = ['beastId' => $beast->getId(), 'featureId' => $link->getDefinition()->getId()];
        }
        $definitions = $this->em->getRepository(CharacterFeatureDefinition::class)->findBy(['visible' => true], ['name' => 'ASC']);
        if ($scope !== 'all') $definitions = array_values(array_filter($definitions, fn ($f) => \App\Service\ReferenceVisibility::allows($f, $scope === 'custom' ? $this->getUser() : null)));
        $features = array_map(fn (CharacterFeatureDefinition $f) => ['id' => $f->getId(), 'name' => $f->getName(), 'description' => $f->getDescription()], $definitions);
        return $this->json(['beasts' => $beasts, 'features' => $features, 'links' => $links, 'habitats' => BeastHabitat::VALUES]);
    }

    #[Route('/{beastId}', name: 'detail', requirements: ['beastId' => '\d+'], methods: ['GET'])]
    public function detail(int $beastId): JsonResponse
    {
        $this->requireEditor();
        return $this->json(['beast' => $this->serializer->detail($this->requireBeast($beastId))]);
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $this->requireEditor();
        $data = $this->payload($request);
        $beast = new Beast('');
        $beast->markCustom($this->getUser());
        try { $this->em->wrapInTransaction(fn () => $this->editor->save($beast, $data)); }
        catch (UniqueConstraintViolationException) { throw new ConflictHttpException('Ce slug existe déjà.'); }
        return $this->json(['beast' => $this->serializer->detail($beast)], 201);
    }

    #[Route('/{beastId}/profile', name: 'edit', requirements: ['beastId' => '\d+'], methods: ['PUT'])]
    public function edit(int $beastId, Request $request): JsonResponse
    {
        $this->requireEditor();
        $data = $this->payload($request);
        $beast = $this->requireBeast($beastId);
        try {
            $this->em->wrapInTransaction(function () use ($beast, $data): void {
                $this->em->refresh($beast, LockMode::PESSIMISTIC_WRITE);
                $this->editor->save($beast, $data);
            });
        } catch (UniqueConstraintViolationException) { throw new ConflictHttpException('Ce slug existe déjà.'); }
        return $this->json(['beast' => $this->serializer->detail($beast)]);
    }

    #[Route('/{beastId}', name: 'replace', requirements: ['beastId' => '\d+'], methods: ['PUT'])]
    #[Route('/scope/{scope}/{beastId}', name: 'scoped_replace', requirements: ['scope' => 'custom|official', 'beastId' => '\d+'], methods: ['PUT'])]
    public function replace(int $beastId, Request $request, string $scope = 'official'): JsonResponse
    {
        if ($scope === 'official') $this->requireEditor();
        elseif (!$this->getUser() instanceof User) throw $this->createAccessDeniedException();
        $ids = $this->payload($request)['featureIds'] ?? null;
        $beast = $this->requireBeast($beastId);
        if ($beast->getOrigin()->value !== strtoupper($scope)) throw $this->createAccessDeniedException('Cette bête ne fait pas partie du référentiel sélectionné.');
        $this->em->wrapInTransaction(function () use ($beast, $ids, $scope): void {
            $this->em->refresh($beast, LockMode::PESSIMISTIC_WRITE);
            if (!$beast->isAccessibleTo($this->getUser())) throw $this->createAccessDeniedException();
            if ($beast->getOrigin()->value !== strtoupper($scope)) throw $this->createAccessDeniedException('Cette bête ne fait pas partie du référentiel sélectionné.');
            $existing = array_map(fn ($link) => $link->getDefinition()->getId(), $beast->getFeatures()->toArray());
            foreach (is_array($ids) ? $ids : [] as $id) {
                if (!is_int($id) || in_array($id, $existing, true)) continue;
                $definition = $this->em->find(CharacterFeatureDefinition::class, $id);
                if ($definition && !\App\Service\ReferenceVisibility::allows($definition, $scope === 'custom' ? $this->getUser() : null)) {
                    throw $this->createAccessDeniedException('Capacité indisponible dans ce référentiel.');
                }
            }
            $this->editor->replaceFeatures($beast, $ids);
        });
        return $this->json(['beastId' => $beastId, 'featureIds' => $ids]);
    }

    #[Route('/{beastId}', name: 'delete', requirements: ['beastId' => '\d+'], methods: ['DELETE'])]
    public function delete(int $beastId): JsonResponse
    {
        $this->requireEditor();
        $beast = $this->requireBeast($beastId);
        $filename = $this->em->wrapInTransaction(function () use ($beast, $beastId): ?string {
            $this->em->refresh($beast, LockMode::PESSIMISTIC_WRITE);
            if ($this->beasts->isReferenced($beastId)) throw new ConflictHttpException('Cette bête est observée ou utilisée par une transformation. Retirez ces références avant de la supprimer.');
            $this->em->remove($beast);
            return $beast->getImageFilename();
        });
        if ($filename) $this->storage->removeBeast($filename);
        return $this->json(['deleted' => $beastId]);
    }

    #[Route('/{beastId}/image', name: 'upload_image', requirements: ['beastId' => '\d+'], methods: ['POST'])]
    public function uploadImage(int $beastId, Request $request): JsonResponse
    {
        $this->requireEditor();
        $beast = $this->requireBeast($beastId);
        $file = $request->files->get('image');
        if (!$file instanceof UploadedFile) throw new BadRequestHttpException('Fichier image requis.');
        try { $filename = $this->storage->storeBeast($file); }
        catch (\InvalidArgumentException $e) { throw new BadRequestHttpException($e->getMessage(), $e); }
        try { $old = $this->changeImage($beast, $filename); }
        catch (\Throwable $e) { $this->storage->removeBeast($filename); throw $e; }
        if ($old) $this->storage->removeBeast($old);
        return $this->json(['beastId' => $beastId, 'imageUrl' => '/api/public/beasts/images/'.$filename]);
    }

    #[Route('/{beastId}/image', name: 'delete_image', requirements: ['beastId' => '\d+'], methods: ['DELETE'])]
    public function deleteImage(int $beastId): JsonResponse
    {
        $this->requireEditor();
        $old = $this->changeImage($this->requireBeast($beastId), null);
        if ($old) $this->storage->removeBeast($old);
        return $this->json(['beastId' => $beastId, 'imageUrl' => null]);
    }
    private function changeImage(Beast $beast, ?string $filename): ?string
    {
        return $this->em->wrapInTransaction(function () use ($beast, $filename): ?string {
            $this->em->refresh($beast, LockMode::PESSIMISTIC_WRITE);
            $old = $beast->getImageFilename();
            $beast->setImageFilename($filename);
            return $old;
        });
    }
    private function requireBeast(int $id): Beast
    {
        $beast = $this->beasts->find($id) ?? throw new NotFoundHttpException('Bête inconnue.');
        if (!$beast->isAccessibleTo($this->getUser())) throw $this->createAccessDeniedException();
        return $beast;
    }
    private function payload(Request $request): array
    {
        try { $data = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new BadRequestHttpException('JSON invalide.'); }
        if (!is_array($data) || array_is_list($data)) throw new BadRequestHttpException('Objet JSON attendu.');
        return $data;
    }
    private function requireEditor(): void
    {
        if (!$this->getUser() instanceof User) throw $this->createAccessDeniedException();
        $this->denyAccessUnlessGranted('ROLE_REFERENCE_ADMIN');
    }
}
