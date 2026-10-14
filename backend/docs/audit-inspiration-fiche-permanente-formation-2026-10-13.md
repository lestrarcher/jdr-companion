# Audit — Inspiration, fiche permanente et formation

> **État de livraison — 14 octobre 2026.** Voir l'[état consolidé](artificer-proficiencies-2014.md#état-de-livraison--14-octobre-2026).
> Migration Bricolages et import artificier réalisés localement ; tests manuels
> LIVE/CLOSED/LIVE satisfaisants, selon confirmation du MJ. Résolution du 503
> médias non confirmée en production. Aucun déploiement effectué.
>
> **Historique :** le contenu ci-dessous conserve les constats, tests et consignes
> du lot à sa date de rédaction. Les mentions d'opérations encore à autoriser,
> de validation manuelle restante ou de l'ancien écran fermé sont historiques.
> Aucun import ni migration supplémentaire n'a été exécuté pour les commits.


Date : 13 octobre 2026. Dépôt examiné : `feature/character-creation`, HEAD
`7420387` (`feat(character): add training choices and multiclass proficiencies`).

## 0. Périmètre, méthode et niveau de preuve

Audit uniquement. Aucun fichier applicatif modifié, aucune migration, aucun
import, aucune écriture SQL, aucun commit/push. Les modifications préexistantes
de l'importeur d'historiques et ses quatre nouveaux fichiers sont conservés,
ainsi que `frontend-architecture.txt`. Ce rapport est le seul fichier ajouté
pendant cet audit.

L'import réel réussi des 19 historiques est une information fournie par le MJ.
La base n'a pas été interrogée : les totaux ci-dessous décrivent les catalogues
versionnés, pas un inventaire certifié des lignes actuellement en base.
Les endpoints et permissions ont été lus dans le code, pas exercés sur des
personnages réels. Deux sondes PHP ont été exécutées avec les fixtures en
mémoire, sans Kernel ni connexion SQL ; résultats en section 6.

Légende : **constaté** = code ou catalogue lu ; **reproduit** = sonde en mémoire ;
**risque** = scénario à tester, pas un incident observé ; **proposition** = nécessite
validation avant développement. Les anciens résultats de tests documentés ne
sont pas présentés comme une nouvelle exécution.

Sorts, métamagie et manifestations occultes sont exclus. Les endpoints d'actions
existants sont cités seulement pour cartographier les contrôles d'accès communs,
sans audit de leurs règles de jeu.

## 1. Synthèse

1. L'inspiration 2014 est déjà correctement **binaire, persistante et globale au
   personnage**. Ne pas la reconstruire sous forme de points. Son API MJ existe,
   mais aucun contrôle Angular d'attribution/retrait n'a été trouvé.
2. La consultation hors session LIVE est déjà autorisée par le GET joueur
   si la participation existe encore. C'est Angular qui cache la fiche. En
   revanche, un personnage sans session n'a pas de lien joueur permanent.
3. Les mutations joueur principales vérifient LIVE côté serveur, à nouveau
   sous verrou. L'ancienne note de dette « PATCH global sans sécurité » n'est
   donc plus une description exacte du code actuel.
4. L'actualisation est incomplète : les transitions de statut de session ne sont
   pas suivies correctement par le polling joueur ; les ajouts manuels de
   formation ne modifient pas les révisions observées par ce polling.
5. Le moteur de formation réutilisable existe. La couverture des capacités
   dans les fichiers d'import n'est pourtant pas exhaustive : seulement trois
   règles dans le catalogue dédié aux capacités (Chantelame/Assassin).
6. Le futur retrait MJ doit viser une **attribution identifiée**, pas supprimer
   une maîtrise effective ni une règle partagée. Historique et autres sources
   doivent survivre.

## 2. Inspiration

### 2.1 Tableau de constat

| Sujet | Existant vérifié | Manquant | Anomalie / risque | Recommandation |
|---|---|---|---|---|
| Modèle | `Character::$inspiration`, booléen `false` par défaut ; migration dédiée | Aucun modèle supplémentaire nécessaire | Le nom « points » suggérerait à tort une réserve | Conserver booléen et distinguer l'Inspiration bardique, ressource différente |
| Persistance | Valeur canonique en colonne du personnage ; `CharacterSessionState::getState()` la projette | Journal des changements | Pas de copie canonique dans chaque JSON de session | Garder cette séparation ; journal seulement si souhaité |
| MJ | PATCH dédié, voter MANAGE, révision obligatoire, valeur booléenne | Contrôle Angular trouvé nulle part ; API exige une participation de session | Impossible avec ce endpoint d'accorder à un personnage jamais inscrit dans une session | Ajouter ultérieurement une commande MJ par personnage, avec politique/révision explicites |
| Joueur | PATCH `state.inspiration=false`, bouton Dépenser ; refus `false→true` | Transfert à un autre personnage, rattachement à un jet | `false→false` est accepté comme mise à jour inoffensive : un HTTP 200 ne prouve pas une dépense effective | Ne pas déclencher un avantage automatique à partir d'un simple 200 |
| Concurrence | Verrou personnage, session, état ; changement touche toutes les révisions d'états du personnage | Tests complémentaires avec création d'une nouvelle participation et retrait concurrent | Pas de double dépense démontrée sur le chemin principal ; tests existants couvrent deux tokens | Réutiliser ce protocole, ne pas gérer le booléen seulement côté navigateur |
| Repos / sessions | Factory, synchronizer, getters relisent le booléen du personnage ; repos ne l'effacent pas | — | Risque futur d'une remise à zéro par réinitialisation globale | Tests permanents de non-régression |
| Synchronisation | Réponse PATCH immédiate ; polling joueur toutes les 5 s en LIVE | Actualisation symétrique du panneau MJ et suivi fiable du statut | Le panneau MJ recharge ses personnages au chargement/à la demande ; son timer concerne les repos | Rafraîchissement commun versionné, puis événements éventuels |

