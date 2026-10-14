<?php
declare(strict_types=1);
namespace App\Service;

/** Presentation and manual controls only; maxima and availability come from the resolver. */
final class MagicalTinkeringResourceConfiguration
{
    public const SLUG = 'magical-tinkering';

    public static function apply(array $definition, array $maximums): array
    {
        if (!array_key_exists(self::SLUG, $maximums)) return $definition;
        foreach ($definition['resources'] ?? [] as $index => $resource) {
            if (($resource['id'] ?? null) === self::SLUG) {
                $definition['resources'][$index]['allowManualIncrease'] = true;
                $definition['resources'][$index]['resetPeriod'] = 'manual';
                return $definition;
            }
        }
        $definition['resources'][] = ['id' => self::SLUG, 'allowManualIncrease' => true, 'resetPeriod' => 'manual'];
        return $definition;
    }
}
