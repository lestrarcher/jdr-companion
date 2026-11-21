# JdR Companion — Documentation du système de Forme sauvage

> **État au 20 novembre 2026 — refonte du référentiel implémentée** — chantier `feature/druid-wild-shape`
> Référentiel de fonctionnement, architecture, données, règles et points restant à traiter.
> **Périmètre :** système D&D 5e 2014 tel que développé et testé dans JdR Companion. Les détails d'API, schémas et signatures non confirmés doivent être vérifiés dans le dépôt avant maintenance.
>
> **État actuel : voir §18.** Les §3 et §17 conservent les constats historiques du chantier. Le modèle Doctrine, le CRUD, la protection des imports et les miniatures sont désormais implémentés. Comptage DEV vérifié : **103 bêtes, 89 profils, 14 incomplets, 128 actions, 10 habitats / 162 associations, 48 définitions liées / 108 associations de capacités**.

## 1. Objectif et principes

Le système de **Forme sauvage** permet à un druide de se transformer en bête, de jouer avec ses statistiques de forme animale, puis de revenir à sa forme normale. Il est intégré aux composants existants du portail joueur : **État**, **Caractéristiques**, **Armes**, **Capacités** et **Sorts**. Il ne crée pas une deuxième fiche de personnage autonome.

Principes retenus :

- Le MJ gère le catalogue de bêtes, les formes observées et les attributions pertinentes.
- Le joueur ne peut sélectionner que les bêtes autorisées par son niveau, son cercle et les restrictions de déplacement.
- La transformation consomme une utilisation de Forme sauvage ; le retour à la forme normale est gratuit.
- Le druide conserve séparément ses PV d'origine et ceux de sa forme animale.
- La fiche affichée présente les statistiques *effectives* de la forme active.
- Les actions et capacités animales apparaissent dans les composants habituels, sans duplication de l'interface.
- Le système suit les règles **2014** ; les exceptions de haut niveau sont à compléter.

## 2. État fonctionnel

| Domaine | État | Remarques |
|---|---|---|
| Catalogue de bêtes | Administrable via Doctrine | 103 bêtes répertoriées |
| Blocs statistiques | Partiel | 89 renseignés, 14 manquants ; sources SRD et PDF |
| Habitats | Implémenté | 10 habitats, 162 associations |
| Bêtes observées | Implémenté | Exemple : 34 formes observées pour Greto |
| Filtrage des formes éligibles | Implémenté | FP, restrictions nage/vol, observation |
| Transformation initiale | Validé | Consomme une utilisation |
| Changement direct de bête | Validé | Consomme une nouvelle utilisation |
| Retour en forme normale | Validé | Gratuit, même sans utilisation restante |
| PV distincts bête/druide | Implémenté | Actualisation UI corrigée |
| Statistiques effectives | Implémenté | Caractéristiques, compétences et maîtrises |
| Attaques naturelles | Implémenté | Dans l'onglet Armes |
| Traits des bêtes | Implémenté | Dans l'onglet Capacités |
| Restriction d'incantation | Implémenté | Exceptions de haut niveau à traiter |
| Guérison lunaire | Validé | Carte virtuelle dans Sorts, dés physiques |
| Repos court/long | Validé | Recharge et actualisation sans F5 |
| Traduction des traits | À revérifier | Le document initial dénombrait 28 définitions ; des enrichissements ont suivi |
| Audit général des formules de PV | À faire | Anomalie confirmée sur le loup sanguinaire |
| PV à zéro et dégâts excédentaires | À vérifier | Retour forcé et report sur le druide |

## 3. Catalogue des bêtes

### 3.1. Données

Le catalogue distingue les **bêtes disponibles**, leurs **blocs statistiques**, leurs **habitats**, leurs **traits** et leur statut d'**observation** par personnage.

Données constatées pendant le chantier :

- **94** bêtes au catalogue ;
- **80** blocs statistiques importés depuis les sources SRD 2014 ;
- **14** profils encore incomplets ;
- **10** habitats et **162** associations bête–habitat ;
- **28** définitions de traits et **102** associations bête–trait ;
- **34** bêtes observées par Greto lors d'un test.

Ces nombres décrivent l'état du catalogue lors du chantier, pas une contrainte fonctionnelle.

### 3.2. Format du bloc statistique

La table `beast` comporte une colonne `stat_block` de type **PostgreSQL `json`** (et non `jsonb`). Parmi les champs utilisés :

- `hitPoints` : PV moyens de la bête ;
- `hitDice` : expression des dés de vie, telle que `5d10+10`.

Les caractéristiques, attaques et autres statistiques de la bête sont également exploitées par le système. Le détail exact des clés doit être contrôlé dans les données et les services actuels.

**Attention :** l'import de certains blocs peut omettre le bonus fixe des dés de vie. Il ne faut pas considérer `hitDice` comme fiable sans audit.

### 3.3. Import et enrichissement

Commandes/classes présentes dans le chantier :

- `ImportBeastCatalogue2014Command.php`
- `EnrichBeastCatalogue2014Command.php`
- `ImportBeastHabitats2014Command.php`
- `ImportBeastTraits2014Command.php`

Fichiers de données identifiés :

- `backend/data/beasts-2014-seed.json`
- `backend/data/beasts-2014-stats.json`
- `backend/data/beasts-2014-habitats.json`
- `backend/data/beasts-2014-import-report.json`
- `backend/data/monster-list.xml`

