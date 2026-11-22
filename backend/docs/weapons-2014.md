# Armes : modèle de lecture 2014

Le profil joueur contient `weapons`, calculé par `CharacterWeaponCalculator` à partir des possessions `CharacterMagicItem`, des caractéristiques effectives et des maîtrises déjà résolues par `CharacterTrainingPresenter` (attributions manuelles comprises). Angular affiche les résultats sans calcul métier et sans appel supplémentaire.

Toutes les possessions de catégorie `weapon` figurent dans État, même non équipées. Les valeurs sont théoriques pour une utilisation de cette arme. Les objets personnels sans définition n'ont pas de catégorie fiable : aucun type n'est inféré à partir de leur nom.

## Attaques sans inventaire

`CharacterNaturalAttackCalculator` ajoute `attack:unarmed` et `attack:improvised` après les armes possédées. Les identifiants de possessions restent numériques ; ceux des attaques sans objet sont des chaînes stables. Aucune possession n'est créée. Une caractéristique manquante laisse l'attaque incomplète, avec des valeurs `null`.

- Mains nues : maîtrise + FOR ; dégâts 1 + FOR, minimum 0.
- Arme improvisée : FOR, 1d4 + FOR ; type et éventuelle assimilation à une arme laissés au MJ. La ligne décrit une attaque de mêlée.
- Bagarreur de tavernes (`tavern-brawler`) : maîtrise improvisée et dé de mains nues 1d4.
- Arts martiaux : niveau de **moine** 1/5/11/17 → 1d4/1d6/1d8/1d10, meilleur modificateur FOR/DEX. Dés alternatifs comparés par dégâts moyens, jamais cumulés. Désactivé avec armure, bouclier ou arme équipée autre qu'une épée courte ou une arme simple de mêlée sans les propriétés lourde/deux mains. Un profil d'arme équipée inconnu ne permet pas de supposer ces conditions satisfaites.

Les capacités passives déjà sélectionnées par `CharacterFeatureResolver` fournissent leurs `attackEffects`. Le contexte de progression du profil est conservé ; sous le seuil, l'attaque disparaît du modèle de lecture sans modifier l'état ou l'inventaire. Les capacités nécessitant une action ne sont pas activées implicitement.

La migration `Version20261122030000` ajoute le JSON nullable et configure seulement les griffes de la capacité officielle `dragon-hide` existante. La définition locale est liée au don Cuir du dragon : griffes rétractiles, 1d4 + FOR tranchants, maîtrise des attaques à mains nues. La restriction sans armure concerne la CA, pas les griffes. Aucune identité de personnage n'intervient.

Le champ `attackEffects` est disponible dans les API existantes de création/édition des capacités, avec les mêmes droits de propriété. Exemple :

```json
[{"key":"claws","type":"natural-attack","name":"Griffes","dice":"1d4","damageType":"slashing","ability":"strength","proficient":true,"unarmed":true,"notes":["Griffes rétractiles."]}]
```

Types : `natural-attack` ajoute une ligne ; `unarmed-damage` propose un profil alternatif pour les mains nues ; `improvised-proficiency` accorde la maîtrise. Les profils supportent FOR, DEX ou `best-strength-dexterity`, bonus fixes `attackBonus`/`damageBonus`, et conditions `no-armor`, `no-shield`, `free-hand`. `unarmed: true` permet aux attaques naturelles concernées de bénéficier d'Arts martiaux. Les clés doivent être uniques par capacité ; l'identifiant final est `feature:<slug>:<key>`. Les effets ne sont jamais extraits automatiquement d'une description. L'éditeur visuel des capacités n'expose pas encore ce JSON.

Le portail actualise les attaques à chaque profil reçu, même lorsque seule la définition du personnage change sans révision du state. Les détails d'une attaque sans objet ne chargent pas l'inventaire et n'offrent pas de charges. La forme sauvage conserve son propre tableau d'armes naturelles.

Références : [mains nues et dégâts, règles 2014](https://www.dndbeyond.com/sources/dnd/basic-rules-2014/combat), [Arts martiaux](https://www.dndbeyond.com/sources/dnd/basic-rules-2014/classes), [armes improvisées](https://www.dndbeyond.com/sources/dnd/basic-rules-2014/equipment).

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
