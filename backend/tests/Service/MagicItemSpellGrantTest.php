<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\MagicItem;
use App\Entity\MagicItemSpellGrant;
use App\Entity\SpellDefinition;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class MagicItemSpellGrantTest extends TestCase
{
    private function item(): MagicItem
    {
        return new MagicItem(null, 'Fragment du sceptre', null, $this->createMock(User::class));
    }

    public function testLimitedGrantCanBeDefined(): void
    {
        $item = $this->item();
        $item->setRequiresAttunement(true);
        $spell = $this->createMock(SpellDefinition::class);
        $grant = new MagicItemSpellGrant($item, $spell, 'attuned', 'limited', 1, 1, 'dawn');
        $item->addSpellGrant($grant);

        self::assertSame('attuned', $grant->getActivation());
        self::assertSame('dawn', $grant->getResetTrigger());
        self::assertCount(1, $item->getSpellGrants());
    }

    public function testChargesRequireChargedItem(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new MagicItemSpellGrant($this->item(), $this->createMock(SpellDefinition::class), 'possession', 'charges');
    }

    public function testAttunementRequiresCompatibleItem(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new MagicItemSpellGrant($this->item(), $this->createMock(SpellDefinition::class), 'attuned', 'limited', 1, 1, 'dawn');
    }

    public function testLimitedGrantRequiresReset(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new MagicItemSpellGrant($this->item(), $this->createMock(SpellDefinition::class), 'possession', 'limited', 1, 1, null);
    }
}
