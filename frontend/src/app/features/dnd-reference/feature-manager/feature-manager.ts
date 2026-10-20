import { AuthService } from '@core/services/auth.service';
import { Component, inject, signal, viewChild } from '@angular/core';
import { ActivatedRoute } from '@angular/router';
import { CustomContentApiService } from '@core/services/custom-content-api.service';
import { REFERENCE_EDITOR_SCOPE, REFERENCE_VIEW_ORIGIN, REFERENCE_READ_ONLY } from '@core/services/reference-editor-context';
import { ClassManager } from '../components/class-manager/class-manager';
import { FeatureDefinitionManager } from '../components/feature-definition-manager/feature-definition-manager';
import { FeatureRuleManager } from '../components/feature-rule-manager/feature-rule-manager';
import { RaceManager } from '../components/race-manager/race-manager';
import { ResourceDefinitionManager } from '../components/resource-definition-manager/resource-definition-manager';
import { SubclassManager } from '../components/subclass-manager/subclass-manager';
import { FeatManager } from '../components/feat-manager/feat-manager';
import { ProgressionManager } from '../components/progression-manager/progression-manager';
import { BackgroundEditor } from '../../custom-content/background-editor/background-editor';
import { SpellEditor } from '../../custom-content/spell-editor/spell-editor';

type ManagerSection =
  | 'classes'
  | 'races'
  | 'subclasses'
  | 'feats'
  | 'progressions'
  | 'features'
  | 'resources'
  | 'rules'
  | 'backgrounds'
  | 'spells';

interface ManagerTab {
  section: ManagerSection;
  label: string;
  description: string;
}

@Component({
  selector: 'app-feature-manager',
  standalone: true,
  providers: [CustomContentApiService,
    { provide: REFERENCE_VIEW_ORIGIN, useFactory: () => inject(ActivatedRoute).snapshot.data['referenceScope'] === 'official' ? 'OFFICIAL' : 'CUSTOM' },
    { provide: REFERENCE_EDITOR_SCOPE, useFactory: () => inject(REFERENCE_VIEW_ORIGIN) === 'OFFICIAL' && inject(AuthService).user()?.roles.includes('ROLE_REFERENCE_ADMIN') ? 'official' : 'custom' },
    { provide: REFERENCE_READ_ONLY, useFactory: () => inject(REFERENCE_VIEW_ORIGIN) === 'OFFICIAL' && inject(REFERENCE_EDITOR_SCOPE) !== 'official' }],
  imports: [
    ClassManager,
    RaceManager,
    SubclassManager,
    FeatureDefinitionManager,
    ResourceDefinitionManager,
    FeatureRuleManager,
    FeatManager,
    ProgressionManager,
    BackgroundEditor,
    SpellEditor,
  ],
  templateUrl: './feature-manager.html',
  styleUrl: './feature-manager.scss',
})
export class FeatureManager {
  private readonly backgroundEditor = viewChild(BackgroundEditor);
  private readonly spellEditor = viewChild(SpellEditor);
  protected readonly official = inject(REFERENCE_VIEW_ORIGIN) === 'OFFICIAL';
  protected readonly readOnly = inject(REFERENCE_READ_ONLY);
  protected readonly activeSection = signal<ManagerSection>(this.official ? 'races' : 'classes');

  protected readonly tabs: ManagerTab[] = [
    {
      section: 'classes',
      label: 'Classes',
      description: 'Dés de vie et progression magique',
    },
    {
      section: 'races',
      label: 'Races',
      description: 'Origines et bonus de caractéristiques',
    },
    {
      section: 'subclasses',
      label: 'Sous-classes',
      description: 'Spécialisations et progressions',
    },
    {
      section: 'feats',
      label: 'Dons',
      description: 'Talents et améliorations spéciales',
    },
    {
      section: 'backgrounds',
      label: 'Historiques',
      description: 'Origines et parcours des personnages',
    },
    {
      section: 'spells',
      label: 'Sorts',
      description: 'Grimoire et magie des personnages',
    },
    {
      section: 'progressions',
      label: 'Progressions',
      description: 'Jauges narratives et paliers',
    },
    {
      section: 'features',
      label: 'Capacités',
      description: 'Actions, passifs et pouvoirs',
    },
    {
      section: 'resources',
      label: 'Ressources',
      description: 'Jauges et règles de recharge',
    },
    {
      section: 'rules',
      label: 'Attributions',
      description: 'Déblocages selon les niveaux',
    },
  ];

  protected selectSection(section: ManagerSection): void {
    if (section === this.activeSection() || !(this.backgroundEditor()?.canDiscard() ?? true) || !(this.spellEditor()?.canDiscard() ?? true)) return;
    this.activeSection.set(section);
  }
}
