<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Weather;
use App\Repository\WeatherRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:world:import-weathers',
    description: 'Importe les météos système de JdR Companion.',
)]
class ImportWeatherCommand extends Command
{
    private const SYSTEM_WEATHERS = [
        [
            'key' => 'cloudy',
            'label' => 'Couvert',
            'imageUrl' => '/assets/weather/cloudy.png',
            'alt' => 'Ciel couvert de nuages sombres',
        ],
        [
            'key' => 'rain',
            'label' => 'Pluie',
            'imageUrl' => '/assets/weather/rain.png',
            'alt' => 'Nuages sombres et pluie',
        ],
        [
            'key' => 'storm',
            'label' => 'Orage',
            'imageUrl' => '/assets/weather/storm.png',
            'alt' => 'Orage accompagné de pluie et d’éclairs',
        ],
        [
            'key' => 'sunny',
            'label' => 'Soleil',
            'imageUrl' => '/assets/weather/sunny.png',
            'alt' => 'Soleil apparaissant derrière les nuages',
        ],
        [
            'key' => 'fog',
            'label' => 'Brouillard',
            'imageUrl' => '/assets/weather/fog.png',
            'alt' => 'Épaisses nappes de brouillard',
        ],
        [
            'key' => 'snow',
            'label' => 'Neige',
            'imageUrl' => '/assets/weather/snow.png',
            'alt' => 'Chute de neige calme',
        ],
        [
            'key' => 'blizzard',
            'label' => 'Blizzard',
            'imageUrl' => '/assets/weather/blizzard.png',
            'alt' => 'Violente tempête de neige',
        ],
        [
            'key' => 'wind',
            'label' => 'Vent fort',
            'imageUrl' => '/assets/weather/wind.png',
            'alt' => 'Fortes rafales de vent',
        ],
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly WeatherRepository $weatherRepository,
    ) {
        parent::__construct();
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        foreach (self::SYSTEM_WEATHERS as $data) {
            $weather = $this->weatherRepository->findOneBy([
                'key' => $data['key'],
                'system' => true,
                'campaign' => null,
            ]);

            if ($weather === null) {
                $weather = new Weather(
                    $data['key'],
                    $data['label'],
                );

                $weather->setSystem(true);
            } else {
                $weather->setLabel(
                    $data['label'],
                );
            }

            $weather
                ->setImageUrl($data['imageUrl'])
                ->setAlt($data['alt']);

            $this->entityManager->persist($weather);
        }

        $this->entityManager->flush();

        $output->writeln(
            '<info>Météos système importées avec succès.</info>',
        );

        return Command::SUCCESS;
    }
}
