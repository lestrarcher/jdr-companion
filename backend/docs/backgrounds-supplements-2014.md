# Historiques des suppléments — règles D&D 2014

> **État de livraison — 14 octobre 2026.** Voir l'[état consolidé](artificer-proficiencies-2014.md#état-de-livraison--14-octobre-2026).
> Migration Bricolages et import artificier réalisés localement ; tests manuels
> LIVE/CLOSED/LIVE satisfaisants, selon confirmation du MJ. Résolution du 503
> médias non confirmée en production. Aucun déploiement effectué.
>
> **Historique :** le contenu ci-dessous conserve les constats, tests et consignes
> du lot à sa date de rédaction. Les mentions d'opérations encore à autoriser,
> de validation manuelle restante ou de l'ancien écran fermé sont historiques.
> Aucun import ni migration supplémentaire n'a été exécuté pour les commits.


## Périmètre et stabilité

Lot repris sur le commit `7420387` (maîtrises et multiclassage intégrés).
Le catalogue `data/reference/dnd-2014-backgrounds.json` reste inchangé :
13 historiques PHB, 26 règles, mêmes slugs et clés d'import. Sur la base de
développement auditée, leurs IDs sont 1 à 13. Aucune écriture de catalogue n'a
été exécutée pendant ce chantier.

Le nouveau fichier `data/reference/dnd-2014-backgrounds-supplements.json`
ajoute 19 historiques, 34 règles de maîtrises et 18 règles de langues.
Toutes les maîtrises sont de niveau 1, mode `ANY`. Les règles de langues
utilisent le modèle existant `LanguageGrantRule` au niveau 1.
Pas de migration, de nouveau modèle ni de modification du level-up.

Les libellés français sont éditoriaux lorsque la traduction publiée n'a pas
pu être confirmée ; `englishName` permet une identification sans ambiguïté.
Toutes ces entrées suivent les règles de 2014, y compris Bigby (VO 2023),
sans bonus de caractéristiques ou don d'origine 2024 ajouté.

| Source | Historique | Nom anglais | Slug | Règles maîtrises / langues |
|---|---|---|---|---|
| SCAG | Garde du guet | City Watch | `city-watch-scag` | 1 / 1 |
| SCAG | Enquêteur du guet | City Watch (Investigator) | `city-watch-investigator-scag` | 1 / 1 |
| SCAG | Artisan de clan | Clan Crafter | `clan-crafter-scag` | 2 / 1 |
| SCAG | Érudit cloîtré | Cloistered Scholar | `cloistered-scholar-scag` | 2 / 1 |
| SCAG | Courtisan | Courtier | `courtier-scag` | 1 / 1 |
| SCAG | Agent de faction | Faction Agent | `faction-agent-scag` | 2 / 1 |
| SCAG | Voyageur lointain | Far Traveler | `far-traveler-scag` | 2 / 1 |
| SCAG | Héritier | Inheritor | `inheritor-scag` | 3 / 1 |
| SCAG | Chevalier d'ordre | Knight of the Order | `knight-of-the-order-scag` | 3 / 1 |
| SCAG | Mercenaire vétéran | Mercenary Veteran | `mercenary-veteran-scag` | 2 / 0 |
| SCAG | Chasseur de primes urbain | Urban Bounty Hunter | `urban-bounty-hunter-scag` | 1 / 0 |
| SCAG | Membre de tribu uthgardt | Uthgardt Tribe Member | `uthgardt-tribe-member-scag` | 2 / 1 |
| SCAG | Noble d'Eauprofonde | Waterdhavian Noble | `waterdhavian-noble-scag` | 2 / 1 |
| Curse of Strahd | Hanté | Haunted One | `haunted-one-cos` | 1 / 2 |
| Van Richten | Enquêteur | Investigator | `investigator-vrgr` | 2 / 0 |
| Witchlight | Égaré féerique | Feylost | `feylost-wbtw` | 2 / 1 |
| Witchlight | Employé du carnaval | Witchlight Hand | `witchlight-hand-wbtw` | 2 / 1 |
| Bigby | Gigantin | Giant Foundling | `giant-foundling-bgg` | 1 / 2 |
| Bigby | Ciseleur de runes | Rune Carver | `rune-carver-bgg` | 2 / 1 |

