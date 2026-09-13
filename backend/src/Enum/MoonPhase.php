<?php

declare(strict_types=1);

namespace App\Enum;

enum MoonPhase: string
{
    case NewMoon = 'new-moon';
    case WaxingCrescent = 'waxing-crescent';
    case FirstQuarter = 'first-quarter';
    case WaxingGibbous = 'waxing-gibbous';
    case FullMoon = 'full-moon';
    case WaningGibbous = 'waning-gibbous';
    case LastQuarter = 'last-quarter';
    case WaningCrescent = 'waning-crescent';

    public function label(): string
    {
        return match ($this) {
            self::NewMoon => 'Nouvelle lune',
            self::WaxingCrescent => 'Premier croissant',
            self::FirstQuarter => 'Premier quartier',
            self::WaxingGibbous => 'Lune gibbeuse croissante',
            self::FullMoon => 'Pleine lune',
            self::WaningGibbous => 'Lune gibbeuse décroissante',
            self::LastQuarter => 'Dernier quartier',
            self::WaningCrescent => 'Dernier croissant',
        };
    }

    public function imageUrl(): string
    {
        return sprintf(
            '/assets/moons/%s.png',
            $this->value,
        );
    }

    public function alt(): string
    {
        return $this->label();
    }

    /**
     * @return array{
     *     id: string,
     *     label: string,
     *     imageUrl: string,
     *     alt: string
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->value,
            'label' => $this->label(),
            'imageUrl' => $this->imageUrl(),
            'alt' => $this->alt(),
        ];
    }
}
