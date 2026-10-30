<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Character;
use App\Service\CharacterInventory;
use App\Service\ItemCatalogue;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CharacterInventoryTest extends TestCase
{
    #[DataProvider('invalidQuantities')]
    public function testInvalidQuantityIsRejected(int $quantity): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $catalogue = new ItemCatalogue($em);
        $inventory = new CharacterInventory($em, $catalogue);
        $character = $this->createMock(Character::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('La quantité doit être comprise entre 1 et 9999.');

        $inventory->acquire($character, 1, $quantity);
    }

    public static function invalidQuantities(): array
    {
        return [
            'quantité négative' => [-1],
            'quantité nulle' => [0],
            'quantité excessive' => [10000],
        ];
    }
}
