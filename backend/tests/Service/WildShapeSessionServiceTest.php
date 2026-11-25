<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\{WildShapeRules2014, WildShapeSessionService};
use PHPUnit\Framework\TestCase;

final class WildShapeSessionServiceTest extends TestCase
{
    private function service(): WildShapeSessionService { return new WildShapeSessionService(new WildShapeRules2014()); }
    private function beast(): array
    {
        return ['id' => 7, 'slug' => 'loup', 'name' => 'Loup', 'challengeRating' => '1/4', 'statBlock' => [
            'hitPoints' => 11, 'hitDice' => '2d8+2', 'armorClass' => 13,
            'abilities' => ['str'=>12,'dex'=>15,'con'=>12,'int'=>3,'wis'=>12,'cha'=>6],
            'speed' => ['walk' => 12], 'actions' => [],
        ]];
    }
    public function testDirectChangeConsumesAnotherUseAndRejectsExhaustedResource(): void
    {
        $service = $this->service();
        $state = $service->transform(['hitPoints'=>['current'=>19]], $this->beast(), 2, false, true, 'merge');
        self::assertSame(1, $state['wildShape']['usesRemaining']);
        self::assertSame(19, $state['hitPoints']['current']);
        self::assertSame(11, $state['wildShape']['active']['hitPoints']['current']);
        $other = $this->beast(); $other['id'] = 8; $other['name'] = 'Autre bête';
        $state = $service->transform($state, $other, 2, false, true, 'merge');
        self::assertSame(0, $state['wildShape']['usesRemaining']);
        self::assertSame(8, $state['wildShape']['active']['beastId']);
        self::assertSame(19, $state['hitPoints']['current']);
        self::assertNull($service->revert($state)['wildShape']['active']);
        $this->expectException(\DomainException::class);
        $service->transform($state, $this->beast(), 2, false, true, 'merge');
    }
    public function testOverflowAndRest(): void
    {
        $service = $this->service();
        $state = $service->transform(['hitPoints'=>['current'=>19]], $this->beast(), 2, false, true, 'merge');
        $state = $service->damage($state, 15);
        self::assertNull($state['wildShape']['active']);
        self::assertSame(15, $state['hitPoints']['current']);
        $state = $service->afterRest($state);
        self::assertSame(2, $state['wildShape']['usesRemaining']);
    }
    public function testTemporaryHitPointsAbsorbDamageBeforeWildShape(): void
    {
        $service = $this->service();
        foreach ([
            [5, 3, 11, 2, 19],
            [5, 5, 11, 0, 19],
            [5, 8, 8, 0, 19],
            [0, 3, 8, 0, 19],
            [5, 16, null, 0, 19],
            [5, 19, null, 0, 16],
        ] as [$temporary, $damage, $formHp, $remainingTemporary, $normalHp]) {
            $state = $service->transform(['hitPoints'=>['current'=>19, 'temporary'=>$temporary]], $this->beast(), 2, false, true, 'merge');
            $state = $service->damage($state, $damage);
            self::assertSame($remainingTemporary, $state['hitPoints']['temporary']);
            self::assertSame($normalHp, $state['hitPoints']['current']);
            if ($formHp === null) {
                self::assertNull($state['wildShape']['active']);
            } else {
                self::assertSame($formHp, $state['wildShape']['active']['hitPoints']['current']);
            }
        }
    }

    public function testVoluntaryRevertPreservesNormalHp(): void
    {
        $service = $this->service();
        $state = $service->transform(['hitPoints'=>['current'=>19]], $this->beast(), 2, false, true, 'merge');
        $state = $service->damage($state, 3);
        $state = $service->revert($state);
        self::assertSame(19, $state['hitPoints']['current']);
        self::assertNull($state['wildShape']['active']);
    }
}
