<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\SpellCastReceipts;
use PHPUnit\Framework\TestCase;

final class ItemSpellCastReceiptTest extends TestCase
{
    private const REQUEST_ID = '6fbd5c1d-57a3-4e81-93c4-bf9329e64710';

    private function command(string $method = 'item-charges'): array
    {
        return [
            'revision' => 3,
            'requestId' => self::REQUEST_ID,
            'sourceKey' => 'item:42:73',
            'method' => $method,
            'castingLevel' => 2,
        ];
    }

    private function cast(string $method = 'item-charges'): array
    {
        return [
            'source' => ['key' => 'item:42:73', 'spellId' => 11, 'policyId' => null, 'sourceType' => 'item'],
            'method' => $method,
            'castingLevel' => 2,
            'poolSlug' => null,
            'cost' => 2,
        ];
    }

    public function testItemChargesReceiptSurvivesValidationAndIdenticalReplay(): void
    {
        $command = $this->command();
        $receipt = SpellCastReceipts::create($command, $this->cast());
        $state = [SpellCastReceipts::STATE_KEY => [self::REQUEST_ID => $receipt]];

        self::assertSame(1, $receipt['version']);
        self::assertSame($receipt, SpellCastReceipts::validateState($state)[self::REQUEST_ID]);
        self::assertSame('identical', SpellCastReceipts::compare($state, $command));
    }

    public function testItemLimitedReceiptSurvivesValidation(): void
    {
        $command = $this->command('item-limited');
        $state = [SpellCastReceipts::STATE_KEY => [self::REQUEST_ID => SpellCastReceipts::create($command, $this->cast('item-limited'))]];

        self::assertSame('identical', SpellCastReceipts::compare($state, $command));
    }

    public function testSameRequestIdWithDifferentItemMethodIsRejectedAsConflict(): void
    {
        $command = $this->command();
        $state = [SpellCastReceipts::STATE_KEY => [self::REQUEST_ID => SpellCastReceipts::create($command, $this->cast())]];

        self::assertSame('conflict', SpellCastReceipts::compare($state, $this->command('item-limited')));
    }

    public function testSameRequestIdWithDifferentItemSourceIsRejectedAsConflict(): void
    {
        $command = $this->command();
        $state = [SpellCastReceipts::STATE_KEY => [self::REQUEST_ID => SpellCastReceipts::create($command, $this->cast())]];
        $other = $command;
        $other['sourceKey'] = 'item:43:73';

        self::assertSame('conflict', SpellCastReceipts::compare($state, $other));
    }

    public function testItemReceiptCannotMasqueradeAsClassCast(): void
    {
        $command = $this->command();
        $cast = $this->cast();
        $cast['source']['sourceType'] = 'class';

        $this->expectException(\UnexpectedValueException::class);
        SpellCastReceipts::create($command, $cast);
    }

    public function testItemReceiptCannotClaimAClassSpellSlot(): void
    {
        $command = $this->command();
        $cast = $this->cast();
        $cast['poolSlug'] = 'spell-slot-2';

        $this->expectException(\UnexpectedValueException::class);
        SpellCastReceipts::create($command, $cast);
    }

    public function testMissingReceiptIsNotMistakenForReplay(): void
    {
        self::assertSame('missing', SpellCastReceipts::compare([], $this->command()));
    }
}
