import { Component, input } from '@angular/core';
import type { CharacterBuilder } from '../character-builder';

@Component({
  selector: 'app-character-builder-training',
  standalone: true,
  templateUrl: './character-builder-training.html',
  styleUrls: ['../character-builder-shared.scss', './character-builder-training.scss'],
})
export class CharacterBuilderTraining {
  readonly vm = input.required<CharacterBuilder>();

  proficiencyName(id: number): string {
    return this.vm().reference()?.proficiencies?.find(p => p.id === id)?.name ?? `Maîtrise #${id}`;
  }

  languageName(slug: string): string {
    return this.vm().reference()?.languages?.find(l => l.slug === slug)?.name ?? slug;
  }

  automaticProficiencies() {
    return this.vm().options()?.resolved.proficiencies.filter(p =>
      p.grants.some(g => this.vm().options()?.proficiencyRules.some(r => r.ruleId === g.ruleId && r.choiceCount === 0))
    ) ?? [];
  }

  automaticProficiencyIds(): Set<number> {
    const options = this.vm().options();
    if (!options) return new Set();
    const automaticRuleIds = new Set(options.proficiencyRules.filter(r => r.choiceCount === 0).map(r => r.ruleId));
    return new Set(options.resolved.proficiencies
      .filter(p => p.grants.some(g => automaticRuleIds.has(g.ruleId)))
      .map(p => p.id));
  }

  alreadyGranted(id: number): boolean {
    return this.automaticProficiencyIds().has(id);
  }

  selectedCount(kind: 'proficiency' | 'language' | 'expertise', key: number | string): number {
    const training = this.vm().training();
    if (kind === 'language') return training.languageChoices.find(c => c.sourceKey === key)?.languageSlugs.length ?? 0;
    if (kind === 'expertise') return training.expertiseChoices.find(c => c.ruleId === key)?.proficiencyIds.length ?? 0;
    return training.proficiencyChoices.find(c => c.ruleId === key)?.proficiencyIds.length ?? 0;
  }

  selectedExpertiseIds(ruleId: number): number[] {
    return this.vm().training().expertiseChoices.find(choice => choice.ruleId === ruleId)?.proficiencyIds ?? [];
  }

  availableExpertiseIds(ruleId: number, optionIds: number[]): number[] {
    const selected = new Set(this.selectedExpertiseIds(ruleId));
    return optionIds.filter(id => !selected.has(id));
  }

  sourceLabel(source: string): string {
    if (source === 'class') return 'Classe';
    if (source === 'race') return 'Race';
    if (source === 'background') return 'Historique';
    return source;
  }

  chosen(kind: 'proficiency' | 'language' | 'expertise', key: number | string, value: number | string): boolean {
    const t = this.vm().training();
    if (kind === 'proficiency') return !!t.proficiencyChoices.find(x => x.ruleId === key)?.proficiencyIds.includes(value as number);
    if (kind === 'expertise') return !!t.expertiseChoices.find(x => x.ruleId === key)?.proficiencyIds.includes(value as number);
    return !!t.languageChoices.find(x => x.sourceKey === key)?.languageSlugs.includes(value as string);
  }

  toggle(kind: 'proficiency' | 'language' | 'expertise', key: number | string, value: number | string, checked: boolean, limit: number): void {
    const t = structuredClone(this.vm().training());
    if (kind === 'language') {
      let group = t.languageChoices.find(x => x.sourceKey === key);
      if (!group) { group = { sourceKey: key as string, languageSlugs: [] }; t.languageChoices.push(group); }
      group.languageSlugs = this.change(group.languageSlugs, value as string, checked, limit);
      if (!group.languageSlugs.length) t.languageChoices = t.languageChoices.filter(x => x.sourceKey !== key);
    } else {
      const list = kind === 'proficiency' ? t.proficiencyChoices : t.expertiseChoices;
      let group = list.find(x => x.ruleId === key);
      if (!group) { group = { ruleId: key as number, proficiencyIds: [] }; list.push(group); }
      group.proficiencyIds = this.change(group.proficiencyIds, value as number, checked, limit);
      if (!group.proficiencyIds.length) {
        if (kind === 'proficiency') t.proficiencyChoices = t.proficiencyChoices.filter(x => x.ruleId !== key);
        else t.expertiseChoices = t.expertiseChoices.filter(x => x.ruleId !== key);
      }
    }
    this.vm().setTraining(t);
  }

  private change<T>(items: T[], value: T, checked: boolean, limit: number): T[] {
    if (!checked) return items.filter(x => x !== value);
    return items.includes(value) || items.length >= limit ? items : [...items, value];
  }

  substitution(ruleId: number, original: number, replacement: string): void {
    const t = structuredClone(this.vm().training());
    t.substitutions = t.substitutions.filter(x => !(x.ruleId === ruleId && x.originalProficiencyId === original));
    if (replacement) t.substitutions.push({
      ruleId, originalProficiencyId: original, replacementProficiencyId: Number(replacement),
    });
    this.vm().setTraining(t);
  }

  replacement(ruleId: number, original: number): string {
    return String(this.vm().training().substitutions.find(x =>
      x.ruleId === ruleId && x.originalProficiencyId === original
    )?.replacementProficiencyId ?? '');
  }

  addGrant(): void {
    const t = structuredClone(this.vm().training());
    t.manualGrants.push({ kind: 'LANGUAGE', languageSlug: '', reason: '' });
    this.vm().setTraining(t);
  }

  editGrant(index: number, field: 'kind' | 'target' | 'reason', value: string): void {
    const t = structuredClone(this.vm().training());
    const g = t.manualGrants[index];
    if (field === 'kind') {
      g.kind = value as 'LANGUAGE' | 'PROFICIENCY' | 'EXPERTISE';
      delete g.languageSlug; delete g.proficiencyId;
      if (g.kind === 'LANGUAGE') g.languageSlug = '';
      else g.proficiencyId = 0;
    } else if (field === 'reason') g.reason = value;
    else if (g.kind === 'LANGUAGE') g.languageSlug = value;
    else g.proficiencyId = Number(value);
    this.vm().setTraining(t);
  }

  removeGrant(index: number): void {
    const t = structuredClone(this.vm().training());
    t.manualGrants.splice(index, 1);
    this.vm().setTraining(t);
  }

  grantValid(): boolean {
    return this.vm().training().manualGrants.every(g =>
      !!g.reason.trim() && g.reason.length <= 500 &&
      (g.kind === 'LANGUAGE' ? !!g.languageSlug : !!g.proficiencyId)
    );
  }
}
