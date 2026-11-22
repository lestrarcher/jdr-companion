<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\HitPointEffectSchema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HitPointEffectSchemaTest extends TestCase
{
    #[DataProvider('invalidEffects')]
    public function testRejectsInvalidOrAmbiguousConfiguration(mixed $effects): void
    {
        $this->expectException(\InvalidArgumentException::class);
        HitPointEffectSchema::validate($effects);
    }

    public static function invalidEffects(): iterable
    {
        $effect = ['key' => 'hp', 'label' => 'HP', 'type' => 'fixed', 'value' => 1];
        yield [null];
        yield [[$effect, $effect]];
        foreach ([['type' => 'unknown'], ['value' => -1], ['value' => 0], ['value' => 1.5], ['value' => '1'],
            ['label' => ''], ['type' => 'per-class-level'], ['type' => 'per-class-level', 'classSlug' => ''],
            ['classSlug' => 'sorcerer'], ['current' => 5]] as $patch) {
            yield [[array_replace($effect, $patch)]];
        }
    }
}
