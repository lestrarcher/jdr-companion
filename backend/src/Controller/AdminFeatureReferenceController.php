<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\AdminFeatureReference;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\{CsrfToken, CsrfTokenManagerInterface};

#[Route('/admin')]
final class AdminFeatureReferenceController extends AbstractController
{
    #[Route('', name: 'admin_dashboard', methods: ['GET'])]
    #[Route('/reference', name: 'admin_reference', methods: ['GET'])]
    public function dashboard(\App\Service\AdminReferenceCatalogue $catalogue): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        return $this->render('admin/dashboard.html.twig', ['counts' => $catalogue->counts()]);
    }

    #[Route('/reference/features', name: 'admin_features', methods: ['GET'])]
    public function list(Request $request, AdminFeatureReference $reference): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        try {
            $context = $this->context($request);
        } catch (\InvalidArgumentException $error) {
            return $this->render('admin/error.html.twig', ['message' => $error->getMessage()], new Response(status: 422));
        }
        return $this->render('admin/features.html.twig', [
            'result' => $reference->search($context['q'] ?? '', array_intersect_key($context, AdminFeatureReference::SOURCES + ['sourceType' => '']), $context['page'], 25),
            'context' => $context,
            'options' => $reference->filterOptions(),
        ]);
    }

    #[Route('/reference/features/{id}', name: 'admin_feature_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(int $id, Request $request, AdminFeatureReference $reference, CsrfTokenManagerInterface $csrf): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        $feature = $reference->find($id);
        if ($feature === null) {
            return $this->render('admin/error.html.twig', ['message' => 'Capacité introuvable.'], new Response(status: 404));
        }
        try {
            $context = $this->context($request);
        } catch (\InvalidArgumentException $error) {
            return $this->render('admin/error.html.twig', ['message' => $error->getMessage()], new Response(status: 422));
        }
        $values = ['name' => $feature->getName(), 'description' => $feature->getDescription() ?? ''];
        $error = null;
        $status = 200;
        if ($request->isMethod('POST')) {
            $payload = $request->request->all();
            foreach (array_keys($values) as $key) {
                if (isset($payload[$key]) && is_string($payload[$key])) $values[$key] = $payload[$key];
            }
            $token = $payload['_token'] ?? null;
            if (!is_string($token) || !$csrf->isTokenValid(new CsrfToken('admin_feature_'.$id, $token))) {
                $error = 'Le formulaire a expiré ou son jeton CSRF est invalide. Vérifiez votre texte puis réessayez.';
                $status = 403;
            } else {
                unset($payload['_token']);
                try {
                    $reference->updateEditorial($feature, $payload);
                    $this->addFlash('success', 'Modifications enregistrées.');
                    return $this->redirectToRoute('admin_feature_edit', ['id' => $id] + $context, 303);
                } catch (\InvalidArgumentException $exception) {
                    $error = $exception->getMessage();
                    $status = 422;
                }
            }
        }
        return $this->render('admin/edit.html.twig', [
            'feature' => $reference->detail($feature), 'values' => $values,
            'context' => $context, 'error' => $error,
        ], new Response(status: $status));
    }

    private function context(Request $request): array
    {
        $params = $request->query->all();
        $search = $params['q'] ?? '';
        if (!is_string($search) || mb_strlen($search) > 200 || str_contains($search, "\0")) {
            throw new \InvalidArgumentException('Recherche invalide (200 caractères maximum).');
        }
        $context = trim($search) === '' ? [] : ['q' => trim($search)];
        foreach (array_keys(AdminFeatureReference::SOURCES) as $key) {
            if (!isset($params[$key]) || $params[$key] === '') continue;
            $id = filter_var($params[$key], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false) throw new \InvalidArgumentException('Filtre invalide : '.$key.'.');
            $context[$key] = $id;
        }
        if (isset($params['sourceType']) && $params['sourceType'] !== '') {
            if (!is_string($params['sourceType']) || !isset(AdminFeatureReference::SOURCES[$params['sourceType']])) {
                throw new \InvalidArgumentException('Origine invalide.');
            }
            $context['sourceType'] = $params['sourceType'];
        }
        $page = filter_var($params['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000000]]);
        if ($page === false) throw new \InvalidArgumentException('Pagination invalide.');
        return $context + ['page' => $page];
    }
}
