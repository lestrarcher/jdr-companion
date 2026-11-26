import { divineSmiteSpell } from '../player-spells/divine-smite';
import { spellSlotGroups } from '../spell-slot-bar/spell-slot-bar';
import { athleticsBonus } from '../character-statistics/skill-bonus';
import { ChangeDetectionStrategy, Component, computed, input, output, signal } from '@angular/core';
import type { CharacterAbilityScore, CharacterMovement, CharacterFeatureSummary, CharacterClassSummary, CharacterTraining } from '@core/services/character-api.service';
import type { PlayerSpell } from '@core/services/character-session-state-api.service';
import { CharacterWeapons } from '../character-weapons/character-weapons';
import type { CharacterWeapon } from '@core/models/character-weapon.model';
import { attackCount, hasThirstingBlade } from './attack-count';
import { SpellQuickList } from '../spell-quick-list/spell-quick-list';
import type { CharacterResource } from '@core/models/character.model';
import { groupTurnFeatures } from './turn-features';
import { groupTurnSpells, warCasterSpells } from './turn-spells';
import { calculateJumps } from './jump';
import { TURN_SECTIONS, MOVEMENT_RULES, TurnRule } from './turn-rules';

@Component({
  selector: 'app-player-turn',
  standalone: true,
  imports: [SpellQuickList, CharacterWeapons],
  templateUrl: './player-turn.html',
  styleUrl: './player-turn.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class PlayerTurn {
  readonly classSummary = input<CharacterClassSummary[]>([]);
  private classLevel(...names: string[]): number {
    return this.classSummary().filter(c => names.includes((c.classSlug || c.className).toLowerCase())).reduce((total, c) => total + c.level, 0);
  }
  readonly smiteEntries = computed(() => [divineSmiteSpell(spellSlotGroups(this.resources()))]);
  readonly divineSmite = computed(() => this.classLevel('paladin') >= 2);
  readonly sneakAttackDice = computed(() => Math.ceil(this.classLevel('rogue', 'roublard') / 2));
  readonly weapons = input<CharacterWeapon[]>([]);
  readonly accessToken = input('');
  readonly readonly = input(true);
  readonly chargesChanged = output<void>();
  readonly attackCount = computed(() => attackCount(this.classSummary(), this.features()));
  readonly pactAttack = computed(() => hasThirstingBlade(this.features()));
  readonly abilities = input.required<CharacterAbilityScore[]>();
  readonly movement = input.required<CharacterMovement | null>();
  readonly features = input<CharacterFeatureSummary[]>([]);
  readonly resources = input<readonly CharacterResource[]>([]);
  readonly moonDruid = input(false);
  readonly warCaster = input(false);
  readonly opportunitySpells = computed(() => this.warCaster() && !this.wildShapeActive() ? warCasterSpells(this.spells()) : []);
  readonly featuresBySection = computed(() => {
    const groups = groupTurnFeatures(this.features());
    if (this.moonDruid()) {
      const wild = groups['action'].filter(f => /^(wild.shape|forme.sauvage)$/i.test(f.slug) || /^(forme sauvage|wild shape)$/i.test(f.name));
      groups['bonus'] = [...groups['bonus'], ...wild];
    }
    return groups;
  });
  resourceFor(feature: CharacterFeatureSummary): CharacterResource | undefined {
    return this.resources().find(resource => resource.id === feature.resource?.slug);
  }
  readonly spells = input<PlayerSpell[]>([]);
  readonly castingDisabled = input(true);
  readonly wildShapeActive = input(false);
  readonly lunarHealing = input(false);
  readonly lunarDisabled = input(true);
  readonly lunarSelected = output<void>();
  readonly revertRule: TurnRule = { id: 'revert', name: 'Reprendre sa forme normale', description: 'Vous pouvez mettre fin volontairement à votre forme sauvage en utilisant une action bonus.' };
  readonly spellSelected = output<PlayerSpell>();
  readonly spellsBySection = computed<Record<string, PlayerSpell[]>>(() => ({
    action: groupTurnSpells(this.spells(), 'action'),
    bonus: groupTurnSpells(this.spells(), 'bonus'),
    reaction: groupTurnSpells(this.spells(), 'reaction'),
  }));
  readonly sections = TURN_SECTIONS;
  readonly movementRules = MOVEMENT_RULES;
  readonly jumps = computed(() => {
    const strength = this.abilities().find(a => a.ability === 'strength');
    return calculateJumps(strength?.effectiveValue, strength?.modifier);
  });
  readonly speeds = computed(() => {
    const movement = this.movement();
    if (!movement) return [];
    const labels: Record<string, string> = { swim: 'Nage', climb: 'Escalade', fly: 'Vol', burrow: 'Fouissement' };
    return [
      ...(movement.walkingSpeed == null ? [] : [{ mode: 'walk', label: 'Marche', speed: movement.walkingSpeed }]),
      ...Object.entries(movement.movementSpeeds ?? {}).filter(([mode, speed]) => mode !== 'walk' && Number.isFinite(speed) && speed >= 0).map(([mode, speed]) => ({ mode, label: labels[mode] ?? mode, speed })),
    ];
  });
  readonly selected = signal<{ rule?: TurnRule; featureId?: number; kind: string } | null>(null);
  readonly selectedFeature = computed(() => this.features().find(f => f.id === this.selected()?.featureId));
  readonly training = input<CharacterTraining | null>(null);
  readonly proficiencyBonus = input(0);
  readonly beastProficiencies = input<unknown>(null);
  readonly athletics = computed(() => athleticsBonus(this.abilities(), this.training(), this.proficiencyBonus(), this.beastProficiencies()));
  readonly attackOptions = TURN_SECTIONS[0].rules.filter(r => r.id === 'grapple' || r.id === 'shove');
  readonly attackRows = computed(() => [...this.weapons(), ...this.attackOptions.map(option => ({
    id: `turn-${option.id}`, name: option.id === 'grapple' ? 'Agripper' : 'Bousculer',
    equipped: false, attackBonus: this.athletics(), damage: option.id === 'grapple' ? 'Agrippé' : 'À terre ou repoussé de 1,5 m',
    notes: [option.description], complete: this.athletics() !== null,
  }))]);
  readonly jumpRule: TurnRule = { id: 'jump', name: 'Sauter', description: 'Distances maximales théoriques. Un élan de 3 m est nécessaire pour un saut avec élan. Chaque mètre sauté consomme du déplacement. Foncer augmente le déplacement disponible, pas la distance maximale de saut. Le MJ peut demander un test d’Athlétisme. Les bonus spécifiques ne sont pas encore intégrés.' };
  ruleIcon(id: string): string {
    return ({ attack: 'bi-crosshair', dash: 'bi-lightning-charge', disengage: 'bi-arrow-left-right', dodge: 'bi-shield', help: 'bi-people', hide: 'bi-eye-slash', ready: 'bi-hourglass', search: 'bi-search', object: 'bi-bag', 'two-weapons': 'bi-crosshair', speak: 'bi-chat-dots', jump: 'bi-arrow-up-right', difficult: 'bi-signpost', 'climb-swim': 'bi-water', crawl: 'bi-arrow-right', stand: 'bi-arrow-up', drag: 'bi-person', split: 'bi-signpost-split', opportunity: 'bi-crosshair', 'ready-trigger': 'bi-hourglass', 'bonus-rule': 'bi-info-circle' } as Record<string,string>)[id] ?? 'bi-stars';
  }
  openRule(rule: TurnRule, kind: string, dialog: HTMLDialogElement): void {
    this.selected.set({ rule, kind }); dialog.showModal();
  }
  openFeature(feature: CharacterFeatureSummary, kind: string, dialog: HTMLDialogElement): void {
    this.selected.set({ featureId: feature.id, kind }); dialog.showModal();
  }
  format(value: number): string { return value.toLocaleString('fr-FR', { maximumFractionDigits: 2 }) + ' m'; }
}