Hanté n'est créé qu'une fois : source Curse of Strahd, reprise dans
Van Richten mentionnée dans le JSON et la description persistée. Les deux
Enquêteurs sont distincts : variante du guet SCAG et historique Ravenloft.
Les autres cadres (Ravnica, Theros, Strixhaven, Spelljammer…) sont hors lot.

## Données et limites

Les compétences, outils individuels et langues supportés sont des règles
structurées, interprétées par les résolveurs existants. Les descriptions
résument l'équipement de départ et la capacité d'historique ; elles ne créent
ni inventaire, ni monnaie, ni capacité runtime. Les tables de personnalité,
héritages et événements narratifs ne sont pas importées.

- Hanté : deux compétences parmi Arcanes, Investigation, Religion et Survie ;
  deux langues distinctes, réparties en un choix exotique obligatoire et un
  choix général non secret. Le deuxième choix peut aussi être exotique.
- Égaré féerique : un instrument et **une seule** langue parmi elfique,
  gnome, gobelin et sylvestre. Aucune langue supplémentaire libre.
- Gigantin : Intimidation, Survie, géant et une autre langue. **Frappe des
  géants n'est pas attribué** : `CharacterFeat` ne représente pas le choix
  du type de géant ni ses effets, et aucune règle d'attribution de don par
  historique n'existe. Avertissement explicite dans la description et le
  rapport d'import. Le don existant dans le référentiel n'est pas modifié.
- Ciseleur de runes : Histoire, Perception, un outil d'artisan, géant.
  Même limite pour Façonneur de runes (*Rune Shaper*) et ses choix de
  runes/sorts : aucun don incomplet attribué.
- Chasseur de primes urbain : les deux compétences au choix sont gérées.
  Les deux outils doivent provenir de deux catégories distinctes parmi un
  jeu, un instrument et les outils de voleur. Le modèle de choix plat ne
  représente pas cette restriction : **ces outils restent à attribuer par
  le MJ**, aucune règle permettant deux instruments arbitraires n'est créée.
- Mercenaire vétéran : le jeu est géré ; les véhicules terrestres manquent
  au référentiel des maîtrises et restent explicitement différés.
- Artisan de clan : nain automatique. S'il est déjà connu, le remplacement
  par une autre langue exige une attribution explicite du MJ. Pas de langue
  libre systématique ajoutée en plus du nain.
- Agent de faction et Chevalier d'ordre : les listes de compétences sont
  structurées, mais leur adéquation narrative à la faction/ordre appartient
  au MJ, ces affiliations n'étant pas modélisées.

Les langues exotiques restent sélectionnables dans le builder MJ. Les langues
secrètes sont interdites dans les règles d'historique. Les règles raciales,
langues d'Ennemi juré, substitutions et calculs de multiclassage sont inchangés.

## Import

L'importeur existant accepte un chemin de catalogue. Son chemin par défaut
reste celui du PHB : **fournir le chemin du complément** pour ce lot.
Les définitions de maîtrises et langues doivent déjà exister. Le dry-run
réalisé sur la base actuelle valide toutes ces dépendances.

Depuis la racine du dépôt, vérification sans écriture :

```bash
docker compose exec -T backend php bin/console app:dnd:import-backgrounds data/reference/dnd-2014-backgrounds-supplements.json --dry-run
```

Après validation du MJ, import réel à exécuter manuellement :

```bash
docker compose exec -T backend php bin/console app:dnd:import-backgrounds data/reference/dnd-2014-backgrounds-supplements.json
```

Réimport identique : aucune nouvelle ligne. Pour appliquer ultérieurement
des corrections éditoriales, comparer d'abord avec `--dry-run --update-existing`,
puis utiliser `--update-existing` uniquement après validation. Sans ce drapeau,
les différences sont signalées `keep` et ne sont pas écrasées.

Toutes les entrées sont validées avant persistance, dans la transaction
existante. Les slugs et clés servent d'identité ; aucun DELETE/recréation.
Une collision avec une référence privée échoue. Les modifications mécaniques
des règles de maîtrises utilisées par des choix/substitutions sont refusées.
Les règles de langues existantes n'acceptent que des changements de libellé :
source, options et nombre de choix restent immuables, y compris avec le drapeau.

