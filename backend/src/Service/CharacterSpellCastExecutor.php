<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CharacterSessionState;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/** Internal command boundary. No effects or public endpoint. */
final readonly class CharacterSpellCastExecutor
{
    public function __construct(
        private EntityManagerInterface $em,
        private CharacterSpellCastCommandValidator $validator,
    ) {}

    /**
     * Owns the transaction; call with a managed session and no pending unrelated writes.
     * @return array{cast:array,revision:int,state:array,replayed:bool} Returned only after commit.
     */
    public function execute(CharacterSessionState $session, array $command): array
    {
        $db = $this->em->getConnection();
        if ($db->isTransactionActive()) {
            throw new \LogicException('Spell execution must own its transaction.');
        }
        $mutated = false;
        $db->beginTransaction();
        try {
            $result = (function () use ($session, $command, &$mutated): array {
                PlayerCharacterAccess::lockForMutation($this->em, $session);
                try {
                    $command = SpellCastReceipts::normalizeCommand($command);
                } catch (\InvalidArgumentException $error) {
                    throw new UnprocessableEntityHttpException('invalid_command', $error);
                }
                $state = $session->getState();
                try {
                    $receiptStatus = SpellCastReceipts::compare($state, $command);
                } catch (\UnexpectedValueException $error) {
                    throw new ConflictHttpException($error->getMessage(), $error);
                }
                if ($receiptStatus === 'conflict') {
                    throw new ConflictHttpException('request_id_conflict');
                }
                if ($receiptStatus === 'identical') {
                    return [
                        'cast'=>$state[SpellCastReceipts::STATE_KEY][$command['requestId']]['cast'],
                        'revision'=>$session->getRevision(),
                        'state'=>$state,
                        'replayed'=>true,
                    ];
                }
                $revision = $command['revision'];
                if ($session->getRevision() !== $revision) {
                    throw new ConflictHttpException('stale_revision');
                }
                $this->em->lock($session, LockMode::OPTIMISTIC, $revision);
                $validation = $this->validator->validate($session->getCharacter(), $state, $command);
                if ($validation['valid'] !== true) {
                    throw new UnprocessableEntityHttpException($validation['reason'] ?? 'cast_not_allowed');
                }
                $method = $validation['method'];
                if (in_array($method, ['standard-slot', 'pact-slot'], true)) {
                    $slug = $method === 'standard-slot' ? 'spell-slot-'.$validation['castingLevel'] : 'warlock-pact-slots';
                    if ($validation['poolSlug'] !== $slug || $validation['cost'] !== 1) {
                        throw new UnprocessableEntityHttpException('invalid_cast_cost');
                    }
                    $indices = [];
                    foreach ($state['resources'] ?? [] as $index => $resource) {
                        if (is_array($resource) && ($resource['id'] ?? null) === $slug) $indices[] = $index;
                    }
                    if (count($indices) !== 1) throw new UnprocessableEntityHttpException('invalid_counter');
                    $index = $indices[0];
                    $remaining = $state['resources'][$index]['currentValue'] ?? null;
                    if (!is_int($remaining) || $remaining < 1) throw new UnprocessableEntityHttpException('invalid_counter');
                    $state['resources'][$index]['currentValue'] = $remaining - 1;
                } elseif (!in_array($method, ['cantrip', 'ritual'], true)
                    || $validation['poolSlug'] !== null || $validation['cost'] !== 0) {
                    throw new UnprocessableEntityHttpException('invalid_cast_cost');
                }
                $cast = array_intersect_key($validation, array_flip(['source','method','castingLevel','poolSlug','cost']));
                $state[SpellCastReceipts::STATE_KEY][$command['requestId']] = SpellCastReceipts::create($command, $cast);
                $mutated = true;
                // The receipt makes zero-cost acceptance a durable state change too.
                $session->setState($state);
                $this->em->flush();
                return [
                    'cast'=>$cast,
                    'revision'=>$session->getRevision(),
                    'state'=>$session->getState(),
                    'replayed'=>false,
                ];
            })();
            $db->commit();
            return $result;
        } catch (\Throwable $error) {
            // A failed COMMIT may already have ended the database transaction.
            if ($db->isTransactionActive()) $db->rollBack();
            // Never leave a failed flush/commit's mutated objects reusable.
            if ($mutated && $this->em->isOpen()) $this->em->close();
            throw $error;
        }
    }
}