**Maintenance :** vérifier les options et les effets exacts de chaque commande avec `php bin/console list` / `--help` avant tout réimport. Un réimport peut écraser une correction SQL faite directement en base.

## 4. Règles d'éligibilité

Le catalogue présenté au druide est filtré en fonction :

1. Du **niveau de druide** et des règles de Forme sauvage applicables ;
2. Du **cercle**, notamment **Cercle de la Lune** ;
3. Du **facteur de puissance** maximal autorisé ;
4. Des limitations liées aux **vitesses de nage et de vol** ;
5. Des **bêtes observées** ou autrement autorisées pour le personnage.

Le backend doit rester l'autorité finale pour ces règles : le filtrage Angular ne suffit pas à sécuriser une transformation.

Référence de logique : `backend/src/Service/WildShapeRules2014.php`.

## 5. Cycle de vie d'une transformation

### 5.1. Entrée en Forme sauvage

Depuis la ressource **Forme sauvage** de l'onglet **État** :

1. Le joueur ouvre la modale compacte de transformation.
2. Il sélectionne une bête éligible.
3. Il choisit les PV de la forme : valeur moyenne, maximum théorique ou valeur personnalisée correspondant à ses dés physiques.
4. Il confirme.
5. Une utilisation de Forme sauvage est consommée.
6. La fiche du joueur affiche les statistiques de la bête et sa réserve de PV animale.

Le choix personnalisé permet de continuer à utiliser des dés physiques à la table.

### 5.2. Changement direct de forme

Lorsqu'il est déjà transformé, le druide peut sélectionner une **autre bête** sans revenir manuellement à sa forme humanoïde. Ce changement **consomme une nouvelle utilisation** de Forme sauvage. Les statistiques et les PV de la nouvelle forme sont alors appliqués.

### 5.3. Retour à la forme normale

Le retour à la forme d'origine est **gratuit**. Il reste possible lorsque le compteur de Forme sauvage est à zéro. Le personnage retrouve sa fiche normale et ses PV humanoïdes conservés séparément.

### 5.4. Repos

Les repos courts et longs restaurent les utilisations de Forme sauvage conformément au mécanisme implémenté. Le rafraîchissement du compteur dans le portail joueur a été corrigé dans le **lot 3F.4** : le joueur ne doit plus devoir appuyer sur F5.

## 6. Points de vie

### 6.1. Deux réserves distinctes

Le système conserve les PV de la forme animale indépendamment des PV du druide. Les soins ou dégâts reçus pendant la transformation affectent la réserve appropriée. L'affichage des PV lors des transitions a été corrigé dans le **lot 3F.3**.

### 6.2. Moyenne, maximum et dés physiques

Pour une formule `NdF+B` :

- **PV minimum théorique :** `N + B` ;
- **PV maximum théorique :** `N × F + B` ;
- **PV moyens :** valeur `hitPoints` importée, à comparer à la formule et au bloc officiel ;
- **PV personnalisés :** résultat des dés physiques, dans la plage autorisée.

Les bornes doivent inclure **le bonus fixe `B`**, y compris côté validation backend.

### 6.3. Exemple réel : loup sanguinaire

Profil `beast.id = 55`, slug `loup-sanguinaire`.

| Champ | Valeur corrigée |
|---|---|
| `hitPoints` | `37` |
| `hitDice` | `5d10+10` |
| PV minimum | `15` |
| PV maximum | `60` |

La base contenait initialement `5d10` sans bonus fixe. La correction SQL a été effectuée et **le joueur a validé une transformation à 60 PV**.

La colonne `stat_block` étant de type `json`, la mise à jour a nécessité un cast vers `jsonb`, puis vers `json` :

```bash
docker compose exec -T database psql -U jdr -d jdr_companion -c "UPDATE beast SET stat_block = jsonb_set(stat_block::jsonb, '{hitDice}', to_jsonb('5d10+10'::text))::json WHERE id = 55 RETURNING id, name, stat_block->>'hitDice' AS des_de_vie;"
```

**Point essentiel :** cette correction a été faite directement dans la base de développement. **Elle n'est pas automatiquement versionnée par Git**. Corriger aussi la source importée et/ou l'importeur pour qu'une autre base ou un réimport retrouve la bonne formule.

### 6.4. Cas limites à tester

- Bête à **0 PV** : retour forcé à la forme normale.
- **Dégâts excédentaires** : report du surplus sur les PV du druide.
- Soins pendant la transformation : bonne réserve modifiée.
- Changement de forme après perte de PV : nouvelle réserve correctement initialisée.
- Concentration maintenue ou interrompue suivant les règles, sans suppression arbitraire lors de la transformation.

Ces cas sont des **points de validation**, pas des bugs tous démontrés.

## 7. Statistiques effectives du personnage

Pendant la transformation, l'interface doit refléter les valeurs applicables à la bête :

- caractéristiques physiques ;
- classe d'armure et caractéristiques utiles ;
- compétences et maîtrises ;
- attaques naturelles, bonus d'attaque et dégâts ;
- traits et capacités de la forme.

Les corrections ont été réparties dans les lots **3C** (fiche effective, maîtrises, armes naturelles) et **3D** (traits et capacités).

Le système doit éviter de confondre les données permanentes du personnage avec les valeurs **temporaires** de la forme active.

## 8. Attaques naturelles

