# Référentiel D&D unifié — 18 octobre 2026

Branche `feature/spell-catalog`. Les changements des missions précédentes sont
conservés. Aucune migration, donnée de démonstration permanente, import, opération
de production, commit ou push dans cette mission.

## Navigation et contextes

- Une entrée « Référentiel D&D » dans la navigation MJ, vers `/dnd/reference`.
- `/custom-content` redirige vers cette route. Les composants génériques sont
  conservés sur disque pour leurs dépendances/tests, mais ne sont plus routés.
- « Mes créations » affiche les CUSTOM du compte ; les choix de relations gardent
  les OFFICIAL et CUSTOM accessibles, indépendamment du filtre de la collection.
- « Officiel » (`/dnd/reference/official`) affiche les OFFICIAL. Un MJ utilise
  les lectures personnelles autorisées en consultation ; un administrateur utilise
  la façade officielle protégée par ROLE_REFERENCE_ADMIN. Modifier le contexte
  ou falsifier le rôle dans Angular ne donne aucun droit supplémentaire à Symfony.
- Les dix formulaires historiques et leur style sombre/doré restent utilisés.
  Les suppressions personnelles de capacités et ressources sont conservées via
  les API CUSTOM existantes. Les limitations de suppression documentées lors de
  la migration des autres catégories ne sont pas élargies.
- Les attributions CUSTOM sur une cible OFFICIAL restent des objets personnels
  séparés : leur administration ne modifie pas la cible officielle.

## Relations et scénario Clerc des Brumes

`ReferencePicker` implémente ControlValueAccessor : recherche par nom (accents
normalisés), libellé avec origine, maintien de la sélection lors du filtrage,
valeur numérique interne, relation facultative effaçable et état désactivé.
Il refuse aussi une valeur absente des options ; Symfony reste la validation
autoritaire contre les références privées ou incompatibles.

Intégration : classe parente de sous-classe, race parente, ressource d'une capacité,
capacité/source d'attribution, source d'une attribution de ressource, sous-classe,
classe et ressource d'une attribution de sort. Les options viennent des API.
Les listes multiples de classes de sorts et de maîtrises/langues des historiques
gardent leur sélection multiple, avec recherche et origine affichée. Aucune
relation inexistante (par exemple classe directement attachée à une progression)
n'est inventée dans le modèle.

Le test Angular crée « Clerc des Brumes » en choisissant « Clerc — OFFICIAL » par
nom, sans saisir de slug ou d'identifiant de relation. Le slug est généré à la
sauvegarde s'il est vide. Après rechargement HTTP simulé, la classe parente est
restaurée et immuable, conformément au contrat existant.
Le test Symfony utilise ces noms uniquement dans des tables temporaires : FK
parent, origine CUSTOM, propriétaire et refus d'un parent privé tiers vérifiés.

## Fichiers concernés

- Navigation : `frontend/src/app/app.routes.ts`, `features/mj-layout/*` et
  `features/dnd-reference/feature-manager/*`.
- Contextes/transports : `core/services/reference-editor-context.ts`,
  `custom-content-api.service.ts`, `reference-feature-editor-api.service.ts`.
- Nouveau composant et test : `features/dnd-reference/components/reference-picker`.
- Gestionnaires spécialisés des huit catégories historiques : filtrage d'origine,
  lecture seule et sélecteurs. Nouveau test `subclass-manager.spec.ts`.
- Éditeurs existants `custom-content/background-editor`, `spell-editor` et
  `spell-grants`, modèles de leurs options et tests associés.
- Symfony : `CustomReferenceBackgroundService` et `SpellSubclassGrants` exposent
  aussi l'origine des choix. Aucun changement des permissions ou règles de jeu.
- `backend/tools/test-reference-race-editor.php` : scénario parent OFFICIAL et
  refus de classe parente privée ; uniquement données isolées.

Les chemins frontend abrégés ci-dessus sont relatifs à `frontend/src/app`.

## Validations

- Angular : **49 tests OK**, neuf fichiers (navigation des dix onglets dans trois
  contextes, sélecteur, sous-classe, races, redirection/contenu personnel,
  historiques, sorts/attributions et grimoire/révisions).
- Test de navigation renforcé et relancé : trois tests OK, filtrage réel des
  origines et formulaire OFFICIAL désactivé pour un MJ.
- `test-reference-race-editor.php` : **141 assertions OK**.
- `test-spell-catalogue-crud.php` : **277 assertions OK**.
- `test-custom-reference-backgrounds.php` : **44 assertions OK**.
- `test-reference-selection-visibility.php` : **178 assertions OK**, incluant
  création, montée de niveau, tokens et progressions.
- Vérifications relancées le 19 octobre 2026 : les 49 tests Angular et les quatre
  suites PHP ci-dessus sont réussis. Les refus HTTP journalisés sont les cas
  négatifs attendus ; les fixtures PostgreSQL restent temporaires.
- Build Angular, lint du conteneur Symfony et `git diff --check` : **OK**.
  Warning préexistant de `player-portal.scss` : 11,51 kB pour un budget de 10 kB.
- `doctrine:schema:validate` : mapping **OK**, schéma **non synchronisé**.
  La lecture `doctrine:schema:update --dump-sql` confirme uniquement la table
  `character_spell_revision`, les colonnes `revision_id`/`retirement_id` des
  acquisitions et leurs index/FK. Aucune commande de mise à jour exécutée.
  Aucun test ne crée Clerc des Brumes dans les données applicatives.

Cette reprise ne modifie que ce bilan : aucune correction de code nécessaire
après les contrôles ciblés. Tous les changements locaux antérieurs, dont les
fichiers du chantier d'import, sont conservés. Aucun import, migration, commit,
push ou accès à la production.

## Limites et contrôle manuel

Les tests Angular vérifient le DOM et les requêtes avec HttpTestingController ;
les tests PHP vérifient séparément les mutations réelles sur PostgreSQL temporaire.
Pas de parcours navigateur connecté avec des données permanentes : vérifier
visuellement le clavier, le responsive et le passage de contexte avec un compte
MJ et un compte administrateur avant publication.

L'écart Doctrine préexistant de `Version20261017140000` (révisions d'acquisitions)
reste distinct de cette mission et non appliqué. Aucun modèle persistant nouveau
n'est nécessaire aux sélecteurs. Le warning SCSS préexistant du portail joueur
n'est pas une régression du référentiel. L'import des sorts reste hors périmètre.
