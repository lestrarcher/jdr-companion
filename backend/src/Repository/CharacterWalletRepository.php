<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CharacterWallet;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CharacterWallet>
 */
final class CharacterWalletRepository
    extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
    ) {
        parent::__construct(
            $registry,
            CharacterWallet::class,
        );
    }
}
