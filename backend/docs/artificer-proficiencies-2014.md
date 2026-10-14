# Artificier et Bricolages magiques — correctifs du 13 octobre 2026

## État de livraison — 14 octobre 2026

État confirmé par le MJ avant la préparation des commits locaux :

- Migration `Version20261014120000` appliquée localement avec succès.
- Import des maîtrises de l'artificier effectué localement ; maîtrises et choix
  de Bloup corrigés. Les historiques supplémentaires ont également été importés.
- Tests manuels du portail LIVE → CLOSED → LIVE jugés satisfaisants.
- Correctif médias : affichage progressif des aperçus livré ; résolution du 503
  non confirmée en production.
- Aucun déploiement effectué. Aucun push, import ou migration supplémentaire
  exécuté pendant la préparation des commits.

Sur une autre base, vérifier séparément les migrations et référentiels présents.
La migration exige la capacité officielle et refuse une configuration incompatible ;
son retour arrière est explicitement irréversible. Ne pas rejouer les opérations
locales aveuglément ni lancer un import global avec `--update-existing`.

Les sections ci-dessous sont des **comptes rendus historiques** des étapes du
13 et du 14 octobre, antérieurs à cette validation. Les mentions « non exécutée »,
« reste à autoriser », « à effectuer » et les anciennes observations sur Bloup
ne décrivent plus l'état local de livraison. Leurs résultats de tests restent
datés de leurs exécutions respectives. Le parcours de consultation final est
celui de [la fiche commune LIVE/CLOSED](player-portal-readonly-2026-10-14.md).

---

> Mise à jour du 14 octobre : l'import des maîtrises est désormais réalisé
> (confirmé par lecture de la base). Le complément ci-dessous décrit la
> finalisation du compteur et l'interface MJ ; les sections du 13 octobre
> restent le compte rendu du lot précédent.

## Finalisation du 14 octobre 2026

### Diagnostic confirmé

- La capacité officielle `magical-tinkering` avait `resource_definition_id = NULL`
  et aucune définition de ressource correspondante. Les notes seules ne pouvaient
  pas faire apparaître un compteur. Le catalogue versionné avait la même lacune.
- Les recherches Git sur les branches locales/distantes disponibles et `main`
  n'ont pas retrouvé le compteur V1 sous les noms Bricolages / magical-tinkering.
  Aucun ancien slug ni comportement de repos n'est donc supposé.
- Le présentateur utilisé par GET training/options, exécuté dans une transaction
  SQL READ ONLY sur Bloup (35), renvoie la règle 166 (outil, 1 choix) et 167
  (compétences, 2 choix) avec `selected: []`, `status: missing`. Ses langues
  nain, géant et gobelin sont complètes. L'API fonctionnait ; aucun formulaire
  MJ ne permettait le rattrapage au niveau actuel hors montée de niveau.
- L'état 40 de Bloup ne contient actuellement que `spell-slot-1` dans resources,
  sans ancien compteur de bricolages ni champ magicalTinkeringNotes. Aucune
  valeur n'a été modifiée et aucun choix n'a été fait pour lui.

### Compteur et données

Ressource normalisée `magical-tinkering`, maximum `ability-modifier`, Intelligence,
base 0, multiplicateur 1, minimum 1, recharge `none`. Le résolveur, la factory,
le synchroniseur et les repos existants sont réutilisés sans modification.
Une configuration de présentation autorise explicitement les augmentations
manuelles côté PATCH et UI ; cela ne rend pas la ressource rechargeable au repos.

Valeur courante : `state.resources[{id: "magical-tinkering", currentValue: N}]`.
Notes conservées : `state.magicalTinkeringNotes`, sans migration ni copie dans un
second champ. Les anciennes notes attachées à une entrée resource restent aussi
préservées. Les contrôles passent par le PATCH public et la révision existants.
Hors LIVE, compteur et notes sont affichés en lecture seule ; les appels directs
de modification sont refusés par le serveur.

Le maximum est recalculé à chaque résolution avec l'Intelligence effective.
La stratégie commune reste inchangée : synchronisation ordinaire = conservation
de la valeur courante bornée au nouveau maximum ; synchronisation après level-up
= application du delta des maximums. Une baisse peut donc borner le compteur,
comme les autres ressources ; les notes et les autres champs ne sont pas effacés.
Il n'y a pas d'archivage supplémentaire des charges excédentaires dans ce lot.
Une ressource absente est initialisée au maximum par le mécanisme existant.

### Activation sur la base existante — reste à autoriser

