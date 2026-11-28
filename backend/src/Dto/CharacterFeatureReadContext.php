<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\{Character, CharacterFeatureRule, User};
use App\Service\CharacterFeatureResolver;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** Runtime rules for one synchronous, mutation-free projection. Never a service/cache. */
#[Exclude]
final class CharacterFeatureReadContext
{
    /** @param array<string, int> $progressionValues
     *  @param array<string, CharacterFeatureRule>|null $rules
     */
    private function __construct(
        private readonly Character $character,
        private readonly User $owner,
        private readonly array $progressionValues,
        private ?array $rules,
    ) {}

    /** The callback must only read. A new projection always resolves again. */
    public static function read(Character $character, array $progressionValues, CharacterFeatureResolver $resolver, callable $projection): array
    {
        ksort($progressionValues);
        $context = new self($character, $character->getCampaign()->getOwner(), $progressionValues,
            $resolver->resolve($character, $progressionValues));
        try {
            return $projection($context);
        } finally {
            // Even a retained context cannot serve rules after this projection/mutation boundary.
            $context->rules = null;
        }
    }

    /** Null means incompatible/closed: the caller must resolve independently. */
    public function rulesFor(Character $character, array $progressionValues): ?array
    {
        ksort($progressionValues);
        return $character === $this->character
            && $character->getCampaign()->getOwner() === $this->owner
            && $progressionValues === $this->progressionValues ? $this->rules : null;
    }
}
