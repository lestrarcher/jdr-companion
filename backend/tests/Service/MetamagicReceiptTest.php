<?php

declare(strict_types=1);
namespace App\Tests\Service;

use App\Service\SpellCastReceipts;
use PHPUnit\Framework\TestCase;

final class MetamagicReceiptTest extends TestCase
{
    private function command(): array
    {
        return ['revision'=>1, 'requestId'=>'11111111-1111-4111-8111-111111111111',
            'sourceKey'=>'acquisition:1', 'method'=>'standard-slot', 'castingLevel'=>2];
    }

    private function cast(): array
    {
        return ['source'=>['key'=>'acquisition:1', 'spellId'=>1, 'policyId'=>1, 'sourceType'=>'class'],
            'method'=>'standard-slot', 'castingLevel'=>2, 'poolSlug'=>'spell-slot-2', 'cost'=>1];
    }

    public function testAllReceiptVersionsCoexistAndAreCanonical(): void
    {
        foreach ([[], ['targetCharacterIds'=>[3, 2]], ['metamagic'=>'metamagic-subtle-spell'],
            ['metamagic'=>'metamagic-subtle-spell', 'targetCharacterIds'=>[3, 2], 'hitPointRoll'=>10]] as $extra) {
            $command = $this->command() + $extra;
            $cast = $this->cast();
            if (isset($extra['metamagic'])) $cast['metamagic'] = ['key'=>$extra['metamagic'], 'poolSlug'=>'sorcery-points', 'cost'=>1];
            $receipt = SpellCastReceipts::create($command, $cast);
            self::assertSame(isset($extra['metamagic']) ? 3 : (isset($extra['targetCharacterIds']) ? 2 : 1), $receipt['version']);
            self::assertSame('identical', SpellCastReceipts::compare([SpellCastReceipts::STATE_KEY=>[$command['requestId']=>$receipt]], array_reverse($command, true)));
        }
    }

    public function testMalformedMetamagicCommandsAreRejected(): void
    {
        foreach ([null, 1, [], ['metamagic-subtle-spell'], '', 'subtle', str_repeat('x', 200)] as $value) {
            try { SpellCastReceipts::normalizeCommand($this->command() + ['metamagic'=>$value]); self::fail('Invalid metamagic accepted'); }
            catch (\InvalidArgumentException $error) { self::assertSame('invalid_metamagic', $error->getMessage()); }
        }
    }

    public function testReceiptRequiresMatchingMetamagicAndHistoricalCost(): void
    {
        $command = $this->command() + ['metamagic'=>'metamagic-subtle-spell'];
        foreach ([null, ['key'=>'metamagic-subtle-spell', 'poolSlug'=>'sorcery-points', 'cost'=>0],
            ['key'=>'metamagic-twinned-spell', 'poolSlug'=>'sorcery-points', 'cost'=>1],
            ['key'=>'metamagic-subtle-spell', 'poolSlug'=>'spell-slot-1', 'cost'=>1]] as $meta) {
            try { SpellCastReceipts::create($command, $this->cast() + ['metamagic'=>$meta]); self::fail('Malformed receipt accepted'); }
            catch (\UnexpectedValueException $error) { self::assertSame('malformed_spell_cast_receipts', $error->getMessage()); }
        }
    }
}
