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
