# Catalogue et gestion des sorts D&D 2014

> Mise à jour du 18 octobre 2026 : la mission de migration du référentiel autorise
> désormais le même gestionnaire Angular en contextes MJ et administrateur,
> avec façades Symfony distinctes et ROLE_REFERENCE_ADMIN pour OFFICIAL.
> Cette décision remplace la restriction historique à Twig décrite ci-dessous.
> Voir [le bilan de migration](reference-interface-migration.md) pour les contrats,
> les 46 tests Angular, les validations backend, la migration de révisions laissée
> en attente et les prérequis de l'import des sorts. Aucun import exécuté.

Date : 16 octobre 2026. Statut : catalogue, listes de classes, attributions de
sous-classes et résolution des accès de référentiel en lecture seule livrés.
Administration Angular CUSTOM / Symfony OFFICIAL préservée. Les migrations des
jalons précédents ont été appliquées uniquement au développement après contrôle ;
aucune migration pour le resolver. Catalogue vide, aucun import.
Bilan du dernier jalon en section 12.

**Architecture définitive des interfaces : Angular gère les créations CUSTOM du
compte et consulte les OFFICIAL ; Symfony gère exclusivement le référentiel
OFFICIAL et exige ROLE_REFERENCE_ADMIN.** Les deux parcours offrent l'édition de
toutes les propriétés et partagent la logique métier. Cette séparation devra
s'étendre aux autres référentiels ultérieurement ; seuls les sorts sont concernés
par ce chantier. Aucun droit sur les CUSTOM d'autrui, même pour un administrateur.
Priorité : livrer un catalogue indépendant, puis des accès, puis les choix des
personnages, puis le lancement. Sauf indication dans le bilan du socle, les types
et fonctionnalités ci-dessous restent des propositions pour les jalons suivants.

## 1. Point de départ vérifié

Après `git fetch origin`, `HEAD`, `main`, `origin/main` et
`origin/feature/spell-catalog` pointent tous sur
`f3f9bfe4b7e96335af2c8a12c504c5aa1c52cf34` (`feat: add custom backgrounds editor`).
Le workspace était propre. La branche `feature/spell-catalog` existait déjà et
était active : elle a été conservée, pas recréée. Aucun commit, push ou déploiement.

L'audit porte sur les sources locales, pas sur un inventaire des données de
production. Aucun import exécuté. La migration a ensuite été vérifiée et appliquée
au développement (section 9). Les tests
backend choisis travaillent sur tables et séquences temporaires avec rollback.

### Inventaire des points d'intégration inspectés

Les chemins sont relatifs à la racine du dépôt. Les ensembles ci-dessous
regroupent les lectures ciblées et les recherches d'usages effectuées.

| Domaine | Fichiers | Constat |
|---|---|---|
| Référence et propriété | `backend/src/Entity/CharacterBackground.php`, `backend/src/Service/ReferenceVisibility.php`, `backend/config/packages/security.yaml` | `ReferenceOrigin`, propriétaire, visibilité OFFICIAL + CUSTOM du MJ ; routes privées sous ROLE_USER. Les unions de types de visibilité accueillent désormais les sorts. |
| CRUD modèle | `backend/src/Controller/CustomReferenceBackgroundController.php`, `backend/src/Service/CustomReferenceBackgroundService.php`, `backend/docs/custom-backgrounds.md`, `backend/docs/custom-reference-write-design.md` | Liste/détail, création/PATCH/suppression ; whitelist, transaction, conflits 409, écritures OFFICIAL/tiers refusées ; usage verrouillant les mécaniques. Le service actuel utilise DBAL. |
| Interface CUSTOM | `frontend/src/app/features/custom-content/custom-content.ts`, `frontend/src/app/core/services/custom-content-api.service.ts`, `frontend/package.json` | Onglet Sorts ajouté avec éditeur complet et listes de classes ; OFFICIAL en consultation uniquement. |
| Emplacements | `backend/src/Service/CharacterSpellSlotCalculator.php`, `backend/src/Service/CharacterSpellSlotStateService.php`, recherches dans `backend/src/Entity/CharacterClass.php` et `CharacterSubclass.php` | Progressions de classe/sous-classe et multiclassage déjà présents ; Pact exclu du calcul commun ; maxima temporaires via `flexibleCastingBonus`. |
| Profil et cycle de vie | `backend/src/Service/CharacterProfileSerializer.php`, `CharacterSessionStateSerializer.php`, `CharacterSessionStateFactory.php`, `CharacterSessionStateSynchronizer.php`, `CharacterRestService.php` | Ressources `spell-slot-N`, valeurs de progression explicites, maxima contextualisés par session, synchronisation/repos existants. |
| Actions magiques | `backend/src/Controller/CharacterActionController.php`, `backend/src/Service/CharacterActionResolver.php`, `backend/src/Service/CharacterAction/AbstractHitPointBoostActionService.php`, `CharacterAidActionService.php`, `CharacterHeroesFeastActionService.php` | Aide/Festin déjà exécutables ; préparation dans `state.characterActions.prepared`, consommation de slots et effets actifs. Flexible Casting et Récupération arcanique ont leurs endpoints. |
| Création/progression | Recherches dans `backend/src/Service/CharacterBuilderService.php`, `CharacterLevelUpOptionsService.php`, `CharacterLevelUpRequestResolver.php`, `frontend/src/app/core/services/character-builder-api.service.ts` | Pas de sélection structurée de sorts trouvée ; conserver les workflows de création et level-up existants. |
| Portail | `frontend/src/app/features/player-portal/player-portal.html`, `frontend/src/app/core/models/character.model.ts` | Onglet Sorts explicitement placeholder ; ressources magiques représentées comme ressources, pas comme catalogue ni grimoire. |
| Imports | `backend/src/Command/ImportBackgroundsCommand.php`, `backend/src/Service/Catalogue/BackgroundImportPlanner.php`, `backend/data/reference/README.md`, `backend/src/Service/DndReferenceReadiness.php` | Validation préalable, plan, dry-run, update explicite, transactions et protection des identités CUSTOM ; gate de disponibilité existant, sans catalogue de sorts. |
| Données | Inventaire de `backend/data/reference`, recherches des métadonnées dans les JSON/Markdown | Dons, races, capacités, historiques, maîtrises et langues ; aucun fichier de catalogue de sorts trouvé. |
| Tests | `backend/tools/test-spell-slot-synchronization.php`, `test-ftd-resource-scenarios.php`, `test-custom-reference-backgrounds.php`, tests Angular CUSTOM existants | Harness PostgreSQL isolé et Vitest réutilisables ; résultats du présent audit en section 8. |
| Administration Symfony | `backend/src/Controller/AdminReferenceCatalogueController.php`, `backend/src/Service/AdminReferenceCatalogue.php` | Écrans OFFICIAL actuellement éditoriaux, recherche/filtres/pagination, CSRF, PRG 303 et save-next ; ne couvrent pas la création ni toutes les relations. |
| Objets | `backend/src/Entity/MagicItem.php`, `backend/src/Entity/CharacterMagicItem.php` | Définition liée à la campagne, attribution avec quantité/charges/équipement ; aucun contenu de grimoire structuré. Une pile d'objets n'identifie pas les exemplaires aux contenus différents. |

### Ce qui manque

Au début de l'audit, aucune entité dédiée aux sorts, commande d'import de sorts, API catalogue de
sorts, liste de sorts par classe, grimoire ou sélection connue/préparée générique
n'a été trouvée dans les recherches `backend/src`, `backend/data` et
`frontend/src`. Les mentions de sorts dans les descriptions de capacités ne sont
pas un catalogue exploitable. La préparation d'actions existante n'est pas un
modèle général de préparation des sorts. Aucun suivi générique de concentration
n'a été identifié dans les modèles examinés.
Le socle décrit en section 8 ajoute désormais l'entité de catalogue et son enum.

## 2. Données OFFICIAL : disponibilité et provenance

Le README local décrit un catalogue de dons, avec métadonnées collectées et
résumés éditoriaux ; il ne fournit pas de licence pour un catalogue de sorts.
Des capacités référencent notamment des URL 5etools et des ouvrages. Ces liens
ne constituent pas une preuve de droits pour importer leurs textes de sorts.
Aucun corpus local de sorts accompagné d'une licence/autorisation n'a été trouvé.
Les droits d'un futur corpus restent donc **non établis dans cet audit** ; aucune
source externe n'a été téléchargée ni déclarée autorisée.

Avant tout import réel, sélectionner un corpus précis puis vérifier auprès de
sa source les droits du texte, de la traduction et de la redistribution. Conserver
un manifeste versionné : édition, langue, version/date du corpus, URL d'origine,
licence/autorisation et attribution, empreinte du fichier et transformations.
Ne pas extrapoler une autorisation SRD à PHB/XGtE/TCoE ni à une traduction tierce.
Une référence bibliographique et l'origine OFFICIAL ne remplacent pas ce manifeste.
Les tests peuvent utiliser des sorts fictifs explicitement CUSTOM, sans reproduire
de descriptions officielles. Le catalogue vide et l'éditeur CUSTOM peuvent être
livrés indépendamment de l'acquisition du corpus OFFICIAL.

## 3. Phase 1 : catalogue et édition CUSTOM

### Modèle proposé

`SpellDefinition` / table `spell_definition`, indépendante de `Character` :

| Champs | Contrat proposé |
|---|---|
| `id`, `slug`, `name`, `originalName?` | ID technique ; slug ASCII stable (100 caractères), globalement unique comme les historiques ; nom d'affichage français (150 caractères), original optionnel. Ne pas inventer une traduction manquante. |
| `level`, `school` | Entier 0–9 ; enum des huit écoles avec codes stables et labels traduits. |
| `castingTime`, `range`, `duration` | Textes requis : garder les conditions et exceptions, sans parser une durée ou une portée en moteur de jeu. |
| `verbal`, `somatic`, `material` | Booléens explicites. |
| `materialDescription?`, `materialCostCp?`, `materialConsumed` | Description libre ; coût en pièces de cuivre, entier positif ou nul, nullable pour inconnu/non applicable ; consommation explicite. Pour coûts multiples/conditionnels, texte conservé et coût structuré nullable, sans approximation. |
| `concentration`, `ritual` | Booléens décrivant le sort, pas son état courant ni une permission de lancement. |
| `description`, `higherLevels?` | Effets complets et texte de surincantation séparés ; aucune exécution automatique. |
| `edition`, `rulesVariant?` | `2014` explicite, variante maison distincte ; OFFICIAL n'implique pas à lui seul une édition. |
| `sourceCode?`, `sourceReference?`, `sourceUrl?`, `importKey?` | Ouvrage principal et localisation/provenance ; importKey stable indépendant du nom traduit. Métadonnées d'import protégées côté API. |
| `origin`, `owner` | Réutiliser `ReferenceOrigin` ; OFFICIAL sans owner, CUSTOM avec owner obligatoire. Pas de booléen custom parallèle. |

Contraintes SQL additives : UNIQUE slug, unicité de l'identité d'import officielle
par édition, CHECK niveau/coût/couple origin-owner, FK owner RESTRICT, index owner
et index adaptés aux filtres niveau/école/source. Validation PHP équivalente.
Composantes : refuser coût/consommation sans M ; pour M, demander une description.
Valider les champs fusionnés d'un PATCH pour éviter les états transitoires.
Ne jamais déduire les mécaniques d'un nom ou d'un texte.

Pour un même sort réédité : ne pas fusionner automatiquement des versions aux
effets différents. Phase 1 peut conserver une source principale ; avant un corpus
multi-ouvrages, arbitrer des publications associées (`SpellPublication`) et la
version canonique. Un slug global évite une refonte des conventions, mais deux MJ
devront choisir des slugs différents ; les collisions ne doivent révéler aucun
contenu tiers. Le socle retient cette convention globale ; un passage à une unicité
par propriétaire demanderait une migration explicite, pas un changement silencieux.

### API et permissions proposées

Suivre `/reference/custom/backgrounds` avec `/reference/custom/spells` :
GET liste, GET `/{id}`, POST, PATCH `/{id}`, DELETE `/{id}` ; préfixe Angular `/api`.
Réponses liste `{spells, total, page, pageSize}`, détail/mutation `{spell}`,
création 201, suppression 204, validation 400, inaccessible 404, écriture OFFICIAL
via cette API interdite (403), même pour un administrateur ;
conflit de slug ou usage 409. Contrat nouveau à tester, sans modifier celui des
historiques. Exposer `editable`, `deletable`, `mechanicsLocked` pour l'interface.

Filtrer en SQL **avant pagination**, y compris le périmètre propriétaire :
`q`, `level`, `school`, `source`, `origin`, `classId`, tri stable nom puis ID,
taille bornée. Les totaux et options de filtre ne doivent pas révéler les CUSTOM
d'autrui. `classId` est désormais opérationnel dans les deux interfaces, via la
relation de liste décrite en phase 2. Les attributions de sous-classes ne sont pas
incluses dans ce filtre de liste de classe.

Le serveur fixe origin/owner à la création et les refuse dans les payloads.
Réutiliser l'authentification MJ actuelle (ROLE_USER), la whitelist, les erreurs et
transactions du CRUD existant. Dans un contexte personnage, le propriétaire est
celui de la campagne, jamais le visiteur ou le joueur. Étendre les types autorisés
de `ReferenceVisibility` au moment de l'intégration ; une référence OFFICIAL ne
doit pas dépendre d'un CUSTOM et une dépendance CUSTOM doit appartenir au même MJ.
Ne pas exposer le catalogue CUSTOM dans la route publique `/dnd/reference`.

Supprimer seulement les CUSTOM sans dépendances : règles de liste/attribution,
choix personnages, grimoire, préparation ou usage historique futur. FK RESTRICT
et garde transactionnel doivent couvrir la concurrence, pas seulement un bouton
désactivé. Dès qu'utilisé, proposer le verrouillage du slug et des mécaniques ;
laisser nom/texte éditables, politique à confirmer. Aucun recalcul de state lors
d'une sauvegarde de définition. Ne pas stocker de références historiques uniquement
dans du JSON si cela contourne les gardes de suppression.

### Import proposé

`SpellCatalogueValidator`, `SpellImportPlanner`, `ImportSpellsCommand`
(`app:dnd:import-spells [path] --dry-run --update-existing`), dans les conventions
de `Service/Catalogue`. Valider tout le fichier et le manifeste avant toute écriture.
Refuser doublons d'importKey/slug, édition incompatible, champs inconnus et identité
ambiguë. Même nom ne signifie pas même sort ; ne pas rapprocher par nom seul.

