<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\MediaStorageService;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{JsonResponse, Request};
use Symfony\Component\HttpKernel\Exception\{BadRequestHttpException, NotFoundHttpException};
use Symfony\Component\Routing\Attribute\Route;

#[Route('/dnd/beast-features', name: 'api_dnd_beast_features_')]
final class BeastFeatureAdminController extends AbstractController
{
    public function __construct(private readonly Connection $db, private readonly MediaStorageService $storage) {}

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $this->requireEditor();
        $beasts = $this->db->fetchAllAssociative('SELECT id, name, slug, image_filename AS "imageFilename" FROM beast ORDER BY name');
        $features = $this->db->fetchAllAssociative('SELECT id, name, description FROM character_feature_definition WHERE visible = true ORDER BY name');
        $links = $this->db->fetchAllAssociative('SELECT beast_id AS "beastId", feature_definition_id AS "featureId" FROM beast_feature ORDER BY display_order, feature_definition_id');
        return $this->json(['beasts' => $beasts, 'features' => $features, 'links' => $links]);
    }

    #[Route('/{beastId}', name: 'replace', requirements: ['beastId' => '\d+'], methods: ['PUT'])]
    public function replace(int $beastId, Request $request): JsonResponse
    {
        $this->requireEditor();
        try { $payload = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new BadRequestHttpException('JSON invalide.'); }
        $ids = $payload['featureIds'] ?? null;
        if (!is_array($payload) || !is_array($ids) || !array_is_list($ids) || count($ids) > 200) {
            throw new BadRequestHttpException('featureIds doit être un tableau de 200 identifiants maximum.');
        }
        foreach ($ids as $id) {
            if (!is_int($id) || $id < 1) throw new BadRequestHttpException('Identifiant de capacité invalide.');
        }
        if (count($ids) !== count(array_unique($ids))) throw new BadRequestHttpException('Capacités en double.');
        $this->db->transactional(function () use ($beastId, $ids): void {
            if (!$this->db->fetchOne('SELECT id FROM beast WHERE id = :id FOR UPDATE', ['id' => $beastId])) {
                throw new NotFoundHttpException('Bête inconnue.');
            }
            foreach ($ids as $id) {
                if (!$this->db->fetchOne('SELECT id FROM character_feature_definition WHERE id = :id AND visible = true', ['id' => $id])) {
                    throw new BadRequestHttpException('Capacité inconnue ou masquée : '.$id);
                }
            }
            $this->db->executeStatement('DELETE FROM beast_feature WHERE beast_id = :id', ['id' => $beastId]);
            foreach ($ids as $order => $id) {
                $this->db->insert('beast_feature', ['beast_id' => $beastId, 'feature_definition_id' => $id, 'display_order' => $order]);
            }
        });
        return $this->json(['beastId' => $beastId, 'featureIds' => $ids]);
    }

    #[Route('/{beastId}/image', name: 'upload_image', requirements: ['beastId' => '\d+'], methods: ['POST'])]
    public function uploadImage(int $beastId, Request $request): JsonResponse
    {
        $this->requireEditor();
        $file = $request->files->get('image');
        if (!$file instanceof UploadedFile) throw new BadRequestHttpException('Fichier image requis.');
        if (!$this->db->fetchOne('SELECT 1 FROM beast WHERE id = :id', ['id' => $beastId])) throw new NotFoundHttpException('Bête inconnue.');
        try { $filename = $this->storage->storeBeast($file); }
        catch (\InvalidArgumentException $e) { throw new BadRequestHttpException($e->getMessage(), $e); }
        try {
            $old = $this->db->transactional(function () use ($beastId, $filename): ?string {
                $old = $this->db->fetchOne('SELECT image_filename FROM beast WHERE id = :id FOR UPDATE', ['id' => $beastId]);
                $this->db->executeStatement('UPDATE beast SET image_filename = :filename WHERE id = :id', ['filename' => $filename, 'id' => $beastId]);
                return $old === false ? null : $old;
            });
        } catch (\Throwable $e) { $this->storage->removeBeast($filename); throw $e; }
        if ($old) $this->storage->removeBeast($old);
        return $this->json(['beastId' => $beastId, 'imageUrl' => '/api/public/beasts/images/' . $filename]);
    }

    #[Route('/{beastId}/image', name: 'delete_image', requirements: ['beastId' => '\d+'], methods: ['DELETE'])]
    public function deleteImage(int $beastId): JsonResponse
    {
        $this->requireEditor();
        $old = $this->db->transactional(function () use ($beastId): ?string {
            $old = $this->db->fetchOne('SELECT image_filename FROM beast WHERE id = :id FOR UPDATE', ['id' => $beastId]);
            if ($old === false && !$this->db->fetchOne('SELECT 1 FROM beast WHERE id = :id', ['id' => $beastId])) throw new NotFoundHttpException('Bête inconnue.');
            $this->db->executeStatement('UPDATE beast SET image_filename = NULL WHERE id = :id', ['id' => $beastId]);
            return $old ?: null;
        });
        if ($old) $this->storage->removeBeast($old);
        return $this->json(['beastId' => $beastId, 'imageUrl' => null]);
    }

    private function requireEditor(): void
    {
        if (!$this->getUser() instanceof User) throw $this->createAccessDeniedException();
        $this->denyAccessUnlessGranted('ROLE_REFERENCE_ADMIN');
    }
}
