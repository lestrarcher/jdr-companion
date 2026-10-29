<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\LevelUpSpellChoices;
use App\Entity\CharacterClassLevel;
use App\Entity\SpellcastingPolicyDefinition;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Only called inside the authorized, character-locked level-up transaction. */
final readonly class CharacterLevelUpSpells
{
    public function __construct(
        private EntityManagerInterface $em,
        private CharacterSpellAcquisitions $acquisitions,
    ) {}

    public function lockReferences(): void
    {
        if (!$this->em->getConnection()->isTransactionActive()) throw new \LogicException('Transaction requise.');
        // Acquire the write-compatible lock before inserting a level. Taking SHARE only
        // after INSERT (as acquire() does) can deadlock two concurrent level-ups.
        // Same table order as the existing acquisition engine; explicit spell choices only.
        $this->em->getConnection()->executeStatement('LOCK TABLE spellcasting_policy_definition, character_class, character_subclass, character_class_level, spell_definition, spell_class_list_entry, spell_subclass_grant, spell_policy_choice_rule IN SHARE ROW EXCLUSIVE MODE');
    }

    public function apply(CharacterClassLevel $level, array $choices, bool $playerChoice): void
    {
        if (!$this->em->getConnection()->isTransactionActive() || $level->getId() === null) throw new \LogicException('Niveau persisté dans la transaction requis.');
        LevelUpSpellChoices::fromPayload(['spellChoices' => $choices]);
        $character = $level->getCharacter();
        $actor = $character->getCampaign()->getOwner();
        $status = $this->acquisitions->levelUpStatus($character, $level->getCharacterClass(), $actor);
        if ($status['status'] === 'no-spellcasting') {
            if ($choices !== []) throw new BadRequestHttpException('Aucun choix de sorts applicable à cette classe.');
            return;
        }
        if (($status['status'] ?? null) !== 'policy-configured') {
            throw new ConflictHttpException('Politique de sorts absente ou ambiguë : les choix explicites ne peuvent pas être validés.');
        }
        $policyId = $status['policyIds'][0];
        $definition = $this->em->find(SpellcastingPolicyDefinition::class, $policyId);
        $before = $this->acquisitions->options($character, $policyId, $actor, $level->getId());
        if ($before['blocked'] || $before['status'] === 'quota-exceeded') throw new ConflictHttpException('Quotas de sorts à régulariser avant cette sélection.');
        $internal = [];
        foreach ($choices as $choice) {
            if ($choice['policyId'] !== $policyId) throw new BadRequestHttpException('Politique étrangère à la classe de cette montée de niveau.');
            $item = ['spellId' => $choice['spellId'], 'classLevelId' => $level->getId()];
            if ($definition->getFormatVersion() === 2) {
                if ($choice['ruleId'] === null) throw new BadRequestHttpException('Règle avancée obligatoire.');
                $item['ruleId'] = $choice['ruleId'];
                if (array_key_exists('replacementConfirmed', $choice)) $item['optionConfirmed'] = $choice['replacementConfirmed'];
            } elseif ($choice['ruleId'] !== null) {
                throw new BadRequestHttpException('Une politique classique ne reçoit pas de règle avancée.');
            }
            if (isset($choice['replacesAcquisitionId'])) $item['replacesId'] = $choice['replacesAcquisitionId'];
            $internal[] = $item;
        }
        $note = $playerChoice
            ? 'Choix effectué via l’accès joueur pendant la montée de niveau ; propriétaire de campagne enregistré comme acteur technique.'
            : 'Choix effectué par le MJ pendant la montée de niveau.';
        // acquire() delegates format 2 to recordLocked(), and retains the classical engine.
        // Its nested DBAL transaction remains inside the enclosing ORM transaction.
        if ($internal !== []) $this->acquisitions->acquire($character, $policyId, $internal, 'level-history', $note, $actor);
        $after = $this->acquisitions->options($character, $policyId, $actor, $level->getId());
        if ($definition->getFormatVersion() === 2) {
            foreach ($after['choices'] as $route) {
                $capacity = $after['remaining'][$route['quota']] ?? null;
                // Spellbook/free opportunities are independent of a cumulative known quota.
                if ($route['remaining'] > 0 && ($capacity === null || $capacity > 0)) {
                    throw new ConflictHttpException('Apprentissage obligatoire incomplet : '.$route['key']);
                }
            }
        } else {
            foreach (['cantrips', 'known', 'spellbook'] as $bucket) {
                $required = min($definition->policy()->additionsAtLevel($before['classLevel'], $bucket), $before['remaining'][$bucket]);
                $learned = count(array_filter($after['acquisitions'], static fn(array $row): bool =>
                    (int) $row['class_level_id'] === $level->getId() && $row['replaces_id'] === null && $row['quota_bucket'] === $bucket));
                if ($learned < $required) throw new ConflictHttpException('Apprentissage obligatoire incomplet : '.$bucket);
            }
        }
    }
}
