<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\{MagicItem, MagicItemSpellGrant, SpellDefinition};
use App\Enum\{MagicItemRarity, MagicItemRechargeType, ReferenceOrigin};
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\{CsrfToken, CsrfTokenManagerInterface};

#[Route('/admin/reference/magic-items')]
final class AdminMagicItemController extends AbstractController
{
    private function authorize(): void { $this->denyAccessUnlessGranted('ROLE_REFERENCE_ADMIN'); }

    #[Route('', name: 'admin_magic_items', methods: ['GET'])]
    public function index(Request $request, EntityManagerInterface $em): Response
    {
        $this->authorize();
        $q = trim((string) $request->query->get('q', ''));
        $page = max(1, min(100000, (int) $request->query->get('page', 1)));
        if (mb_strlen($q) > 150) throw $this->createNotFoundException();
        $qb = $em->createQueryBuilder()->from(MagicItem::class, 'i')
            ->where('i.origin = :origin AND i.campaign IS NULL AND i.owner IS NULL')
            ->setParameter('origin', ReferenceOrigin::Official);
        if ($q !== '') $qb->andWhere('LOWER(i.name) LIKE :q OR LOWER(i.englishName) LIKE :q')
            ->setParameter('q', '%'.mb_strtolower($q).'%');
        $total = (int) (clone $qb)->select('COUNT(i.id)')->getQuery()->getSingleScalarResult();
        $items = $qb->select('i')->orderBy('i.name', 'ASC')->setFirstResult(($page - 1) * 25)
            ->setMaxResults(25)->getQuery()->getResult();
        return $this->render('admin/magic-items.html.twig', compact('items', 'total', 'page', 'q'));
    }

    #[Route('/new', name: 'admin_magic_item_new', methods: ['GET', 'POST'])]
    #[Route('/{id}', name: 'admin_magic_item_edit', requirements: ['id' => '\\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, EntityManagerInterface $em, CsrfTokenManagerInterface $csrf, ?int $id = null): Response
    {
        $this->authorize();
        $item = $id === null ? null : $em->getRepository(MagicItem::class)->find($id);
        if ($id !== null && (!$item || $item->getOrigin() !== ReferenceOrigin::Official || $item->getCampaign() !== null || $item->getOwner() !== null)) throw $this->createNotFoundException();
        $error = null;
        if ($request->isMethod('POST')) {
            $data = $request->request->all();
            if (!$csrf->isTokenValid(new CsrfToken('admin_magic_item_'.($id ?? 'new'), (string) ($data['_token'] ?? '')))) {
                $error = 'Jeton CSRF invalide.';
            } else {
                try {
                    $name = trim((string) ($data['name'] ?? ''));
                    if ($name === '' || mb_strlen($name) > 150) throw new \InvalidArgumentException('Nom invalide (150 caractères maximum).');
                    $rarity = ($data['rarity'] ?? '') === '' ? null : MagicItemRarity::tryFrom((string) $data['rarity']);
                    if (($data['rarity'] ?? '') !== '' && $rarity === null) throw new \InvalidArgumentException('Rareté invalide.');
                    if ($item === null) $item = new MagicItem(null, $name, $rarity, null, ReferenceOrigin::Official);
                    $item->setName($name)->setDescription((string) ($data['description'] ?? ''));
                    if ($rarity !== null) $item->setRarity($rarity);
                    $item->setRequiresAttunement(isset($data['attunement']));
                    $charges = trim((string) ($data['charges'] ?? ''));
                    if ($charges !== '' && (!ctype_digit($charges) || (int) $charges < 1)) throw new \InvalidArgumentException('Nombre de charges invalide.');
                    $item->setMaximumCharges($charges === '' ? null : (int) $charges);
                    $recharge = MagicItemRechargeType::tryFrom((string) ($data['recharge'] ?? 'none'));
                    if ($recharge === null) throw new \InvalidArgumentException('Recharge invalide.');
                    $item->setRechargeType($recharge);
                    $item->setRechargeFormula((string) ($data['formula'] ?? ''));
                    $em->persist($item);
                    $em->flush();
                    $this->addFlash('success', 'Objet enregistré.');
                    return $this->redirectToRoute('admin_magic_item_edit', ['id' => $item->getId()], 303);
                } catch (\InvalidArgumentException $e) { $error = $e->getMessage(); }
            }
        }
        $spellQuery = trim((string) $request->query->get('spell_q', ''));
        $spells = [];
        if ($item !== null && mb_strlen($spellQuery) >= 2 && mb_strlen($spellQuery) <= 100) {
            $spells = $em->createQueryBuilder()->select('s')->from(SpellDefinition::class, 's')
                ->where('s.origin = :origin AND s.owner IS NULL AND LOWER(s.name) LIKE :q')
                ->setParameter('origin', ReferenceOrigin::Official)
                ->setParameter('q', '%'.mb_strtolower($spellQuery).'%')
                ->orderBy('s.name')->setMaxResults(30)->getQuery()->getResult();
        }
        return $this->render('admin/magic-item-edit.html.twig', [
            'item' => $item, 'error' => $error, 'rarities' => MagicItemRarity::cases(),
            'recharges' => MagicItemRechargeType::cases(), 'spells' => $spells, 'spell_q' => $spellQuery,
        ], new Response(status: $error === null ? 200 : 422));
    }