Plan déterministe create/unchanged/keep/update/conflict, différences lisibles.
Par défaut conserver les OFFICIAL existants ; mise à jour explicite uniquement,
IDs stables, aucune suppression. Collision avec CUSTOM/owner ou incohérence
d'identité : échec sans écriture, même avec `--update-existing`. Prévoir le gel
des mécaniques utilisées dès les phases suivantes. Transaction et gestion des
collisions concurrentes ; dry-run sans mutation d'entités gérées ni flush.
Un second import identique ne change rien. Aucun import automatique au déploiement
ou à la migration. Ne pas rendre le gate existant bloquant sur les sorts tant que
le périmètre OFFICIAL livré n'est pas défini.

### Découpage livrable

1. Entité/enum + migration additive + tests de contraintes sur tables temporaires
   (socle réalisé, voir section 8).
2. Service/contrôleur + tests HTTP d'isolation et conflits ; catalogue vide valide.
3. Onglet Sorts et éditeur Angular dans `/custom-content`, recherche/filtres livrés,
   consultation OFFICIAL, erreurs serveur, chargement et accessibilité.
4. Validateur/planner/commande testés sur fixtures ; corpus réel uniquement après
   vérification de provenance. Relation de liste simple et filtre classe avant de
   déclarer le catalogue complet.

### Administration Symfony : exigence de la reprise

L'écran dédié `/admin/reference/spells` existe, avec navigation Symfony/Twig,
recherche, filtres, détail, création, édition des champs structurés et gestion des
relations de classes. Les attributions de sous-classes ont leur éditeur distinct
(section 11). Réutiliser CSRF,
POST/Redirect/GET, messages d'erreur, conservation du contexte GET et navigation
du catalogue actuel. **Ne pas limiter les sorts à `updateEditorial()`** : cette
limitation existante n'est pas le périmètre fonctionnel demandé.

Le backoffice global OFFICIAL et l'espace personnel CUSTOM `/custom-content`
ont des droits distincts et partagent les services de validation et
mutation. ROLE_USER identifie le MJ ; le rôle
ROLE_REFERENCE_ADMIN protège désormais tout le parcours Symfony des sorts.
Aucune création, lecture ou édition CUSTOM dans ces écrans, même pour leur
propriétaire ; Angular est le parcours personnel. La création OFFICIAL
sera manuelle ou issue d'un corpus vérifié, jamais déduite du nom d'un sort.

Critères de livraison UI : aucune opération courante de création/modification/
relation ne nécessite du SQL ; références invisibles rejetées ; champs et liens
contrôlés côté serveur ; suppression utilisée refusée ; relations conservant leurs
identités ; tests HTTP/CSRF/propriétaire et parcours de formulaire. Les écrans
Symfony font partie de la phase 1, les relations avancées de la phase 2.

## 4. Phase 2 : accès et attributions

Deux notions séparées, sans remplacer les capacités existantes :

- `SpellClassListEntry(spell, characterClass)` pour la liste de base, unique par
  paire, implémentée. Sa provenance et son propriétaire sont ceux du sort, sans
  duplication de colonnes origin/owner. Voir matrice de relations en section 10.
- `SpellSubclassGrant`, implémenté pour la source sous-classe uniquement : sort,
  vraie sous-classe source, classe de lancement si applicable, mode (`add-to-list`,
  `grant-known`, `grant-always-prepared`, `grant-cast`), niveau et paramètres
  explicites. Provenance propre héritée de la source, indépendante du sort cible.
  Le modèle général proposé `SpellAccessRule` pour les autres sources reste futur.

Une source pointe vers une vraie relation (sous-classe, capacité existante, don ou
objet), pas un identifiant polymorphe libre. Ajouter ces sources par jalons avec
FK et exactement une source, sans construire toutes les tables d'avance.
Réutiliser les capacités pour leur activation, notamment les valeurs de progression
explicitement fournies ; ne pas comparer un seuil de progression à un niveau.
Un ajout à une liste ne prépare ni n'apprend le sort. Un accès par objet ne crée
pas automatiquement une entrée de grimoire ou une dépense de slot.

`CharacterSpellAccessResolver` reçoit désormais Character et les valeurs de
progression explicites nécessaires aux ressources ; il contrôle les dépendances
visibles et retourne les accès **avec leurs provenances** (section 12).
Ne pas écraser deux accès au même sort : leurs caractéristiques, ressources ou
restrictions peuvent différer. Les permissions de lancement gratuit/avec slot,
les usages par repos et la caractéristique de lancement doivent être explicites ;
les usages limités réutilisent `TrackableResourceDefinition` et son resolver.
Tests : classe/sous-classe, sources multiples, seuils, origine/owner, dépendance
invisible, liste ajoutée sans préparation, sort automatiquement préparé distinct.

## 5. Phase 3 : sorts du personnage

Les choix persistants appartiennent à Character ; la préparation courante proposée
appartient au contexte de session. Ce dernier choix doit être confirmé avant DDL.
Proposition normalisée :

- `CharacterSpellAcquisition` : personnage, sort, contexte de classe/source,
  mode d'acquisition (apprentissage, copie, attribution), niveau et provenance.
  Les sorts connus et inscrits au grimoire sont des propriétés distinctes : un
  simple enum exclusif known/prepared/book ne peut pas les représenter correctement.
- `CharacterSpellPreparation` : session state, acquisition/contexte et sort,
  avec unicité dans ce contexte. Les toujours-préparés sont résolus depuis leurs
  règles, ne consomment pas une sélection manuelle et ne sont pas effaçables par elle.

Accessible = résultat calculé ; appris = acquisition ; connu = mode accordant la
connaissance ; grimoire = inscription ; préparé = choix courant ; toujours préparé
et accordé = résultats/provenances conservés séparément. Ne pas copier toute une
liste de clerc/druide comme acquisitions individuelles.

Prévoir une politique de lancement par classe/sous-classe et édition, distincte
de `SpellcastingProgressionType` qui ne décrit que les slots. Matrice à documenter
et vérifier dans les règles autorisées avant implémentation : magicien (grimoire
et préparation), clerc/druide/paladin (préparation depuis liste), barde/ensorceleur/
rôdeur/occultiste (choix connus), artificier et sous-classes lanceuses. Compter
séparément sorts mineurs, sorts de niveau, apprentissages/remplacements et
attributions gratuites. Ne pas coder des quotas non vérifiés dans cet audit.

En multiclassage, résoudre accès/quota/niveau maximal des choix par classe ; le
plus haut slot commun n'autorise pas à apprendre un sort de ce niveau. Conserver
la caractéristique et la source de chaque voie de lancement du même sort.
Brancher les options/validation dans builder et level-up existants, avec choix
atomiques et rollback. Les repos peuvent autoriser une nouvelle préparation selon
la politique, jamais effacer les acquisitions ni les progressions narratives.
Rendre les mutations serveur autoritaires et renvoyer le profil résolu.

### Grimoire personnel et livres physiques : deux concepts

Le grimoire personnel décrit les inscriptions reconnues pour le personnage et ses
choix d'apprentissage/préparation. Un livre physique est une instance d'objet de
campagne qui peut exister avant d'être possédée : contenu découvert, propriétaire
ou détenteur courant, emplacement et état de découverte/consultation. Ni sa
possession ni sa consultation n'ajoutent automatiquement d'acquisitions ou de
préparations au personnage.

Prévoir ultérieurement `Spellbook` (exemplaire, campagne, détenteur/emplacement)
et `SpellbookEntry` (livre, sort, notes éventuelles, unicité livre+sort), avec FK
RESTRICT vers SpellDefinition. Une définition d'objet peut fournir un contenu
initial, mais les inscriptions appartiennent à l'exemplaire. Ne pas utiliser
`CharacterMagicItem.quantity` pour fusionner des livres aux contenus différents,
ni imposer qu'un grimoire ordinaire soit un objet magique. L'intégration exacte à
l'inventaire devra être décidée après son audit complet ; aucune nouvelle table
de livres n'est nécessaire pour le socle catalogue.

Une copie autorisée référence le livre source et le contexte de classe, valide
accès au livre, éligibilité du sort et coûts/temps selon la politique vérifiée,
puis crée une inscription/acquisition explicite dans une transaction. Conserver
la provenance de copie sans dépendre du détenteur actuel du livre. Le lien entre
inscriptions personnelles et exemplaires utilisables permettra de traiter perte,
transfert et copie de sauvegarde sans transformer la perte d'un livre en oubli
automatique de tout apprentissage. Les effets sur la préparation restent à
arbitrer selon les règles et à tester.

Tests futurs : découverte sans détenteur, transfert entre personnages autorisés,
isolation des campagnes, contenu visible seulement après accès, possession sans
connaissance/préparation, refus de copie inéligible, copie idempotente, coûts
atomiques et conservation d'une acquisition après transfert du livre source.

Migration des personnages existants : aucune sélection inventée. Afficher un état
« à compléter » explicite, avec reprise MJ/joueur autorisée, sans reset de slots,
HP, tokens, consommations ou progressions. L'absence de contexte session ne doit
jamais provoquer de lookup arbitraire. Tester deux sessions du même personnage.

## 6. Phase 4 : utilisation en jeu

Service de lancement recevant explicitement session, sort, voie d'accès, niveau,
mode (normal/rituel) et pool choisi. Backend vérifie accès, préparation si requise,
niveau autorisé, slots/ressources, statut de session et revision ; transaction et
verrouillage inspirés de `PlayerCharacterAccess::lockForMutation` et du contrôleur
d'actions. Les endpoints dédiés renvoient state, profil résolu et revision.

Réutiliser `spell-slot-N`, `CharacterSpellSlotStateService` et les ressources Pact
existantes : pas de second compteur. Choisir explicitement le pool lorsque plusieurs
voies sont possibles. Un sort mineur ne consomme pas de slot ; le mode rituel exige
une permission de classe/capacité, pas seulement `spell.ritual`. Lancer à un niveau
supérieur ne signifie pas automatiser le texte des effets.

Concentration : état explicite lié à la session avec référence relationnelle au
sort/source et remplacement atomique ; arbitrer son cycle de fin et les contrôles
manuels avant implémentation. Ne pas transformer `CharacterActiveEffect` en moteur
universel d'effets. Métamagie : préparer l'extension de la commande et réutiliser
les points existants, sans implémenter les variantes maintenant.

Compatibilité Aide/Festin : garder `state.characterActions.prepared`, endpoints
et handlers tant qu'un pont testé vers les vrais sorts n'est pas livré. Ne pas
interpréter rétroactivement ce tableau comme une liste de SpellDefinition. Prévoir
un mapping explicite et une seule consommation par lancement, sans double
application des effets. Conserver Flexible Casting et Récupération arcanique.

Tests : mineur/rituel/slot/Pact, surincantation, absence de préparation, slot épuisé,
deux requêtes concurrentes (409), rollback multi-cibles, concentration, repos,
visibilité et non-régression des actions existantes. UI : états de chargement,
erreurs 409/422, rechargement du profil, absence de recalcul métier en Angular.

## 7. Arbitrages et prochaine séance

À décider avant les jalons concernés : corpus et droits/traduction ; slug global
ou par propriétaire ; source principale ou publications multiples ; politique
d'édition d'un sort utilisé ; préparation par session ou partagée ; portée des
variantes maison ; modalités du pont Aide/Festin et de la concentration.

Les attributions de sous-classes sont configurables et testées (section 11) et
leur résolution de référentiel en lecture seule est livrée (section 12).
**Prochaine étape proposée, à cadrer avant implémentation :** définir les politiques
de choix et de lancement par classe/sous-classe : caractéristique, niveau maximal
de sort éligible, quotas et portée de la préparation. Arbitrer également la classe
de lancement absente du personnage, les restrictions descriptives et la disponibilité
des ressources en session. Aucun apprentissage, préparation ou lancement n’est
implémenté. Ne pas convertir les listes ou règles en acquisitions.

## 8. Vérifications du socle initial (historique)

- `git fetch origin`, statut et comparaison des quatre références : OK, branche
  déjà existante à jour ; aucun changement local initial.
- `docker compose exec -T backend php tools/test-custom-reference-backgrounds.php` :
  **OK, 44 assertions**, tables temporaires. Le log d'authentification refusée
  fait partie des cas négatifs du test terminé avec code 0.
- `docker compose exec -T backend php tools/test-spell-slot-synchronization.php` :
  **ÉCHEC**, code 1 dans le scénario FTD inclus, assertion
  `FTD resources: rejects unexpected identities, links, origins or mechanics`.
  Trace : `test-ftd-resource-scenarios.php:109`, appel ligne 131, inclus par
  `test-spell-slot-synchronization.php:225`. Les sous-scénarios Fougue, Lay on
  Hands, domaines de clerc et Gem Flight ont affiché OK auparavant. Cela ne
  valide pas toute la suite slots. Échec observé avant tout changement de code ;
  cause non diagnostiquée, à traiter séparément, sans correction périphérique ici.
- Tests Angular ciblés CUSTOM : **20 tests OK**, deux fichiers
  `custom-content.spec.ts` et `background-editor/background-editor.spec.ts`, via
  `npm test -- --watch=false --include=src/app/features/custom-content/custom-content.spec.ts --include=src/app/features/custom-content/background-editor/background-editor.spec.ts`.
- Pas de build Angular global : aucun changement frontend.

### Socle livré lors de la reprise

- `backend/src/Entity/SpellDefinition.php` : catalogue complet en champs simples,
  propriété immutable via constructeur/factory, slug stable, édition 2014,
  provenance d'import distincte, composantes modifiées atomiquement, coût nullable,
  variante réservée aux CUSTOM. Aucun lien Character/session/inventaire créé.
- `backend/src/Enum/SpellSchool.php` : huit écoles, codes stables et labels français.
- `backend/migrations/Version20261016120000.php` : création de la seule table
  `spell_definition`, index et contraintes. Aucun seed/import. Down volontairement
  irréversible pour préserver les contenus. Non appliquée lors du premier jalon ;
  application locale vérifiée lors du jalon suivant, section 9.
- `backend/src/Service/ReferenceVisibility.php` : ajout du type aux mécanismes
  existants allows/choices/requireVisible, sans modifier les autres règles.
- `backend/tools/test-spell-catalogue.php` : validation domaine, roundtrip ORM,
  comparaison migration/mapping, contraintes SQL et visibilité, exclusivement
  sur tables/séquences temporaires avec rollback. Les lignes synthétiques OFFICIAL
  sont des fixtures de test, jamais des données applicatives.
- `docker compose exec -T backend php tools/test-spell-catalogue.php` :
  **91 assertions OK**, dont comparaison du schéma réellement migré avec le
  mapping Doctrine et rejet des violations CHECK/UNIQUE/FK. Introspection via
  le nom réel du schéma temporaire PostgreSQL ; aucune table persistante créée.
- `docker compose exec -T backend php tools/test-reference-runtime-visibility.php` :
  **279 assertions OK**, tables temporaires annulées ; non-régression de la
  visibilité et des intégrations runtime existantes.
