# Maîtrises — PHB 2014

Le catalogue conserve les 18 compétences et 6 sauvegardes initiales et ajoute
37 armes, 2 groupes d'armes, 4 catégories d'armures/boucliers et 37 outils.
Les nouveaux slugs reprennent les noms anglais, indépendamment des libellés français.
Toutes ces nouvelles définitions ont `ability: null`.

Sources consultées (règles 2014, pas 2024), le 11 octobre 2026 :

- https://www.aidedd.org/regles/equipement/armes/
- https://www.aidedd.org/regles/equipement/armures/
- https://www.aidedd.org/regles/equipement/outils/

Les libellés français suivent les tables bilingues AideDD ; leur identité avec
chaque impression du PHB français n'a pas été vérifiée sur un exemplaire papier.
Aucun texte descriptif de règle n'est reproduit.

## Outils individuels et catégories de choix

Les 37 outils sont : 17 métiers artisanaux, 10 instruments, 4 jeux et 6 autres
outils/kits (déguisement, contrefaçon, empoisonneur, herboriste, navigateur, voleur).
Les familles « artisan », « instrument » et « jeu » ne sont pas des maîtrises
effectives. Aucune définition globale n'est créée pour ces familles.

Le modèle actuel permet à une future ProficiencyGrantRule de référencer les
outils individuels dans ses options ManyToMany, avec son choiceCount. Il ne
dispose pas de taxonomie persistée des familles : leur regroupement automatique
reste à concevoir si nécessaire. Ce catalogue ne crée aucune règle d'attribution.

Les groupes d'armes sont des maîtrises effectives, distinctes des propriétés
d'armes. Le modèle actuel ne relie pas les armes individuelles à leur groupe ;
l'ajout de définitions ne fournit donc pas une résolution de cette inclusion.

## Limite de périmètre

Les véhicules terrestres et aquatiques figurent également dans la table des
outils du PHB. Leur représentation doit être décidée explicitement : l'enum
actuel ne possède pas de type VEHICLE. Ils ne sont pas ajoutés arbitrairement
comme outils dans ce lot. Aucune langue, arme improvisée, propriété d'arme ou
maîtrise individuelle de modèle d'armure n'est ajoutée.
