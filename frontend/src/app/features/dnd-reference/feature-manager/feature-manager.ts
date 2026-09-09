import { Component, signal } from '@angular/core';
import { ClassManager } from '../components/class-manager/class-manager';
import { FeatureDefinitionManager } from '../components/feature-definition-manager/feature-definition-manager';
import { FeatureRuleManager } from '../components/feature-rule-manager/feature-rule-manager';
import { RaceManager } from '../components/race-manager/race-manager';
import { ResourceDefinitionManager } from '../components/resource-definition-manager/resource-definition-manager';
import { SubclassManager } from '../components/subclass-manager/subclass-manager';
import { FeatManager } from '../components/feat-manager/feat-manager';

type ManagerSection =
  | 'classes'
  | 'races'
  | 'subclasses'
  | 'features'
  | 'resources'
  | 'rules'
  | 'feats';

interface ManagerTab {
  section: ManagerSection;
  label: string;
  description: string;
}

@Component({
  selector: 'app-feature-manager',
  standalone: true,
  imports: [
    ClassManager,
    RaceManager,
    SubclassManager,
    FeatureDefinitionManager,
    ResourceDefinitionManager,
    FeatureRuleManager,
    FeatManager,
  ],
  templateUrl: './feature-manager.html',
  styleUrl: './feature-manager.scss',
})
export class FeatureManager {
  protected readonly activeSection = signal<ManagerSection>('classes');

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
    this.activeSection.set(section);
  }
}