- `docker compose exec -T backend php bin/console lint:container` : **OK**.
- `docker compose exec -T backend php bin/console doctrine:schema:validate` :
  mapping **OK**, synchronisation **en écart attendu** (code 1) car migration non
  appliquée. `doctrine:schema:update --dump-sql` confirme que le delta ne concerne
  que la création de `spell_definition`, ses index et sa FK ; aucune mise à jour
  exécutée. Utiliser la migration, pas schema:update --force, pour conserver les CHECK.
- `git diff --check` et contrôle équivalent des nouveaux fichiers : **OK**.

Fichiers ajoutés : les quatre fichiers entity/enum/migration/test cités et cette
roadmap. Seul fichier préexistant modifié : `ReferenceVisibility.php`. Aucun
commit/push, aucune donnée de référence ou de personnage modifiée. La suite
slots reste en échec FTD préexistant décrit plus haut ; ne pas annoncer l'ensemble
des tests backend vert.

## 9. Catalogue paginé et administration : contrats actuels

### Droits retenus et testés

| Action | MJ (`ROLE_USER`) | `ROLE_REFERENCE_ADMIN` |
|---|---|---|
| Lire/rechercher OFFICIAL | Oui | Oui |
| Créer/modifier/supprimer OFFICIAL | Non, 403 | Oui, uniquement Symfony |
| Créer CUSTOM | Pour lui-même via Angular | Pour lui-même via Angular |
| Lire/modifier/supprimer son CUSTOM | Oui, Angular | Oui, Angular |
| Lire/modifier/supprimer CUSTOM d'un autre MJ | Non, 404 | Non, 404 |

Le propriétaire est imposé depuis le compte authentifié, jamais reçu en payload.
L'API rejette origin, owner, edition, importKey et les champs inconnus ; le slug
est fixé à la création et immuable ensuite. L'édition 2014 ne se transforme pas
en 2024 par formulaire. Tous les champs mécaniques du catalogue sont éditables,
ainsi que la provenance descriptive ; la variante maison reste réservée à CUSTOM.
Les mécaniques restent éditables pour configurer le catalogue ; la politique de
gel lors d'une utilisation par un personnage reste à définir avant cette phase.
Une liste de classe est une configuration, pas une acquisition. La suppression
est refusée tant que des entrées de liste existent (FK RESTRICT, 409) ; les retirer
explicitement via l'éditeur avant suppression. Aucune cascade silencieuse.

Le rôle dédié est explicite, sans hiérarchie ni attribution automatique. Pour un
opérateur autorisé, la commande suivante permet de gérer ce droit sans SQL :

```sh
docker compose exec -T backend php bin/console app:user:reference-admin adresse@example.com
docker compose exec -T backend php bin/console app:user:reference-admin adresse@example.com --revoke
```

Reconnecter le compte après modification. Ces commandes n'ont été testées que sur
des comptes temporaires : **aucun utilisateur réel n'a reçu de nouveau rôle**.
Le rôle n'ouvre pas les autres CUSTOM et ne refond pas les permissions des anciens
écrans éditoriaux Symfony.

### Service et API

`SpellCatalogue` centralise visibilité SQL, total/pagination, validation via une
`SpellDefinition` candidate non persistée, whitelists et mutations DBAL atomiques.
Les PATCH fusionnent les valeurs avant validation ; aucun champ n'est écrit en
cas d'erreur. Les mises à jour/suppressions verrouillent la ligne ; une collision
de slug renvoie 409 sans révéler le contenu du propriétaire concerné. Il n'y a pas
encore de contrôle optimiste des formulaires : deux sauvegardes valides successives
appliquent la dernière. Les futures relations devront compléter les gardes d'usage.

Filtres disponibles : `q` (nom, original, slug, recherche littérale insensible à la
casse), `level` (0 inclus), `school`, `origin`, `source` (code exact), `page`,
`pageSize` (1–100, défaut 25). Tri nom puis ID ; visibilité et filtres avant COUNT
et pagination. `classId` (entier positif) filtre les listes de classes par EXISTS,
sans doubler les résultats. Paramètres invalides/inconnus rejetés.

Routes Symfony (préfixer `/api` depuis Angular) :

| Méthode | Route | Comportement |
|---|---|---|
| GET | `/reference/custom/spells` | `{spells,total,page,pageSize}` |
| GET | `/reference/custom/spells/{id}` | `{spell}` visible |
| POST | `/reference/custom/spells` | CUSTOM du compte, 201 |
| GET | `/reference/custom/spells/options` | Écoles et classes visibles (OFFICIAL + CUSTOM du compte) |
| PATCH | `/reference/custom/spells/{id}` | Mise à jour de son CUSTOM uniquement, `{spell}` |
| DELETE | `/reference/custom/spells/{id}` | Suppression de son CUSTOM uniquement, 204 |

JSON objet et Content-Type application/json obligatoires en création/PATCH.
Lecture anonyme interdite ; pas d'extension de `/dnd/reference` public.
L'ancienne route POST `/reference/custom/spells/official` est supprimée (404).
Un administrateur utilisant l'API personnelle voit les OFFICIAL avec editable=false
et ne peut pas les modifier/supprimer (403). Le rôle ne contourne pas le parcours.
Le payload `classIds` remplace la liste atomiquement ; omis dans PATCH, il la préserve ;
`[]` retire toutes les entrées. Les doublons et IDs invisibles sont refusés.

### Administration utilisable

Navigation « Sorts officiels » dans Symfony, visible avec ROLE_REFERENCE_ADMIN ;
route `/admin/reference/spells`, elle-même protégée, y compris ses GET.
Liste exclusivement OFFICIAL (filtre origine forgé sans effet), création officielle
à `/admin/reference/spells/new`, édition mécanique complète et listes de classes.
Un CUSTOM est introuvable ici, même s'il appartient à l'administrateur. L'ancienne
route `/new/CUSTOM` est supprimée. Suppression avec confirmation explicite. POST protégés
par CSRF, validation serveur, erreurs affichées, PRG 303, contexte GET conservé
jusqu'au retour à la liste, filtre classe inclus. Les listes de classes sont éditables
dans le formulaire principal ; les attributions particulières de sous-classes ont
désormais un lien vers leur éditeur distinct (section 11).

Angular : onglet « Sorts » dans `/custom-content`, recherche paginée/filtres,
création CUSTOM, édition de toutes les mécaniques et listes de classes, suppression
des CUSTOM détachés, consultation OFFICIAL en lecture seule. Protection des saisies
non enregistrées, erreurs serveur conservant le brouillon et commandes désactivées
pendant les mutations. Aucun bouton d'édition OFFICIAL, même pour un administrateur.

Fichiers de ce jalon :

- `src/Service/SpellCatalogue.php`.
- `src/Controller/SpellCatalogueController.php`, `AdminSpellController.php`.
- `src/Command/ReferenceAdminCommand.php`.
- `templates/admin/spell-list.html.twig`, `spell-edit.html.twig`, lien dans `base.html.twig`.
- `tools/test-spell-catalogue-crud.php` et mise à jour de cette roadmap.

Les chemins de cette liste sont relatifs à `backend`. Les fichiers non commités
du socle ont été conservés. Le jalon suivant ajoute Angular, sans changement au moteur de jeu.

### Migration locale et vérifications

Relecture : CREATE TABLE `spell_definition`, six index et contraintes locales,
aucun ALTER/UPDATE/DELETE des tables applicatives préexistantes. Test de la migration
réelle en tables temporaires et comparaison mapping/schema : OK. Exécution ciblée
avec `doctrine:migrations:execute DoctrineMigrations\Version20261016120000 --up
--dry-run --no-interaction` avant la même commande sans dry-run, dans Docker local.

Empreintes COUNT + MD5 de toutes les lignes JSON triées des 52 tables publiques
avant application, nouvelle lecture après : les 51 tables applicatives existantes
sont **inchangées**. Seul `doctrine_migration_versions` change ; seule table ajoutée,
`spell_definition`, **0 ligne**. Aucun import ni attribution de rôle réel.

- `test-spell-catalogue-crud.php` : **127 assertions OK**, service/API/formulaires,
  permissions, champs mécaniques, isolation, pagination, CSRF, PRG, rollback,
  dépendance RESTRICT et commande grant/revoke ; tables temporaires annulées.
- `test-spell-catalogue.php` : **91 assertions OK**.
- `test-admin-feature-reference.php` : **1 280 assertions OK**, non-régression
  des écrans et de la navigation Symfony existants, tables temporaires annulées.
- `lint:container` et lint Twig des deux nouveaux écrans : **OK**.
- `doctrine:schema:validate` après application : **mapping et schéma OK**.
- `debug:router` : contrôleurs enregistrés ; aucune route existante remplacée.
- `git diff --check` et contrôle des nouveaux fichiers : **OK**.

Les journaux de refus 401/403/404 pendant les tests sont les cas négatifs attendus.
L'échec FTD préexistant de la suite slots reste documenté en section 8 et n'a pas
été corrigé dans ce chantier. Aucun commit, push ou déploiement.

## 10. Listes de classes et séparation des interfaces livrées

### Relations et portée

`SpellClassListEntry` relie un sort à une classe, avec identité stable, unicité de
la paire et deux FK RESTRICT. Ni niveau d'acquisition, ni flag « connu/préparé »,
ni sous-classe dans cette table. La relation hérite de la provenance du sort.

| Sort | Classe OFFICIAL | Classe CUSTOM du propriétaire du sort | Classe CUSTOM d'autrui |
|---|---|---|---|
| OFFICIAL | Autorisé, administrateur via Symfony | Interdit | Interdit |
| CUSTOM | Autorisé, propriétaire via Angular | Autorisé | Interdit |

L'ajout d'un sort CUSTOM à une classe OFFICIAL est personnel : la liste et ses
totaux ne l'exposent qu'au propriétaire du sort. Il ne modifie pas la liste globale
des autres MJ. Ce jalon édite les classes depuis le sort ; ajouter un sort OFFICIAL
à une liste personnelle de classe CUSTOM nécessitera un futur modèle d'extension
personnelle, distinct de ces entrées héritant du sort. Ne pas modifier un OFFICIAL
via ce mécanisme ni présenter cette extension comme déjà réalisée.

Les écritures passent par `SpellCatalogue` pour les deux surfaces : validation des
classes sous verrou partagé, changement du sort et de ses relations dans la même
transaction, conservation des IDs retenus, suppression uniquement des entrées
explicitement retirées. Les callbacks ORM de l'entité vérifient aussi la compatibilité
des dépendances. PostgreSQL assure existence/unicité/RESTRICT ; les règles croisées
de provenance sont validées par le service et l'entité (pas de trigger SQL ajouté).
Les lectures refusent également les dépendances incohérentes issues d'une écriture
directe : détail indisponible et exclusion avant comptage/pagination.

`classIds` est une liste d'entiers uniques, maximum 100. `classes` retourne les
résumés visibles ; `deletable` tient compte des listes configurées. Le filtre classe
est opérationnel en API, Angular et Symfony ; il concerne exclusivement cette
appartenance aux listes, sans calcul d'accès personnage.

### Fichiers principaux de cette étape

- Backend : `Entity/SpellClassListEntry.php`, migration `Version20261016130000`,
  `Service/SpellCatalogue.php`, contrôleurs du catalogue et de l'administration,
  templates de liste/édition et lien de navigation Symfony.
- Angular : `core/models/spell.model.ts`, méthodes de `CustomContentApiService`,
  composant `features/custom-content/spell-editor` et intégration à `custom-content`.
- Tests : `test-spell-class-list-cases.php` inclus dans le test CRUD isolé ;
  adaptation des tests du socle et CRUD ; tests Angular de l'éditeur et catégories.

### Vérifications et migration de développement

- `test-spell-catalogue-crud.php` : **182 assertions OK**, incluant la migration
  réelle de relation sur table temporaire, comparaison ORM, doublons, FK RESTRICT,
  options/relations OFFICIAL et CUSTOM, visibilité défensive, atomicité, filtre
  classe et séparation stricte des interfaces. Logs 401/403/404 attendus.
- `test-spell-catalogue.php` : **91 assertions OK**.
- `test-admin-feature-reference.php` : **1 280 assertions OK** ; autres référentiels inchangés.
- Angular : **26 tests OK** dans `custom-content.spec.ts`,
  `spell-editor/spell-editor.spec.ts`, `background-editor/background-editor.spec.ts`.
- Build Angular : **OK**, warning de budget préexistant sur `player-portal.scss`
  (11,51 kB pour 10 kB), sans modification de ce fichier.
- `lint:container`, lint des 12 templates admin, `debug:router` : **OK**.
- `doctrine:schema:validate` après migration : **mapping et schéma OK**.

Migration `Version20261016130000` relue : une table et trois index uniquement.
Simulation ciblée puis application avec `doctrine:migrations:execute` dans Docker
de développement. Empreintes avant/après des 53 tables publiques : seules les
métadonnées `doctrine_migration_versions` changent ; les 52 tables applicatives
préexistantes sont inchangées. Nouvelle table `spell_class_list_entry` vide,
`spell_definition` toujours vide. Aucun rôle réel attribué, aucun import, aucun
commit/push/déploiement. Les consommations, personnages et états de session sont
préservés. L'échec FTD historique n'est pas corrigé dans cette étape.

## 11. Attributions particulières de sous-classes livrées

### Modèle et sémantique

`SpellSubclassGrant` est une configuration autonome du référentiel. Source et cible
sont des FK RESTRICT vers `CharacterSubclass` et `SpellDefinition`. La classe de
lancement, la ressource existante et le propriétaire utilisent également des FK
RESTRICT. Aucun champ ni lien vers un personnage ou un état de session n’est ajouté.

`origin` et `owner` sont stockés explicitement et imposés d’après la sous-classe
source ; le client ne peut pas les fournir. Le service et les callbacks ORM
contrôlent leur concordance avec cette source. L’activation est un `unlockLevel`
entier de **1 à 20 dans la classe parente de la sous-classe**, pas le niveau total
multiclassé ni une progression narrative. Le nom de cette classe est affiché.

| Mode | Signification de la configuration | Paramètres |
|---|---|---|
| `add-to-list` | Extension de liste due à la sous-classe, distincte de la liste de base | Classe de lancement obligatoire |
| `grant-known` | Attribution automatique de connaissance à résoudre ultérieurement | Classe de lancement obligatoire |
| `grant-always-prepared` | Attribution toujours préparée à résoudre ultérieurement | Classe de lancement obligatoire |
| `grant-cast` | Voie particulière de lancement à résoudre ultérieurement | Classe facultative ; usage des emplacements explicite, restrictions textuelles facultatives, ressource et coût facultatifs mais indissociables |

