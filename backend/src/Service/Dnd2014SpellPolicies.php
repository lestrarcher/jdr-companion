<?php
declare(strict_types=1);
namespace App\Service;

use App\Dto\SpellcastingPolicy;
use App\Enum\Ability;

/** Explicit presets, never automatically matched or assigned to a persisted class.
 * Source checked 2026-10-17: https://www.dndbeyond.com/sources/dnd/basic-rules-2014/classes
 * Numbers describe ordinary Spellcasting/Pact Magic, not additional feature choices.
 */
final class Dnd2014SpellPolicies
{
    public static function get(string $key): SpellcastingPolicy
    {
        $spec = match ($key) {
            'wizard-2014' => [Ability::Intelligence, 'spellbook', 3, null],
            'cleric-2014' => [Ability::Wisdom, 'prepared', 3, null],
            'druid-2014' => [Ability::Wisdom, 'prepared', 2, null],
            'paladin-2014' => [Ability::Charisma, 'prepared', 0, null],
            'sorcerer-2014' => [Ability::Charisma, 'known', 4, [2,3,4,5,6,7,8,9,10,11,12,12,13,13,14,14,15,15,15,15]],
            'ranger-2014' => [Ability::Wisdom, 'known', 0, [0,2,3,3,4,4,5,5,6,6,7,7,8,8,9,9,10,10,11,11]],
            'warlock-2014' => [Ability::Charisma, 'known', 2, [2,3,4,5,6,7,8,9,10,10,11,11,12,12,13,13,14,14,15,15]],
            default => throw new \InvalidArgumentException('Politique non couverte : configurer et vérifier explicitement ses règles.'),
        };
        [$ability, $style, $cantrips, $known] = $spec;
        $fullMax = [1,1,2,2,3,3,4,4,5,5,6,6,7,7,8,8,9,9,9,9];
        $halfMax = [0,1,1,1,2,2,2,2,3,3,3,3,4,4,4,4,5,5,5,5];
        $half = in_array($key, ['paladin-2014','ranger-2014'], true);
        $rows = [];
        for ($level=1; $level<=20; ++$level) {
            $rows[] = [
                'maxSpellLevel' => $key === 'warlock-2014' ? min(5,$fullMax[$level-1]) : ($half ? $halfMax[$level-1] : $fullMax[$level-1]),
                'cantrips' => $cantrips === 0 ? 0 : $cantrips + ($level>=4?1:0) + ($level>=10?1:0),
                'known' => $known[$level-1] ?? 0,
                'bookAdditions' => $style === 'spellbook' ? ($level===1?6:2) : 0,
                'replacements' => $style === 'known' && $level>($key==='ranger-2014'?2:1) ? 1 : 0,
                'preparationBase' => $style === 'known' || ($half && $level===1) ? null : ($half ? intdiv($level,2) : $level),
            ];
        }
        $limits = ['Base 2014 uniquement ; options ultérieures et choix supplémentaires de capacités non inclus.'];
        if ($key==='warlock-2014') $limits[] = 'Pact Magic 1–5 seulement ; arcanums, invocations et pactes à choix séparés non représentés.';
        if ($key==='wizard-2014') $limits[] = 'Gains de niveau uniquement en reconstruction ; copies antérieures admises en reprise MJ, sans validation de leur temps/coût.';
        return new SpellcastingPolicy($key,1,$ability,$style,$rows,'D&D Basic Rules 2014, classe concernée — https://www.dndbeyond.com/sources/dnd/basic-rules-2014/classes',$limits);
    }

    public static function unsupported(): array
    {
        return [
            'bard-2014' => 'Utiliser Dnd2014AdvancedSpellPolicies::bard avec les références explicites.',
            'eldritch-knight-2014' => 'Utiliser Dnd2014AdvancedSpellPolicies::thirdCaster avec la liste explicite.',
            'arcane-trickster-2014' => 'Utiliser Dnd2014AdvancedSpellPolicies::thirdCaster avec la liste et le sort imposé explicites.',
            'artificer-2014' => 'Source complète des règles applicables à vérifier avant encodage.',
        ];
    }
}