### 2.2 Contrats et références

- `backend/src/Entity/Character.php:34` — `hasInspiration()`, `setInspiration()`.
- `backend/migrations/Version20261012150000.php:19` — colonne booléenne NOT NULL,
  défaut false. Présence du fichier vérifiée, état d'application non interrogé.
- `backend/src/Entity/CharacterSessionState.php:110` — `getState()` ; le constructeur
  et `setState()` retirent la clé du JSON stocké pour éviter deux autorités.
- `backend/src/Service/CharacterInspirationService.php:20` — `changeLocked()` :
  exige une transaction ; contrat du caller = verrou personnage déjà pris.
  Verrouille les états par ID, touche leur date et donc leur révision Doctrine.
- `backend/src/Controller/CharacterInspirationController.php:20` —
  `PATCH /api/sessions/{sessionId}/characters/{characterId}/inspiration`,
  body `{ "inspiration": true, "revision": 4 }`, false pour retirer.
  MJ propriétaire via `CampaignVoter::MANAGE`, participation requise ; LIVE non
  requis pour ce chemin MJ. Révision périmée : 409, booléen invalide : 422.
- `backend/src/Controller/CharacterSessionStateController.php:655` — `updatePublic()` :
  `PATCH /api/public/characters/{accessToken}`, body
  `{ "state": { "inspiration": false }, "revision": 4 }`.
- `backend/src/Service/PlayerCharacterStateUpdater.php:155` — `merge()` : interdit
  l'attribution joueur, accepte l'écho inchangé de true ou false. La fusion a lieu
  avant transaction, mais révision et accès sont revérifiés sous verrou.
- `frontend/src/app/features/player-portal/player-portal.ts:247` —
  `spendInspiration()` bloque un envoi local concurrent et recharge sur conflit.
  Double affectation identique du signal aux lignes 265–266 : redondance bénigne.
- `backend/docs/inspiration-api.md` — contrat préexistant cohérent avec ces points.

### 2.3 Cohérence D&D 2014