Les trois premiers modes refusent les paramètres de lancement particulier.
Pour `grant-cast`, `slotUsage` vaut `required`, `not-required` ou `either`.
Une ressource doit être visible et son coût entier strictement positif ; le coût
concerne cette règle, sans condition implicite selon le pool choisi. Les restrictions
sont un texte descriptif limité à 10 000 caractères, jamais interprété comme code.
Configurer la ressource ne l’attribue ni ne la consomme sur un personnage.

L’ID de règle reste stable lors d’une modification. Sous-classe source et sort
cible sont immuables ; changer d’identité nécessite une suppression et une création
explicites. Unicité `(subclass, spell, mode, unlockLevel)` : plusieurs modes ou
niveaux peuvent coexister, mais pas deux configurations concurrentes pour cette
même combinaison. Aucun arbitrage runtime entre ces règles n’est encore effectué.

### Droits, dépendances et interfaces

| Source de la règle | Sort OFFICIAL | Sort CUSTOM du propriétaire de la source | CUSTOM d’autrui |
|---|---|---|---|
| Sous-classe OFFICIAL | Symfony, ROLE_REFERENCE_ADMIN | Interdit | Interdit |
| Sous-classe CUSTOM | Angular, propriétaire de la source | Angular, même propriétaire | Interdit |

La même compatibilité s’applique à la classe de lancement et à la ressource.
La classe parente de la sous-classe est également vérifiée. Une sous-classe CUSTOM
peut appartenir à une classe OFFICIAL ; sa règle reste personnelle. Une source
OFFICIAL ne peut pas dépendre d’un CUSTOM, même détenu par l’administrateur.

`SpellSubclassGrants` partage validation, transactions et permissions entre les
deux interfaces. Modifications/suppressions verrouillent la règle ; les écritures
vérifient les références sous verrou partagé. Les doublons donnent 409, les
paramètres invalides 400, les références invisibles 404. Une mutation OFFICIAL
depuis l’API personnelle reste refusée en 403, même avec le rôle administrateur.
Les lectures excluent les règles dont les dépendances ou la provenance sont
incohérentes. Les contrôles de provenance entre tables sont applicatifs et ORM,
sans trigger SQL ; PostgreSQL garantit CHECK, unicité et FK RESTRICT.

Depuis Symfony, le lien « Gérer les attributions officielles » ouvre
`/admin/reference/spells/{id}/grants` : création, édition et suppression confirmée,
CSRF, PRG 303, conservation des filtres et du brouillon en cas d’erreur.
Le rôle est obligatoire dès le GET ; les règles CUSTOM n’y apparaissent pas.

Depuis Angular, « Afficher les attributions » ouvre un panneau distinct du
formulaire de définition. Il reste utilisable sur un sort OFFICIAL en lecture
seule : le compte peut ajouter une règle issue de sa propre sous-classe CUSTOM
sans sauvegarder le sort. Les règles OFFICIAL sont consultables ; seules les
personnelles offrent Modifier/Supprimer. Les saisies non enregistrées sont
protégées lors d’un changement de sort ou de catégorie ; les commandes sont
bloquées pendant les mutations. Classes et ressources sont affichées par leur nom.

API personnelle (préfixe `/api` dans Angular) :

| Méthode | Route | Réponse |
|---|---|---|
| GET | `/reference/custom/spells/grant-options` | `subclasses`, `classes`, `resources`, `modes` autorisés |
| GET | `/reference/custom/spells/{id}/grants` | `{grants}` visibles |
| POST | `/reference/custom/spells/{id}/grants` | `{grant}`, 201 |
| PATCH | `/reference/custom/spells/{id}/grants/{grantId}` | `{grant}`, identité conservée |
| DELETE | `/reference/custom/spells/{id}/grants/{grantId}` | 204 |

Champs de création : `subclassId`, `mode`, `unlockLevel`, `castingClassId`,
`slotUsage`, `restrictions`, `resourceId`, `resourceCost`. PATCH fusionne uniquement
les paramètres fournis et interdit `subclassId`. Le sort cible vient de la route.
JSON objet requis ; champs inconnus et identités/provenance forgées refusés.

La suppression d’un sort référencé reste bloquée par FK RESTRICT, avec 409 générique
et sans révéler les sources personnelles d’un autre compte. L’indicateur existant
`deletable` du catalogue reflète les listes de base, pas les règles privées des
autres propriétaires : le contrôle final reste serveur. Aucune cascade ne retire
silencieusement les attributions. La suppression d’une ressource CUSTOM référencée
est également convertie en conflit 409 par `CustomReferenceResourceService`, après
rollback de la violation FK ; ce garde ciblé ne change pas ses autres règles.

### Vérifications et migration de développement

- `test-spell-catalogue-crud.php` : **277 assertions OK**. Inclut les tests
  `test-spell-subclass-grant-cases.php` : quatre modes, niveaux 1/20 et refus 0/21,
  ressources, dépendances, propriétaire source, CUSTOM → OFFICIAL, identités,
  rollback, API, CSRF, formulaires Symfony, suppression, contraintes SQL et ORM.
- `test-spell-catalogue.php` : **91 assertions OK**.
- `test-admin-feature-reference.php` : **1 280 assertions OK**.
- `test-reference-runtime-visibility.php` : **281 assertions OK**.
- `test-custom-reference-resources.php` : **189 assertions OK**, non-régression
  du garde de suppression des ressources CUSTOM.
- Angular : **35 tests OK**, dont **15 tests de l’éditeur de sorts/attributions**,
  dans les trois fichiers sorts, catégories CUSTOM et historiques.
- Build Angular **OK** ; warning préexistant de `player-portal.scss`
  (11,51 kB pour 10 kB), fichier non modifié.
- `lint:container`, `lint:twig templates/admin` (**13 templates**), routes des
  deux interfaces et `git diff --check` : **OK**.
- `doctrine:schema:validate` après migration : **mapping et schéma OK**.

Les tests backend utilisent uniquement des tables et séquences temporaires avec
rollback. Le test d’attributions compare les empreintes de toutes les autres
tables ORM avant/après les mutations : aucune modification, y compris des
définitions de sorts, listes de classes, ressources, personnages et sessions.
Les logs 401/403/404 sont les refus attendus. L’échec FTD historique documenté en
section 8 n’est ni corrigé ni requalifié comme vert ; la suite slots complète
n’a pas été relancée dans ce jalon.

Migration `Version20261016140000` relue et testée réellement sur table temporaire,
avec comparaison ORM. Elle crée **une table et six index**, cinq FK RESTRICT et
les CHECK de provenance locale, niveau, mode et paramètres. Aucun seed ni changement
aux tables existantes. Simulation ciblée `doctrine:migrations:execute
DoctrineMigrations\Version20261016140000 --up --dry-run --no-interaction -vv`, puis
application ciblée uniquement dans Docker de développement, après les tests et le
build. Empreintes COUNT/MD5 des **54 tables publiques avant migration** : les
**53 tables applicatives existantes restent identiques** ; seules les métadonnées
`doctrine_migration_versions` changent. La nouvelle table `spell_subclass_grant`
est vide ; `spell_definition` reste vide.

### Portée et limites restantes

Les formulaires gèrent des sous-classes **déjà présentes et accessibles**. Ce jalon
ne crée pas d’éditeur général de classes/sous-classes CUSTOM et ne refond aucun
autre référentiel. En l’absence de sous-classe CUSTOM du compte, Angular affiche
explicitement cette absence et désactive la création d’attribution.
Les sources capacité, don, objet et progression restent à modéliser séparément.
Les règles ne créent **aucun sort connu, appris ou préparé** sur les personnages ;
elles n’accordent aucun slot, n’activent aucun resolver et ne modifient aucun repos.
Pas de verrouillage optimiste de formulaire : deux sauvegardes valides successives
appliquent la dernière. Les restrictions textuelles et alternatives de coûts
nécessiteront un contrat explicite avant toute exécution en jeu.

Fichiers principaux : `Entity/SpellSubclassGrant.php`, `Enum/SpellGrantMode.php`,
`Service/SpellSubclassGrants.php`, `Controller/AdminSpellGrantController.php`,
extensions de `SpellCatalogueController.php`, `templates/admin/spell-grants.html.twig`,
composant Angular `spell-editor/spell-grants`, modèles/service API et tests associés.
Les modifications précédentes et la commande `CreateVillageIdiotBackgroundCommand`
présente à la reprise ont été conservées. Aucun import, rôle réel attribué,
commit, push ou déploiement.

## 12. Résolution des accès de référentiel en lecture seule

### Audit préalable et décisions

État Git vérifié avant modification : branche `feature/spell-catalog`, travaux
précédents non commités conservés, y compris les commandes d’historiques. Lectures
ciblées des entités et services existants avant le patch :

| Point audité | Constat et intégration |
|---|---|
| `Character`, `CharacterClassLevel` | Un niveau par ligne ; `getLevelInClass()` compte uniquement les lignes de la classe. Réutilisé sans calcul depuis le niveau total. |
| Sous-classe choisie | `getSubclassFor()` parcourt les niveaux ordonnés par position. Le resolver vérifie au préalable qu’une seule sous-classe distincte est choisie pour la classe et que son parent est cohérent. |
| `SpellDefinition`, `SpellClassListEntry`, `SpellSubclassGrant` | Définitions et relations de référentiel indépendantes des personnages ; identités et provenance conservées. |
| `SpellCatalogue`, `SpellSubclassGrants`, `ReferenceVisibility` | Réutilisation des contrôles OFFICIAL/CUSTOM et des lectures défensives ; propriétaire pris exclusivement dans la campagne. |
| `CharacterResourceResolver`, `CharacterFeatureResolver`, progressions | Résolution déjà en lecture seule avec `progressionValues` explicites. Ce contexte est transmis sans charger une session. Zéro reste une valeur valide. |
| `CharacterSpellSlotCalculator`, `CharacterSpellSlotStateService` | Les emplacements ne définissent pas les droits d’accès aux sorts. Aucun appel au calculateur de slots dans le nouveau resolver. |
| `CharacterProfileSerializer`, sérialisation des ressources | Contrats existants conservés. Le nouveau service n’est branché ni au profil ni au portail joueur. |

Le modèle actuel ne fournit pas de politique générale d’éligibilité des niveaux de
sorts ni de caractéristique de lancement pour ces voies. Le resolver n’invente
aucune valeur à partir du nom de classe, du niveau total ou des emplacements.
Les listes de base sont retournées intégralement, y compris les sorts de niveau
élevé : ce sont des accès au référentiel, pas une autorisation d’apprentissage ou
de lancement. Les limites correspondantes sont explicites dans le résultat.

### Contrat livré

```php
CharacterSpellAccessResolver::resolve(
    Character $character,
    array $progressionValues = [],
): array; // list<ResolvedSpellAccess>
```

`ResolvedSpellAccess` est un DTO readonly. Une entrée est produite **par relation**,
avec tri déterministe par ID du sort, type de source et ID de relation. Aucune
déduplication par nom, slug ou sort ; deux classes et plusieurs règles peuvent
donner autant de voies distinctes pour le même sort. Aucun choix automatique de
la règle au niveau le plus élevé.

| Champ | Contrat |
|---|---|
| `key` | `class-list:{id}` ou `subclass-grant:{id}`, identité stable de la relation |
| `spell` | Résumé visible : id, slug, nom, niveau, école, édition et origine |
| `mode` | `class-list` pour la liste de base ; sinon l’un des quatre modes de `SpellGrantMode`, conservé tel quel |
| `source` | Type et ID de relation, origine, classe source (ID, nom, niveau réel), sous-classe si présente, `unlockLevel` de la règle ; seuil null pour une liste de base |
| `castingClass` | ID, nom et niveau réel dans cette classe ; null si non renseignée par la règle ; niveau 0 explicite si le personnage n’a pas cette classe |
| `slotUsage`, `restrictions` | Paramètres originaux de la règle ; aucune interprétation des restrictions textuelles |
| `resource` | null sans exigence ; sinon ID, nom, coût, `resolvedForCharacter`, maximum résolu ou null, `current: null` |
| `castingAbility` | null : information non représentée par le modèle actuel |
| `spellLevelEligibility` | `not-evaluated` : aucune politique d’apprentissage/préparation par classe implémentée |
| `canCast` | null : aucune autorisation de lancement calculée |

Une règle de sous-classe est incluse seulement si la sous-classe est effectivement
choisie, visible, cohérente avec la classe source et si le niveau réel dans cette
classe atteint `unlockLevel`. Le niveau de la classe de lancement, éventuellement
différente, ne remplace jamais le niveau d’activation. Un choix de sous-classe
ambigu exclut ses attributions, tout en conservant les listes de classe valides.

Une ressource visible mais non résolue pour le personnage ne supprime pas la
configuration de la voie : elle apparaît avec `resolvedForCharacter: false` et
`maximum: null`. La règle ne crée pas cette ressource. Si elle est résolue, son
maximum vient uniquement de `CharacterResourceResolver`. La correspondance se fait
par ID de définition, pas seulement par slug. Aucun solde courant n’est supposé,
même avec plusieurs sessions du personnage ; aucune suffisance de coût n’est déduite.
Une ressource invisible ou une dépendance incohérente exclut toute la règle.

Le cas sous-classe CUSTOM → sort OFFICIAL est conservé : la règle garde l’origine
de sa source et le sort celle de sa définition. Les CUSTOM d’un autre propriétaire
ne sont jamais retournés. Les lectures SQL n’hydratent pas les règles malformées ;
les contrôles existants sont partagés, et les modes/paramètres invalides sont aussi
exclus défensivement par `SpellSubclassGrants`.

### Périmètre et vérifications

Fichiers ajoutés : `src/Service/CharacterSpellAccessResolver.php`,
`src/Dto/ResolvedSpellAccess.php`, `tools/test-character-spell-access.php`.
Les deux services de catalogue existants gagnent des méthodes de lecture groupée
(`entriesForClasses`, `forSubclasses`) pour réutiliser leurs contrôles de visibilité.
Aucun endpoint, branchement au profil, écran, source d’attribution supplémentaire,
entité persistante ou migration ajouté. Aucun import ni rôle attribué.

- `test-character-spell-access.php` : **98 assertions OK**, tables et séquences
  PostgreSQL temporaires avec rollback. Classe unique et multiclassage, seuil
  inférieur/atteint/dépassé, sous-classe choisie/absente/ambiguë, quatre modes,
  six voies distinctes pour un même sort, classe de lancement différente ou absente,
  ressources résolues/absentes/invisibles, progression explicite à 0 et au seuil,
  CUSTOM → OFFICIAL, isolation de campagne, corruption de dépendances et de
  paramètres, ordre stable et rechargement ORM.
- Empreintes de **toutes les tables ORM** identiques avant/après résolution ;
  états des deux sessions et calculs d’emplacements inchangés. Le UnitOfWork ne
  contient aucune insertion, modification ou suppression programmée après résolution.
