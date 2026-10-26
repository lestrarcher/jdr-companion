<?php
declare(strict_types=1);
namespace App\Controller;

use App\Entity\{Character, User};
use App\Service\CharacterSpellAcquisitions;
use App\Service\CharacterSpellInitializationPreview;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{JsonResponse, Request};
use Symfony\Component\HttpKernel\Exception\{BadRequestHttpException, NotFoundHttpException};
use Symfony\Component\Routing\Attribute\Route;

#[Route('/characters/{id}/spell-acquisitions', requirements: ['id' => '\d+'])]
final class CharacterSpellAcquisitionController extends AbstractController
{
    private function actor(Character $character): User
    {
        $actor = $this->getUser();
        if (!$actor instanceof User || $actor->getId() !== $character->getCampaign()->getOwner()->getId()) throw new NotFoundHttpException('Personnage indisponible.');
        return $actor;
    }

    #[Route('', name: 'api_character_spell_acquisition_status', methods: ['GET'])]
    public function status(Character $character, CharacterSpellAcquisitions $service): JsonResponse
    {
        return $this->json(['characterName' => $character->getName(), 'policies' => $service->status($character, $this->actor($character))]);
    }

    #[Route('/initialization', name: 'api_character_spell_initialization_preview', methods: ['GET'])]
    public function initialization(Character $character, CharacterSpellInitializationPreview $service): JsonResponse
    {
        return $this->json($service->preview($character, $this->actor($character)));
    }

    #[Route('/initialization', name: 'api_character_spell_initialization', methods: ['POST'])]
    public function initialize(Character $character, Request $request, CharacterSpellAcquisitions $service): JsonResponse
    {
        $actor = $this->actor($character);
        if ($request->getContentTypeFormat() !== 'json' || !str_starts_with(ltrim($request->getContent()), '{')) throw new BadRequestHttpException('Objet JSON requis.');
        $result = $service->initialize($character, $request->toArray(), $actor);
        return $this->json($result, $result['createdAcquisitionIds'] === [] ? 200 : 201);
    }

    #[Route('/{policyId}/historical-preview', name: 'api_character_spell_historical_preview', requirements: ['policyId' => '\d+'], methods: ['GET'])]
    public function historicalPreview(Character $character, int $policyId, CharacterSpellAcquisitions $service): JsonResponse
    {
        return $this->json($service->historicalPreview($character, $policyId, $this->actor($character)));
    }

    #[Route('/{policyId}/historical-completion', name: 'api_character_spell_historical_completion', requirements: ['policyId' => '\d+'], methods: ['POST'])]
    public function completeHistorical(Character $character, int $policyId, Request $request, CharacterSpellAcquisitions $service): JsonResponse
    {
        $actor = $this->actor($character);
        if ($request->getContentTypeFormat() !== 'json' || !str_starts_with(ltrim($request->getContent()), '{')) throw new BadRequestHttpException('Objet JSON requis.');
        $result = $service->completeHistorical($character, $policyId, $request->toArray(), $actor);
        return $this->json($result, $result['createdAcquisitionIds'] === [] ? 200 : 201);
    }

    #[Route('/{policyId}', name: 'api_character_spell_acquisition_options', requirements: ['policyId' => '\d+'], methods: ['GET'])]
    public function options(Character $character, int $policyId, CharacterSpellAcquisitions $service): JsonResponse
    {
        return $this->json($service->options($character, $policyId, $this->actor($character)));
    }

    #[Route('/{policyId}/copy/{spellId}', name: 'api_character_spell_copy_quote', requirements: ['policyId' => '\d+', 'spellId' => '\d+'], methods: ['GET'])]
    public function quote(Character $character, int $policyId, int $spellId, CharacterSpellAcquisitions $service): JsonResponse
    {
        return $this->json($service->copyQuote($character, $policyId, $spellId, $this->actor($character)));
    }

    #[Route('/{policyId}/copy/{spellId}', name: 'api_character_spell_copy', requirements: ['policyId' => '\d+', 'spellId' => '\d+'], methods: ['POST'])]
    public function copy(Character $character, int $policyId, int $spellId, Request $request, CharacterSpellAcquisitions $service): JsonResponse
    {
        $actor = $this->actor($character);
        if ($request->getContentTypeFormat() !== 'json' || !str_starts_with(ltrim($request->getContent()), '{')) throw new BadRequestHttpException('Objet JSON requis.');
        return $this->json(['acquisitionId' => $service->copy($character, $policyId, $spellId, $request->toArray(), $actor)], 201);
    }

    #[Route('/{policyId}/{acquisitionId}/qualification', name: 'api_character_spell_qualification', requirements: ['policyId' => '\d+', 'acquisitionId' => '\d+'], methods: ['POST'])]
    public function qualify(Character $character, int $policyId, int $acquisitionId, Request $request, CharacterSpellAcquisitions $service): JsonResponse
    {
        $actor = $this->actor($character);
        if ($request->getContentTypeFormat() !== 'json' || !str_starts_with(ltrim($request->getContent()), '{')) throw new BadRequestHttpException('Objet JSON requis.');
        $data = $request->toArray();
        if (array_diff(array_keys($data), ['ruleId', 'reason'])
            || !is_int($data['ruleId'] ?? null) || $data['ruleId'] <= 0
            || !is_string($data['reason'] ?? null)) throw new BadRequestHttpException('Qualification invalide.');
        return $this->json(['qualification' => $service->qualifyChoice(
            $character, $policyId, $acquisitionId, $data['reason'], $actor, $data['ruleId'],
        )], 201);
    }

    #[Route('/{policyId}/revision', name: 'api_character_spell_revision_options', requirements: ['policyId' => '\d+'], methods: ['GET'])]
    public function revisionOptions(Character $character, int $policyId, CharacterSpellAcquisitions $service): JsonResponse
    {
        return $this->json($service->revisionOptions($character,$policyId,$this->actor($character)));
    }

    #[Route('/{policyId}/revision', name: 'api_character_spell_revision', requirements: ['policyId' => '\d+'], methods: ['POST'])]
    public function revise(
        Character $character,
        int $policyId,
        Request $request,
        CharacterSpellAcquisitions $service
    ): JsonResponse {
        $actor = $this->actor($character);

        if (
            $request->getContentTypeFormat() !== 'json'
            || !str_starts_with(ltrim($request->getContent()), '{')
        ) {
            throw new BadRequestHttpException('Objet JSON requis.');
        }

        $data = $request->toArray();

        if (
            array_diff(array_keys($data), [
                'targetPolicyId',
                'spellIds',
                'expectedAcquisitionIds',
                'reason',
                'overrideRules',
                'confirmed',
                'ruleChoices',
            ])
            || !is_int($data['targetPolicyId'] ?? null)
            || !is_array($data['spellIds'] ?? null)
            || !is_array($data['expectedAcquisitionIds'] ?? null)
            || !is_string($data['reason'] ?? null)
            || !is_bool($data['overrideRules'] ?? null)
            || !is_bool($data['confirmed'] ?? null)
            || (array_key_exists('ruleChoices', $data) && !is_array($data['ruleChoices']))
        ) {
            throw new BadRequestHttpException('Révision invalide.');
        }

        return $this->json(
            $service->revise(
                $character,
                $policyId,
                $data['targetPolicyId'],
                $data['spellIds'],
                $data['expectedAcquisitionIds'],
                $data['reason'],
                $data['overrideRules'],
                $data['confirmed'],
                $actor,
                $data['ruleChoices'] ?? []
            ),
            201
        );
    }
}