La migration **Version20261014120000 est préparée, non exécutée**. Elle crée la
définition et relie la capacité officielle ; elle ne touche aucun Character ni
CharacterSessionState. Elle refuse une référence privée/personnalisée ou une
configuration/liaison incompatible plutôt que de l'écraser. Le catalogue de
capacités est également corrigé pour les futures installations.

Après revue et validation, exécuter uniquement cette migration ciblée :

```sh
docker compose exec -T backend php bin/console doctrine:migrations:execute 'DoctrineMigrations\Version20261014120000' --up
```

Puis recharger le portail. Jusqu'à cette activation, les notes restent visibles
mais le compteur ne peut pas apparaître. Aucun nouvel import de maîtrises n'est
nécessaire. Ne pas lancer un import global avec mise à jour des références pour
réparer uniquement cette liaison. Pas d'exécution SQL de la migration pendant
ce travail ; sa syntaxe PHP est vérifiée et la configuration métier est exercée
par les fixtures isolées.

### Parcours MJ et compatibilité

Campagne → **Personnages** → sélectionner Bloup → **Compléter les maîtrises**.
Choisir deux compétences et un outil puis Enregistrer les choix. La fiche est
rechargée après succès. Aucun niveau supplémentaire n'est ajouté.

Le nouveau panneau réutilise CharacterApiService, les options serveur et les
styles du formulaire de montée de niveau. Il n'affiche que les groupes de
maîtrises incomplets ; les groupes complets, langues et expertises sont omis du
PATCH et conservés. Ce n'est pas un éditeur de toutes les formations ni un
éditeur des substitutions. Le builder existant continue à proposer ces règles
aux nouveaux artificiers, sans sélection automatique.

### Fichiers modifiés dans cette finalisation

- Backend : `data/reference/dnd-2014-class-features.json` ;
  `migrations/Version20261014120000.php` (nouveau) ;
  `src/Service/MagicalTinkeringResourceConfiguration.php` (nouveau),
  `CharacterProfileSerializer.php`, `PlayerCharacterStateUpdater.php`.
- Tests backend : `tools/test-player-security.php`, `tools/test-feature-training.php`.
  Le premier rétablit aussi le bon contexte de session après les scénarios inclus
  afin de tester le token du personnage attendu et ses révisions successives.
- Angular : `core/services/character-api.service.ts` ;
  `features/campaign-characters/campaign-characters.{ts,html}` ;
  `components/character-profile/character-profile.{ts,html}` ; nouveau
  `components/character-training/character-training.ts` et son `.spec.ts` ;
  `features/player-portal/player-portal.{ts,html}` ;
  `components/character-resources/character-resources.{ts,html}` et nouveau `.spec.ts` ;
  `components/magical-tinkering-notes/magical-tinkering-notes.ts`.
- Documentation : ce fichier. Les autres travaux non commités sont conservés.

### Tests exécutés pendant cette finalisation

| Vérification | Résultat |
|---|---|
| `php tools/test-player-security.php` | 337 assertions, dont 50 inspiration ; compteur, INT, repos, notes, LIVE/CLOSED et rechargement |
| `php tools/test-feature-training.php` | 59 assertions ; nouvel artificier et ancien artificier sans choix, sauvegarde/rechargement, langues préservées, autres classes/multiclassage |
| `php tools/test-import-class-features.php` | 26 assertions ; catalogue valide, import et idempotence dans tables temporaires uniquement |
| `npm test -- --watch=false` | 69 tests, 11 fichiers réussis ; compteur et rattrapage couverts |
| `npm run build` | Réussi ; avertissement préexistant player-portal.scss 11,47 kB / budget 10 kB |
| `php bin/console lint:container` | Réussi |
| `git diff --check` | Réussi |

Les tests SQL utilisent des tables temporaires et rollback, pas les personnages
réels. Aucun import réel, aucune migration appliquée, aucun commit/push.

### Validation manuelle avant livraison

1. Après activation autorisée de la migration, ouvrir le portail en LIVE :
   Bricolages magiques dans Ressources, compteur correspondant à INT (minimum 1).
2. Tester − et +, saisir des notes, attendre la sauvegarde puis actualiser.
3. Faire un repos : compteur consommé et notes restent conservés. Fermer la
   session : lecture possible, édition impossible.
4. Dans la fiche MJ de Bloup, compléter deux compétences et un outil ; recharger
   et vérifier les compétences Acolyte, les langues et les maîtrises automatiques.
5. Créer un nouvel artificier : vérifier les deux groupes dès le builder.
6. Vérifier visuellement tablette/mobile (pas de validation navigateur réelle
   effectuée dans cette mission).

## Cause et périmètre