Les attaques naturelles de la bête apparaissent dans l'interface **Armes** existante. L'objectif est de réutiliser les cartes et conventions déjà développées pour les armes, plutôt que d'ajouter un écran de combat parallèle.

La source des attaques et leurs valeurs doivent provenir du bloc statistique effectif de la bête. Vérifier la restitution des bonus et dégâts lors d'un changement de forme ou du retour à l'état normal.

## 9. Traits et capacités des bêtes

### 9.1. Catalogue partagé

Les traits sont importés comme **définitions réutilisables**, associées à plusieurs bêtes :

- **28** définitions de traits ;
- **102** associations avec des bêtes.

Exemples visibles sur le **loup sanguinaire** :

- `Pack Tactics` ;
- `Keen Hearing and Smell`.

Les capacités sont présentées dans l'onglet **Capacités** du portail joueur.

### 9.2. Administration

Le chantier a ajouté un gestionnaire de traits de bêtes côté MJ et une intégration au gestionnaire de références.

Fichiers repères :

- `backend/src/Controller/BeastFeatureAdminController.php`
- `frontend/src/app/features/dnd-reference/components/beast-feature-manager/`
- composants `feature-rule-manager` et `feature-manager`.

### 9.3. Traduction à réaliser

Les définitions importées sont encore principalement **en anglais**. Certaines descriptions contiennent des références à une espèce particulière (par exemple **« baboon »**) alors que le même trait est partagé par d'autres bêtes.

Plan de finition :

1. Traduire les **28 définitions** en français, selon la terminologie D&D 2014 utilisée dans l'application.
2. Rendre les descriptions **génériques** lorsqu'un trait est partagé.
3. Conserver les **102 associations** existantes.
4. Éviter les doublons entre traits importés et traits créés manuellement.
5. Examiner la suppression de la capacité française manuelle **« Tactique de groupe »** si elle est réellement redondante et non attribuée.

Ne pas supprimer de trait sans vérifier ses associations.

## 10. Magie pendant la transformation

### 10.1. Restrictions

Le lancement ordinaire de sorts est bloqué pendant la Forme sauvage, selon les règles 2014 au niveau actuellement pris en charge. La **concentration sur un sort déjà actif** doit rester possible selon les règles applicables.

Les exceptions de druide de haut niveau (notamment niveaux 18 et 20) sont **reportées**.

### 10.2. Guérison lunaire

Pour le druide du **Cercle de la Lune**, **Guérison lunaire** est intégrée comme une **carte de sort virtuelle** :

1. La carte apparaît dans la liste rapide des sorts, avec une **icône de lune**.
2. Le joueur ouvre la **modale de sort habituelle**.
3. Il choisit le niveau de l'emplacement à dépenser.
4. Le système consomme **l'emplacement**, sans lancer automatiquement de dés.
5. Le joueur lance **1d8 par niveau d'emplacement** avec ses dés physiques.
6. Il applique ensuite le total via **État → Soins**.

Ce comportement a été validé. **Ne pas ajouter de lancer automatique de dés ni de formulaire de soin spécifique** : la réutilisation de la modale de sorts est un choix explicite de conception.

Fichiers repères :

- `backend/src/Service/CharacterSpellCastExecutor.php`
- composants `player-spells`, `spell-quick-list` et `spell-cast-dialog`.

## 11. Architecture technique

### 11.1. Backend Symfony

| Élément | Responsabilité générale |
|---|---|
| `BeastCatalogue.php` | Consultation et exploitation du catalogue |
| `WildShapeRules2014.php` | Éligibilité et règles 2014 |
| `WildShapeSessionService.php` | Cycle de vie des transformations |
| `PublicBeastCatalogueController.php` | Exposition du catalogue au portail |
| `PublicWildShapeController.php` | Actions de Forme sauvage côté joueur |
| `BeastFeatureAdminController.php` | Administration des traits |
| `CharacterRestService.php` | Restauration des ressources au repos |
| `CharacterSpellCastExecutor.php` | Restrictions et Guérison lunaire |

Les chemins ci-dessus correspondent aux fichiers identifiés lors du chantier. **Les routes, noms de méthodes et structures de réponses doivent être relus dans les sources actuelles** avant d'écrire un client ou des tests d'intégration.

### 11.2. Frontend Angular

| Composant | Intégration |
|---|---|
| `druid-wild-shapes/` | Choix et contrôle des formes |
| `character-resources` | Utilisations et déclenchement de transformation |
| `character-vitals` | PV de la forme active |
| `character-statistics` | Caractéristiques effectives |
| `character-weapons` | Attaques naturelles |
| `character-features` | Traits animaux |
| `player-spells` | Restriction d'incantation et Guérison lunaire |
| `spell-quick-list` | Carte virtuelle de Guérison lunaire |
| `spell-cast-dialog` | Choix d'emplacement et consommation |
| `player-portal` | Coordination et rafraîchissement |
| `beast-feature-manager/` | Gestion des traits côté MJ |

### 11.3. Migrations et tests

Migrations créées pendant le chantier :

- `backend/migrations/Version20261119020000.php`
- `backend/migrations/Version20261119030000.php`
- `backend/migrations/Version20261120010000.php`

Tests identifiés :

- `backend/tests/Service/WildShapeRules2014Test.php`
- `backend/tests/Service/WildShapeSessionServiceTest.php`

Ne pas attribuer une responsabilité précise à chaque migration sans lire son contenu.

## 12. Commandes d'environnement

