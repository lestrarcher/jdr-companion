<?php
declare(strict_types=1);
namespace App\Controller;

use App\Entity\User;
use App\Service\{SpellCatalogue, SpellSubclassGrants};
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\HttpKernel\Exception\{BadRequestHttpException, HttpExceptionInterface};
use Symfony\Component\Routing\Attribute\Route;

final class AdminSpellGrantController extends AbstractController
{
    public function __construct(private readonly SpellCatalogue $spells, private readonly SpellSubclassGrants $grants) {}

    #[Route('/admin/reference/spells/{id}/grants', name: 'admin_spell_grants', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(int $id, Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User || !$this->spells->canManageOfficial($user)) throw $this->createAccessDeniedException();
        $spell = $this->spells->get($id, $user, true);
        $context = $this->spells->context($request->query->all());
        $values = ['id' => '', 'subclassId' => '', 'mode' => 'add-to-list', 'unlockLevel' => 1, 'castingClassId' => '', 'slotUsage' => '', 'restrictions' => '', 'resourceId' => '', 'resourceCost' => ''];
        $values['quotaImpact'] = 'unspecified';
        $error = null; $status = 200;
        if ($request->isMethod('POST')) {
            try {
                $input = $request->request->all();
                if (!is_string($input['_token'] ?? null) || !$this->isCsrfTokenValid('spell_grants_'.$id, $input['_token'])) throw $this->createAccessDeniedException('Jeton CSRF invalide.');
                foreach ($input as $value) if (!is_string($value)) throw new BadRequestHttpException('Formulaire invalide.');
                $action = $input['_action'] ?? 'save'; unset($input['_action'], $input['_token']);
                $values = array_replace($values, array_intersect_key($input, $values));
                $grantId = ($input['id'] ?? '') === '' ? null : filter_var($input['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($grantId === false) throw new BadRequestHttpException('Identifiant invalide.');
                unset($input['id']);
                if ($action === 'delete' && $grantId !== null) {
                    if ($input !== []) throw new BadRequestHttpException('Suppression invalide.');
                    $this->grants->delete($id, $grantId, $user, true);
                } elseif ($action === 'save') {
                    foreach (['subclassId', 'unlockLevel', 'castingClassId', 'resourceId', 'resourceCost'] as $key) {
                        if (!array_key_exists($key, $input)) continue;
                        $input[$key] = $input[$key] === '' ? null : filter_var($input[$key], FILTER_VALIDATE_INT);
                        if ($input[$key] === false) throw new BadRequestHttpException('Entier invalide : '.$key);
                    }
                    foreach (['slotUsage', 'restrictions'] as $key) if (($input[$key] ?? null) === '') $input[$key] = null;
                    $this->grants->save($id, $grantId, $input, $user, true);
                } else throw new BadRequestHttpException('Action invalide.');
                $this->addFlash('success', 'Attribution enregistrée.');
                return $this->redirectToRoute('admin_spell_grants', ['id' => $id] + $context, 303);
            } catch (HttpExceptionInterface $e) { $error = $e->getMessage(); $status = $e->getStatusCode(); }
        }
        return $this->render('admin/spell-grants.html.twig', ['spell' => $spell, 'context' => $context, 'grants' => $this->grants->list($id, $user, true), 'options' => $this->grants->options($user, true), 'values' => $values, 'error' => $error], new Response(status: $status));
    }
}
