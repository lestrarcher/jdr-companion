export interface TurnRule { id: string; name: string; description: string; }
export interface TurnSection { id: string; name: string; icon: string; rules: TurnRule[]; }
export const TURN_SECTIONS: TurnSection[] = [
  { id: 'action', name: 'Action', icon: 'bi-sword', rules: [
    { id: 'attack', name: 'Attaquer', description: 'Effectuer une attaque au corps à corps ou à distance. Certaines capacités permettent plusieurs attaques avec cette action.' },
    { id: 'grapple', name: 'Agripper (Attaquer)', description: 'Remplacer une attaque par un jet de Force (Athlétisme) opposé à la Force (Athlétisme) ou à la Dextérité (Acrobaties) de la cible, à son choix. Une main libre est nécessaire ; la cible doit être à portée et au maximum une catégorie de taille plus grande.' },
    { id: 'shove', name: 'Bousculer (Attaquer)', description: 'Remplacer une attaque par un jet opposé comme pour agripper, sans exigence de main libre. En cas de réussite, mettre la cible à terre ou la repousser de 1,5 m.' },
    { id: 'cast', name: 'Lancer un sort', description: 'Lancer un sort dont le temps d’incantation est une action, en respectant ses composantes et ressources.' },
    { id: 'dash', name: 'Foncer', description: 'Gagner un déplacement supplémentaire égal à sa vitesse actuelle pour ce tour.' },
    { id: 'disengage', name: 'Se désengager', description: 'Son déplacement ne provoque pas d’attaque d’opportunité pendant le reste du tour.' },
    { id: 'dodge', name: 'Esquiver', description: 'Jusqu’au début de son prochain tour, les attaques des adversaires visibles contre soi ont le désavantage et les sauvegardes de Dextérité ont l’avantage. Le bénéfice cesse si l’on est neutralisé ou si sa vitesse tombe à zéro.' },
    { id: 'help', name: 'Aider', description: 'Donner l’avantage au prochain test de caractéristique pertinent d’un allié avant son prochain tour, ou à sa première attaque contre une cible située à 1,5 m de soi avant son prochain tour.' },
    { id: 'hide', name: 'Se cacher', description: 'Effectuer un test de Dextérité (Discrétion) si les circonstances permettent de se cacher.' },
    { id: 'ready', name: 'Se tenir prêt', description: 'Choisir un déclencheur perceptible et une action ou un déplacement à effectuer en réaction avant son prochain tour. Préparer un sort exige une action d’incantation, dépense ses ressources et nécessite la concentration.' },
    { id: 'search', name: 'Chercher', description: 'Chercher quelque chose ; le MJ peut demander un test de Sagesse (Perception) ou d’Intelligence (Investigation).' },
    { id: 'object', name: 'Utiliser un objet', description: 'Utiliser un objet nécessitant une action ou effectuer une interaction supplémentaire avec un objet. Les objets magiques peuvent avoir leurs propres règles.' },
  ] },
  { id: 'bonus', name: 'Action bonus', icon: 'bi-lightning-charge', rules: [
    { id: 'bonus-rule', name: 'Utiliser une action bonus', description: 'Une seule par tour, uniquement si un sort, une capacité ou une règle le permet. Elle ne remplace pas une action principale.' },
    { id: 'cast', name: 'Lancer un sort', description: 'Lancer un sort dont le temps d’incantation est une action bonus. Les autres sorts lancés pendant ce tour doivent être des tours de magie dont le temps d’incantation est une action.' },
    { id: 'two-weapons', name: 'Combat à deux armes', description: 'Après une attaque avec une arme de corps à corps légère tenue dans une main lors de l’action Attaquer, attaquer avec une autre arme légère tenue dans l’autre main. Ne pas ajouter le modificateur de caractéristique aux dégâts de cette seconde attaque, sauf s’il est négatif ou si une capacité le permet.' },
  ] },
  { id: 'speech', name: 'Parler', icon: 'bi-chat-dots', rules: [{ id: 'speak', name: 'Communiquer', description: 'Prononcer quelques mots ou faire de brefs gestes pendant son tour sans dépenser d’action.' }] },
  { id: 'movement', name: 'Se déplacer', icon: 'bi-signpost-split', rules: [] },
  { id: 'free', name: 'Actions libres', icon: 'bi-stars', rules: [] },
  { id: 'special', name: 'Déclenchements spéciaux', icon: 'bi-exclamation-circle', rules: [] },
  { id: 'reaction', name: 'Réaction', icon: 'bi-shield', rules: [
    { id: 'opportunity', name: 'Attaque d’opportunité', description: 'Lorsqu’une créature hostile visible quitte volontairement sa portée, effectuer une attaque de corps à corps en réaction, juste avant qu’elle ne la quitte. La téléportation et certains déplacements forcés ne déclenchent pas cette attaque.' },
    { id: 'cast', name: 'Lancer un sort', description: 'Lancer un sort dont le temps d’incantation est une réaction lorsque son déclencheur survient.' },
    { id: 'ready-trigger', name: 'Déclencher une action préparée', description: 'Lorsque le déclencheur choisi survient, utiliser sa réaction juste après celui-ci ou choisir de l’ignorer.' },
  ] },
];
export const MOVEMENT_RULES: TurnRule[] = [
  { id: 'difficult', name: 'Terrain difficile', description: 'Chaque mètre parcouru coûte un mètre supplémentaire de déplacement.' },
  { id: 'climb-swim', name: 'Escalader et nager', description: 'Chaque mètre coûte un mètre supplémentaire, sauf si une vitesse adaptée est utilisée. Le terrain difficile ajoute encore un mètre. Le MJ peut demander un test d’Athlétisme.' },
  { id: 'crawl', name: 'Ramper', description: 'Chaque mètre parcouru en rampant coûte un mètre supplémentaire.' },
  { id: 'stand', name: 'Se relever', description: 'Se relever de l’état à terre coûte la moitié de sa vitesse. Impossible si sa vitesse est nulle ou si le déplacement restant est insuffisant.' },
  { id: 'drag', name: 'Déplacer une créature agrippée', description: 'Sa vitesse est divisée par deux en déplaçant une créature agrippée, sauf si celle-ci est au moins deux catégories de taille plus petite.' },
  { id: 'split', name: 'Fractionner son déplacement', description: 'Le déplacement peut être réparti avant et après une action, et entre les attaques. En changeant de mode de déplacement, soustraire la distance déjà parcourue de la nouvelle vitesse.' },
];