Depuis `D:\Dev\jdr-companion`, branche de développement :

```bash
git status -sb
git branch --show-current
docker compose ps
docker compose exec backend php bin/console list
```

Connexion PostgreSQL DEV documentée dans Trello :

```bash
docker compose exec -T database psql -U jdr -d jdr_companion -c "SELECT id, name, slug, stat_block->>'hitPoints' AS pv_moyens, stat_block->>'hitDice' AS des_de_vie FROM beast WHERE slug = 'loup-sanguinaire';"
```

Compilation Angular utilisée dans le projet :

```bash
docker compose exec frontend npm run build
```

**Précaution :** vérifier la cible DEV/PROD avant toute écriture ou réimport.

## 13. Historique des lots

| Lot | Objet |
|---|---|
| Fondation / catalogue | Catalogue, import, blocs statistiques, habitats et observations |
| 3C | Statistiques effectives, maîtrises, compétences et attaques naturelles |
| 3D | Traits de bêtes, gestion MJ et affichage Capacités |
| 3E | Restrictions magiques et Guérison lunaire |
| 3F | Ergonomie de transformation, changement direct, rafraîchissements |
| 3F.3 | Actualisation des PV lors des transformations |
| 3F.4 | Actualisation des ressources après repos |

Les numéros servent de repères historiques ; le détail exact de chaque patch est à confronter aux commits.

## 14. Reste à faire — priorités

### Priorité 1 — Fiabiliser les PV importés

- Auditer les **80** profils statistiques renseignés, notamment les expressions `hitDice` et les modificateurs de Constitution.
- Corriger l'importeur ou le fichier source, pas seulement la base courante.
- Tester les bornes min/max et la validation backend pour les formules avec bonus.
- Prévoir une correction reproductible pour les autres environnements.

### Priorité 2 — Traduire les traits

- Traduire les **28** traits.
- Conserver les **102** associations.
- Supprimer les références d'espèce incorrectes dans les définitions partagées.
- Traiter les doublons manuels après vérification des usages.

### Priorité 3 — Cas limites

- PV à zéro, retour forcé, dégâts excédentaires.
- Concentration pendant la transformation.
- Comportement en cas de repos ou de rafraîchissement dans des états inhabituels.
- Éventuelles exceptions de lancement de sorts aux niveaux 18/20.

### Priorité 4 — Qualité des données

- Compléter les **14** blocs statistiques manquants.
- Vérifier les traits, attaques et habitats sur un échantillon varié de bêtes.
- Ajouter des tests de non-régression sur plusieurs formules de dés de vie.

## 15. Scénarios de recette recommandés

| Scénario | Résultat attendu |
|---|---|
| Greto sélectionne une bête éligible | Transformation acceptée, 1 utilisation consommée |
| Bête hors FP autorisé | Transformation refusée |
| Bête non observée | Non proposée / refusée selon les règles du système |
| Transformation en loup sanguinaire avec PV max | **60 PV** |
| Changement direct vers une autre bête | Nouvelle forme, nouvelle utilisation consommée |
| Retour à la forme normale avec 0 utilisation | Retour autorisé |
| Attaques de loup sanguinaire | Visibles dans Armes |
| Traits du loup sanguinaire | Visibles dans Capacités |
| Sort ordinaire en forme animale | Bloqué au niveau actuellement pris en charge |
| Guérison lunaire | Dépense un emplacement ; aucun dé automatique |
| Repos court ou long | Utilisations restaurées et affichées sans F5 |
| Bête à 0 PV avec surplus de dégâts | Retour forcé et report correct du surplus **à confirmer** |

---

## 16. Note de transmission aux prochaines conversations

**Le système est déjà largement fonctionnel.** Il ne faut pas repartir de zéro ni recréer des interfaces indépendantes. Le portail joueur réutilise volontairement ses composants **État**, **Armes**, **Capacités** et **Sorts**.

Le correctif du loup sanguinaire (`5d10+10`, maximum 60) a été testé avec succès **en base locale**, mais n'est pas garanti dans les fichiers versionnés. Le prochain chantier naturel est l'**audit des formules de PV** puis la **traduction des traits**.

Pour toute modification, demander ou ouvrir les **fichiers actuels** de la branche `feature/druid-wild-shape` : les archives de lots précédents peuvent être dépassées.


## 17. Évolution récente et problème architectural majeur — à transmettre à Codex

### 17.1. Décision de conception (prioritaire)

**Le modèle actuel n'est pas satisfaisant pour un référentiel administrable.** Les bêtes sont principalement manipulées via la table PostgreSQL `beast`, des blocs `stat_block` JSON, des commandes d'import et des services utilisant directement **Doctrine DBAL / SQL**. Il n'existe pas, dans l'état inspecté lors de ce chantier, d'entité Doctrine `Beast` servant de point d'entrée métier. Les fichiers JSON sont adaptés au *seeding* mais **ne doivent pas tenir lieu de modèle métier ou de source de vérité à l'exécution**.

Le besoin réel du MJ est de pouvoir **lister, rechercher, créer, éditer et gérer individuellement** chaque bête : identité, caractéristiques, FP, CA, PV, vitesses, traits/capacités, attaques naturelles, habitats, illustrations et relations utiles à la Forme sauvage. La tentative d'ajouter une image a révélé ce problème plus large.

