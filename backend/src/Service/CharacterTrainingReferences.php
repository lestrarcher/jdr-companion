<?php
declare(strict_types=1);
namespace App\Service;

use App\Entity\{Character, LanguageDefinition, LanguageGrantRule, ExpertiseGrantRule, ProficiencyDefinition};
use App\Repository\ProficiencyGrantRuleRepository;
use Doctrine\ORM\EntityManagerInterface;

/** One owner-scoped snapshot per operation; no runtime reference imports. */
class CharacterTrainingReferences
{
    public function __construct(private EntityManagerInterface $em, private ProficiencyGrantRuleRepository $rules) {}
    public function load(Character $character): array
    {
        $owner = $character->getCampaign()->getOwner();
        $result = [];
        foreach (['languages' => LanguageDefinition::class, 'proficiencies' => ProficiencyDefinition::class,
            'languageRules' => LanguageGrantRule::class, 'expertiseRules' => ExpertiseGrantRule::class] as $key => $class) {
            $query = ReferenceVisibility::scope($this->em->createQueryBuilder()->select('r')->from($class, 'r'), 'r', $owner);
            $result[$key] = array_values(array_filter($query->getQuery()->getResult(), static fn ($r) => ReferenceVisibility::allows($r, $owner)));
        }
        $result['proficiencyRules'] = array_values(array_filter($this->rules->findVisibleForOwner($owner), static fn ($r) => ReferenceVisibility::allows($r, $owner)));
        return $result;
    }
}