- `test-spell-catalogue-crud.php` : **277 assertions OK**, régression des deux
  services partagés et des formulaires/API existants.
- `test-reference-runtime-visibility.php` : **281 assertions OK**.
- `lint:container`, `doctrine:schema:validate` : **OK** ; schéma inchangé.
- `git diff --check` et contrôle des nouveaux fichiers : **OK**.

Aucun changement Angular dans ce jalon, donc pas de nouveau build ni de relance
des tests frontend ; leurs résultats antérieurs restent en section 11. La suite
slots complète et son échec FTD historique n’ont pas été relancés. Aucun commit,
push, déploiement ni modification des données applicatives persistantes.

## 13. Politiques de magie et acquisitions : socle livré, couverture limitée

### 13A — Vérification du socle conservé, 19 octobre 2026

**Clôture après autorisation explicite : migration appliquée uniquement en Docker
local de développement le 19 octobre 2026.** Ce bilan remplace l'attente de
migration décrite dans le compte rendu préalable ci-dessous.

Connexion contrôlée : environnement `dev`, hôte `database`, base `jdr_companion`,
compte `jdr`, conteneurs du projet `D:\Dev\jdr-companion`. Dépendances 17120000 et
17130000 présentes ; 17140000 et ses structures absentes avant application.
Aucune autre opération active ni transaction ouverte détectée au contrôle préalable.
Les quatre tables politiques/acquisitions/sorts/attributions restent vides comme
lors de l'audit. Empreintes COUNT/MD5 des 57 tables publiques et historique complet
des 66 migrations enregistrés dans `/tmp/jdr-spell-revision-before.json` du
conteneur ; seconde comparaison strictement identique juste avant l'application.

Exécution ciblée de `doctrine:migrations:execute
DoctrineMigrations\Version20261017140000 --up --no-interaction`, avec délais de
verrouillage/requête transmis par PGOPTIONS : **code 0**, une seule migration.
Aucun `schema:update --force`, import ou autre migration.

Après application puis après tests : les **56 tables applicatives préexistantes
ont des empreintes identiques**, dont 13 personnages, 127 niveaux de classe et
21 états de session. Comparaison des anciennes colonnes pour les acquisitions.
Seule nouvelle table : `character_spell_revision`, vide. L'historique conserve
exactement ses anciennes lignes et ajoute uniquement 17140000. Deux colonnes
nullables, six FK RESTRICT, CHECK du motif et six index explicites vérifiés.
`doctrine:schema:validate` : **mapping et schéma OK**.
`test-character-spell-acquisitions.php --concurrency` relancé après migration :
**281 assertions OK**, dont acquisitions, copies, révisions et courses 201/409,
schéma synthétique nettoyé. `git diff --check` : **OK**. Aucun rollback nécessaire.

Outil ajouté : `tools/verify-spell-revision-migration.php`, contrôles avant,
recontrôle et comparaison après, sans écriture en base. Aucun code métier modifié,
travaux antérieurs conservés. Les résultats Angular/build du contrôle préalable
ci-dessous restent valables comme résultats de cette même étape, sans nouvelle
exécution après la seule migration. Pas de recette navigateur annoncée.
**État à la clôture du 13A : terminé ; 13B alors non commencé.** Le bilan du 13B
figure ci-dessous. Aucun commit, push, déploiement ni production.

Ce bilan actualise les paragraphes historiques ci-dessous : les copies, corrections
et transitions sont déjà implémentées dans `CharacterSpellAcquisitions`,
`CharacterSpellAcquisitionController`, `SpellbookCopyDecision`,
`CharacterSpellRevision` et les composants Angular `character-spellbook` et
`spell-revision`. Elles n'ont pas été reconstruites. Aucun défaut nécessitant une
correction n'a été démontré par les vérifications de cette reprise.

**Migration de développement toujours suspendue à un accord explicite.**
`Version20261017140000` crée `character_spell_revision` (identité, personnage,
politiques source/cible, auteur, motif, dérogation, date), ajoute `revision_id` et
`retirement_id` nullables à `character_spell_acquisition`, six index explicites et
six FK RESTRICT. Le motif possède un CHECK de longueur 1–10000. SQL complet dans
`backend/migrations/Version20261017140000.php`, relu sans modification.
Les tables dépendantes sont `character`, `user`, `spellcasting_policy_definition`
et `character_spell_acquisition`. Les migrations de politiques et copies précèdent
celle-ci ; les tests exécutent réellement cette chaîne dans leur schéma isolé.

Simulation exécutée : `doctrine:migrations:execute
DoctrineMigrations\Version20261017140000 --up --dry-run --no-interaction -vv` :
**code 0**. Le message final « migrated » appartient au dry-run, pas à une application.
Contrôle SQL après simulation : table de révisions absente, version non enregistrée,
zéro politique et zéro acquisition dans la base de développement.

Impact prévu : aucune suppression, mise à jour de choix ou révision automatique.
Les acquisitions éventuellement présentes recevraient deux valeurs NULL ; ici la
table est vide. Aucun ALTER de personnage ou de session, aucun reset de PV,
ressource ou progression. Les ALTER TABLE nécessitent des verrous PostgreSQL et
peuvent attendre/bloquer des opérations concurrentes ; simulation et tests isolés
ne mesurent pas cette attente sur une base occupée. Le down est volontairement
irréversible. Les FK ne remplacent pas les contrôles métier de propriété et de
compatibilité des politiques, qui restent dans le service.

Vérifications nouvellement exécutées :

- `test-character-spell-acquisitions.php --concurrency` : **281 assertions OK**,
  migration réelle en schéma synthétique, comparaison mapping, permissions,
  copies confirmées, corrections/dérogations, transitions, rollback et conflits.
  Courses à deux connexions pour dernière place, même copie et révisions : une
  réussite 201 et un refus 409. Schéma isolé nettoyé, aucun catalogue importé.
- `test-character-spell-access.php` : **101 assertions OK**.
- `test-spell-catalogue-crud.php` : **277 assertions OK** ; refus HTTP attendus.
- Angular grimoire/révision : **9 tests OK**, deux fichiers.
- Build Angular : **OK**, warning préexistant `player-portal.scss` 11,51 kB/10 kB.
- `lint:container` : **OK**. Doctrine : mapping **OK**, schéma en écart attendu.
  `doctrine:schema:update --dump-sql` confirme que le delta structurel concerne
  uniquement le journal et les deux liens de révision ; aucun SQL appliqué.

Les répétitions de mutations ne créent pas de doublons : les copies déjà présentes
et confirmations de révision périmées sont refusées, sans promettre un mécanisme
général de clé d'idempotence HTTP. Les tests ne remplacent pas une recette navigateur.
Le code lit déjà les colonnes de révision dans les parcours d'acquisition : leur
utilisation sur la base de développement reste bloquée avant migration autorisée.
Builder, level-up et politiques avancées inchangés. Seule cette documentation a
été modifiée dans 13A ; aucun import, commit, push ou déploiement.

Date de reprise : 17 octobre 2026. Les neuf fichiers du travail interrompu ont été
relus et conservés. Ils contenaient les modèles et une première validation, mais
aucune migration ni test d’acquisition ; ils n’ont pas été considérés comme livrés.
Les commandes d’historiques et tous les travaux antérieurs restent non commités.

### Décisions validées et modèle livré

- `SpellQuotaImpact` : `counts-toward-quota`, `free`, `unspecified`. Défaut SQL/PHP
  `unspecified`, sans interprétation des anciennes règles. Champ exposé dans les
  formulaires Symfony OFFICIAL et Angular CUSTOM, avec les mêmes permissions.
- `SpellcastingPolicyDefinition` : version immuable liée explicitement à une classe
  **ou** une sous-classe, FK RESTRICT. Caractéristique, fonctionnement known/prepared/
  spellbook, provenance, limites et table explicite de vingt niveaux. Aucun lien
  créé à partir d’un nom, slug ou type de progression d’emplacements.
- `CharacterSpellAcquisition` : FK RESTRICT vers personnage, sort, version de
  politique, vrai `CharacterClassLevel`, compte décidant et acquisition remplacée.
  Date et note de décision obligatoires ; identités conservées lors des remplacements.
  Une acquisition est apprise ; l’inscription au grimoire et la connaissance sont
  des propriétés séparées. Aucun champ de préparation ou de session.
- `level-history` contrôle les budgets de chaque niveau et les remplacements,
  dans l’ordre chronologique. Le niveau est calculé depuis les vraies lignes de
  classe ; un identifiant de niveau d’un autre personnage ou classe est rejeté.
- `manual-reconciliation` est réservée au propriétaire MJ de la campagne, avec
  `historicalValidation=false` persisté. Elle contrôle niveau actuel, accès, quotas
  vérifiables et doublons, sans prétendre reconstituer les choix passés. Le grimoire
  peut contenir plus de sorts que les gains de niveau : les copies historiques ne
  sont pas limitées artificiellement par ce budget. Leur temps/coût n’est pas validé.

La reconstruction historique valide les décisions saisies, pas une histoire perdue
ou des règles de référentiel anciennes non versionnées. Les choix peuvent rester
incomplets. Une reprise ne peut pas réécrire une reconstruction déjà commencée ;
après une reprise, des décisions historiques sont possibles seulement à des niveaux
strictement ultérieurs au niveau repris, sans requalifier le passé comme vérifié.

### Services et contrats

`CharacterSpellAcquisitions` expose :

- `definePolicy(source, policy, actor)` : OFFICIAL avec ROLE_REFERENCE_ADMIN ou
  CUSTOM du propriétaire de la source. Version strictement croissante, sans seed.
- `status(character, actor)` : politique à configurer, configurée ou ambiguë par
  classe visible ; aucun choix automatiquement inventé.
- `options(character, policyId, actor, classLevelId?)` : sorts éligibles, budgets,
  voies automatiques distinctes, acquisitions actives, configuration manquante et
  état des choix. `canCast` reste null. Une vue historique exclut les décisions
  et remplacements ultérieurs.
- `acquire(character, policyId, choices, provenance, note, actor)` : lot atomique
  de `{spellId, classLevelId, replacesId?}`, retournant les identifiants créés.
  Le propriétaire vient de la campagne, jamais du payload ; le rôle de gestion
  OFFICIAL ne donne aucun droit sur le personnage d’un autre compte.

Les quotas connus, mineurs et préparation restent distincts. `grant-known` dépend
également de la politique de sa source et du niveau du sort ; `grant-always-prepared`
reste dans le budget de préparation. `add-to-list` et `grant-cast` n’utilisent aucun
quota d’acquisition générique. `unspecified` bloque les choix dont le quota dépend
encore d’une configuration, sans créer d’acquisition. Les attributions automatiques
restent calculées. Plusieurs voies comptabilisées vers le même sort dans un même
budget ne comptent qu’une fois ; les provenances restent présentes.

Les mutations verrouillent le personnage avant lecture des budgets. Un verrou
SHARE court sur les tables de configuration stabilise les références pendant la
transaction ; ce choix conservateur bloque temporairement les éditions du
référentiel, pas les lectures. Les erreurs annulent le lot entier. Aucune session
implicite, consommation, progression, préparation ou acquisition automatique.
Une version déjà utilisée ne peut pas être remplacée silencieusement par une
nouvelle politique. Avant le premier choix, seule la dernière version non ambiguë
est utilisable ; classe et sous-classe configurées simultanément sont signalées
comme ambiguës, sans priorité inventée.

### Politiques 2014 et couverture réelle

