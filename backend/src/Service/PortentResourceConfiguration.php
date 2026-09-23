<?php

declare(strict_types=1);

namespace App\Service;

/** Runtime overlay for Portent; the persisted character definition stays untouched. */
final class PortentResourceConfiguration
{
    public const RESOURCE_SLUG = 'des-de-presage';

    /**
     * @param array<string, mixed> $definition
     * @param array<string, int> $resolvedMaximums
     * @return array<string, mixed>
     */
    public static function apply(array $definition, array $resolvedMaximums): array
    {
        if (!array_key_exists(self::RESOURCE_SLUG, $resolvedMaximums)) {
            return $definition;
        }

        $config = [
            'requiredCount' => $resolvedMaximums[self::RESOURCE_SLUG],
            'minimumValue' => 1,
            'maximumValue' => 20,
        ];

        foreach ($definition['resources'] ?? [] as $index => $resource) {
            if (($resource['id'] ?? null) === self::RESOURCE_SLUG) {
                $definition['resources'][$index]['storedValuesConfig'] = $config;

                return $definition;
            }
        }

        $definition['resources'][] = ['id' => self::RESOURCE_SLUG, 'storedValuesConfig' => $config];

        return $definition;
    }
}
