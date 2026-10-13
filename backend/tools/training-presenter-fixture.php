<?php

declare(strict_types=1);

use App\Repository\ProficiencyGrantRuleRepository;
use App\Service\{CharacterProficiencyResolver, CharacterRaceMetadataResolver, CharacterTrainingPresenter, CharacterTrainingReferences, CharacterTrainingResolver};
use Doctrine\Persistence\ManagerRegistry;

/** Wire the real presenter to the isolated database used by integration tests. */
function trainingPresenterForRegistry(ManagerRegistry $registry): CharacterTrainingPresenter
{
    $rules = new ProficiencyGrantRuleRepository($registry);
    $proficiencies = new CharacterProficiencyResolver($rules);
    $references = new CharacterTrainingReferences($registry->getManager(), $rules);
    return new CharacterTrainingPresenter($references, new CharacterTrainingResolver(
        $proficiencies, new CharacterRaceMetadataResolver(), $references,
    ), $proficiencies);
}

function trainingServiceForRegistry(ManagerRegistry $registry): App\Service\CharacterTrainingService
{
    $rules = new ProficiencyGrantRuleRepository($registry);
    $proficiencies = new CharacterProficiencyResolver($rules);
    $references = new CharacterTrainingReferences($registry->getManager(), $rules);
    $metadata = new CharacterRaceMetadataResolver();
    return new App\Service\CharacterTrainingService($references, new CharacterTrainingResolver($proficiencies, $metadata, $references),
        $proficiencies, new App\Service\CharacterProficiencyChoiceService(), $metadata);
}
