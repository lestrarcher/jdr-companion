import { Component } from '@angular/core';

interface QuestPreview {
  title: string;
  objective: string;
  category: string;
}

@Component({
  selector: 'app-quests-panel',
  imports: [],
  templateUrl: './quests-panel.html',
  styleUrl: './quests-panel.scss',
})
export class QuestsPanel {
  protected readonly quests: QuestPreview[] = [
    {
      title: `L'attaque de loups-garous`,
      objective: 'Retrouver les loups-garous qui ont attaqué le Gué de la Dague et sauver les enfants enlevés.',
      category: 'Barovie'
    },
    {
      title: 'La prophétie de Mme Eva',
      objective: `Rassembler les artefacts et trouver l'allié pour vaincre Strahd.`,
      category: 'Prophétie'
    },
    {
      title: 'Ireena Kolyana',
      objective: `Protéger Ireena et l'accompagner jusqu'à l'Abbaye Ste Markovia`,
      category: 'Barovie'
    },
    {
      title: 'Le maître du Barovie',
      objective: 'Participer au bal de Strahd.',
      category: 'Principale'
    },
    {
      title: 'Le Livre de Strahd',
      objective: `Vaincre Vladimir Cornegaarde pour récupérer le Livre et venger Greto et Rhéa.`,
      category: 'Prophétie'
    },
    {
      title: 'La deuxième gemme',
      objective: `Aller aux ruines du bérez pour récupérer la deuxième gemme`,
      category: 'Bérez'
    },
  ];

  protected get scrollDuration(): string {
    /*
    * Environ sept secondes de lecture par quête,
    * avec un minimum de trente secondes.
    */
    return `${Math.max(this.quests.length * 7, 30)}s`;
  }
}

