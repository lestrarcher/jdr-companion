<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\AbilityScoreEffectSchema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AbilityScoreEffectSchemaTest extends TestCase
{
    #[DataProvider('invalidEffects')]
    public function testRejectsInvalidEffects(mixed $effects): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AbilityScoreEffectSchema::validate($effects);
    }

    public static function invalidEffects(): iterable
    {
        $effect = ['key' => 'con', 'label' => 'Constitution', 'ability' => 'constitution', 'value' => 2];
        yield [null];
        yield [[$effect, $effect]];
        foreach ([['ability' => 'luck'], ['value' => 0], ['value' => 31], ['value' => -31], ['value' => '2'],
            ['scoreCap' => 31], ['scoreCap' => 0], ['scoreCap' => null], ['label' => ''], ['unknown' => 1]] as $patch) {
            yield [[array_replace($effect, $patch)]];
        }
    }
}