    #[Route('/{id}/spells', name: 'admin_magic_item_spell_add', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function addSpell(int $id, Request $request, EntityManagerInterface $em, CsrfTokenManagerInterface $csrf): Response
    {
        $this->authorize();
        $item = $this->official($id, $em);
        if (!$csrf->isTokenValid(new CsrfToken('admin_magic_item_spell_'.$id, (string) $request->request->get('_token', '')))) throw $this->createAccessDeniedException();
        try {
            $spellId = filter_var($request->request->get('spell_id'), FILTER_VALIDATE_INT);
            $spell = $spellId ? $em->find(SpellDefinition::class, $spellId) : null;
            if (!$spell || $spell->getOrigin() !== ReferenceOrigin::Official || $spell->getOwner() !== null) throw new \InvalidArgumentException('Sort officiel introuvable.');
            foreach ($item->getSpellGrants() as $grant) if ($grant->getSpell()->getId() === $spellId) throw new \InvalidArgumentException('Ce sort est déjà associé.');
            $mode = (string) $request->request->get('mode', 'limited');
            $uses = $mode === 'limited' ? (int) $request->request->get('uses', 1) : null;
            $reset = $mode === 'limited' ? (string) $request->request->get('reset', 'manual') : null;
            $castingLevelRaw = trim((string) $request->request->get('casting_level', ''));
            $maxCastingLevelRaw = trim((string) $request->request->get('max_casting_level', ''));
            foreach (['Niveau de lancement' => $castingLevelRaw, 'Niveau maximal' => $maxCastingLevelRaw] as $label => $value) {
                if ($value !== '' && (!ctype_digit($value) || (int) $value < 1 || (int) $value > 9)) {
                    throw new \InvalidArgumentException($label.' invalide (1 à 9).');
                }
            }
            $castingLevel = $castingLevelRaw === '' ? null : (int) $castingLevelRaw;
            $maxCastingLevel = $maxCastingLevelRaw === '' ? null : (int) $maxCastingLevelRaw;
            $grant = new MagicItemSpellGrant($item, $spell, (string) $request->request->get('activation', 'possession'), $mode,
                (int) $request->request->get('cost', 1), $uses, $reset, $castingLevel, $maxCastingLevel);
            $item->addSpellGrant($grant);
            $em->persist($grant);
            $em->flush();
            $this->addFlash('success', 'Sort octroyé ajouté.');
        } catch (\InvalidArgumentException $e) { $this->addFlash('error', $e->getMessage()); }
        return $this->redirectToRoute('admin_magic_item_edit', ['id' => $id], 303);
    }

    #[Route('/{id}/spells/{grantId}/delete', name: 'admin_magic_item_spell_delete', requirements: ['id' => '\\d+', 'grantId' => '\\d+'], methods: ['POST'])]
    public function removeSpell(int $id, int $grantId, Request $request, EntityManagerInterface $em, CsrfTokenManagerInterface $csrf): Response
    {
        $this->authorize();
        $item = $this->official($id, $em);
        if (!$csrf->isTokenValid(new CsrfToken('admin_magic_item_spell_delete_'.$grantId, (string) $request->request->get('_token', '')))) throw $this->createAccessDeniedException();
        $grant = $em->find(MagicItemSpellGrant::class, $grantId);
        if (!$grant || $grant->getMagicItem() !== $item) throw $this->createNotFoundException();
        $uses = (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM character_magic_item_spell_usage WHERE magic_item_spell_grant_id = :id', ['id' => $grantId]);
        if ($uses > 0) {
            $this->addFlash('error', 'Impossible de retirer ce sort : des utilisations sont enregistrées.');
            return $this->redirectToRoute('admin_magic_item_edit', ['id' => $id], 303);
        }
        $item->removeSpellGrant($grant);
        $em->remove($grant);
        $em->flush();
        $this->addFlash('success', 'Octroi supprimé.');
        return $this->redirectToRoute('admin_magic_item_edit', ['id' => $id], 303);
    }

    #[Route('/{id}/delete', name: 'admin_magic_item_delete', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function delete(int $id, Request $request, EntityManagerInterface $em, CsrfTokenManagerInterface $csrf): Response
    {
        $this->authorize();
        $item = $this->official($id, $em);
        if (!$csrf->isTokenValid(new CsrfToken('admin_magic_item_delete_'.$id, (string) $request->request->get('_token', '')))) throw $this->createAccessDeniedException();
        // Never remove referenced catalogue objects: this would orphan inventories.
        $references = (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM character_magic_item WHERE magic_item_id = :id', ['id' => $id]);
        if ($references > 0) {
            $this->addFlash('error', 'Suppression impossible : cet objet est déjà attribué à un personnage.');
            return $this->redirectToRoute('admin_magic_item_edit', ['id' => $id], 303);
        }
        $em->remove($item);
        $em->flush();
        $this->addFlash('success', 'Objet supprimé.');
        return $this->redirectToRoute('admin_magic_items', [], 303);
    }

    private function official(int $id, EntityManagerInterface $em): MagicItem
    {
        $item = $em->find(MagicItem::class, $id);
        if (!$item || $item->getOrigin() !== ReferenceOrigin::Official || $item->getOwner() !== null || $item->getCampaign() !== null) throw $this->createNotFoundException();
        return $item;
    }
}