**Mission proposée à Codex : refactoriser progressivement vers un modèle Doctrine orienté objet, sans perdre les données ni casser le portail joueur.** Ne pas confondre POO et création d'une table pour chaque propriété : des champs typés, objets-valeurs ou `Embeddable` conviennent aux scores de caractéristiques et vitesses ; les armes naturelles, habitats et capacités partagées justifient des objets et associations dédiés. Réutiliser les entités génériques existantes si elles conviennent.

Modèle cible **à confirmer après audit du schéma réel** :

- `Beast` : entité Doctrine mappée sur la table **existante** `beast` (pas de table parallèle), identité, slug, FP, caractéristiques, CA, PV, vitesses, image, etc.
- `BeastNaturalWeapon` : entité mappée sur la table existante `beast_natural_weapon`, relation vers `Beast`, composantes de dégâts, bonus, portée et description.
- Traits/capacités : réutiliser les définitions de capacités existantes et leurs tables d'association ; ne pas dupliquer le référentiel générique.
- Habitats : entités ou référentiels existants et associations bête–habitat, selon le schéma réel.
- Illustration : référence à un média ou fichier géré par le système d'upload, avec un stockage global indépendant des campagnes.
- Services de règles (`WildShapeRules2014`, `WildShapeSessionService`) : **conserver leur responsabilité métier** ; remplacer progressivement les accès DBAL du catalogue par des repositories, sans réécrire les règles fonctionnelles validées.

**Exigences non négociables** : migration non destructive, aucune perte de bêtes/observations/relations/transformations, API compatibles ou adaptation coordonnée du frontend, pas de doublons de définitions, imports idempotents, tests de non-régression, vérification des règles 2014 et du personnage Greto. Les imports JSON deviennent des commandes de chargement initial, non des modifications permanentes de données administrées par le MJ. Prévoir une stratégie explicite de conflit entre import et modifications manuelles.

### 17.2. Armes naturelles structurées (travail ajouté après la documentation initiale)

Un lot a introduit une **table `beast_natural_weapon`**, la migration `backend/migrations/Version20261120020000.php`, le service `BeastNaturalWeaponCatalogue.php` et la commande `ImportBeastNaturalWeapons2014Command.php` (`app:beasts:import-natural-weapons-2014`). L'import a été exécuté en développement : **113 actions créées pour 80 bêtes**, puis un second passage sans modification. Un correctif ultérieur a nettoyé les descriptions pour retirer la répétition des jets d'attaque/dégâts ; l'import a signalé 113 mises à jour, puis 0 au passage suivant. **Ces chiffres sont historiques, à revérifier après les nouveaux imports.**

Le portail joueur consomme les profils structurés dans `character-weapons`, au lieu d'interpréter les textes d'action avec des regex. Exemple validé : araignée géante, *Morsure* (+5, 1d8+3 perforants) et *Toile* (+5, action spéciale sans dégâts directs). Le tableau d'armes a été allégé : **nom, bonus d'attaque, dégâts, détails** ; les notes, effets et capacités longues appartiennent à la **modale descriptive**, pas à une colonne « Notes ». En responsive, cartes compactes sans défilement horizontal.

Attention : un profil d'arme naturelle est distinct d'une capacité passive et peut contenir un effet spécial ; le modèle Doctrine et les DTO doivent préserver cette distinction. Le texte traduit et les modifications manuelles doivent survivre aux réimports.

### 17.3. Navigation et ergonomie du portail

La navigation a été extraite du composant parent vers `frontend/src/app/features/player-portal/components/player-portal-tabs/` (`.ts`, `.html`, `.scss`). Répartition `space-evenly` ; libellés desktop, icônes Bootstrap Icons sur mobile ; l'onglet **Formes sauvages** n'apparaît que lorsqu'il est pertinent. L'icône `bi-paw-print` n'existe pas dans Bootstrap Icons : elle a été remplacée par `bi-bug`. La dépendance `bootstrap-icons` est installée via npm **dans le conteneur frontend**, et sa CSS est importée **globalement** dans `frontend/src/styles.scss` ; ne pas l'importer dans le SCSS d'un composant (dépassement du budget Angular). Un build a été validé ; reste un warning indépendant sur `feature-definition-manager.scss`.

Le composant `druid-wild-shapes` présente les formes dans des cartes compactes regroupées par FP, avec filtres et modale. Le mobile doit rester léger, notamment pour Greto qui a un onglet supplémentaire.

### 17.4. Images des bêtes : lot préparé, bug d'administration à résoudre

Le besoin est un **upload d'image par le MJ**, réutilisant la logique d'upload déjà disponible pour les figures, avec illustrations officielles ou personnelles, et **miniatures lazy-loaded** dans le portail joueur. Les médias des figures sont rattachés aux campagnes : **ne pas utiliser leur dossier comme stockage définitif d'un référentiel global**.

Un patch de lot 1 a été préparé avec :

- migration `backend/migrations/Version20261120180000.php` ajoutant `beast.image_filename` nullable ;
- adaptation de `MediaStorageService.php`, `BeastCatalogue.php` et `BeastFeatureAdminController.php` ;
- `PublicBeastImageController.php`, endpoint de consultation des fichiers référencés ;
- modifications du gestionnaire MJ `beast-feature-manager` et de `druid-wild-shapes` ;
- stockage global `backend/public/uploads/beasts`, formats JPEG/PNG/WebP/GIF, limite prévue de 5 Mo ; attributs HTML `loading="lazy"` et `decoding="async"`.

