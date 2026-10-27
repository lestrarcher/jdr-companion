<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\{CharacterActiveEffect, CharacterSessionState, SpellDefinition};
use Doctrine\DBAL\{ArrayParameterType, LockMode};
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/** Only the two canonical HP spells; never consumes a casting resource. */
final readonly class CharacterSpellHitPointEffects
{
    public function __construct(
        private EntityManagerInterface $em,
        private CharacterHitPointBoostService $boost,
    ) {}

    /** Before locking the caster: overlapping/crossed casts share one character lock order.
     * Missing historical targets do not prevent receipt replay.
     */
    public function lockCharacters(CharacterSessionState $caster, array $command): void
    {
        if (!isset($command['targetCharacterIds'])) return;
        $ids = array_values(array_unique([$caster->getCharacter()->getId(), ...$command['targetCharacterIds']]));
        $this->em->getConnection()->executeQuery(
            'SELECT id FROM character WHERE id IN (?) ORDER BY id FOR UPDATE',
            [$ids], [ArrayParameterType::INTEGER],
        )->fetchFirstColumn();
    }

    /** Resolve and validate every target before the executor consumes anything.
     * @return array{type:string,amount:int,targets:list<CharacterSessionState>}|null
     */
    public function prepare(CharacterSessionState $caster, array $command, array $validation): ?array
    {
        $spell = $this->em->find(SpellDefinition::class, $validation['source']['spellId']);
        $type = match ($spell?->getImportKey()) {
            'aidedd-2014:aide' => CharacterActiveEffect::TYPE_AID,
            'aidedd-2014:festin-des-heros' => CharacterActiveEffect::TYPE_HEROES_FEAST,
            default => null,
        };
        if ($type === null) {
            if (isset($command['targetCharacterIds']) || isset($command['hitPointRoll'])) {
                throw new UnprocessableEntityHttpException('unexpected_spell_effect_parameters');
            }
            return null;
        }
        $aid = $type === CharacterActiveEffect::TYPE_AID;
        $ids = $command['targetCharacterIds'] ?? [];
        if ($ids === [] || count($ids) > ($aid ? 3 : 12)) {
            throw new UnprocessableEntityHttpException('invalid_spell_targets');
        }
        if ($validation['castingLevel'] < ($aid ? 2 : 6)) {
            throw new UnprocessableEntityHttpException('invalid_effect_casting_level');
        }
        if ($aid ? isset($command['hitPointRoll']) : !isset($command['hitPointRoll'])) {
            throw new UnprocessableEntityHttpException('invalid_spell_hit_point_roll');
        }
        $amount = $aid ? 5 * ($validation['castingLevel'] - 1) : $command['hitPointRoll'];
        $targets = [];
        foreach ($ids as $id) {
            $target = $this->em->getRepository(CharacterSessionState::class)->findOneBy([
                'gameSession' => $caster->getGameSession(), 'character' => $id,
            ]);
            if (!$target instanceof CharacterSessionState) throw new UnprocessableEntityHttpException('invalid_spell_target');
            $this->em->lock($target, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($target);
            if (!$target->isParticipating()) throw new UnprocessableEntityHttpException('invalid_spell_target');
            $this->em->refresh($target->getCharacter());
            try {
                $this->boost->validate($target, $type, $amount);
            } catch (\DomainException $error) {
                throw new UnprocessableEntityHttpException($error->getMessage(), $error);
            }
            $targets[] = $target;
        }
        return ['type'=>$type, 'amount'=>$amount, 'targets'=>$targets];
    }

    public function apply(CharacterSessionState $caster, ?array $effect): void
    {
        if ($effect === null) return;
        foreach ($effect['targets'] as $target) {
            $this->boost->applyHitPointBoost($caster->getCharacter(), $target, $effect['type'], $effect['amount']);
        }
    }
}
