<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Campaign;
use App\Entity\Weather;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Weather>
 */
class WeatherRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
    ) {
        parent::__construct(
            $registry,
            Weather::class,
        );
    }

    /**
     * @return list<Weather>
     */
    public function findAvailableForCampaign(
        Campaign $campaign,
    ): array {
        return $this->createQueryBuilder('weather')
            ->andWhere(
                'weather.system = true OR weather.campaign = :campaign',
            )
            ->setParameter(
                'campaign',
                $campaign,
            )
            ->orderBy(
                'weather.system',
                'DESC',
            )
            ->addOrderBy(
                'weather.label',
                'ASC',
            )
            ->getQuery()
            ->getResult();
    }
}
