<?php
declare(strict_types=1);
namespace App\Service;

use App\Entity\{Character, CharacterFeatureRule, CharacterFeatureDefinition};
use Doctrine\ORM\EntityManagerInterface;

/** Reference mutations only: never invoked by a profile read or a session sync. */
final readonly class FeatureSpellGrantReconciler
{
    public function __construct(private EntityManagerInterface $em, private CharacterFeatureResolver $features,
        private CharacterFeatureSpellService $spells) {}

    /** Lock old and new beneficiaries before grant/state writes. @return list<Character> */
    public function prepare(array $featureIds): array
    {
        $db = $this->em->getConnection();
        if (!$db->isTransactionActive()) throw new \LogicException('Reconciliation requires a transaction.');
        // Rule/feature editors use DBAL. Refresh their managed ORM counterparts,
        // without clearing characters or losing pending session state changes.
        foreach ($this->em->getUnitOfWork()->getIdentityMap() as $entities) {
            foreach ($entities as $entity) {
                if ($entity instanceof CharacterFeatureRule || $entity instanceof CharacterFeatureDefinition) {
                    $this->em->refresh($entity);
                }
            }
        }
        $result = [];
        foreach ($this->em->getRepository(Character::class)->findBy([], ['id'=>'ASC']) as $character) {
            $owned = array_map(static fn($rule) => $rule->getFeatureDefinition()->getId(), $this->features->acquisitionRules($character));
            $historical = $db->fetchFirstColumn('SELECT g.feature_id FROM character_feature_spell a JOIN feature_spell_grant g ON g.id=a.grant_id WHERE a.character_id=?', [$character->getId()]);
            if (!array_intersect($featureIds, $owned) && !array_intersect($featureIds, $historical)) continue;
            $db->fetchOne('SELECT id FROM character WHERE id=? FOR UPDATE', [$character->getId()]);
            $result[] = $character;
        }
        return $result;
    }

    public function reconcile(array $featureIds, ?int $grantId = null, ?array $characters = null): void
    {
        $db = $this->em->getConnection();
        $characters ??= $this->prepare($featureIds);
        $ids = $grantId === null
            ? $db->fetchFirstColumn('SELECT id FROM feature_spell_grant WHERE feature_id IN (?) ORDER BY id', [$featureIds], [\Doctrine\DBAL\ArrayParameterType::INTEGER])
            : [$grantId];
        foreach ($characters as $character) foreach ($ids as $id) $this->spells->synchronize($character, (int)$id);
    }
}
