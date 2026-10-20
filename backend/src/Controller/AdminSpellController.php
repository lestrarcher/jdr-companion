<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Enum\SpellSchool;
use App\Service\SpellCatalogue;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\HttpKernel\Exception\{BadRequestHttpException, HttpExceptionInterface};
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/reference/spells', name: 'admin_spell_')]
final class AdminSpellController extends AbstractController
{
    public function __construct(private readonly SpellCatalogue $catalogue) {}

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Request $request): Response
    {
        $user = $this->owner();
        try {
            $context = $this->catalogue->context(array_replace($request->query->all(), ['origin' => 'OFFICIAL']));
            return $this->render('admin/spell-list.html.twig', ['context' => $context, 'result' => $this->catalogue->search($context, $user, true), 'schools' => SpellSchool::cases(), 'classes' => $this->catalogue->classOptions($user, true)]);
        } catch (HttpExceptionInterface $error) { return $this->render('admin/error.html.twig', ['message' => $error->getMessage()], new Response(status: $error->getStatusCode())); }
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function create(Request $request): Response { return $this->form(null, 'OFFICIAL', $request); }

    #[Route('/{id}', name: 'edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(int $id, Request $request): Response { return $this->form($id, null, $request); }

    private function form(?int $id, ?string $origin, Request $request): Response
    {
        $user = $this->owner();
        $context = $this->catalogue->context(array_replace($request->query->all(), ['origin' => 'OFFICIAL']));
        if ($id === null && $origin === 'OFFICIAL' && !$this->catalogue->canManageOfficial($user)) throw $this->createAccessDeniedException();
        $values = $id === null ? array_fill_keys(array_keys(SpellCatalogue::TEXT), '') + array_fill_keys(array_keys(SpellCatalogue::FLAGS), false) + ['slug' => '', 'level' => 0, 'school' => 'abjuration', 'materialCostCp' => null, 'origin' => $origin, 'edition' => '2014', 'importKey' => null, 'editable' => true, 'classIds' => []] : $this->catalogue->get($id, $user, true);
        $error = null; $status = 200;
        $tokenId = 'spell_'.($id ?? 'new_'.$origin);
        if ($request->isMethod('POST')) {
            try {
                $input = $request->request->all();
                if (!is_string($input['_token'] ?? null) || !$this->isCsrfTokenValid($tokenId, $input['_token'])) throw $this->createAccessDeniedException('Jeton CSRF invalide.');
                $action = $input['_action'] ?? 'save';
                unset($input['_token'], $input['_action']);
                if ($action === 'delete' && $id !== null) {
                    if ($input !== []) throw new BadRequestHttpException('Suppression invalide.');
                    $this->catalogue->delete($id, $user, true);
                    $this->addFlash('success', 'Sort supprimé.');
                    return $this->redirectToRoute('admin_spell_list', $context, 303);
                }
                if ($action !== 'save') throw new BadRequestHttpException('Action invalide.');
                $payload = $this->formPayload($input);
                // Retain valid scalar form input on failure, never overwrite authorization flags.
                foreach ($payload as $key => $value) if (array_key_exists($key, SpellCatalogue::TEXT) || array_key_exists($key, SpellCatalogue::FLAGS) || in_array($key, ['slug', 'level', 'school', 'materialCostCp', 'classIds'], true)) $values[$key] = $value;
                $saved = $id === null ? $this->catalogue->create($payload, $user, true) : $this->catalogue->patch($id, $payload, $user, true);
                $this->addFlash('success', 'Sort enregistré.');
                return $this->redirectToRoute('admin_spell_edit', $context + ['id' => $saved['id']], 303);
            } catch (HttpExceptionInterface $exception) { $error = $exception->getMessage(); $status = $exception->getStatusCode(); }
        }
        return $this->render('admin/spell-edit.html.twig', ['values' => $values, 'id' => $id, 'context' => $context, 'schools' => SpellSchool::cases(), 'classes' => $this->catalogue->classOptions($user, true), 'tokenId' => $tokenId, 'error' => $error], new Response(status: $status));
    }

    private function formPayload(array $input): array
    {
        $classIds = $input['classIds'] ?? [];
        unset($input['classIds']);
        if (!is_array($classIds) || !array_is_list($classIds)) throw new BadRequestHttpException('Liste de classes invalide.');
        $normalized = [];
        foreach ($classIds as $id) {
            if (!is_string($id) || !ctype_digit($id) || (int) $id < 1) throw new BadRequestHttpException('Classe invalide.');
            $normalized[] = (int) $id;
        }
        foreach ($input as $value) if (!is_string($value)) throw new BadRequestHttpException('Valeur de formulaire invalide.');
        foreach (SpellCatalogue::FLAGS as $field => $_) {
            if (isset($input[$field]) && $input[$field] !== '1') throw new BadRequestHttpException('Booléen invalide.');
            $input[$field] = isset($input[$field]);
        }
        foreach (['level', 'materialCostCp'] as $field) {
            if ($field === 'materialCostCp' && ($input[$field] ?? '') === '') { $input[$field] = null; continue; }
            $value = filter_var($input[$field] ?? '', FILTER_VALIDATE_INT);
            if ($value === false) throw new BadRequestHttpException('Entier invalide : '.$field);
            $input[$field] = $value;
        }
        $input['classIds'] = $normalized;
        return $input;
    }

    private function owner(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) throw $this->createAccessDeniedException();
        if (!$this->catalogue->canManageOfficial($user)) throw $this->createAccessDeniedException();
        return $user;
    }
}
