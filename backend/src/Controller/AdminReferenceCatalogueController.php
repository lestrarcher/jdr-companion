<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\AdminReferenceCatalogue;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\{CsrfToken, CsrfTokenManagerInterface};

#[Route('/admin/reference/{category}', requirements: ['category' => 'classes|subclasses|races|feats|resources|progressions'])]
final class AdminReferenceCatalogueController extends AbstractController
{
    #[Route('', name: 'admin_catalogue_list', methods: ['GET'])]
    public function list(string $category, Request $request, AdminReferenceCatalogue $catalogue): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        $filters = $catalogue->filters($category);
        try {
            $context = $catalogue->context($request->query->all(), $filters);
        } catch (\InvalidArgumentException $error) {
            return $this->render('admin/error.html.twig', ['message' => $error->getMessage()], new Response(status: 422));
        }
        return $this->render('admin/catalogue-list.html.twig', [
            'category' => $category, 'definition' => AdminReferenceCatalogue::CATEGORIES[$category],
            'context' => $context, 'filters' => $filters, 'result' => $catalogue->search($category, $context),
        ]);
    }

    #[Route('/{id}', name: 'admin_catalogue_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(string $category, int $id, Request $request, AdminReferenceCatalogue $catalogue, CsrfTokenManagerInterface $csrf): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        $entity = $catalogue->find($category, $id);
        if (!$entity) return $this->render('admin/error.html.twig', ['message' => 'Entrée de référentiel introuvable.'], new Response(status: 404));
        try {
            $context = $catalogue->context($request->query->all(), $catalogue->filters($category));
        } catch (\InvalidArgumentException $error) {
            return $this->render('admin/error.html.twig', ['message' => $error->getMessage()], new Response(status: 422));
        }
        $values = $catalogue->values($entity);
        $error = null;
        $status = 200;
        if ($request->isMethod('POST')) {
            $payload = $request->request->all();
            foreach ($values as $key => $value) if (isset($payload[$key]) && is_string($payload[$key])) $values[$key] = $payload[$key];
            $token = $payload['_token'] ?? null;
            if (!is_string($token) || !$csrf->isTokenValid(new CsrfToken('admin_'.$category.'_'.$id, $token))) {
                $error = 'Le formulaire a expiré ou son jeton CSRF est invalide. Vérifiez votre texte puis réessayez.';
                $status = 403;
            } else {
                unset($payload['_token']);
                try {
                    $catalogue->updateEditorial($category, $entity, $payload);
                    $this->addFlash('success', 'Modifications enregistrées.');
                    return $this->redirectToRoute('admin_catalogue_edit', ['category' => $category, 'id' => $id] + $context, 303);
                } catch (\InvalidArgumentException $exception) {
                    $error = $exception->getMessage();
                    $status = 422;
                }
            }
        }
        return $this->render('admin/catalogue-edit.html.twig', [
            'category' => $category, 'definition' => AdminReferenceCatalogue::CATEGORIES[$category],
            'entity' => $entity, 'detail' => $catalogue->detail($category, $entity),
            'values' => $values, 'context' => $context, 'error' => $error,
        ], new Response(status: $status));
    }
}