L'inspiration est possédée ou non, sans cumul ; sa dépense accorde l'avantage
sur un jet concerné. Les règles 2014 permettent aussi de céder son inspiration
à un autre personnage. Le booléen actuel est conforme ; le transfert n'est pas
implémenté. L'application marque une dépense, elle ne prouve pas qu'un jet a
été effectué. Aucun système de réserve maison n'a été trouvé.
[Référence officielle 2014, sections Inspiration et Using Inspiration](https://www.dndbeyond.com/sources/dnd/basic-rules-2014/personality-and-background#Inspiration).

Le retrait MJ et l'autorisation hors LIVE sont des choix applicatifs existants,
à conserver ou revoir séparément ; ne pas appliquer d'office au MJ la nouvelle
politique de lecture seule du joueur.

## 3. Fiche permanente, consultation et autorisations

### 3.1 Tableau de constat

| Sujet | Existant vérifié | Manquant | Anomalie / risque | Recommandation |
|---|---|---|---|---|
| Identité durable | `Character` indépendant des sessions ; profil sérialisable | Identité/lien d'accès joueur indépendant des sessions | `playerName` est un texte, pas un compte joueur ni une autorisation | Credential de personnage distinct, révocable ; décider lien secret ou compte |
| Lien actuel | Token aléatoire de 64 caractères dans `CharacterSessionState`, unique | Lien pour personnage sans aucune session | Désinscription rend le lien invalide ; suppression session détruit son état par cascade | Ne pas utiliser un ancien token de session comme identité permanente |
| GET hors LIVE | `showPublic()` n'exige que participation et token valides | Vue Angular consultable ; contrat sans état de session | HTML remplace toute la fiche par « session terminée » | Réutiliser les composants de lecture, séparer shell de jeu |
| Mutations joueur | `PlayerCharacterAccess::lockForMutation()` vérifie participation et statut LIVE sous verrou | Matrice centralisée des capacités de l'UI ; harmonisation de quelques chemins historiques | Un champ frontend readonly ne protège pas une API | Garder garde serveur, publier capacités indicatives et les revérifier à chaque écriture |
| État affiché | PV, ressources et progressions sont par session ; inspiration globale | Politique d'affichage hors session et en multi-session | « Dernière session » choisie implicitement donnerait un contexte arbitraire | Profil permanent sans état, ou snapshot de session explicitement choisi et daté |
| Statut live/closed | GET renvoie `session.status` | Actualisation indépendante de `state.revision` | Le poll ignore le statut et s'arrête si le statut local n'est pas LIVE | Contrôler statut et permissions même si la révision de l'état est identique |
| Création / niveaux | Builder atomique ; niveau joueur autorisé par MJ ; calcul multiclassage existant | Adaptation de navigation vers le lien durable | Un lien permanent ne doit pas autoriser un level-up hors LIVE par accident | Garder les services, transactions et validations actuels |
| Confidentialité | Voters MJ ; profil public masque les détails des attributions manuelles | Révocation dédiée d'un lien permanent | Un bearer token partagé est une autorisation ; ne pas exposer les autres tokens ou notes MJ | Conserver sérialisation publique filtrée ; portée de lecture clairement définie |

### 3.2 Parcours et contrôles actuels

Les routes Angular `frontend/src/app/app.routes.ts:20` et `:30` portent toutes
`campaignId`, `sessionId`, `accessToken` : portail et level-up sont liés à une
session. `PlayerPortal` vérifie les trois paramètres (`player-portal.ts:187`),
charge le GET public et contrôle la correspondance de session. Ce n'est pas une
route générale de fiche de personnage.

`backend/src/Service/PlayerCharacterAccess.php:22`, `requireParticipating()` :
token absent ou participation retirée = 404. `lockForMutation()` (`:35`) :
verrous personnage → session en lecture → état en écriture, refresh, puis
participation et LIVE revérifiés ; hors LIVE = 409.

| Endpoint joueur existant | Lecture / mutation | Contrôle observé |
|---|---|---|
| GET `/api/public/characters/{token}` | Lecture | Participation, pas LIVE — `CharacterSessionStateController::showPublic`, ligne 640 |
| PATCH même URL | Jeu | Participation, LIVE avant et sous transaction, révision — `updatePublic`, ligne 655 |
| GET `…/level-up/options` | Préparation d'une modification | LIVE et `levelUpAllowed` — même contrôleur ligne 500 |
| POST `…/level-up` | Build permanent | Mêmes contrôles, puis verrou et revalidation dans `CharacterLevelUpService::levelUp`, ligne 62 |
| GET/PATCH `…/wallet` | Lecture / jeu | Lecture participante ; écriture LIVE avec garde transactionnelle — `CharacterWalletController`, lignes 38/69/101 |
| GET/PATCH `…/magic-items…` | Lecture / jeu | Même distinction — `PublicCharacterMagicItemController`, lignes 28/51/81 |
| POST `…/rest-requests`, GET `…/rest-requests/latest` | Demande de jeu / lecture | POST LIVE et garde sous verrou ; GET participant — `RestRequestController`, lignes 27/64/139 |
| Actions existantes | Jeu | Vérifications LIVE présentes ; détails des règles de jeu hors périmètre |

Les chemins historiques `CharacterActionController` lignes 89 et 399 prennent
le verrou session et revérifient LIVE sans passer par le helper commun. La
protection contre fermeture existe : il ne faut pas annoncer un contournement
prouvé. En revanche, participation, ordre global des verrous et états chargés
doivent être harmonisés/testés contre un retrait concurrent. Audit limité aux
contrôles d'accès, pas aux effets des actions.

`GameSessionController::update()` (`backend/src/Controller/GameSessionController.php:198`)
modifie le statut de session sans toucher les révisions des états personnage.
Or `PlayerPortal::refreshCharacterIfChanged()` (`player-portal.ts:541`) :

1. ne demande rien si le statut local n'est pas LIVE ;
2. ignore une réponse dont la révision d'état est inchangée ;
3. même quand elle a changé, ne met pas à jour `sessionStatus`.

Conséquence constatée par lecture : ouvrir une session dont le portail affiche
déjà DRAFT, ou fermer une session ouverte sur un téléphone, ne bascule pas
fiablement l'interface via ce polling. Une recharge ou une erreur d'écriture
peut rattraper la situation. Le serveur reste l'autorité et refuse l'écriture
fermée ; cela n'excuse pas un affichage resté modifiable.

`frontend/src/app/features/player-portal/player-portal.html:17` masque toute
la fiche hors LIVE. Les composants de statistiques et capacités sont déjà
consultatifs ; ceux qui modifient l'état devront recevoir un droit d'édition.
La bourse possède déjà un input `disabled` (`components/character-wallet/character-wallet.ts:42`).
`CharacterStateService` gère état local et BroadcastChannel, pas les permissions
serveur. Le portail désactive l'adoption de broadcasts non versionnés lors de
son initialisation (`player-portal.ts:435`).

Le panneau MJ `SessionCharacters::ngOnInit()` charge une fois (`:226`) et
`refreshCharacters()` recharge explicitement (`:432`). Le timer du dashboard
(`control-dashboard.ts:664`) ne récupère que les demandes de repos. Ni Mercure
ni synchronisation automatique complète MJ/joueur ne peuvent être considérés
comme acquis à partir de ce code.

### 3.3 Architecture proposée, sans implémentation

**Séparer quatre responsabilités** : profil permanent, état contextualisé,
autorisation serveur, contrôles d'édition Angular.

1. **Accès durable** : lien/token dédié au personnage, distinct du token de
   session. Hypothèse minimale proposée : accès bearer révocable, sans comptes
   joueurs nouveaux pour ce lot. Exemples de routes à valider : Angular
   `/player/characters/:sheetToken`, GET `/api/public/character-sheets/{sheetToken}`.
   Les anciennes URL de session continuent de fonctionner. Un retrait de
   participation n'efface pas le droit de lire la fiche ; une révocation du
   lien permanent le fait.
2. **Lecture** : réutiliser `CharacterProfileSerializer::serialize()`
   (`backend/src/Service/CharacterProfileSerializer.php:32`) et
   `CharacterTrainingPresenter::resolved()`. Sans session : pas de création
   de `CharacterSessionState`, pas de lookup arbitraire du plus récent.
   `sessionContext` peut être null ; les valeurs courantes restent absentes,
   ou proviennent d'un snapshot explicitement demandé, selon décision MJ.
3. **Contexte de jeu** : si une session éligible est sélectionnée, réutiliser
   `CharacterSessionStateSerializer::serialize()` (`:23`) et les endpoints
   métier existants. La liaison personnage/session doit être vérifiée côté
   serveur ; un paramètre sessionId seul n'accorde aucun droit. S'il existe
   plusieurs sessions LIVE, demander un contexte explicite plutôt que choisir
   la première. Ne pas publier tous les tokens historiques avec le profil.
4. **Autorisations** : exposer, à titre indicatif pour l'UI, par exemple
   `canView`, `canPlay`, `canLevelUp`, raison de lecture seule et état de session.
   Chaque mutation doit encore vérifier credential, participation, LIVE,
   permission spécifique et révision sous transaction. La politique MJ reste
   séparée, conservée jusqu'à arbitrage.
5. **Angular** : extraire/réutiliser la présentation de `PlayerPortal`,
   `CharacterStatistics` et `CharacterFeatures`, sans copier une deuxième fiche.
   Désactiver l'autosave, pas seulement les boutons, quand `canPlay=false`.
   Actualiser statut/permissions indépendamment de la révision des valeurs.
   Utiliser une requête de refresh sérialisée/annulable pour éviter des réponses
   anciennes arrivant après une sauvegarde récente.
6. **Progressions** : les capacités dépendantes de seuils utilisent un état
   explicite. Sans contexte, elles ne doivent pas être arbitrairement actives.
   `CharacterFeatureResolver::resolve()` (`:30`) et le sérialiseur existant
   reçoivent déjà les valeurs ; conserver cette chaîne. Une fiche hors session
   devra expliquer cette absence de contexte, pas inventer une valeur.

Ne pas déplacer dans ce lot tous les PV/ressources/progressions vers `Character`.
`CharacterSessionStateFactory` initialise un nouvel état de session ;
`CharacterSessionStateController::create()` (`:88`) réactive en revanche le
même état et token quand la participation existe. La continuité entre deux
sessions différentes exige une décision distincte de la consultation permanente.

## 4. Maîtrises, langues, expertises et ajustements MJ

### 4.1 Tableau par source et mécanisme

| Source / sujet | Existant vérifié | Manquant | Anomalie / risque | Recommandation |
|---|---|---|---|---|
| Espèce et sous-espèce | `CharacterProficiencyResolver::isApplicable()` remonte les parents ; options/choix par règle | Traits à alternative et attributions temporaires listés dans la doc raciale | Ne pas importer une compétence temporaire comme définitive | Conserver parentage et exclusions explicites |
| Langues raciales | `CharacterRaceMetadataResolver` fusionne les langues, hérite/remplace le nombre de choix ; clé de choix de la race sélectionnée | Choix de traits complexes | Changement de race rend l'ancien sourceKey inactif, même avec un ancêtre commun | Conserver l'historique et demander seulement les choix applicables manquants |
| Historique | 13 PHB + 19 supplémentaires ; auto/choix, langues, substitutions | Outils groupés, véhicules, dons Bigby et exceptions déjà documentés | Ne pas confondre « import réussi » avec tous les bénéfices représentés | Réutiliser le rapport des suppléments |
| Classe initiale | 69 règles de classes ; mode STARTING_CLASS basé sur position globale 1 | Références hors périmètre des 12 classes, notamment formation initiale d'artificier dans ce catalogue | L'artificier existe dans le catalogue de capacités mais pas dans ce lot d'attributions de départ | Compléter les données après audit d'édition, sans changer les calculs |
| Multiclassage | Mode MULTICLASS distinct ; niveaux de classe existants ; ANY indépendant du contexte | Pas de refonte nécessaire | Attribuer STARTING_CLASS à une classe secondaire serait une régression | Garder `getLevelInClass()` et position 1 |
| Sous-classe / capacités | Sources de sous-classe et niveau fonctionnent ; Chantelame Tasha et Assassin structurés | Couverture générale des autres capacités dans les fichiers | Une capacité descriptive résolue n'accorde pas automatiquement de formation | Importer des règles explicites et relier leur provenance descriptive |
| Dons | Source `feat` pour maîtrises et expertises ; `hasFeat()` requis | Catalogue complet des bénéfices de dons ; langue via don non représentée | Présence d'un don descriptif ne prouve pas ses effets | Garder l'avertissement pour les dons incomplets ; aucun auto-grant nouveau |
| Langues de classe | Druidique et argot des voleurs structurés ; règles de classe/historique | `LanguageGrantRule` n'a pas de source sous-classe/don, ni sélection couplée à une capacité | Ne pas déplacer une langue de sous-classe vers toute la classe | Extension minimale future seulement pour effets permanents vérifiés |
| Expertises | Règles barde 3/10, roublard 1/6 ; source sous-classe/race/don dans l'entité ; maîtrise préalable | Couples « compétences choisies puis expertise de ces mêmes compétences » | Recouvrement auto/choix peut invalider tout un lot valide en partie | Définir conflits et dépendances de choix, ne pas empiler les bonus |
| MJ manuel | Ajout maîtrise/expertise/langue ; auteur, motif, date ; sources séparées | Retrait/révocation, état actif, idempotence inter-requêtes, UI | Deux requêtes identiques créent deux traces ; aucune révision session touchée | Retrait par attribution, journal append-only, versionnement/synchronisation |
| Doublons | Une maîtrise effective par slug, toutes les règles d'origine conservées | Normalisation groupe d'armes → armes individuelles éventuelle | Un groupe et une arme peuvent apparaître côte à côte ; ce ne sont pas deux bonus | Conserver les origines, ne pas supprimer les attributions parce qu'un groupe couvre une arme |
| Remplacements | Explicitement limités aux doublons d'historique SKILL/TOOL | Édition/rattrapage des substitutions après création | PATCH choices exclut substitutions ; collision avec attribution manuelle ignorée dans `resolveBase()` | Décider parcours MJ et politique, sans généralisation arbitraire |
| Bonus affichés | Caractéristique + bonus une fois, doublé pour expertise ; bonus basé sur niveau total | Modificateurs particuliers/conditionnels, compétences privées hors 18 slugs, marquage expertise outils | Valeurs de base ne représentent pas tous les bénéfices descriptifs | Étiqueter limites ; tests dédiés plutôt que faux bonus permanents |

### 4.2 Références techniques précises

- `backend/src/Service/CharacterProficiencyResolver.php:25` — `resolve()` :
  substitutions puis attributions manuelles ; `resolveBase()` (`:54`) : règles
  visibles/applicables et choix complets ; `isApplicable()` (`:95`) : niveaux,
  acquisition, sous-classe, ascendance, historique et dons.
- `backend/src/Service/CharacterProficiencyChoiceService.php:21` — `validate()` ;
  `replaceChoices()` (`:50`) retient les lignes inchangées d'une règle.
- `backend/src/Entity/CharacterProficiencyChoice.php:11` et
  `CharacterExpertiseChoice.php:7` — unicité personnage/règle/définition.
  `CharacterLanguageChoice` utilise un sourceKey ; pas une simple liste globale.
- `backend/src/Service/CharacterTrainingService.php:17` — `applyChoices()` accepte
  seulement trois familles de choix. `apply()` (`:27`) gère aussi substitutions
  et attributions manuelles ; le caller porte transaction et accès.
- `backend/src/Service/CharacterTrainingResolver.php:14` — `applicable()` ;
  `languageRequirements()` (`:32`) réutilise exclusivement les métadonnées pour
  les races ; `resolve()` (`:62`) réunit langues et expertises effectives.
- `backend/src/Entity/LanguageGrantRule.php:19` — sources classe/historique
  seulement ; `ExpertiseGrantRule` a davantage de sources. Aucune analyse du
  texte des capacités dans ces résolveurs.
- `backend/src/Service/CharacterTrainingReferences.php:13` — `load()` applique la
  visibilité du MJ. `ReferenceVisibility::allows()` protège aussi les dépendances.
- `backend/src/Service/CharacterTrainingPresenter.php:11` — `resolved()` expose
  définitions et origines ; `options()` (`:39`), `choices()` (`:95`) exposent
  options autorisées, choix enregistrés et états missing/invalid/complete.
- `backend/src/Service/CharacterProfileSerializer.php:54` — réutilise ce contrat
  public avec `includeManualGrantDetails=false`, sans exposer les motifs MJ.
- `backend/src/Service/CharacterLevelUpService.php:62` et `:119` — garde joueur,
  application des nouveaux choix, rollback si les nouveaux choix sont incomplets ;
  les anciens manques ne bloquent pas arbitrairement la progression.
- `backend/src/Service/CharacterSessionStateSynchronizer.php:226` —
  `synchronizeAfterLevelUp()` maintient les états existants.
- `backend/src/Controller/CharacterTrainingController.php:36` — PATCH
  `/api/characters/{id}/training/choices` avec verrou et invalidation des révisions.
  `grant()` (`:63`), POST `/api/characters/{id}/manual-grants`, n'a pas cette
  invalidation ; voter MANAGE présent. Aucun DELETE d'attribution trouvé.
- `backend/src/Entity/CharacterManualGrant.php:12` — type, cible, motif, auteur,
  createdAt ; pas de révocation ni clé d'idempotence. `Character::addManualGrant()`
  (`backend/src/Entity/Character.php:192`) déduplique l'objet, pas sa cible métier.
- `frontend/src/app/features/player-portal/components/character-statistics/character-statistics.ts:119`
  — `build()` : modificateur + bonus × 0/1/2 ; 18 compétences et 6 sauvegardes
  définies localement. `otherTraining` (`:78`) affiche les noms d'outils mais pas
  leur expertise. `Character::getProficiencyBonus()` (`Character.php:523`) utilise
  le niveau total : ne pas le remplacer par le niveau d'une seule classe.

Le bonus de maîtrise ne s'additionne pas plusieurs fois du fait de sources
multiples ; une expertise double le bonus sans donner implicitement une
maîtrise. [Règles officielles 2014, Proficiency Bonus](https://www.dndbeyond.com/sources/dnd/basic-rules-2014/using-ability-scores#ProficiencyBonus).
Le remplacement de doublons de compétence/outil figure dans la section des
historiques ; le périmètre applicatif actuel reste explicitement l'historique,
conformément aux décisions antérieures, pas tous les effets du jeu.
[Règles officielles 2014, Backgrounds](https://www.dndbeyond.com/sources/dnd/basic-rules-2014/personality-and-background#Backgrounds).

### 4.3 Couverture réelle des fichiers de référence

| Fichier dans `backend/data/reference/` | Contenu lu |
|---|---|
| `dnd-2014-class-proficiencies.json` | 69 règles, 12 classes PHB |
| `dnd-2014-race-proficiencies.json` | 63 entrées de périmètre, 29 règles ; certaines entrées n'accordent rien ou documentent une limite |
| `dnd-2014-feature-proficiencies.json` | 3 règles : Chantelame Tasha niveau 2 (auto + arme à choisir), Assassin niveau 3 (outils) |
| `dnd-2014-training.json` | 18 langues, 8 règles de langue classe/PHB, 4 règles d'expertise barde/roublard |
| `dnd-2014-backgrounds.json` | 13 historiques PHB, 26 règles |
| `dnd-2014-backgrounds-supplements.json` | 19 historiques, 34 règles de maîtrises, 18 de langues ; compléments, pas doublons PHB |

`ProficiencyGrantRule` ne référence pas `CharacterFeatureDefinition` : elle
référence la classe/sous-classe/race/don/historique. Les deux catalogues peuvent
donc dériver : description/niveau d'une capacité changés sans changement de la
règle de maîtrise. `FeatureProficiencyImportPlanner::plan()` (`:13`) ne crée pas
de relation à une capacité. Prévoir un contrôle de cohérence éditorial ou une
référence de provenance si nécessaire, pas un moteur d'interprétation du texte.

Capacités présentes dans `dnd-2014-class-features.json`, **non couvertes par le
catalogue dédié de trois règles** et à examiner lors d'un futur lot :

| Famille / niveau inscrit dans les featureRules | Slugs présents, à recouper avant attribution |
|---|---|
| Artificier, sous-classes 3 et classe 6 | `alchemist-tool-proficiency`, `artillerist-tool-proficiency`, `battle-smith-tool-proficiency`, `tool-expertise` |
| Barde, collèges 3 | `college-of-lore-bonus-proficiencies`, `college-of-valor-bonus-proficiencies`, `college-of-swords-bonus-proficiencies` |
| Clerc, domaines 1 | `knowledge-domain-blessings-of-knowledge`, `life-domain-bonus-proficiency`, `nature-domain-bonus-proficiency`, `tempest-domain-bonus-proficiencies`, `war-domain-bonus-proficiencies`, `forge-domain-bonus-proficiency`, `order-domain-bonus-proficiencies`, `twilight-domain-bonus-proficiencies` |
| Guerrier, sous-classes 3 | `arcane-archer-arcane-archer-lore`, `cavalier-bonus-proficiency`, `samurai-bonus-proficiency`, `rune-knight-bonus-proficiencies` |
| Moine, sous-classe 3 | `drunken-master-bonus-proficiencies` |
| Roublard, sous-classes 3 | `mastermind-master-of-intrigue`, `scout-survivalist` |

Ce tableau est une file de vérification des données, **pas une transcription
de leurs effets officiels**. Plusieurs descriptions locales sont génériques
ou vides : leur nom ne suffit pas à inventer options, langues ou substitutions.
La ligne `tool-expertise` décrit explicitement un doublement pour les outils
déjà maîtrisés ; son modèle d'expertise pourrait réutiliser une règle automatique
filtrée par maîtrise préalable après vérification de la source.

Ne pas classer automatiquement comme maîtrises permanentes les noms voisins :
Maîtrise chimique, Divination experte, Langue d'argent, Entraînement exceptionnel,
Langage du soleil et de la lune. Les effets conditionnels, temporaires ou
surnaturels ne sont pas des langues/maîtrises fixes. Les restrictions raciales
déjà documentées dans `dnd-2014-race-proficiencies.md:73` restent applicables
(Trance, Astral Knowledge, Stonecunning, legs ancestraux, choix de trait).
La langue d'Ennemi juré reste une attribution MJ explicite, décision conservée.

### 4.4 Anomalies et cas limites à traiter avant l'interface MJ

**A. Ajout manuel non idempotent — reproduit.** Deux applications du même
payload produisent deux `CharacterManualGrant` mais une seule maîtrise
effective. Pas de bonus doublé ; audit pollué et retrait ambigu. La validation
`manualSeen` ne couvre qu'une requête. Préférer une clé de commande idempotente
si plusieurs motifs légitimes peuvent accorder la même maîtrise.

**B. Ajout manuel non diffusé — constaté.** Le POST manual-grants ne touche
aucun `CharacterSessionState` contrairement au PATCH choices. Le GET recalculera
la bonne formation, mais le portail peut jeter sa réponse parce que la révision
reste identique. L'UI ne doit pas annoncer une synchronisation acquise.

**C. Recouvrement d'expertises — reproduit sur fixture synthétique.** Roublard
ayant choisi Discrétion et outils de voleur : une règle automatique applicable
sur Discrétion, triée avant sa règle de choix, conduit au `continue 2` de
`CharacterTrainingResolver::resolve()` et invalide tout le lot. Les outils de
voleur perdent alors leur expertise effective, bien que les deux choix soient
encore stockés. Le catalogue actuel de quatre règles ne contient pas cette
règle automatique de test ; c'est un défaut latent pour l'extension aux capacités.
Conserver les origines et ne pas supprimer arbitrairement les autres choix.

**D. Prérequis après modification du build — risque confirmé par lecture.**
Le résolveur rend inactive une expertise sans maîtrise. Mais `apply()` repasse
sur toutes les expertises manuelles et lève une exception si une maîtrise a
disparu : un ancien grant peut bloquer une modification de choix sans rapport.
Définir « inactif faute de prérequis » ou « retrait/revalidation exigé », puis tester.

**E. Remplacement et manuel.** Les substitutions sont calculées avant les
grants manuels et avec `resolveBase()` : un doublon historique + manuel ne
propose pas de remplacement. Inversement une cible de substitution déjà accordée
manuellement n'est pas écartée par ce calcul. C'est une limite de périmètre à
arbitrer, pas une invitation à remplacer tous les doublons automatiquement.
Après disparition de la source justifiant le doublon, la substitution historique
reste stockée mais perd son effet. Ce comportement doit être expliqué dans l'UI.

**F. Substitutions hors création.** PATCH choices refuse ce champ et POST
manual-grants ne l'accepte pas ; pas de parcours d'édition identifié. `apply()`
ajoute une nouvelle ligne de substitution à chaque occurrence ; la contrainte
unique personnage/règle/original empêcherait une répétition déjà persistée.
Un futur endpoint doit remplacer/retirer explicitement, pas réutiliser ce chemin
d'insertion tel quel pour un formulaire d'édition.

**G. Bonus et rendu.** Les bonus standards affichés sont cohérents pour les
18 compétences connues. Touche-à-tout (`jack-of-all-trades`, barde 2) ne participe
pas à la formule ; les bonus conditionnels non structurés non plus. L'initiative
et la perception passive sont étiquetées de base dans le composant. Une expertise
d'outil est dans le JSON mais pas distinguée dans sa liste de noms. Une compétence
personnalisée avec un autre slug n'apparaît pas dans les 18 lignes prédéfinies.

### 4.5 Proposition d'interface MJ

Panneau de jeu dans la fiche MJ du personnage, réutilisant GET training et
training/options ; administration de définitions séparée. Aucun CRUD de
référentiel privé à développer dans ce lot.

- Une ligne par définition effective, avec état maîtrisé/expertise, et liste
  dépliable de ses attributions : race, historique, classe, sous-classe, don,
  choix ou manuel. Afficher aussi les choix manquants et les attributions
  historiques inactives, distinctes des bénéfices actuels.
- Ajout manuel : type, définition visible, motif obligatoire. Une expertise
  exige une maîtrise effective. Langue secrète possible par attribution MJ
  explicite, pas par choix ordinaire. Prévisualiser l'effet sur les bonus.
- Retrait : révoquer une attribution manuelle par ID, avec auteur/date/motif de
  révocation. Si une autre source accorde encore la définition, annoncer
  « attribution retirée, maîtrise conservée ». Ne jamais effacer une règle globale.
- Ne pas proposer immédiatement « retirer toutes les maîtrises de cette ligne ».
  Neutraliser une attribution automatique est une fonctionnalité distincte
  (suppression locale/override), nécessitant décision et trace propres.
- Concurrence : version attendue, verrou personnage, invalidation des états,
  commande idempotente ; réponse utilisant le présentateur existant. Les motifs
  et auteurs restent dans la vue MJ, comme aujourd'hui.

## 5. Plan d'implémentation proposé

Chaque étape nécessite validation et peut être livrée/testée séparément.

| Étape | Livrable borné | Dépendance / critères de sortie |
|---|---|---|
| 1 — Fiabilité de refresh | Suivi session.status/permissions indépendant des révisions ; empêcher les snapshots tardifs de remplacer un état récent | Pas de nouveau modèle ; tests ouverture/fermeture, conflit, réponse retardée |
| 2 — Inspiration MJ | Boutons accorder/retirer sur API existante, état visible MJ et joueur | Garder booléen ; décider si journal et accès sans session font partie du lot |
| 3 — Contrat fiche permanente | Credential révocable, GET profil durable, contexte session nullable et politique publique | Choix fonctionnels de la section 7 ; aucun déplacement global des états |
| 4 — Présentation lecture seule | Réutilisation composants du portail, ancienne route compatible, aucune autosauvegarde hors droits | Étape 3 ; tester aussi appels API directs fermés et retrait de participation |
| 5 — Ajouts/retraits manuels fiables | Idempotence, révocation traçable, invalidation des révisions, statuts actif/inactif | Arbitrer retrait automatique et expertise sans prérequis ; avant l'UI complète |
| 6 — Interface formation MJ | Ajout/retrait par origine, rattrapage de choix, explication des substitutions | Étape 5, contrat unique du présentateur ; sans modifier le calcul des niveaux |
| 7 — Couverture du catalogue | Lots petits par capacité avec source/édition, auto vs choix, langues et expertise couplées si nécessaires | Inventaire 4.3 ; imports seulement après validation dédiée, jamais inférence depuis le texte |
| 8 — Bonus étendus | Rendu expertise outils et prise en charge explicite des bonus hors maîtrise permanente | Après validation de leur modèle ; sorts/métamagie/manifestations restent exclus |

Les étapes 1, 2 et les corrections de formation peuvent progresser indépendamment
de la fiche permanente. Ne pas conditionner les corrections simples à une
refonte des sessions ou au déploiement de Mercure.

## 6. Vérifications réalisées et tests à prévoir

### Réalisé pendant cet audit

- Lectures Git, PHP, TypeScript, templates, migrations et JSON ; aucune commande
  Symfony susceptible de modifier cache/base n'a été nécessaire.
- Deux sondes PHP via Docker utilisant `tools/character-training-fixture.php`
  uniquement en mémoire :
  - payload manuel répété : **2 traces, 1 maîtrise effective** ;
  - expertise automatique recouvrant un lot : **Discrétion seule effective,
    2 choix historiques stockés**, expertise outils perdue.
- Comptage des catalogues à partir des fichiers, pas de SQL.
- Lecture des règles officielles 2014 pour inspiration, doublons et bonus.
- Aucun test d'intégration écrivant dans une base, même temporaire, exécuté
  dans cette mission. Les suites ci-dessous sont à rejouer après validation.

### Matrice de tests futurs

| Domaine | Scénarios attendus | Points d'appui existants |
|---|---|---|
| Inspiration | Défaut false, true répété sans cumul, faux types, refus attribution joueur, dépenses simultanées même/deux tokens, stale revision, rollback, repos et nouvelle session, fermeture/retrait concurrents | `backend/tools/test-inspiration-scenarios.php`, `test-player-security.php`, `test-player-concurrency.php` — tests lus, non relancés |
| Consultation | Jamais de session, DRAFT, CLOSED, participant retiré, token permanent révoqué, session supprimée, plusieurs LIVE, profil sans valeurs de progression | Tests nouveaux du contrat durable ; conserver `test-character-profile-training.php` |
| Sécurité | Chaque mutation joueur fermée retourne refus sans effet ; LIVE ne suffit pas pour level-up non autorisé ; session d'un autre personnage refusée ; motifs MJ absents du public | `test-player-security.php`, `test-reference-runtime-visibility.php` |
| Actualisation | LIVE→CLOSED et DRAFT→LIVE à révision identique ; mutation MJ manuelle puis refresh joueur ; réponse GET tardive après PATCH ; aucune autosave en lecture seule | Tests Angular HTTP/rxjs à ajouter ; tests des révisions backend |
| Sources | Race/parent, historique, initiale vs multiclassage, sous-classe, don, niveau insuffisant, retrait de source avec autre origine restante | `test-import-backgrounds.php`, `test-import-race-proficiencies.php`, `test-import-class-proficiencies.php`, `test-feature-training.php`, `test-character-proficiency-resolver.php` |
| Choix et expertises | Sauvegarde/rechargement, options invalides, choix historiques inactifs, conflits auto/choix, perte de prérequis manuel, aucun choix arbitraire | `test-character-training.php`, `test-backgrounds-supplements-roundtrip.php`, fixtures en mémoire |
| Manuel et remplacements | Retry idempotent, motifs multiples légitimes, retrait d'une attribution parmi trois, audit de révocation, langue secrète MJ, substitution répétée/cible manuelle/source disparue | Nouveaux tests ciblés sur `CharacterTrainingService` et controller |
| Bonus | Niveau total multiclassé, compétence/sauvegarde maîtrisée, expertise ×2 une seule fois, outils affichés, bonus conditionnels séparés, données manquantes non assimilées silencieusement à un zéro certain | Tests `CharacterStatistics` à ajouter ; ne pas recalculer les niveaux dans Angular |
| Non-régression | POST builder unique et atomique, Chantelame Tasha/Assassin, 19 historiques, level-up autorisé consommé une fois, multiclassage sans maîtrises de départ de la classe secondaire | `test-builder-language-roundtrip.php`, `test-character-lifecycle.php`, `test-feature-training.php` |

Risques prioritaires : perte d'état lors d'un changement de contexte, confusion
token de lecture/token de jeu, mode readonly seulement visuel, invalidation de
lots de choix historiques, suppression d'une maîtrise encore accordée ailleurs,
fuite de motifs MJ et introduction de doubles systèmes de résolution.

## 7. Décisions fonctionnelles avant développement

1. **Accès permanent** : lien secret révocable recommandé pour rester proche du
   portail actuel, ou compte joueur nominatif ? Qui peut révoquer/régénérer le lien ?
2. **Hors session** : afficher uniquement le profil permanent, ou aussi un état
   archivé explicitement sélectionné ? Quelles valeurs afficher avant la première
   session ? Plusieurs sessions LIVE sont-elles autorisées pour un personnage ?
3. **Droits** : confirmer lecture seule joueur hors LIVE, y compris préparation
   des choix de level-up ; définir séparément les exceptions MJ. Une révocation
   de participation doit-elle conserver la consultation permanente ?
4. **Inspiration** : booléen 2014 maintenu ; journal souhaité ? Transfert joueur
   vers joueur inclus ou différé ? Attribution MJ hors toute session nécessaire ?
5. **Retraits de formation** : révocation des seuls grants manuels recommandée
   pour le premier lot, ou également neutralisation locale de sources automatiques ?
6. **Historique et prérequis** : une expertise devenue sans maîtrise reste-t-elle
   inactive et traçable, ou bloque-t-elle le changement de build ? Quand demander
   de refaire un choix recouvert par une nouvelle source automatique ?
7. **Remplacements** : garder la politique historique actuelle ; faut-il inclure
   les doublons impliquant une attribution manuelle et autoriser leur édition
   après création ? Aucun élargissement implicite aux autres sources.

Recommandation de premier lot : actualisation fiable + consultation permanente
sans migration des états de jeu + révocation des seules attributions manuelles.
Conserver intégralement les calculs de niveau, le builder atomique et les
résolveurs, puis élargir les données capacité par capacité après contrôle des sources.
