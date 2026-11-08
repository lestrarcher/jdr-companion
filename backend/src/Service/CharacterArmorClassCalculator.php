<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\{Character, CharacterMagicItem};
use App\Enum\Ability;

final readonly class CharacterArmorClassCalculator
{
    public function __construct(
        private CharacterAbilityCalculator $abilities,
        private CharacterEquipmentService $equipment,
    ) {}

    /**
     * Explicitly active effects from a trusted caller, never inferred from known spells.
     * No session lookup, duration management or persistence occurs here.
     * @param list<array{source: string, effects: array}> $contextEffects
     */
    public function calculate(Character $character, array $contextEffects = []): array
    {
        $active = array_values(array_filter($character->getMagicItems()->toArray(),
            static fn (CharacterMagicItem $item): bool => $item->isEquipped()));
        $errors = $this->equipment->conflicts($active);
        $warnings = $excluded = $bonuses = $formulas = [];
        $armor = $shield = null;
        foreach ($active as $owned) {
            $definition = $owned->getMagicItem();
            if ($definition?->getCategory() === 'armor') $armor = $owned;
            if ($definition?->getCategory() === 'shield') $shield = $owned;
            if ($definition?->isMagical() && $definition->getArmorClassEffects() === null) {
                $warnings[] = $owned->getDisplayName().' : effets de CA non configurés, aucun bonus supposé.';
            }
            if (in_array($definition?->getCategory(), ['armor', 'shield'], true)
                && ($definition->getArmorType() === null || $definition->getBaseArmorClass() === null)) {
                $errors[] = 'Données mécaniques manquantes : '.$owned->getDisplayName().'.';
            }
        }
        if ($errors !== []) return ['value' => null, 'complete' => false, 'source' => null, 'formula' => null,
            'reasons' => $errors, 'warnings' => $warnings, 'contributions' => [], 'excluded' => []];

        $dex = $this->abilities->calculate($character, Ability::Dexterity)->modifier();
        if ($armor !== null) {
            $definition = $armor->getMagicItem();
            $formulas[] = $this->formula($character, $armor->getDisplayName(), [
                'value' => $definition->getBaseArmorClass(), 'dexterity' => $definition->getArmorDexterityMode(),
                'dexterityCap' => $definition->getArmorDexterityCap(),
            ], $dex);
        } else {
            $formulas[] = $this->formula($character, 'Sans armure', ['value' => 10, 'dexterity' => 'full'], $dex);
        }
        // First acquisition by global level position blocks the other Unarmored Defense (2014).
        $levels = $character->getClassLevels()->toArray();
        usort($levels, static fn ($a, $b): int => $a->getPosition() <=> $b->getPosition());
        foreach ($levels as $level) {
            $slug = $level->getCharacterClass()->getSlug();
            if (!in_array($slug, ['barbarian', 'monk'], true)) continue;
            $source = 'Défense sans armure — '.($slug === 'barbarian' ? 'barbare' : 'moine');
            if ($armor !== null || ($slug === 'monk' && $shield !== null)) {
                $excluded[] = ['source' => $source, 'label' => 'Formule alternative', 'reason' => $armor !== null ? 'Armure équipée.' : 'Bouclier équipé.'];
            } else {
                $formulas[] = $this->formula($character, $source, ['value' => 10, 'dexterity' => 'full',
                    'ability' => $slug === 'barbarian' ? 'constitution' : 'wisdom'], $dex);
            }
            break;
        }
        foreach ($character->getMagicItems() as $owned) {
            $definition = $owned->getMagicItem();
            if ($definition === null) continue;
            foreach ($definition->getArmorClassEffects() ?? [] as $effect) {
                $reason = !$owned->isEquipped() ? 'Objet non équipé.'
                    : (!$owned->isEffectActive() ? 'Harmonisation requise.' : null);
                $reason ??= $this->conditionFailure($effect, $armor !== null, $shield !== null,
                    $owned->isEquipped() && $owned->getEquippedSlot() === 'hand' && ($owned->getHandsUsed() ?? 0) > 0);
                $identity = 'item:'.($definition->getId() ?? spl_object_id($definition));
                $this->collect($character, $effect, $owned->getDisplayName(), $identity, $reason, $dex, $formulas, $bonuses, $excluded);
            }
        }
        foreach ($contextEffects as $context) {
            if (!is_array($context) || array_diff(array_keys($context), ['source', 'effects'])
                || !is_string($context['source'] ?? null) || trim($context['source']) === '') {
                throw new \InvalidArgumentException('Contexte de CA invalide.');
            }
            foreach (ArmorClassEffectSchema::validate($context['effects'] ?? null) as $effect) {
                $reason = $this->conditionFailure($effect, $armor !== null, $shield !== null, false);
                $this->collect($character, $effect, $context['source'], 'context', $reason, $dex, $formulas, $bonuses, $excluded);
            }
        }
        // Select one complete formula, never sum alternative bases or ability modifiers.
        usort($formulas, static fn ($a, $b) => $b['value'] <=> $a['value']);
        $selected = array_shift($formulas);
        foreach ($formulas as $alternative) {
            $excluded[] = ['source' => $alternative['source'], 'label' => $alternative['formula'],
                'reason' => 'Formule alternative non retenue (CA '.$alternative['value'].').'];
        }
        $contributions = $selected['contributions'];
        if ($shield !== null) $contributions[] = ['kind' => 'shield', 'source' => $shield->getDisplayName(), 'label' => 'Bouclier', 'value' => 2];
        $groups = [];
        foreach ($bonuses as $bonus) {
            $group = $bonus['group'];
            if (!isset($groups[$group]) || $bonus['value'] > $groups[$group]['value']) {
                if (isset($groups[$group])) $excluded[] = $this->nonStacking($groups[$group]);
                $groups[$group] = $bonus;
            } else $excluded[] = $this->nonStacking($bonus);
        }
        foreach ($groups as $bonus) {
            unset($bonus['group']);
            $contributions[] = $bonus;
        }
        return ['value' => array_sum(array_column($contributions, 'value')), 'complete' => true,
            'source' => $selected['source'], 'formula' => $selected['formula'], 'reasons' => [],
            'warnings' => $warnings, 'contributions' => $contributions, 'excluded' => $excluded];
    }

    private function formula(Character $character, string $source, array $effect, int $dex): array
    {
        $retained = match ($effect['dexterity']) {
            'full' => $dex, 'capped' => min($dex, $effect['dexterityCap']), 'none' => 0,
        };
        $parts = [['kind' => 'base', 'source' => $source, 'label' => 'Base', 'value' => $effect['value']]];
        $formula = (string) $effect['value'];
        if ($effect['dexterity'] !== 'none') {
            $parts[] = ['kind' => 'ability', 'source' => $source, 'label' => 'DEX retenue', 'value' => $retained];
            $formula .= sprintf(' + DEX retenue (%d)', $retained);
        }
        if (isset($effect['ability'])) {
            $ability = Ability::from($effect['ability']);
            $modifier = $this->abilities->calculate($character, $ability)->modifier();
            $label = $ability->abbreviation();
            $parts[] = ['kind' => 'ability', 'source' => $source, 'label' => $label, 'value' => $modifier];
            $formula .= sprintf(' + %s (%d)', $label, $modifier);
        }
        return ['value' => array_sum(array_column($parts, 'value')), 'source' => $source, 'formula' => $formula, 'contributions' => $parts];
    }

    private function conditionFailure(array $effect, bool $armor, bool $shield, bool $held): ?string
    {
        foreach ($effect['conditions'] ?? [] as $condition) {
            $reason = match ($condition) {
                'no-armor' => $armor ? 'Armure équipée.' : null,
                'no-shield' => $shield ? 'Bouclier équipé.' : null,
                'armor' => !$armor ? 'Armure requise.' : null,
                'held' => !$held ? 'Objet non tenu en main.' : null,
            };
            if ($reason !== null) return $reason;
        }
        return null;
    }

    private function collect(Character $character, array $effect, string $source, string $identity, ?string $reason, int $dex, array &$formulas, array &$bonuses, array &$excluded): void
    {
        if ($reason === null && isset($effect['classSlugs'])) {
            $classes = array_map(static fn ($level) => $level->getCharacterClass()->getSlug(), $character->getClassLevels()->toArray());
            if (array_intersect($classes, $effect['classSlugs']) === []) $reason = 'Classe requise : '.implode(', ', $effect['classSlugs']).'.';
        }
        if ($reason !== null) {
            $excluded[] = ['source' => $source, 'label' => $effect['label'], 'reason' => $reason];
        } elseif ($effect['type'] === 'formula') {
            $formulas[] = $this->formula($character, $source.' — '.$effect['label'], $effect, $dex);
        } else {
            $bonuses[] = ['kind' => 'bonus', 'source' => $source, 'label' => $effect['label'], 'value' => $effect['value'],
                'group' => isset($effect['stackingKey']) ? 'shared:'.$effect['stackingKey'] : $identity.':'.$effect['key']];
        }
    }

    private function nonStacking(array $bonus): array
    {
        return ['source' => $bonus['source'], 'label' => $bonus['label'], 'reason' => 'Bonus de même groupe déjà retenu (non cumulable).'];
    }
}