Sur une base neuve, les imports existants des maîtrises et de la formation
(`app:dnd:import-proficiencies`, `app:dnd:import-training`) doivent avoir été
réalisés avec leurs propres dépendances ; ce complément ne les lance pas.
Aucun de ces imports avec écriture n'a été lancé pour ce chantier.

## Visibilité et contrat existant

`GET /api/campaigns/{campaignId}/dnd/reference` expose `backgrounds` avec
`id`, `slug`, `name`, `description`. `GET /api/dnd/reference` expose seulement
les références officielles. Pas de nouveau contrat parallèle.

Le builder utilise déjà le catalogue de campagne et parcourt `backgrounds`
sans liste de slugs codée en dur. Les 19 entrées apparaîtront après import.
Les choix continuent à utiliser `training.proficiencyChoices` (`ruleId`,
`proficiencyIds`) et `training.languageChoices` (`sourceKey`, `languageSlugs`).
Les avertissements sont disponibles dans `description` ; **le formulaire
Angular actuel n'affiche que le nom**. Leur affichage serait utile dans un
lot frontend distinct. Aucun fichier Angular n'a été modifié.

Un `CharacterBackground` privé déjà créé avec `origin=CUSTOM` et `owner=MJ`
est proposé dans les campagnes de ce MJ, accepté par le builder, et caché
aux autres MJ. Ses règles doivent également être privées et appartenir au
même MJ. Le modèle et `ReferenceVisibility` le permettent déjà ; en revanche,
les écrans d'administration de référence actuels n'offrent pas de CRUD
d'historiques privés. Cette fonctionnalité n'est pas développée ici.

## Vérifications reproductibles

```bash
docker compose exec -T backend php tools/test-import-backgrounds.php
docker compose exec -T backend php tools/test-backgrounds-supplements.php
docker compose exec -T backend php tools/test-backgrounds-supplements-roundtrip.php
docker compose exec -T backend php tools/test-character-training.php
docker compose exec -T backend php tools/test-feature-training.php
docker compose exec -T backend php bin/console lint:container
docker compose exec -T backend php bin/console doctrine:schema:validate
git diff --check
```

Résultats : 134 + 257 + 186 + 57 + 41 = **675 assertions**, conteneur et
schéma valides. Le test d'intégration crée uniquement des tables/séquences
temporaires PostgreSQL, vérifie leur isolation et annule sa transaction.
Il couvre les 19 créations via le builder, la relecture Doctrine, les choix,
les identités PHB et associations existantes, les listes officielles/privées,
un réimport inchangé et la commande réelle en dry-run. Les collections sans
ordre SQL garanti sont comparées comme ensembles, avec multiplicité conservée.

## Sources et contrôle éditorial

- *Sword Coast Adventurer's Guide*, chapitre Backgrounds (2015), y compris
  la variante City Watch (Investigator).
- [Hanté — fiche officielle legacy](https://www.dndbeyond.com/backgrounds/34-haunted-one) :
  Curse of Strahd, repris dans *Van Richten's Guide to Ravenloft* (2021).
- *Van Richten's Guide to Ravenloft*, Character Creation : Investigator.
- [Présentation officielle des historiques Witchlight](https://www.dndbeyond.com/posts/1077-new-backgrounds-creating-characters-from-the)
  (2021).
- [Gigantin et Frappe des géants — présentation officielle](https://www.dndbeyond.com/posts/1531-first-look-giant-foundling-background-and-strike)
  (2023) ; *Bigby Presents: Glory of the Giants*, Rune Carver.
- [Fiche française du livre Bigby](https://www.aidedd.org/dnd-5/livres/bigby-presents-glory-of-the-giants/)
  pour les noms Gigantin et Ciseleur de runes.

Le recoupement de l'audit utilisait aussi les données de backgrounds de
`5etools-mirror-3/5etools-src` (sources SCAG, VRGR, WBtW et BGG). Les champs
mécaniques ont été confrontés au texte : notamment Feylost n'obtient pas deux
langues, et Clan Crafter n'obtient pas systématiquement nain plus un choix.
Les descriptions françaises sont des résumés, pas une reproduction des livres.