Le catalogue de maîtrises de classe et la liste autorisée par
`ClassProficiencyImportPlanner` ne couvraient que les douze classes PHB.
L'artificier existait dans le catalogue des classes et capacités, mais aucune
règle de maîtrise ne lui était associée. Le builder ne proposait donc aucun
choix ; le résolveur et le profil ne pouvaient afficher aucun bénéfice de classe.
Les compétences d'Acolyte étaient correctement résolues séparément.

Lecture SQL locale : Bloup, personnage 35, est le seul personnage artificier
identifié ; zéro règle pour sa classe. Les douze classes PHB disposent de leurs
69 règles. Cela ne certifie pas une autre base ni les classes personnalisées.

## Correction

Huit règles Tasha 2020, compatibles D&D 2014, sont ajoutées :

| Contexte | Automatique | Choix |
|---|---|---|
| Classe initiale | Sauvegardes CON/INT ; armures légères/intermédiaires/boucliers ; armes courantes ; outils de voleur/bricoleur | Deux compétences parmi Arcanes, Histoire, Investigation, Médecine, Nature, Perception, Escamotage ; un outil d'artisan |
| Multiclassage | Armures légères/intermédiaires/boucliers ; outils de voleur/bricoleur | Aucun |

L'importeur accepte une catégorie `tools-choice` séparée des outils automatiques.
Les nouvelles clés commencent par `tce-2014-artificer-`. Les 69 clés PHB restent
inchangées ; les protections des références privées sont conservées. Aucun
calcul de niveau ni résolveur n'a été modifié pour ce correctif.

Les 17 outils d'artisan sont proposés, y compris les outils de bricoleur déjà
maîtrisés : pas de règle de remplacement supplémentaire inventée. Les armes à
feu optionnelles ne sont pas accordées automatiquement.

Le builder Angular consomme déjà les options par règle et leurs identifiants.
La persistance des choix et le contrat `character.training` sont réutilisés.

## Import et réparation, à effectuer après validation

Aucune migration nécessaire. Aucun import réel exécuté pendant le correctif.
Simulation vérifiée : **8 créations, 69 inchangées**, aucune mise à jour.

```sh
docker compose exec -T backend php bin/console app:dnd:import-class-proficiencies --dry-run
# Import réel, uniquement après validation :
docker compose exec -T backend php bin/console app:dnd:import-class-proficiencies
```

Après import, les maîtrises automatiques sont recalculées au prochain GET, sans
réécriture du personnage. Recharger les portails : l'import du catalogue ne
modifie pas les révisions des états de session.

Pour Bloup, le MJ utilise `GET /api/characters/35/training/options`, puis
`PATCH /api/characters/35/training/choices`. Envoyer uniquement les deux règles
manquantes dans `proficiencyChoices`, chaque entrée contenant `ruleId` et
`proficiencyIds` : deux identifiants de compétences pour la règle skills et un
identifiant d'outil pour tools-choice. Les identifiants réels doivent provenir
du GET après import. Aucun choix ne doit être fait arbitrairement.

Cet endpoint existant conserve les sources omises et les choix historiques,
verrouille le personnage et invalide les révisions après changement. Ne pas
recréer Bloup et ne pas remplacer ses choix de langues/historique. Aucun nouveau
formulaire de réparation n'est ajouté dans ce lot.

## Bricolages magiques

Le composant existant est projeté dans `CharacterResources`, après les autres
ressources, au lieu d'être placé au-dessus des PV. Le message « aucune ressource »
est masqué lorsqu'il contient cette capacité. Une feuille SCSS dédiée reprend
fond sombre, bordures dorées, typographie et focus ; champ compact redimensionnable,
bouton lisible et pleine largeur sur mobile. La vue hors LIVE reste consultative.
Le champ sauvegardé, les contrôles LIVE et le PATCH existant sont inchangés.

## Vérifications

- Import de classes : 169 assertions, dont idempotence et stabilité des clés PHB.
- Formation/cycle de vie : 58 assertions, création artificier/Acolyte dans toutes
  les catégories, conservation des langues, rechargement ORM, multiclassage et
  scénarios magicien/Chantelame, roublard/Assassin existants. Tables temporaires
  et rollback ; aucune modification des personnages réels.
- Formation générale : 57 assertions.
- Angular : 67 tests réussis, dont sauvegarde LIVE et consultation des notes.
- Build Angular réussi ; avertissement de budget préexistant sur
  `player-portal.scss` (11,47 kB pour 10 kB).
- `lint:container` réussi.

Validation visuelle tablette/mobile restant manuelle : ouvrir Bloup en LIVE,
vérifier Ressources, modifier/enregistrer/recharger les notes, fermer la session
et vérifier leur consultation sans saisie. Après import validé et rattrapage des
choix, vérifier toutes les catégories dans `character.training`, puis la fiche.
