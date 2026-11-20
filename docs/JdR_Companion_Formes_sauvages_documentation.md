# JdR Companion — Documentation du système de Forme sauvage

> **État au 20 novembre 2026** — chantier `feature/druid-wild-shape`  
> Référentiel de fonctionnement, architecture, données, règles et points restant à traiter.  
> **Périmètre :** système D&D 5e 2014 tel que développé et testé dans JdR Companion. Les détails d'API, schémas et signatures non confirmés doivent être vérifiés dans le dépôt avant maintenance.

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
| Catalogue de bêtes | Implémenté | 94 bêtes répertoriées |
| Blocs statistiques SRD 2014 | Partiel | 80 renseignés, 14 manquants |
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
| Traduction des traits | À faire | 28 définitions importées |
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