`Dnd2014SpellPolicies` fournit des préréglages explicites, sans les attribuer à
aucune donnée réelle. Tables et principes contrôlés dans les
[règles de classes 2014](https://www.dndbeyond.com/sources/dnd/basic-rules-2014/classes)
et les [règles de multiclassage 2014](https://www.dndbeyond.com/sources/dnd/basic-rules-2014/customization-options#Multiclassing).
Les emplacements multiclassés ne déterminent jamais l’éligibilité des choix.

| Préréglage | Portée |
|---|---|
| Magicien | INT, mineurs, gains de grimoire par niveau, éligibilité par classe |
| Clerc, druide | SAG, mineurs, accès à la liste ; aucun apprentissage massif des sorts de niveau |
| Paladin | CHA, accès à partir du niveau de classe 2, base de préparation par demi-niveau arrondi inférieur |
| Ensorceleur | CHA, table de sorts connus et mineurs, remplacement par niveau |
| Rôdeur | SAG, table de sorts connus à partir du niveau 2, remplacement |
| Occultiste | CHA, magie de pacte ordinaire limitée aux sorts 1–5, mineurs et remplacements |

**Couverture partielle assumée, pas une politique complète de toutes les classes.**
Barde : Secrets magiques et choix supplémentaires exigent des budgets distincts ;
aucun préréglage approximatif livré. Chevalier occulte et mystificateur profane :
restrictions d’école, exceptions et choix imposés restent à vérifier/modéliser.
Artificier : source complète encore à vérifier. Les arcanums, choix de pacte,
invocations et autres choix particuliers ne sont pas remplacés par des acquisitions
ordinaires. La méthode `unsupported()` décrit ces cas, et `get()` les refuse.
Les politiques CUSTOM explicites restent possibles sans association automatique.

La préparation quotidienne et son quota final après caractéristique ne sont pas
exécutés ici : le résultat expose la base et les attributions comptabilisées
séparément.

**Arbitrage multiclassage validé et intégré : le budget appartient à la classe
grâce à laquelle le personnage acquiert le sort.** La liste d’origine et la
caractéristique d’incantation ne déterminent jamais ce propriétaire.

- Pour un choix persistant, la classe est celle de la politique d’acquisition et
  du vrai `CharacterClassLevel`. Emprunter un sort à une autre liste ne transfère
  aucun quota à la classe dont cette liste provient.
- `add-to-list` étend l’éligibilité dans la liste ciblée ; cette configuration ne
  consomme rien. L’apprentissage ultérieur utilise le budget de sa propre classe.
- `grant-known` et `grant-always-prepared` sont attribués par la sous-classe source :
  ils utilisent la politique de sa classe d’acquisition, même si `castingClassId`
  désigne une autre classe. La version utilisée pour les acquisitions est conservée.
- Les résultats exposent `acquisitionClassId` et `quotaClassId` ; chaque attribution
  automatique conserve également `castingClassId`, sans confondre ces identités.
- `free` est une exception explicite au décompte ; `unspecified` continue de bloquer
  le budget concerné, sans bloquer les choix indépendants de l’autre classe.
  Les autres exceptions, notamment un transfert de budget imposé par une règle,
  nécessiteront une configuration dédiée validée : ni `castingClassId`, ni la
  liste, ni un champ `quotaClassId` forgé dans le payload d’acquisition ne constituent
  une telle exception. Aucun transfert implicite n’est autorisé.

Le cas de test équivalent Chevalier occulte/Magicien utilise une politique synthétique
à sorts connus et une politique de grimoire, toutes deux avec INT, et un sort fictif
provenant uniquement de la liste du second. Il valide la séparation des budgets,
sans prétendre livrer les restrictions d’école du Chevalier occulte encore manquantes.

### Intégration et limites

Le service reste une frontière métier MJ autonome, sans endpoint d’acquisition,
éditeur de politique, nouveau portail joueur ni branchement au profil. Le builder,
les requêtes de level-up et leurs choix actuels ne sont pas modifiés. Pour leur
intégration : ajouter un payload typé et facultatif aux options/request resolver,
contrôler l’autorisation MJ/joueur existante, puis appeler le service dans la même
transaction, après persistance des identités de personnage/niveau et avant la
synchronisation finale. Le flush nécessaire doit rester dans cette transaction.
Ne pas rendre obligatoires des choix qui n’étaient pas encore représentés.

La correction d’une reprise déjà enregistrée, la migration entre versions de
politique et les copies nouvelles de sorts nécessitent des commandes dédiées ;
aucune suppression historique ou exception implicite n’est fournie. Les modifications
ultérieures du référentiel peuvent rendre un choix existant incohérent : le service
le signale et ne le supprime pas. Le gel complet des références utilisées reste
à traiter avant leur édition en production.

### Vérifications exécutées

- `test-character-spell-acquisitions.php --concurrency` : **188 assertions OK**,
  migration réelle et comparaison ORM, politiques, reconstruction/remplacement,
  reprise MJ, grimoire, mineurs, classes préparées, multiclassage, CUSTOM → OFFICIAL,
  quotas libres/comptés/inconnus, séparation préparation/connaissance, doublons,
  rollback, contraintes SQL/FK et rechargement ORM.
  Complément multiclassage : liste empruntée, même caractéristique d’incantation,
  même sort acquis dans deux classes indépendantes, attributions connues/mineures/
  préparées avec classe de lancement différente, exemption explicite, quota non
  renseigné et propriétaire de quota forgé refusé. Les voies automatiques ne créent
  toujours aucune acquisition persistante.
- Deux processus PHP et connexions PostgreSQL concurrents : une seule acquisition
  accepte la dernière place (201), l’autre est refusée (409). Schéma aléatoire
  isolé `spell_acq_test_*`, vérifié avant usage et supprimé à la fin ; aucune table
  publique utilisée pour les fixtures. Le mode sans option utilise des tables
  temporaires annulées.
- Empreintes des autres tables ORM inchangées lors des mutations, dont deux états
  de session contenant PV, ressources consommées et progression. Aucun recalcul.
- `test-character-spell-access.php` : **100 assertions OK** (deux tables isolées
  supplémentaires expliquent le passage de 98 à 100).
- `test-spell-catalogue-crud.php` : **277 assertions OK** ; socle catalogue : **91**.
- Visibilité runtime : **283 assertions OK** ; administration Symfony : **1 280**.
- Angular : **36 tests OK**, dont le champ de quota envoyé sans mutation du sort
  OFFICIAL ciblé. Build **OK**, warning préexistant `player-portal.scss` inchangé.
- `lint:container`, 13 templates Twig, `debug:router`, `doctrine:schema:validate`
  après migration et `git diff --check` : **OK**. L’échec FTD historique n’a pas été corrigé ; la suite
  slots complète n’a pas été relancée.

### Migration locale et fichiers

`Version20261017120000` : deux tables, index et FK RESTRICT ; ajout de
`quota_impact` et de son CHECK à `spell_subclass_grant`. Aucun seed ni affectation
réelle de politique. Testée en isolation, puis simulation ciblée `--dry-run` avant
application ciblée dans Docker de développement uniquement.
`verify-spell-policy-migration.php` a comparé les empreintes des **55 tables
publiques avant migration** : les **54 tables applicatives préexistantes restent
inchangées** (comparaison des anciennes colonnes pour les attributions), seules
les métadonnées de migration changent. Les deux tables nouvelles sont vides ; les
anciennes attributions ont toutes `unspecified`. Mapping et schéma synchronisés.

Fichiers du socle : DTO `SpellcastingPolicy`, entités `SpellcastingPolicyDefinition`
et `CharacterSpellAcquisition`, enum `SpellQuotaImpact`, services
`CharacterSpellAcquisitions` et `Dnd2014SpellPolicies`. Propagation du quota dans
`SpellSubclassGrant`, `SpellSubclassGrants`, `ResolvedSpellAccess`,
`CharacterSpellAccessResolver`, contrôleur/template Symfony des attributions,
modèle Angular et formulaire `spell-grants`. Migration et deux outils de validation
ci-dessus ; adaptations des tests de catalogue et test Angular associé.

Aucun import, rôle attribué, commit, push, déploiement ou accès à la production.
Tous les changements antérieurs sont conservés. Le socle est testable et utilisable
par service, avec les limites ci-dessus ; la couverture complète du jalon 13 reste
à achever pour les choix particuliers et les parcours applicatifs.

Le complément sur le propriétaire des quotas ne modifie que
`CharacterSpellAcquisitions.php`, son test et cette section. Tests d’acquisition
(188), d’accès (100), lint du conteneur et validation du schéma relancés avec succès.
Aucune migration ni donnée applicative modifiée pour ce complément. Aucun changement
frontend : les résultats Angular/build ci-dessus restent ceux de la livraison du
socle, sans nouvelle exécution.

### Reprise : audit des extensions et protection des sorts acquis

Le jalon 13 reste **incomplet**. L’audit confirme que les budgets particuliers
(Secrets magiques, écoles et exceptions des sous-classes, arcanums et choix de
pacte) ne sont pas représentés par les trois budgets actuels. Ajouter seulement
des tables de nombres aux préréglages serait insuffisant. Builder et level-up
ne transportent toujours aucun choix de sort ; les commandes de correction,
transition de politique et copie ne sont pas encore implémentées.

Un complément indépendant est livré dans `SpellCatalogue.php` : dès qu’une
acquisition persistante référence un sort, ses mécaniques structurées, son texte
de surincantation et ses listes de classes sont verrouillés (409). Cela inclut les
acquisitions historiques remplacées. Nom, nom original, description et provenance
descriptive restent éditables ; la description n’est pas analysée comme une règle.
Une sauvegarde identique et un simple réordonnancement des classes restent permis.
La suppression reste protégée par FK RESTRICT. Ce garde est commun aux mutations
Symfony OFFICIAL et API CUSTOM, sans changer leurs permissions.

Le verrou de table ROW EXCLUSIVE est pris avant le verrou de ligne des mutations
du catalogue, pour respecter la synchronisation avec le verrou SHARE des acquisitions.
Ce complément ne constitue pas un gel complet du référentiel : les changements
de sous-classe, classe, attribution et autres dépendances restent à protéger, ainsi
que les écritures ORM ou SQL contournant le service de catalogue.

Trois décisions ont été soumises avant les extensions dépendantes :

- Périmètre PHB 2014 avec artificier de Tasha seulement, ou ajout explicite des
  options de classe de Tasha (sans les activer par défaut).
- Corrections/transitions avec revalidation stricte atomique, ou possibilité
  d’exceptions MJ motivées et persistées.
- Copie avec validation MJ de la source, du temps et du coût, ou débit atomique
  du portefeuille en complément de la validation de source et de temps.

Ces décisions ne sont pas considérées comme acceptées sans réponse. Aucun nouveau
préréglage approximatif, parcours joueur ou modèle de correction n’a été ajouté.

Vérifications de ce complément :

- `test-character-spell-acquisitions.php --concurrency` : **195 assertions OK**,
  dont refus de modification du niveau, école, rituel et listes d’un sort acquis,
  refus de suppression, sauvegarde identique et correction éditoriale. Le test de
  concurrence porte sur la dernière place d’acquisition ; une course spécifique
  entre édition de référence et acquisition reste à ajouter.
- `test-spell-catalogue-crud.php` : **277 assertions OK**.
- `test-character-spell-access.php` : **100 assertions OK**.
- `lint:container` et `doctrine:schema:validate` : **OK**.

Les tests utilisent des données isolées ; les empreintes des autres tables de
test restent identiques. Aucun changement Angular, aucun nouveau build frontend,
aucune migration ni modification des données applicatives persistantes. Fichiers
modifiés pour ce complément : service de catalogue, test d’acquisitions et roadmap.
Tous les travaux précédents sont conservés ; aucun commit, push ou déploiement.

### 13B — Règles de choix avancées : livraison backend

Reprise du 19 octobre 2026 sur `feature/spell-catalog`. Les fichiers du chantier
interrompu ont été relus et complétés, sans restauration ni suppression des travaux
antérieurs. Ce bilan remplace le statut « non commencé » du rapport historique 13A.
Le périmètre validé du 13B est implémenté par services. Sa migration, initialement
en attente, a ensuite été autorisée et appliquée localement ; bilan en 13C.

#### Modèle et contrats

Les politiques existantes restent au **format 1**, sans conversion de leurs
acquisitions. Le format 2 ajoute une composition explicite : une politique de
sous-classe peut référencer une version figée de politique de sa classe parente.
Leurs tables de quotas, fonctionnement et caractéristique doivent correspondre ;
les règles de choix s'additionnent sans priorité implicite. Une nouvelle version de
la classe ne remplace jamais cette référence. Les clés de règles dupliquées et les
budgets de remplacement partagés contradictoires sont refusés.

`SpellPolicyChoiceRule` porte une identité stable et des FK RESTRICT vers la
politique, la liste de classe, la liste autorisée au remplacement, la classe de
lancement et, si nécessaire, le sort imposé. `SpellChoiceParameters` valide un JSON
fermé : vingt niveaux d'opportunités, niveaux maximaux, niveau minimal initial et
de remplacement, écoles initiales et de remplacement, budget consommé, résultat
connu/grimoire, budget partagé et limites de remplacement, choix dans toutes les
listes. Aucun identifiant de référence ni expression exécutable n'est caché en JSON.

Les budgets `known`, `cantrips`, `spellbook` et `free` sont distincts. La préparation
reste exposée séparément, sans sélection quotidienne. Une opportunité de choix
n'est pas un quota global ; une règle gratuite conserve son propre nombre de choix.
La classe d'acquisition reste celle de la politique et du vrai niveau du personnage,
indépendamment de la liste empruntée et de la classe de lancement.

`CharacterSpellChoice` complète chaque nouvelle acquisition avancée : règle,
opportunité historique d'origine nullable, provenance qualifiée ou inconnue, compte,
date et motif. Les remplacements successifs conservent la règle et l'opportunité
initiales ; aucune ancienne ligne d'acquisition n'est effacée.

`CharacterSpellAcquisitions` réutilise ses permissions, transactions, verrou du
personnage et stabilisation du référentiel :

- `defineAdvancedPolicy(source, policy, actor, rules, base?)` crée une version
  explicite ; OFFICIAL exige le rôle dédié, CUSTOM exige le propriétaire source.
- `options()` délègue le format 2 à `AdvancedSpellChoices`. Il expose les règles,
  leurs opportunités restantes, candidats initiaux et candidats de remplacement,
  budgets et voies automatiques. Les extensions de liste actives sont conservées,
  avec contrôle du niveau et du choix historique de sous-classe.
- `acquire()` attend aussi `ruleId` pour chaque choix de format 2. Un ID étranger,
  une école interdite, une opportunité épuisée, un remplacement incompatible ou
  une tentative de changer la règle d'origine sont refusés. Le lot reste atomique.
- `revise()` accepte un dernier argument `ruleChoices`, aligné sur les sorts de la
  nouvelle sélection. Une transition format 1 → 2 est explicite, motivée et inscrite
  au journal existant ; les acquisitions antérieures restent liées à leur révision.
- `qualifyChoice()` permet au MJ propriétaire de confirmer la voie déclarée d'une
  reprise avec un motif obligatoire. Cette qualification unique ne fabrique pas de
  niveau historique et ne transforme pas la reprise en reconstruction vérifiée.
  Un remplacement est bloqué si l'origine inconnue peut changer ses règles ; les
  autres choix restent disponibles. Changer de voie exige une révision explicite.
- Les copies utilisent toujours devis et confirmation manuelle, sans débit de
  portefeuille. Elles ne consomment pas les opportunités d'apprentissage de niveau.

Les options et les mutations n'attribuent aucun sort automatiquement, ne cherchent
aucune session et ne modifient ni ressources, ni emplacements, ni progression.
Les paramètres `free`/`counts-toward-quota`/`unspecified` existants restent respectés.

#### Préréglages et couverture

`Dnd2014AdvancedSpellPolicies` fournit des constructeurs explicites recevant les
références réelles ; le moteur ne compare aucun nom ou slug de classe :

| Préréglage | Règles représentées |
|---|---|
| Barde 2014 | Choix ordinaires, mineurs, deux Secrets aux niveaux 10/14/18 comptés dans les sorts connus ; un Secret mineur compte dans ce quota de Secrets, pas celui des mineurs ordinaires |
| Collège du Savoir | Deux Secrets supplémentaires au niveau 6, opportunités propres et hors quota ordinaire ; composition avec une version figée du Barde |
| Chevalier occulte | Liste du Magicien sans niveau de Magicien, abjuration/évocation, exceptions aux niveaux 3/8/14/20 et conservation de leur origine lors des remplacements |
| Escroc arcanique | Même séparation des listes et budgets, enchantement/illusion, exceptions historiques, sort mineur imposé fourni par FK explicite |

Les remplacements de Secrets utilisent la liste du Barde, avec le budget de
remplacement commun aux choix ordinaires et supplémentaires. Les sorts mineurs ne
sont pas remplaçables via ce budget. Les règles CUSTOM utilisent le même moteur et
les mêmes contrôles de visibilité. Les préréglages ne sont associés à aucune donnée
réelle et ne contiennent aucun texte de sort importé. L'ancienne méthode de
préréglages simples reste compatible : les nouvelles variantes se construisent
via le service avancé, et non via un remplacement silencieux de `get()`.

#### Migration créée, non appliquée

`Version20261020120000` ajoute `format_version` (défaut 1) et `base_policy_id`
à `spellcasting_policy_definition`, puis crée `spell_policy_choice_rule` et
`character_spell_choice`, avec index, unicité, CHECK locaux et FK RESTRICT.
Elle ne contient aucun seed, import, suppression ou transition de personnage.
Les anciennes acquisitions ne reçoivent aucune provenance inventée.

À la livraison initiale du 13B, la migration réelle a été exécutée uniquement dans le schéma de test isolé, puis
comparée au mapping ORM. Le dry-run ciblé en développement réussit : **17 requêtes**.
**Aucune application en développement à cette date.** `doctrine:schema:validate` validait le
mapping et retourne l'écart attendu (code 1) ; `schema:update --dump-sql`, en lecture
seule, confirme que le delta concerne les deux tables et les deux colonnes décrites.
Le code utilisant ces nouveaux champs nécessite la migration avant activation.

#### Vérifications de cette reprise

- Acquisitions `--concurrency` : **357 assertions OK**, dont règles avancées,
  écoles, sort imposé, Secrets, budgets partagés, remplacements successifs,
  qualification MJ, permissions CUSTOM, liste OFFICIAL empruntée, extensions,
  multiclassage, transition historique, copies et rollback d'un lot partiel.
- Quatre courses à deux processus/connexions : dernière place ordinaire, copie
  concurrente, révision concurrente, dernière opportunité d'exception avancée.
  Une seule écriture acceptée et un conflit 409 dans chaque course. Schéma aléatoire
  vérifié avant usage puis supprimé ; aucune fixture dans les tables publiques.
- Contraintes SQL avancées et comparaison migration/ORM vérifiées ; empreintes des
  sessions identiques avant/après les scénarios avancés.
- Accès de sorts : **103 assertions OK** ; catalogue CRUD : **277** ; socle
  catalogue : **91** ; visibilité runtime : **286** ; cycle de vie personnage :
  **262**. Le harness du catalogue supprime désormais ses nouvelles dépendances
  temporaires dans l'ordre avant de retester la migration initiale.
- Angular grimoire/révisions : **9 tests OK**, deux fichiers. Build **OK**, warning
  préexistant de `player-portal.scss` (11,51 kB pour 10 kB), sans modification frontend.
- `lint:container`, `debug:router`, `git diff --check` : **OK**. Les refus HTTP
  affichés dans les tests correspondent aux cas négatifs attendus.

Fichiers du 13B : `Dto/SpellChoiceParameters.php`, `Entity/SpellPolicyChoiceRule.php`,
`Entity/CharacterSpellChoice.php`, extensions de `SpellcastingPolicyDefinition.php`
et `CharacterSpellAcquisition.php`, services `AdvancedSpellChoices.php`,
`Dnd2014AdvancedSpellPolicies.php`, `CharacterSpellAcquisitions.php` et indication
des nouveaux préréglages dans `Dnd2014SpellPolicies.php`, migration, tests
`test-advanced-spell-choice-cases.php`, `test-character-spell-acquisitions.php`,
adaptation de `test-spell-catalogue.php` et cette roadmap. Chemins relatifs à `backend`.

#### Limites et suite

La livraison concerne les services backend. Aucun éditeur de politique avancée,
payload de choix builder/level-up ou écran de qualification n'est ajouté. Les
endpoints et écrans existants restent ceux du 13A ; la transition avec règles
avancées et la qualification sont actuellement des commandes de service.
Les substitutions individuelles d'attributions automatiques restent au **13C**.
Les options Tasha de remplacement, arcanums et autres sources particulières ne sont
pas activés par ces préréglages 2014. Les compositions sont additives, sans surcharge
implicite des quotas de la base ; une autre composition demande une version explicite.
Les contrôles croisés sont applicatifs ; les FK/CHECK ne remplacent pas ces services
pour des écritures SQL externes. Aucun changement des politiques existantes,
personnages ou sessions en base de développement, aucun import AideDD, commit,
push, déploiement ou intervention en production.

### 13C — Reprise, substitutions et administration (20 octobre 2026)

**Statut : 13C terminé dans le périmètre explicitement clarifié par le MJ :
règles propres aux sorts et effets des classes/sous-classes configurées.**
La section de clôture en fin de document fait foi ; les bilans intermédiaires
ci-dessous conservent l'historique des limites et travaux successifs.
Les travaux antérieurs sont conservés. Aucun changement au builder, au level-up,
à la préparation quotidienne ou au lancement en session.

#### État des migrations et données

La migration 13B `Version20261020120000` a été appliquée uniquement dans Docker
local, après l'autorisation explicite précédente, contrôle de connexion,
concurrence, historique, empreintes et simulation ciblée. Le contrôle
`verify-spell-choice-migration.php after` a été relancé pendant cette reprise :
**57 tables applicatives préexistantes identiques**, deux tables nouvelles vides,
anciennes politiques conservées. Connexion contrôlée : base `jdr_companion`,
compte `jdr`, environnement local. Version 13B enregistrée une fois.

**`Version20261020130000` reste NON APPLIQUÉE en développement.**
Elle est exécutée réellement uniquement dans le schéma isolé des tests. Elle crée :

- `spell_grant_substitution_rule` : version, attribution source, budget partagé,
  écoles, opportunités par niveau et provenance ;
- `spell_grant_substitution_list` : listes autorisées avec FK ;
- `character_spell_substitution` : personnage, règle figée, sort, niveau réel,
  décision précédente, auteur, motif et date.

Les FK sont RESTRICT. La version d'une règle et le successeur d'une décision sont
uniques. La migration remplace aussi trois CHECK de `character_spell_acquisition`
pour autoriser le budget `cast` sans connaissance ni inscription au grimoire, et
les remplacements de mineurs/voies de lancement. Elle ne modifie aucune ligne
existante, ne crée aucune politique et n'importe aucun sort. Les nouvelles
contraintes acceptent les anciennes valeurs ; leur installation nécessitera
néanmoins un verrou sur la table d'acquisitions. Un contrôle des données et de
l'activité restera nécessaire avant une future application autorisée.

Simulation ciblée `doctrine:migrations:execute
DoctrineMigrations\Version20261020130000 --up --dry-run --no-interaction` :
**OK, 17 requêtes simulées**. Vérification après simulation : version absente de
l'historique, table publique de substitutions absente. `doctrine:schema:validate` :
mapping OK, schéma volontairement désynchronisé. Le dump SQL ne propose que les
trois tables et leurs index/FK ; les CHECK sont contrôlés par la migration et les
tests, pas par le comparateur ORM. Aucun `schema:update --force`.

#### Substitutions réellement prises en charge

`SpellGrantSubstitutionRule` configure explicitement une attribution `grant-known`
ou `grant-always-prepared`. Ses vingt opportunités se rapportent au niveau réel de
la classe source. Les écoles et listes sont des critères de sélection ; le sort
remplaçant conserve le niveau du sort initial. Aucun nom ou slug de classe ne
déclenche une règle. Aucun préréglage n'est affecté automatiquement aux personnages.

`CharacterSpellSubstitutions::substitute()` exige propriétaire MJ de la campagne,
confirmation, motif et ID de décision précédente attendu. Il contrôle sous-classe
choisie, seuil, niveau appartenant au personnage, visibilité, listes, écoles et
budget partagé entre les attributions de la même sous-classe. La première décision
emploie la dernière version ; les suivantes conservent cette version. Les décisions
sont ajoutées sans suppression, dans l'ordre des niveaux ; une répétition périmée
renvoie 409 plutôt que créer un doublon. Deux connexions concurrentes ne créent
qu'un seul successeur. Le verrou du personnage précède les contrôles et l'écriture.

Le resolver conserve la voie originale, sa classe d'acquisition et son quota,
mais retourne le sort substitué et les identifiants de provenance. Une consultation
historique ignore les décisions ultérieures. Les autres voies du même sort restent
distinctes. Aucune acquisition automatique n'est créée, aucune définition OFFICIAL,
liste de classe, ressource ou session n'est modifiée par une substitution.

Une attribution configurée pour substitution ne peut plus changer de mécanique
via le service existant. Les sorts sources ou choisis sont également protégés
contre les mutations mécaniques via `SpellCatalogue`. Les FK protègent la
suppression. Les contrôles croisés restent applicatifs ; ce n'est pas un garde
universel contre des écritures SQL externes.

API MJ : `GET/POST /characters/{id}/spell-substitutions`. POST reçoit
`ruleId`, `classLevelId`, `spellId`, `expectedPrevious` (null au premier choix),
`reason`, `confirmed`. GET retourne `{history}` ; POST `{id}`, 201.
Un administrateur de référentiel n'obtient aucun droit sur le personnage d'un autre
MJ. Sans migration, ces opérations retournent 503 après contrôle d'autorisation.

#### Administration versionnée

`SpellPolicyAdministration` partage validation et règles métier :

- Symfony `/admin/reference/spell-policies` : OFFICIAL uniquement,
  ROLE_REFERENCE_ADMIN dès le GET, CSRF, création de versions, PRG 303,
  conservation du document invalide ;
- Angular `/dnd/spell-policies` : consultation des OFFICIAL, création de versions
  pour les seules sources CUSTOM du compte, erreurs conservant le brouillon,
  protection des saisies et commandes bloquées pendant une requête ;
- API personnelle `GET/POST /reference/custom/spell-policies` et
  `POST /reference/custom/spell-policies/substitution-rules`. L'API refuse
  l'écriture OFFICIAL même pour un administrateur.

Les deux écrans sont des **éditeurs avancés de documents JSON**, avec références
lisibles, exports des versions et contrôle strict côté serveur. Ce ne sont pas
encore des formulaires guidés de vingt niveaux. Aucune version utilisée n'est
écrasée ; créer une version ne migre aucun personnage. L'enregistrement 13C est
désactivé tant que la migration n'est pas disponible. Les parcours des dix onglets
existants restent inchangés.

Contrat de politique : `{sourceType: "class"|"subclass", sourceId, policy,
rules?, basePolicyId?}`. `policy` porte `key`, `version`, `ability`,
`learningStyle`, les vingt `levels`, `provenance`, `limitations`.
Chaque règle porte `key`, `parameters` stricts et les FK explicites
`listClassId`, `replacementListClassId`, `castingClassId`, `requiredSpellId`.
La composition vise une version figée, sans héritage implicite.

Exemple synthétique de règle de substitution (remplacer les IDs par des références
compatibles réellement choisies ; cet exemple n'est pas une règle D&D préchargée) :

```json
{
  "grantId": 123,
  "version": 1,
  "budgetKey": "substitutions-personnelles",
  "schools": ["illusion"],
  "opportunities": [0,0,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1],
  "classIds": [456],
  "provenance": "Règle personnelle explicitement validée par le MJ"
}
```

#### Particularités : couverture et limites

- Artificier : générateur explicite de politique préparée INT, table d'éligibilité
  et choix/remplacement de mineurs. Aucun calcul de préparation finale, infusions,
  objets ou attribution automatique des sorts de spécialiste.
- Occultiste : opportunités distinctes d'Arcanum 6/7/8/9, stockées `cast`,
  hors quota connu. Leur choix ne donne pas un compteur ni une autorisation de
  lancement. Remplacement optionnel et mineurs peuvent partager le même budget ;
  confirmation `optionConfirmed=true` obligatoire et tracée. Pactes et invocations
  ne sont pas modélisés par ces choix.
- Druide et Ensorceleur : moteur ordinaire réutilisable et option explicite de
  remplacement d'un mineur aux niveaux d'ASI ; pas d'activation automatique.
  Ce générateur n'est pas un paquet Tasha universel : les Formules de sorts mineurs
  du magicien, par exemple, ne relèvent pas de cette fréquence.
- Cercles et origines : les listes fixes restent configurables par les attributions
  existantes. Les substitutions à écoles/listes définies sont représentables,
  mais les configurations officielles complètes des cercles, de l'Âme mécanique
  et de l'Esprit aberrant ne sont ni préchargées ni déclarées vérifiées ici.
  Les modes de lancement particuliers, réductions, Familiers sauvages et autres
  usages ne sont pas implémentés.

Pour clôturer complètement le 13C restent : vérification et configuration explicite
des particularités officielles manquantes ; formulaires guidés ; écran de décision
de substitution par personnage (service/API disponibles) ; commande de correction
ou révocation d'une substitution et transition de sa version sans effacer
l'historique. Aucune correction de ce type n'est implicitement autorisée par
l'éditeur de versions. Les acquisitions, copies et révisions du socle restent
disponibles selon leurs contrats antérieurs.

#### Fichiers et validations de reprise

Backend : entités `SpellGrantSubstitutionRule`, `CharacterSpellSubstitution`,
services `CharacterSpellSubstitutions`, `SpellPolicyAdministration`, contrôleurs
`SpellPolicyAdministrationController`, `CharacterSpellSubstitutionController`,
template `spell-policies.html.twig`, navigation admin ; raccordements dans
`SpellSubclassGrants`, `CharacterSpellAccessResolver`, `ResolvedSpellAccess`,
`SpellCatalogue`, `AdvancedSpellChoices`, `CharacterSpellAcquisitions`,
`CharacterSpellAcquisition`, `SpellChoiceParameters`, `Dnd2014AdvancedSpellPolicies`.
Migration `Version20261020130000`, cas `test-spell-substitution-cases.php`,
harness acquisitions et adaptations des tests catalogue. Angular :
`features/spell-policies` (composant/template/style/tests), route et lien personnel.

Résultats exécutés :

- `test-character-spell-acquisitions.php --concurrency` : **419 assertions OK**,
  comprenant migrations réelles isolées, comparaison ORM des trois nouvelles
  tables, substitutions successives/historiques, propriété, critères, absence
  d'acquisition automatique, versionnement et formulaires Symfony (CSRF, PRG,
  brouillon), API, Arcanums, confirmation facultative et budget partagé.
- Cinq courses à deux processus : acquisition, copie, révision, exception avancée,
  substitution. Dans chaque course : une réussite et un conflit 409.
- `test-spell-catalogue-crud.php` : **277** ; `test-character-spell-access.php` :
  **105** ; `test-spell-catalogue.php` : **91** ;
  `test-reference-runtime-visibility.php` : **288** ;
  `test-character-lifecycle.php` : **264 assertions OK**.
- Angular : **31 tests distincts OK** sur quatre fichiers : politiques (6),
  éditeur de sorts (16), grimoire (5), révisions (4). Les six tests de politiques
  ont été exécutés dans les deux lots et ne sont pas comptés deux fois.
- Build Angular **OK**, warning préexistant `player-portal.scss` (11,51 kB / 10 kB).
  `lint:container`, lint des **14 templates admin**, routes, `git diff --check` :
  **OK**. Mapping Doctrine valide ; synchronisation volontairement en écart,
  comme décrit plus haut, et non présentée comme verte.

Les erreurs HTTP 401/403/404/409 affichées correspondent aux refus attendus.
Les deux défauts détectés lors de la reprise (visibilité d'une attribution dans
l'administration et construction d'une politique avancée de grimoire) ont été
corrigés et les tests relancés. Le harness catalogue recrée ses dépendances
temporaires après son test de migration historique.

Ces résultats ne couvrent pas un parcours navigateur complet ni toutes les
particularités D&D de classe. La suite slots et son échec FTD historique ne sont
pas requalifiés comme verts. Les tests utilisent des fixtures synthétiques
isolées, sans catalogue AideDD. Aucun import, migration 13C appliquée, commit,
push, déploiement ou intervention en production.

#### Finalisation des interfaces et révisions du 13C — 20 octobre 2026

Les substitutions individuelles disposent désormais d'un écran Angular accessible
 depuis la liste des personnages : sélection de l'attribution, version applicable,
 opportunités et sorts compatibles, motif et confirmation explicite. Correction MJ,
 révocation et transition de version utilisent ce même écran. Un conflit conserve
 le brouillon et exige une nouvelle confirmation. Le chargement signale explicitement
 la migration manquante, sans fabriquer de choix. L'autorisation reste celle du MJ
 propriétaire de la campagne ; le rôle administrateur de référentiel ne la remplace pas.

Les décisions sont ajoutées au journal, jamais réécrites. Une correction doit
 respecter les critères de la règle ; une révocation rétablit le sort initial sans
 rembourser l'opportunité utilisée. Une transition conserve le sort courant et
 exige une version supérieure compatible. Les révisions prennent effet à la position
 actuelle du personnage, y compris en multiclassage ; les consultations historiques
 antérieures conservent l'ancien résultat. La clé de budget reste stable entre
 versions ; modifier la fréquence d'un budget partagé n'est pas pris en charge
 par cette transition. Aucun effet sur les acquisitions, PV, ressources ou sessions.

Les éditeurs Angular et Symfony proposent des champs guidés pour les sources,
 versions, références, quotas, écoles, opportunités et règles de remplacement.
 Ils modifient le même document que l'éditeur JSON avancé, qui reste disponible.
 Les prévisualisations de politiques Artificier, Occultiste, Druide et Ensorceleur
 ne persistent rien ; les options Tasha proposées demandent une activation explicite.
 La composition avancée et les exceptions restent configurables dans le document.

La migration NON APPLIQUÉE `Version20261020130000` a été complétée dans sa création
 de `character_spell_substitution` : `decision_kind`, `effective_position` et CHECK
 associés. Aucun fichier de migration supplémentaire. Ses trois tables, index,
 FK et adaptations de contraintes sont testés réellement dans un schéma isolé,
 avec comparaison ORM. Dry-run ciblé réussi : **17 requêtes SQL**, aucune application
 en développement. Mapping valide ; écart de synchronisation attendu tant que cette
 migration n'est pas appliquée. Elle est techniquement prête pour une exécution
 séparément autorisée, après nouveau contrôle de connexion et d'état des données.

Vérifications finales de cette reprise :

- Acquisitions et substitutions : **429 assertions OK**, option `--concurrency`,
  schéma isolé nettoyé. Inclut corrections, révocations, transitions, refus d'une
  version antérieure, historique, permissions et les cinq courses déjà présentes.
  Une course dédiée entre deux corrections de substitution n'a pas été ajoutée.
- Angular : **18 tests OK** sur substitutions (6), guide (2), politiques (6),
  révisions (4). Le défaut du test de sélection venait de l'initialisation asynchrone
  de `ngModel` ; le test attend maintenant sa stabilité et déclenche un vrai événement.
- Guide Symfony : test DOM isolé réussi, modification guidée répercutée dans le JSON.
- Build Angular **OK** ; warning préexistant `player-portal.scss` 11,51 kB / 10 kB.
- `lint:container`, les **14 templates Twig**, `git diff --check` : **OK**.
- `doctrine:schema:validate` : mapping **OK**, synchronisation **en écart attendu**.
  Ce résultat n'est pas présenté comme une validation du schéma de développement.

Couverture de classe et limites de clôture : Artificier (choix ordinaires et
 remplacement des mineurs), Arcanums et options de remplacement déjà présentes
 restent couverts par les tests existants. Deux recettes déclaratives supplémentaires
 sont testées : mineur gratuit du Cercle de la Terre au niveau 2, substitutions
 psioniques/horlogères avec écoles distinctes et trois listes explicitement choisies.
 Ces recettes sont des outils de configuration, pas des attributions automatiques
 à toutes les sous-classes existantes. Les cercles et spécialistes doivent recevoir
 leurs attributions explicites ; aucun corpus n'a été importé.

**Le 13C complet n'est pas déclaré clos.** Les parcours d'administration et de
 révision demandés sont livrés, mais la couverture exhaustive des particularités
 reste à terminer : choix de pacte et invocations, articulation des alternatives
 de Métamagie, autres capacités et cercles non configurés. La règle de partage du
 budget Tasha entre remplacement de mineur et d'Arcanum mérite une vérification
 sur le texte primaire complet avant de certifier ce préréglage : l'accès public
 au chapitre Tasha renvoie à la boutique et n'a pas permis cette confirmation.
 Le comportement existant n'a pas été changé sur la base d'une supposition.
 Aucun parcours navigateur complet n'est revendiqué par les tests de composants.

Fichiers concernés par cette finalisation : `CharacterSpellSubstitutions`,
 `CharacterSpellSubstitution`, `SpellSubclassGrants`, contrôleur de substitutions,
 service/contrôleur d'administration, préréglages avancés, migration 13C, template
 et script `spell-policy-guide.js`, composants Angular `spell-substitutions` et
 `policy-guide`, intégration aux politiques et navigation, tests ciblés et roadmap.
 Aucun import, migration appliquée en développement, commit, push ou déploiement.
 Les jalons 13D/13E, builder, level-up et lancement en session restent inchangés.
#### Application autorisée de la migration 13C — 20 octobre 2026

`Version20261020130000` appliquée seule sur PostgreSQL Docker local
`jdr_companion` (utilisateur `jdr`, hôte `database`, environnement `dev`), après
contrôle des dépendances, absence des objets à créer et des transactions
concurrentes. Simulation ciblée réussie, puis comparaison des empreintes et de
l'historique immédiatement avant exécution : aucun changement intermédiaire.

Le contrôle `tools/verify-spell-substitution-migration.php` confirme que les
**59 tables applicatives préexistantes sont identiques** (nombre de lignes et
empreintes de toutes les colonnes). Seule cette version a été ajoutée à
l'historique des migrations. Les trois tables nouvelles sont vides. Les colonnes
`decision_kind` et `effective_position`, les index (7/3/3 pour substitutions,
listes et règles) et les contraintes sont présents. `doctrine:schema:validate` :
**mapping et synchronisation OK**. Aucun import, autre migration, réinitialisation,
commit, push ou déploiement. Cette application ne change pas les limites
fonctionnelles du 13C documentées plus haut.
#### Complément métier après `ecacfeb` — 20 octobre 2026

**13C non clos : ce complément corrige et vérifie une partie du métier, mais ne
livre pas le moteur de choix d'invocations demandé. Ces lacunes restent au 13C ;
elles ne sont pas transférées aux jalons 13D/13E.**

Correction livrée dans `CharacterSpellAccessResolver` : `throughPosition` filtre
maintenant les niveaux, les classes et le choix de sous-classe, et pas seulement
le journal des substitutions. Une attribution débloquée ultérieurement ou une
classe de lancement acquise plus tard n'apparaît plus rétroactivement. Un choix
ultérieur de sous-classe contradictoire n'invalide pas une vue antérieure non
ambiguë. Aucun changement de données ni de politique déjà enregistrée.

Contrôles ajoutés : table complète des mineurs de l'Artificier (gains 10/14), base
de préparation par niveau de classe, plafond ordinaire de Magie de pacte à 5,
remplacements facultatifs aux niveaux ASI, partage du budget de remplacement
mineur/Arcanum et refus de convertir un Arcanum en mineur en forgeant sa règle.
Le message de redirection du préréglage Artificier vers le générateur avancé est
corrigé ; aucune association automatique à une classe réelle.

Résultats de cette reprise : **438 assertions acquisitions** avec `--concurrency`
(schéma isolé nettoyé, cinq courses existantes), **109 assertions accès** (tables
et séquences temporaires annulées), `lint:container`, `doctrine:schema:validate`
et `git diff --check` **OK**. Les tests de budget démontrent le comportement du
moteur, pas la couverture complète de toutes les alternatives Tasha. Aucune
interface modifiée, donc aucun nouveau build Angular nécessaire.

Points métier restant effectivement à implémenter :

- Choix individuel versionné des invocations et du pacte, budgets propres,
  prérequis de classe/pacte/capacité et remplacement. Le modèle actuel des
  attributions pointe exclusivement vers une sous-classe : l'utiliser pour une
  invocation choisie serait incorrect. Le moteur des capacités existant ne fournit
  pas à lui seul ce journal de décisions.
- Sources de sorts issues des invocations : à volonté, avec slot de pacte et
  limite par repos, cibles/composantes particulières et rituel seulement. Le
  [chapitre officiel Occultiste 2014](https://www.dndbeyond.com/classes/7-warlock)
  distingue ces voies. Le Livre des secrets anciens exige en outre des choix et
  inscriptions rituels propres au Livre des ombres ; il ne doit pas être assimilé
  au grimoire de Magicien ni débiter le quota de sorts connus.
- Alternatives Tasha non liées à un sort : changement de pacte et de Métamagie
  doivent participer au budget commun approprié. Les tests actuels couvrent
  uniquement les alternatives déjà représentées comme acquisitions de sorts.
- Configuration complète des cercles/spécialistes et recettes de sous-classes :
  les générateurs déclaratifs existants et leurs tests ne valent pas sélection
  individuelle ni attribution de toutes ces capacités aux personnages.

La lecture secondaire de la [règle de polyvalence occulte](https://dnd5e.wikidot.com/warlock)
corrobore les alternatives partageant un choix ; elle n'est pas présentée comme
un texte primaire vérifié. Les pages PDF D&D Beyond indexées n'ont pas fourni un
texte intégral exploitable lors de cette vérification. Le point de vérification
primaire Tasha reste donc explicitement ouvert.

Fichiers de ce complément : `CharacterSpellAccessResolver.php`,
`Dnd2014SpellPolicies.php`, `test-character-spell-access.php`,
`test-spell-substitution-cases.php` et cette roadmap. Aucune migration créée ou
appliquée. Les fichiers de données non suivis sont conservés. Aucun import,
commit, push, déploiement, changement du builder ou du level-up.
#### Clôture du 13C dans le périmètre clarifié — 20 octobre 2026

**13C terminé dans le périmètre explicitement défini par le MJ.** Cette décision
remplace les statuts intermédiaires « non clos » conservés plus haut. Elle ne
signifie ni couverture d'un futur moteur de capacités, ni préchargement d'un
catalogue officiel complet. Les fonctionnalités antérieures sont conservées.

| Livré et vérifié | Portée exacte |
|---|---|
| Politiques et acquisitions | Versions explicites, budgets distincts, niveau réel de classe, listes/écoles et exceptions de choix ; propriétaire du quota indépendant de la liste empruntée et de la classe de lancement. |
| Modes connus, grimoire et préparés | Acquisitions/reconstruction/reprise et copies selon leur contrat ; accès de liste et toujours-préparés calculés séparément. Aucun apprentissage massif d'une liste préparée. La préparation quotidienne et son état ne font pas partie de ce jalon. |
| Effets de sous-classes | Attributions configurées avec seuils, provenance, quota explicite et visibilité ; substitutions individuelles, correction, révocation et transition append-only. Les vues historiques n'anticipent plus les niveaux ultérieurs. |
| Occultiste | Éligibilité ordinaire de Magie de pacte limitée à 1–5, séparée des Arcanums 6–9 ; opportunités propres, résultat `cast` hors quota connu, remplacement sous la même règle d'origine. Aucun nouveau pool d'emplacements ou compteur créé. |
| Artificier | Table préparée INT, niveaux de sorts propres, mineurs aux niveaux 1/10/14 et remplacement à partir du niveau 2 ; base de préparation par demi-niveau de classe. |
| Druide et Ensorceleur | Politiques ordinaires, recette du mineur supplémentaire du Cercle de la Terre, attributions fixes configurables et recettes de substitutions psioniques/horlogères avec écoles et listes explicites. Aucun rapprochement par nom ou slug. |
| Règles de sorts Tasha déjà implémentées | Activation explicite dans la version de politique, confirmation de chaque remplacement facultatif ; fréquence aux niveaux ASI, budget partagé des alternatives de sorts concernées et respect de la provenance du mineur. Aucun droit rétroactif accordé aux personnages. |
| Administration | Formulaires guidés et JSON avancé conservés ; OFFICIAL administrateur, CUSTOM propriétaire ; décisions sur personnage réservées à son MJ. |

Le report porte **uniquement sur les choix de capacités non représentés** :
manifestations/invocations occultes, pactes, métamagies, styles de combat et autres
sélections alternatives. Leurs prérequis, choix/remplacements et historique seront
portés par le futur moteur transversal. Aucun de ces choix n'est inventé depuis
la seule classe ou une description. Les apprentissages, remplacements de sorts,
Arcanums et substitutions autonomes restent livrés au 13C ; ils ne sont pas reportés.

Raccordements futurs à respecter :

1. Le moteur de capacités fournira une sélection effective, son identifiant et sa
   version figée, sa source, sa classe d'acquisition et sa position historique.
   Ne pas détourner `SpellSubclassGrant` pour faire passer une invocation pour une
   sous-classe ; étendre les sources avec de vraies références lorsque nécessaire.
2. Le moteur de sorts conservera une voie par provenance : connu, toujours préparé,
   extension de liste ou accès particulier. Une voie de capacité ne devra pas créer
   implicitement une acquisition ordinaire, ni consommer le quota d'une autre classe.
3. Les alternatives capacité/sort d'un même choix facultatif devront partager un
   budget transactionnel unique. Le futur changement de pacte ou de Métamagie devra
   consulter les décisions de remplacement de sorts déjà enregistrées, et inversement.
   Le moteur actuel ne prétend pas contrôler une sélection de capacité inexistante.
4. Révocation et transition devront préserver l'historique, vérifier les dépendances
   et les permissions, et ne retirer que la voie issue de la capacité concernée.
   Une autre acquisition du même sort devra rester indépendante. Aucun reset de
   session, ressource ou progression ; pas de recherche implicite d'une session.

Dernières vérifications ciblées : `test-character-spell-acquisitions.php
--concurrency` **438 assertions OK**, `test-character-spell-access.php`
**109 assertions OK**, `doctrine:schema:validate` **mapping et schéma OK**,
`git diff --check` **OK**. Fixtures synthétiques uniquement, schéma de concurrence
isolé nettoyé ; les refus HTTP affichés sont attendus. Les résultats Angular
antérieurs ne sont pas présentés comme relancés : aucun changement d'interface.

Cette clôture ne nécessite aucune nouvelle correction de code ni migration.
`Version20261020130000` reste appliquée et validée en développement. Seule la
roadmap est modifiée pour cette clarification ; les modifications précédentes
restent intactes. Les configurations de catalogue absentes restent à renseigner
explicitement dans les éditeurs : aucun import ou seed n'est inclus. Aucune
ouverture des jalons 13D/13E, aucun commit, push ou déploiement.