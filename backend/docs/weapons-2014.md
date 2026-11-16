# Armes : modèle de lecture 2014

Le profil joueur contient `weapons`, calculé par `CharacterWeaponCalculator` à partir des possessions `CharacterMagicItem`, des caractéristiques effectives et des maîtrises déjà résolues par `CharacterTrainingPresenter` (attributions manuelles comprises). Angular affiche les résultats sans calcul métier et sans appel supplémentaire.

Toutes les possessions de catégorie `weapon` figurent dans État, même non équipées. Les valeurs sont théoriques pour une utilisation de cette arme. Les objets personnels sans définition n'ont pas de catégorie fiable : aucun type n'est inféré à partir de leur nom.

## Profil physique

`MagicItem.weaponProfile` est un objet JSON nullable validé par `WeaponProfileSchema` :

```json
{
  "proficiency": "longsword",
  "group": "martial-weapons",
  "mode": "melee",
  "dice": "1d8",
  "damageType": "slashing",
  "properties": [],
  "versatile": "1d10",
  "attackBonus": 2,
  "damageBonus": 2
}
```

`range` contient deux distances en mètres ; `notes` contient un texte facultatif. Les dés physiques excluent les bonus. Les bonus intrinsèques sont séparés (+0 à +3) et soumis à l'harmonisation demandée par la définition. Un profil absent produit des totaux `null`, jamais une estimation depuis le nom ou la description.

Configuration avec les droits existants : `PUT /dnd/items/{itemId}/weapon-profile` (administrateur des références), ou `PUT /campaigns/{campaignId}/magic-items/{itemId}/weapon-profile` (MJ de la campagne), corps `{ "weaponProfile": {...} }`. `null` retire la configuration. Pas d'éditeur Angular de catalogue ajouté.

## Références et installation

La migration `Version20261115010000` ajoute uniquement le champ nullable. `weapon-profiles-2014.json` enrichit 36 références **existantes** par clé d'import ; il ne crée ni second catalogue ni possession. Source : [table des armes et propriétés, Basic Rules 2014](https://www.dndbeyond.com/sources/dnd/basic-rules-2014/equipment).

```sh
php bin/console app:items:enrich-weapons-2014
php bin/console app:items:enrich-weapons-2014 --apply
```

Simulation par défaut ; validation complète avant écriture ; transaction ; configurations existantes préservées ; références absentes/ambiguës signalées. Réservé aux objets officiels 2014 ordinaires et globaux. Aucun nouvel objet créé.

## Calcul et imprégnations

- FOR au corps à corps, DEX à distance. Finesse : meilleur modificateur FOR/DEX, faute de choix enregistré. Une arme de lancer conserve la règle de son mode de base ; une fléchette utilise Finesse.
- Maîtrise spécifique ou de groupe : bonus de maîtrise une seule fois, jamais aux dégâts.
- Polyvalente : dés à deux mains avec les mêmes modificateurs dans Notes.
- Arme améliorée : +1/+2 au niveau d'artificier 10 du créateur ; Arme de retour : +1 et note de retour, propriété Lancer requise.
- Tir à répétition : arme à distance avec Munitions, +1 seulement après harmonisation ; notes sur Chargement et munitions. L'harmonisation réutilise le champ et la limite existants, sans changement permanent de la définition.
- `ItemInfusionArmorEffects::itemProperties(..., true)` fournit les bonus théoriques ; son appel historique conserve le comportement lié à l'équipement. Aucune imprégnation ne s'ajoute à un bonus intrinsèque configuré ou à un objet marqué magique.
- `Version20261115020000` libère l'harmonisation d'une possession ordinaire lorsque son imprégnation prend fin (FIFO, remplacement, retrait ou perte de connaissance). Les harmonisations des objets intrinsèquement magiques sont préservées.

## Limites

Le filet sans dégâts n'a pas encore de profil. Les références génériques « Arme +1/+2/+3 » n'identifient pas leur arme physique : elles restent incomplètes tant qu'un profil n'est pas configuré. Les armes spéciales, choix de caractéristique avancés (pacte, moine, etc.), styles de combat et dégâts circonstanciels ne sont pas déduits automatiquement. Les bonus intrinsèques conditionnels ne sont pas représentés par ce profil standard.

Le tableau ne réalise aucun jet, attaque, déplacement, retour physique ou consommation de munitions. Le rafraîchissement suit le profil existant, notamment après les mutations d'inventaire, sans polling supplémentaire.
