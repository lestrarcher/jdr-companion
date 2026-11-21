<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\WildShapeRules2014;
use PHPUnit\Framework\TestCase;

final class WildShapeRules2014Test extends TestCase
{
    public function testMoonCircleChallengeRating(): void
    {
        $rules = new WildShapeRules2014();
        self::assertSame(0.25, $rules->maxChallengeRating(2, false));
        self::assertSame(1.0, $rules->maxChallengeRating(2, true));
        self::assertSame(2.0, $rules->maxChallengeRating(6, true));
        self::assertSame(3.0, $rules->maxChallengeRating(9, true));
        self::assertSame(1.0, $rules->maxChallengeRating(20, false));
    }

    public function testSwimmingAndFlyingRestrictionsApplyToMoonDruids(): void
    {
        $rules = new WildShapeRules2014();
        $beast = ['challengeRating' => '1/4', 'statBlock' => ['hitPoints' => 3, 'speed' => ['fly' => 18]]];
        self::assertSame('Vol accessible au niveau 8.', $rules->unavailableReason($beast, 2, true, true));
        $beast['statBlock']['speed'] = ['swim' => 9];
        self::assertSame('Nage accessible au niveau 4.', $rules->unavailableReason($beast, 2, true, true));
        self::assertNull($rules->unavailableReason($beast, 4, true, true));
    }

    public function testDamageOverflowAndReversion(): void
    {
        $rules = new WildShapeRules2014();
        $form = ['hitPoints' => ['current' => 5, 'maximum' => 10]];
        self::assertSame(['form' => null, 'overflow' => 7, 'reverted' => true], $rules->damage($form, 12));
        $result = $rules->damage($form, 3);
        self::assertSame(2, $result['form']['hitPoints']['current']);
        self::assertSame(0, $result['overflow']);
    }

    public function testDurationUsesDruidLevelsAndGameTime(): void
    {
        $rules = new WildShapeRules2014();
        self::assertSame(60, $rules->durationMinutes(3));
        self::assertSame(120, $rules->durationMinutes(4));
        self::assertNull($rules->advanceTime(['durationMinutes' => 60, 'elapsedMinutes' => 50], 10));
    }
}
