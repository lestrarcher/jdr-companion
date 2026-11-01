<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\MagicItemRechargeService;
use PHPUnit\Framework\TestCase;

final class MagicItemRechargeServiceTest extends TestCase
{
    public function testFixedRecovery(): void
    {
        self::assertSame(4, MagicItemRechargeService::roll('4'));
        self::assertSame(0, MagicItemRechargeService::roll('1d2-9'));
    }

    public function testDiceBounds(): void
    {
        for ($i = 0; $i < 30; ++$i) {
            $value = MagicItemRechargeService::roll('2d6+1');
            self::assertGreaterThanOrEqual(3, $value);
            self::assertLessThanOrEqual(13, $value);
        }
    }

    public function testInvalidFormulaIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MagicItemRechargeService::roll('2d999999');
    }
}
