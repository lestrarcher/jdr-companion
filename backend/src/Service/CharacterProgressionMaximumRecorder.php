<?php
declare(strict_types=1);
namespace App\Service;

use App\Entity\Character;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\LockMode;

/** Called only by mutation endpoints, after validation and under the character lock. */
final readonly class CharacterProgressionMaximumRecorder
{
    public function __construct(private EntityManagerInterface $em, private ?CharacterFeatureSpellService $spells = null) {}

    public function recordChanges(Character $character, array $before, array $after): void
    {
        if (!$this->em->getConnection()->isTransactionActive()) throw new \LogicException('Transaction required.');
        $this->em->lock($character, LockMode::PESSIMISTIC_WRITE);
        $old = CharacterSessionStateSynchronizer::extractProgressionValues($before);
        $new = CharacterSessionStateSynchronizer::extractProgressionValues($after);
        foreach ($character->getProgressions() as $assignment) {
            $definition = $assignment->getProgressionDefinition();
            $slug = $definition->getSlug();
            // An echoed snapshot or a missing old value is not evidence of a mutation.
            if (!$definition->isIrreversible() || !array_key_exists($slug, $old)
                || !array_key_exists($slug, $new) || $old[$slug] === $new[$slug]) continue;
            $value = $new[$slug];
            if ($value < $definition->getMinimumValue()
                || ($definition->getMaximumValue() !== null && $value > $definition->getMaximumValue())) {
                throw new \DomainException('Valeur de progression hors limites.');
            }
            $this->em->lock($assignment, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($assignment);
            // Only the accepted new value is proven; never infer an older maximum.
            $assignment->recordReachedValue($value);
            // The mutation controller subsequently synchronizes its accepted state.
            $this->spells?->synchronizeProgression($character, $definition, false);
        }
    }
}