**Ne pas considérer ce lot comme entièrement validé.** Une capture du 20 novembre montre que la requête réseau `beast-features` renvoie un objet avec un tableau `beasts` (champs `id`, `name`, `slug`, `imageFilename`), **mais l'interface du gestionnaire affiche « Aucune bête trouvée »**. Le composant ne parvient donc pas à présenter la liste nécessaire pour sélectionner une bête et modifier son image. Vérifier le flux exact HTTP → service Angular → état du composant → filtrage/template, les erreurs console et la compatibilité des structures de réponse. Ne pas conclure sans diagnostic que Doctrine est la cause directe de ce bug. Vérifier également le statut réel de la migration et de l'upload sur la branche.

**Optimisation ultérieure** : générer des miniatures WebP/AVIF adaptées au rendu (le simple `loading="lazy"` ne réduit pas le poids d'une image originale). Prévoir image de remplacement, dimensions fixes, et préserver les images lors des imports.

### 17.5. Nouvelles bêtes du PDF « dinosaurs.pdf »

Un PDF de trois pages a été fourni. Il comporte **neuf fiches** :

| Bête | FP | CA | PV | Page PDF |
|---|---:|---:|---:|---:|
| Allosaure | 2 | 13 | 51 | 1 |
| Ankylosaure | 3 | 15 | 68 | 1 |
| Plésiosaure | 2 | 13 | 68 | 1 |
| Ptéranodon | 1/4 | 13 | 13 | 1 |
| Quetzalcoatlus | 2 | 13 | 30 | 2 |
| Strige | 1/8 | 14 | 2 | 2 |
| Tricératops | 5 | 13 | 95 | 2 |
| Tyrannosaure | 8 | 13 | 136 | 3 |
| Vélociraptor | 1/4 | 13 | 10 | 3 |

Le document source est **partiel** pour le quetzalcoatlus et le vélociraptor : ne pas inventer leurs attaques manquantes. Un patch d'import a été préparé : `backend/data/beasts-dinosaurs-pdf.json` et `backend/src/Command/ImportDinosaursPdfCommand.php`, commande `app:beasts:import-dinosaurs-pdf` (simulation par défaut, `--apply` pour insertion). Il compare les **slugs**, ignore les bêtes déjà présentes et ajoute les manquantes via SQL dans `beast` ; il ne met pas à jour les fiches existantes. **L'exécution effective de cet import et le nouveau nombre total de bêtes ne sont pas confirmés dans cette documentation.**

Après import, vérifier les associations habitats/traits et les armes naturelles : les commandes de synchronisation ne doivent pas écraser les données enrichies ou administrées manuellement. Les chiffres historiques **94 bêtes / 80 blocs / 14 incomplets** ci-dessus précèdent cette opération et ne doivent pas être présentés comme un état courant garanti.

### 17.6. Plan d'exécution conseillé à Codex

1. **Auditer avant de modifier** : schéma PostgreSQL, migrations, services DBAL, JSON, routes/DTO, relations et tests. Dresser une cartographie des tables existantes et des données déjà persistées. Vérifier le statut des patches images/dinosaures sur la branche.
2. **Débloquer le gestionnaire MJ** : réparer l'affichage de la liste de bêtes renvoyée par `beast-features`, puis tester sélection et upload d'image ; éviter une refonte globale comme prétexte pour laisser ce bug ouvert.
3. **Introduire le modèle Doctrine** : entités et associations mappées sur les tables existantes, repositories, types cohérents et règles de validation ; **pas de migration destructive ni de duplication de tables**.
4. **Migrer les accès aux données par tranches** : catalogue et administration d'abord, puis les consommateurs publics, sans changer arbitrairement les contrats JSON ni la logique de transformation. Conserver les services de règles métier.
5. **Créer le gestionnaire complet** : liste/recherche, création, édition, image, statistiques, attaques, capacités et habitats ; gérer les suppressions avec protections référentielles et préférer une désactivation si une bête est utilisée.
6. **Sécuriser les imports** : idempotence, respect des modifications MJ, import dinosaures et mise à jour des associations ; conserver le JSON comme source d'amorçage seulement.
7. **Recette** : tests backend et frontend, build Angular, Greto (transformations, armes, capacités, PV, ressources), uploads et lazy loading, aucun changement de comportement des autres personnages.

**Définition de terminé** : un MJ peut administrer individuellement une bête de bout en bout ; la fiche est persistée via des objets/repositories Doctrine ; les images et relations sont conservées ; le portail joueur continue à fonctionner ; les imports ne détruisent pas les corrections manuelles ; les tests et builds passent.

**Ne pas élargir automatiquement la mission à tout le référentiel D&D.** La refonte vise d'abord les bêtes ; ne toucher aux modules partagés que lorsque leur réutilisation est nécessaire et documentée. Le propriétaire du projet souhaite garder Codex disponible pour d'autres chantiers : privilégier des lots autonomes et vérifiables plutôt qu'une réécriture monolithique.

## 18. Référentiel administrable — implémentation du 20 novembre 2026

### 18.1. Audit et modèle retenu

Le schéma DEV confirme les migrations `20261119020000`, `20261119030000`, `20261120010000`, `20261120020000` et `20261120180000`. L'import dinosaures a bien enrichi le catalogue : les anciens nombres 94/80 ne sont plus les compteurs courants. Aucune illustration n'était associée dans cette base au moment de l'audit.

Les responsabilités étaient réparties entre `BeastCatalogue`, `BeastNaturalWeaponCatalogue`, les contrôleurs public/admin et six importeurs DBAL. Les mêmes lectures et conversions JSON étaient répétées. Le bug de liste vide ne venait pas de Doctrine : **14 noms `NULL` provoquaient une exception dans `name.toLocaleLowerCase()`**. Le backend et le gestionnaire affichent maintenant le slug en remplacement ; une erreur de chargement reste visible même sans sélection.

Les entités suivantes mappent directement les tables existantes, sans copie de données :

| Entité | Table / responsabilité |
|---|---|
| `Beast` | `beast`, identité, FP, statistiques JSON, image, associations et protection des imports |
| `BeastNaturalWeapon` | `beast_natural_weapon`, actions individuelles et dégâts structurés ; les identifiants sont conservés à l'édition |
| `BeastFeature` | `beast_feature`, lien ordonné vers **la même** `CharacterFeatureDefinition` partagée |
| `BeastHabitat` | `beast_habitat`, identité composée bête/habitat et liste des dix valeurs réutilisables |

`BeastRepository` charge le catalogue et ses relations ; `BeastEditor` valide les requêtes avant mutation ; `BeastSerializer` maintient les contrats JSON. Les scores, CA, PV, dés de vie, taille, vitesses et sens restent dans `stat_block` (`json`) : aucune table par valeur numérique, aucune suppression des métadonnées de source ou champs importés supplémentaires. Les valeurs éditées sont validées côté serveur. Un profil `null` reste autorisé pour une référence incomplète, et reste inéligible aux transformations.

Le référentiel ne contient que des bêtes. Le nom affiché est modifiable ; **le slug d'une bête persistée est stable**, afin qu'un import ne recrée pas son ancienne identité. Les portées/allonges et effets particuliers restent dans la description des actions, conformément au contrat déjà consommé par `character-weapons` ; les jets et composantes de dégâts restent structurés séparément.

### 18.2. Migration et conservation

`Version20261121010000` ajoute uniquement `beast.customized BOOLEAN NOT NULL DEFAULT FALSE`. Aucun changement d'identifiant, aucune table supplémentaire, aucune réécriture des statistiques ou états de session. Son retour arrière est volontairement refusé pour ne pas perdre les marqueurs de protection.

Migration simulée, testée sur une table temporaire, puis appliquée **uniquement en DEV** par exécution ciblée. Les empreintes avant/après des données existantes sont identiques pour : 103 bêtes, 128 armes, 162 liens habitats, 108 liens capacités, 34 observations et 35 états de session. Aucune migration de production, aucun import de catalogue réel, aucun commit/push n'a été effectué.

Les observations restent dans `character_seen_beast`, gérées par le contrat DBAL idempotent existant. Les transformations restent dans `CharacterSessionState.state.wildShape`. Le contrôleur public lit les bêtes via le repository, conserve les règles et verrouille la bête pendant une transformation. Aucun resolver ne cherche une session implicite.

### 18.3. Administration et contrats HTTP

Le composant existant `beast-feature-manager` réutilise ses panneaux et ajoute `beast-profile-editor` pour le formulaire. Création, modification des statistiques, sens, habitats, ajout/édition/retrait d'actions, association/retrait de capacités, images et suppression sont disponibles dans le référentiel. Les capacités partagées continuent à se créer/modifier dans leur gestionnaire existant.

Toutes les routes d'administration exigent un utilisateur connecté et `ROLE_REFERENCE_ADMIN` :

| Méthode / chemin backend (préfixer `/api` depuis Angular) | Résultat |
|---|---|
| `GET /dnd/beast-features` | Contrat existant `beasts`, `features`, `links` ; ajoute `habitats` |
| `POST /dnd/beast-features` | Création, réponse 201 `{beast}` |
| `GET /dnd/beast-features/{id}` | Détail `{beast}` |
| `PUT /dnd/beast-features/{id}/profile` | Édition transactionnelle ; relations omises préservées |
| `PUT /dnd/beast-features/{id}` | Contrat existant de remplacement des `featureIds` |
| `DELETE /dnd/beast-features/{id}` | Refus 409 si observation ou transformation active ; sinon retrait des relations propres à la bête |
| `POST / DELETE /dnd/beast-features/{id}/image` | Upload / remplacement / suppression |

Le formulaire de profil n'envoie pas les attributions de capacités : elles sont enregistrées séparément et ne peuvent pas être écrasées par une ancienne réponse de détail. Les champs importés non édités restent conservés. Les tableaux de relations fournis remplacent leur contenu ; les identifiants d'armes étrangers, doublons, habitats inconnus et valeurs invalides sont refusés. Les suppressions ne suppriment jamais les définitions de capacités partagées.

Le catalogue joueur conserve ses champs existants et ajoute `naturalWeapons` et `features`. La modale de consultation affiche ces relations administrées ; `statBlock.actions` et `statBlock.traits` restent des données d'import conservées, sans reprendre l'autorité sur les tables métier. Les onglets Armes et Capacités réutilisent leurs composants habituels. Les statistiques d'une transformation déjà active restent son instantané ; éditer le catalogue ne réécrit pas cet état.

### 18.4. Imports et conflits

- Une édition de profil, d'action, d'habitat ou d'attribution marque la bête `customized=true`. Aucun importeur ne remplace ces données ni ne recrée les relations retirées de cette bête.
- `app:beasts:import-2014` crée seulement les slugs absents ; les URLs existantes sont préservées.
- `app:beasts:enrich-2014` remplit les profils absents par défaut. Les différences sur des profils existants sont signalées et préservées, y compris les anciennes corrections SQL sans marqueur.
- `app:beasts:import-natural-weapons-2014` conserve les actions existantes par défaut ; les actions personnalisées restent toujours protégées.
- Pour ces deux dernières commandes, `--replace-unmanaged` permet une actualisation explicite **uniquement des données non personnalisées**. Examiner d'abord la simulation sans `--apply`. Cette option peut remplacer d'anciennes corrections SQL non marquées : elle n'est pas nécessaire à l'administration courante.
- L'import habitats est désormais additif ; il ne retire aucune association existante et ignore les bêtes personnalisées.
- L'import traits conserve toutes les variantes pour calculer les slugs, ignore les attributions des bêtes personnalisées et ne réécrit plus les définitions partagées existantes. Leur édition appartient au gestionnaire de capacités.
- L'import PDF reste insert-only, ignore les slugs présents et n'invente pas les actions absentes du document source.

Les fichiers JSON restent des sources d'amorçage. La base administrée est la source de vérité à l'exécution. Les commandes d'enrichissement, habitats, traits, actions et PDF restent en simulation par défaut.

### 18.5. Illustrations et portail

Stockage global dans `public/uploads/beasts`, réutilisant `MediaStorageService`, indépendant des campagnes. JPEG/PNG/WebP/GIF, 5 Mo maximum, limite de décodage à 16 millions de pixels. L'original est conservé. L'endpoint public `/public/beasts/images/{filename}/thumbnail` génère à la demande un WebP de **256 px maximum**, sans agrandissement ; le GIF utilise sa première image. Les miniatures sont mises en cache dans `beasts/thumbnails`, y compris pour les images anciennes. Le remplacement et la suppression retirent l'ancien original et sa miniature après réussite de la transaction.

**GD avec JPEG/WebP est requis** ; le Dockerfile backend l'installe désormais. L'image Docker a été reconstruite et le service backend DEV recréé. Les cartes utilisent les miniatures, `loading="lazy"`, `decoding="async"`, des dimensions fixes et `/assets/beast-placeholder.svg` en absence/échec d'image. Aucun changement de navigation du portail.

### 18.6. Validation et limites explicites

Commandes exécutées depuis le dépôt :

```bash
docker compose build backend
docker compose up -d --no-deps backend
docker compose exec -T backend php bin/console doctrine:migrations:execute 'DoctrineMigrations\Version20261121010000' --up --dry-run --no-interaction
docker compose exec -T backend php bin/console doctrine:migrations:execute 'DoctrineMigrations\Version20261121010000' --up --no-interaction
docker compose exec -T backend php bin/phpunit tests/Integration/BeastAdministrationTest.php tests/Service/WildShapeRules2014Test.php tests/Service/WildShapeSessionServiceTest.php
docker compose exec -T frontend npm test -- --watch=false --include='**/beast-feature-manager.spec.ts'
docker compose exec -T frontend npm run build
docker compose exec -T backend php bin/console lint:container
docker compose exec -T backend php bin/console doctrine:schema:validate
docker compose exec -T backend php bin/console debug:router --show-controllers
docker compose exec -T backend php scripts/check-beast-catalogue.php Greto
git diff --check
```

Les tests de persistance utilisent exclusivement des tables PostgreSQL temporaires dans une transaction rollbackée. Ils couvrent le CRUD, l'identité des armes, les relations partagées, les valeurs invalides, les droits administrateur, les imports répétés, la migration additive, les suppressions protégées, l'upload/remplacement/suppression et les dimensions réelles des WebP. La suite ciblée comporte **16 tests backend** et **4 tests Angular**. Les tests Angular reproduisent les noms `null`, vérifient le filtrage, les erreurs visibles, la conservation des données et l'annulation d'une requête de détail devenue obsolète.

Un ancien test interdisait le changement direct de forme malgré son implémentation déjà fonctionnelle : il a été corrigé pour vérifier la seconde consommation et le refus à zéro utilisation. Aucun changement des règles n'a été fait pour satisfaire le test.

La recette `check-beast-catalogue.php Greto` vérifie les 103 fiches contre les données SQL et les 128 actions, puis simule **20 formes observées éligibles** de Greto (druide 2, Lune, 34 observations), dont araignée géante, loup sanguinaire et ours brun. Les transformations, changements directs, repos et retour se font en mémoire ; **aucun état de Greto n'est écrit**. Ce contrôle ne remplace pas une recette visuelle interactive sur mobile.

`lint:container`, mapping Doctrine, routes et build Angular passent. Le build garde l'avertissement préexistant `feature-definition-manager.scss` (11,30 kB / budget 10 kB). La validation globale de synchronisation du schéma reste en échec sur les tables historiques DBAL non mappées (`character_seen_beast`, tables de choix/sorts, etc.) et des écarts hors périmètre. La proposition SQL a seulement été lue : **aucune mise à jour globale du schéma n'a été exécutée**. Les quatre tables mappées du bestiaire ne présentent pas d'écart proposé.

Restent hors de cette refonte : compléter les 14 profils absents, vérifier les données partielles du PDF, et traiter les exceptions D&D de haut niveau déjà différées. Aucune donnée de règles n'a été inventée ou réimportée pour remplir ces lacunes.
