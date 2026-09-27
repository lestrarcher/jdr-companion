# Audit de visibilité du référentiel OFFICIAL / CUSTOM

Date : 27 septembre 2026. Périmètre : code et base locale après la première étape OFFICIAL/CUSTOM.

Jalon admin implémenté : `/admin` et `/admin/reference` sont désormais limités à OFFICIAL (listes, compteurs, options, relations, navigation et accès GET/POST par ID). Le filtre et les badges legacy `custom` ont été retirés de l'admin. Validation : 1 280 assertions du harness admin, 56 assertions sur les probes réelles en READ ONLY ; données conservées. Container, schéma, routes et dix templates applicatifs valides ; le lint Twig global signale six templates vendor de profiler avec extensions manquantes. Les constats ci-dessous restent l'audit historique avant ce jalon ; les autres surfaces n'ont pas été modifiées.

## 1. Résumé exécutif

**Jalon fermeture des anciennes API implémenté (27 septembre 2026).** Inventaire courant avant retrait : 39 routes `/dnd/*`, dont 10 GET et 29 mutations ; toutes exigeaient ROLE_USER sauf `GET /dnd/reference`. Les mutations modifiaient le catalogue global, ses attributions et ses enfants, sans autorisation OFFICIAL/CUSTOM. Les neuf anciennes lectures étaient consommées uniquement par les managers Angular retirés avec ce jalon ; builder et écrans de jeu utilisent déjà les catalogues de campagne. Aucune acquisition Character, aucun import/initializer HTTP et aucune API globale d'action n'existaient sous ce préfixe.

| Routes retirées (préfixe `/dnd`) | Méthodes avant retrait | Ancien consommateur |
|---|---|---|
| `/classes`, `/subclasses`, `/features`, `/resources` ; `/{id}` sous chacune | GET/POST collection, PATCH ID | Managers Angular correspondants |
| `/races`, `/races/{id}`, `/races/{id}/ability-modifiers` | GET/POST collection, PATCH ID, POST modificateur | RaceManager |
| `/feats`, `/feats/{id}` | GET/POST collection, PATCH/DELETE ID | FeatManager |
| `/feature-rules`, `/feature-rules/{id}` | GET/POST collection, PATCH/DELETE ID | FeatureRuleManager |
| `/resources/{id}/rules`, `/resource-rules/{id}` | GET/POST règles, PATCH/DELETE règle | ResourceDefinitionManager |
| `/progressions`, `/progressions/{id}` | GET/POST collection, PATCH/DELETE ID | ProgressionManager |
| `/progressions/{id}/stages`, `/progressions/{id}/adjustment-rules` et enfants `/{childId}` | POST collection enfant, PATCH/DELETE enfant | ProgressionManager ; ancien harness de CRUD adapté |

Après retrait, **seul `GET /dnd/reference` subsiste**, public et OFFICIAL-only, conservé comme contrat public demandé (aucun consommateur Angular de jeu actuel ; couvert par les harnesses). Sa règle PUBLIC_ACCESS est bornée au chemin exact et à GET. Les anciens chemins, y compris les accès directs par ID CUSTOM, retournent 404. **Liste des mutations globales `/dnd/*` accessibles à ROLE_USER : vide.** Huit contrôleurs devenus inutiles ont été supprimés ; `ProgressionController` conserve le catalogue de campagne et sa sérialisation MJ. L'écran Angular `/dnd/reference`, ses huit managers, leur service API et leurs payloads d'édition ont été retirés ; aucune interface de remplacement ni CRUD CUSTOM ajouté.

Les catalogues `/campaigns/{campaignId}/dnd/reference` et `/campaigns/{campaignId}/dnd/progressions`, le build Character, le level-up MJ/token et l'attribution/retrait de progression restent actifs, avec CampaignVoter et les validations ReferenceVisibility déjà présentes. Le back-office Symfony garde sa sécurité actuelle. Les commandes `app:dnd:import-class-features`, `app:dnd:import-feats`, `app:dnd:import-races` et `app:dnd:initialize-reference`, leurs services et JSON sont volontairement conservés sans exécution. Tests de fermeture : `tools/test-legacy-reference-api.php` (fixtures OFFICIAL/A/B, routes retirées, IDs directs, absence de mutation et maintenance CLI disponible). Le harness progression conserve les tests de modèle/persistance/cascades et de séparation MJ/joueur via le catalogue contextualisé ; les tests du CRUD HTTP supprimé sont remplacés par les refus des anciennes API. Les sections suivantes relatent les jalons antérieurs et l'audit historique.

**Jalon catalogues/choix implémenté (27 septembre 2026).** Le catalogue public `/dnd/reference` est OFFICIAL uniquement. Les nouveaux GET `/campaigns/{campaignId}/dnd/reference` et `/campaigns/{campaignId}/dnd/progressions` utilisent CampaignVoter puis Campaign.owner (OFFICIAL + CUSTOM owner). `ReferenceVisibility` est une politique de sélection explicite, sans Security ni filtre Doctrine global ; elle contrôle les dépendances classe/sous-classe et l'ascendance raciale, y compris les modificateurs hérités. Les capacités et ressources ne sont pas des catalogues nécessaires aux écrans modifiés.

Le builder et les sélections de progression campagne/session Angular consomment ces contrats. Build, level-up MJ/token et attribution de progression revalident les références avant acquisition. Les choix de level-up utilisent Character → Campaign → owner, indépendamment du visiteur ; le token reçoit classes, sous-classes nécessaires au prochain choix et dons/caractéristiques uniquement si une option éligible exige ASI/don. Les dons non répétables déjà acquis sont exclus. L'écran joueur n'appelle plus le catalogue global. Aucune progression, capacité ou ressource personnelle supplémentaire n'est ajoutée aux options tokenisées. Une sous-classe historique hors visibilité est signalée par une erreur métier, sans purge ; la politique générale des historiques reste différée.

Probes READ ONLY : Campaign #2 owner1 ; `arch-hag` #20 et progressions #1/#2/#3 visibles pour owner1, exclues du public et d'un autre owner ; sous-classe acquise de Riven conservée. La politique raciale jouable historique est conservée après filtrage : un futur CUSTOM avec legacy `custom=false`, absent du manifeste jouable, resterait visible mais non sélectionnable. Cette divergence de sélection doit être décidée séparément, pas transformée en permission d'accès.

Restent à traiter : anciens GET et mutations globaux `/dnd/classes`, `/dnd/subclasses`, `/dnd/races`, `/dnd/feats`, `/dnd/features`, `/dnd/feature-rules`, `/dnd/resources`, règles de ressources et `/dnd/progressions` (notamment les managers Angular historiques). Leurs chemins restent inchangés ; déplacer les sélecteurs métier ne ferme pas ces API. Les trois resolvers runtime et CharacterRestService restent inchangés. Tests : 161 assertions A/B ciblées et harnesses lifecycle, sécurité, concurrence, progressions, ressources/slots, races et admin ; build Angular et contrôles Symfony validés. Les sections suivantes décrivent l'audit initial, avant ces jalons.

**Jalon runtime implémenté (27 septembre 2026).** `CharacterFeatureRule`, `TrackableResourceRule` et `CharacterActionClassRule` sont filtrés en Doctrine par `Character → Campaign → owner` : OFFICIAL + CUSTOM du propriétaire. Les méthodes globales de maintenance gardent leur comportement. `ReferenceVisibility` vérifie ensuite les sources, définitions et dépendances, dont `feature → resource` : OFFICIAL ne dépend que d'OFFICIAL ; CUSTOM U dépend d'OFFICIAL ou CUSTOM U. Une feature ayant une ressource incompatible est ignorée entièrement, afin de ne pas exposer une définition partiellement valide ni lui inventer une version sans ressource. Aucune relation ni acquisition historique n'est modifiée.

Le repos recherche les ressources historiques par slug et visibilité : une ressource personnelle inactive reste rechargeable selon sa configuration ; une clé étrangère ou inconnue reste intégralement conservée, sans recharge ni fallback étranger. Les maxima, bonus additifs et arbitrages restent inchangés : niveau applicable le plus élevé pour `maximumOverride`, première règle rencontrée à niveau égal, sans priorité de provenance. Factory, synchronizer, sérialiseurs et level-up utilisent déjà Character et les valeurs explicites de chaque state, sans nouvelle dépendance à Security. Le harness `tools/test-reference-runtime-visibility.php` couvre A/B sur parent OFFICIAL, dépendances incompatibles, acquisitions historiques, repos, states multiples, recalcul après level-up, profils/token et visiteur différent du propriétaire. Probes READ ONLY : seuils 10/15/50 préservés ; Eneis #23 conserve `deplacement-eclair=5` sous le seuil ; aucune acquisition étrangère ni relation incompatible détectée. Les constats suivants restent ceux de l'audit initial.

Les colonnes `origin` et `owner` existent sur les huit définitions et les trois attributions, mais **les parcours de consultation et de résolution ne les utilisent pas encore pour isoler le référentiel**. Les callbacks et contraintes du premier jalon garantissent le couple OFFICIAL/owner NULL ou CUSTOM/owner non NULL ; ils ne garantissent pas la visibilité ni la compatibilité des relations.

Constats prioritaires :

- `GET /dnd/reference` est public et charge toutes les classes, sous-classes, races et dons. La sous-classe CUSTOM #20 `arch-hag` est donc exposée sans authentification.
- Les listes `/dnd/*`, les choix de level-up et les recherches par ID ne filtrent pas le propriétaire. Le contrôle d'une campagne ne contrôle pas les références sélectionnées.
- Les trois resolvers lisent globalement les attributions. Aujourd'hui les trois règles CUSTOM concernent des progressions explicitement attribuées ; demain une règle CUSTOM sur une classe OFFICIAL pourrait affecter les personnages d'autres propriétaires.
- L'administration Symfony inclut les dix définitions CUSTOM dans ses recherches et compteurs. Ses routes sont protégées par `ROLE_USER`, pas par une autorisation particulière d'éditer l'officiel.
- Le repos recharge aussi des ressources historiques retrouvées globalement par slug. Ce chemin doit être traité avec les resolvers.
- Les anciennes API de mutation restent présentes. Leur existence ne constitue pas un CRUD CUSTOM : leurs constructions utilisent encore le défaut OFFICIAL/owner NULL, même quand le payload legacy indique `custom=true`.

**74 routes Symfony nommées directement concernées**, soit **76 opérations méthode + patron de chemin** : les deux routes d'édition admin acceptent chacune GET et POST. Les catégories dynamiques de l'admin ne sont pas multipliées dans ce compte. Cinq routes connexes sont examinées séparément (§3), sans les présenter comme des lecteurs des onze types.

Méthode : lecture du code, `debug:router --show-controllers --format=json`, SELECT SQL dans des transactions `READ ONLY`, appels en mémoire aux resolvers et recherches admin dans une transaction `READ ONLY` finalement annulée. Aucun endpoint de mutation appelé, aucune fixture persistée, aucun import exécuté. Les effets HTTP décrits résultent du code et des probes de services ; ce n'est pas une campagne de tests HTTP avec deux comptes. Seul ce document est ajouté ; les changements du jalon précédent sont conservés.

## 2. Règles métier validées

| Contexte | Ensemble autorisé |
|---|---|
| Catalogue anonyme | OFFICIAL uniquement |
| Catalogue personnel de U | OFFICIAL + CUSTOM owner U |
| Administration `/admin/reference` | OFFICIAL uniquement, y compris relations et attributions présentées |
| Résolution d'un Character | Catalogue de `Character.getCampaign().getOwner()` |
| Token joueur | Références nécessaires à ce Character et à ses choix autorisés, jamais tout le catalogue personnel du MJ |

Le visiteur autorisé et le propriétaire métier sont deux notions distinctes. Un futur administrateur visitant le personnage de A doit obtenir la résolution de A. Aucun resolver ne doit chercher l'utilisateur connecté ni choisir une session implicitement.

| Objet portant origin/owner | Relations permises |
|---|---|
| Définition OFFICIAL | Dépendances OFFICIAL seulement |
| Définition CUSTOM U | OFFICIAL ou CUSTOM U |
| Attribution OFFICIAL | Parent OFFICIAL et définition attribuée OFFICIAL |
| Attribution CUSTOM U | Parent OFFICIAL/CUSTOM U et définition OFFICIAL/CUSTOM U |

Les attributions CUSTOM sont additives. Aucun ordre de chargement, priorité d'origine ou slug ne doit instaurer un masquage implicite de l'officiel. Le rendu unique d'une capacité déjà acquise n'est pas un mécanisme de remplacement d'attribution.

`CharacterClassLevelRule`, `RaceAbilityModifier`, `ProgressionStage`, `ProgressionAdjustmentRule` héritent du périmètre de leur parent ; ne pas leur ajouter un propriétaire indépendant. Contrôler également les ancêtres de race et les dépendances de sous-classe. Les conditions de jeu (`visible`, `active`, `selectable`, seuil, niveau) s'ajoutent à la visibilité, sans la remplacer.

## 3. Cartographie endpoints

Sources : [contrôleurs](../src/Controller), [security.yaml](../config/packages/security.yaml), [CampaignVoter](../src/Security/Voter/CampaignVoter.php), [PlayerCharacterAccess](../src/Service/PlayerCharacterAccess.php). Les chemins sont ceux de Symfony ; Angular utilise le préfixe proxy `/api`.

Légende commune aux tableaux :

- **U** : `ROLE_USER`, utilisateur connecté disponible ; pas de contrôle de propriété du référentiel.
- **C** : `ROLE_USER` + voter VIEW/MANAGE de Campaign ; Character/Campaign disponibles, références sans filtre origin/owner.
- **S** : `ROLE_USER` + contrôle de campagne via session/Character ; contexte métier disponible.
- **T** : chemin PUBLIC_ACCESS, token de CharacterSessionState validé et participation contrôlée ; mutations avec contrôles complémentaires (session active/verrouillage/permission de level-up selon l'opération). Le token n'est pas un utilisateur.
- **P** : PUBLIC_ACCESS sans token.

### Catalogues et mutations historiques : 39 routes

Chaque méthode listée représente une route distincte, sauf indication contraire. Les colonnes contexte/risque s'appliquent à chaque méthode de la ligne, y compris à la réponse de mutation.

| Méthodes et chemins | Contrôleur | Auth/contexte | Types, filtrage actuel et risque |
|---|---|---|---|
| GET `/dnd/reference` | DndReferenceController | P ; aucun Character | Classes, sous-classes, races/modificateurs hérités, dons ; `findBy([])` ; fuite anonyme de CUSTOM. La sélection jouable des races est une autre règle. |
| GET, POST `/dnd/classes` ; PATCH `/dnd/classes/{classId}` | CharacterClassController | U | Classes ; listes globales, ID global ; consultation/édition inter-user et de l'officiel. |
| GET, POST `/dnd/subclasses` ; PATCH `/dnd/subclasses/{subclassId}` | CharacterSubclassController | U | Sous-classes et classe parent ; filtre métier éventuel par classe sans owner ; parent choisi par ID global. |
| GET, POST `/dnd/races` ; PATCH `/dnd/races/{raceId}` ; POST `/dnd/races/{raceId}/ability-modifiers` | CharacterRaceController | U | Races, parents, modificateurs ; aucune séparation owner ; enfant à autoriser par race. |
| GET, POST `/dnd/feats` ; PATCH, DELETE `/dnd/feats/{featId}` | FeatController | U | Dons et effets associés ; lectures/IDs globaux. |
| GET, POST `/dnd/features` ; PATCH `/dnd/features/{featureId}` | CharacterFeatureDefinitionController | U | Capacités et catalogue de ressources associé ; deux ensembles globaux, FK resource par ID sans compatibilité de provenance. |
| GET, POST `/dnd/feature-rules` ; PATCH, DELETE `/dnd/feature-rules/{ruleId}` | CharacterFeatureRuleController | U | Attributions + sources classe/sous-classe/race/don/progression + capacité ; `findOrdered()` global et IDs globaux. |
| GET, POST `/dnd/resources` ; PATCH `/dnd/resources/{resourceId}` | TrackableResourceDefinitionController | U | Ressources ; catalogue global, mutation par ID global. |
| GET, POST `/dnd/resources/{resourceId}/rules` ; PATCH, DELETE `/dnd/resource-rules/{ruleId}` | TrackableResourceRuleController | U | Attributions de ressources ; liste réduite au resourceId après lecture globale, mais ressource et sources non autorisées par owner. |
| GET, POST `/dnd/progressions` ; PATCH, DELETE `/dnd/progressions/{progressionId}` | ProgressionController | U | Progressions, stages, règles descriptives MJ ; global. Les trois progressions CUSTOM sont accessibles à tout ROLE_USER. |
| POST `/dnd/progressions/{progressionId}/stages` ; PATCH, DELETE `/dnd/progressions/{progressionId}/stages/{stageId}` | ProgressionController | U | Stage retrouvé via progression ; rattachement technique contrôlé, propriété de la progression non contrôlée. |
| POST `/dnd/progressions/{progressionId}/adjustment-rules` ; PATCH, DELETE `/dnd/progressions/{progressionId}/adjustment-rules/{ruleId}` | ProgressionController | U | Règle descriptive via progression ; même défaut d'autorisation du parent. |

Pas de CRUD HTTP autonome pour CharacterActionDefinition/CharacterActionClassRule : exposition par les actions résolues et les détails admin de classe ; alimentation de maintenance.

### Administration : 6 routes nommées, 8 opérations

| Méthodes et chemins | Contrôleur | Auth/contexte | Types, filtre actuel, risque |
|---|---|---|---|
| GET `/admin` | AdminFeatureReferenceController::dashboard | U | Agrégats des sept catégories éditoriales, toutes origines ; chiffres incluant CUSTOM. |
| GET `/admin/reference` | AdminFeatureReferenceController::reference | U | Entrée du catalogue et compteurs globaux. |
| GET `/admin/reference/features` | AdminFeatureReferenceController::list | U | Capacités et origines d'attribution ; recherche/source/éditorial/pagination sans origin. |
| GET, POST `/admin/reference/features/{id}` | AdminFeatureReferenceController::edit | U + CSRF au POST | Capacité, ressource, règles liées ; ID global, édition nom/description possible sur CUSTOM. |
| GET `/admin/reference/{category}` | AdminReferenceCatalogueController::list | U | classes/subclasses/races/feats/resources/progressions ; filtres métier et legacy custom, pas owner/origin. |
| GET, POST `/admin/reference/{category}/{id}` | AdminReferenceCatalogueController::edit | U + CSRF au POST | Définition, enfants et relations ; accès ID global, édition éditoriale sur CUSTOM possible. |

### Campagne / personnage : 10 routes

Préfixe **CP** = `/campaigns/{campaignId}/characters/{characterId}`.

| Méthodes et chemins | Contrôleur | Auth/contexte | Types, filtrage et risque |
|---|---|---|---|
| GET `/campaigns/{campaignId}/characters` | CharacterController::list | C | Race, niveaux classe/sous-classe, progressions des personnages de la campagne ; pas de catalogue global, mais relation étrangère éventuelle sérialisée. |
| POST `/campaigns/{campaignId}/characters` | CharacterController::create | C | Création historique à définition JSON ; réponse synthétique identique. Aucun nouveau catalogue chargé ; vérifier que le JSON libre ne serve pas de raccourci d'acquisition normalisée. |
| POST `/campaigns/{campaignId}/characters/build` | CharacterBuilderController::create | C | Race, modificateurs, classes/sous-classes, dons retrouvés globalement ; ID CUSTOM étranger sélectionnable malgré campagne autorisée. |
| GET `CP` | CharacterProfileController::show | C | Profil, acquisitions et capacités/ressources/actions résolues ; pas de state implicite, progressions sans activation par valeur courante. |
| PUT `CP/hit-points/history` | CharacterHitPointController::updateHistory | C | Niveaux acquis et profil recalculé ; résolution indirecte, propriétaire déductible du Character. |
| GET `CP/level-up/options` | CharacterLevelUpController::options | C | Toutes classes et sous-classes chargées ; éligibilité mécanique ne filtre pas la visibilité. |
| POST `CP/level-up` | CharacterLevelUpController::levelUp | C | Classe/sous-classe/don par ID global, puis profil et synchronisation des states. |
| GET `CP/progressions` | CharacterProgressionController::list | C | Seulement progressions attribuées, stages/règles MJ ; pas de validation d'owner des définitions acquises. |
| POST, DELETE `CP/progressions/{progressionId}` | CharacterProgressionController::add/remove | C | Progression recherchée par ID, attribution/retrait et synchronisation ; contrôle campagne insuffisant pour accepter une progression étrangère. |

### CharacterSessionState : 11 routes

Préfixe **SC** = `/sessions/{sessionId}/characters/{characterId}` ; **PC** = `/public/characters/{accessToken}`. Toutes sont dans CharacterSessionStateController.

| Méthode et chemin | Auth/contexte | Types, filtre actuel, risque |
|---|---|---|
| GET `/sessions/{sessionId}/characters` | S | States de cette session et profils ; catalogue indirect via resolvers globaux. |
| POST `SC` | S | Vérifie même campagne personnage/session ; factory ou réactivation, puis profil. |
| DELETE `SC` | S | Désactive participation sans supprimer state ; profil en réponse. |
| POST `SC/hit-points/maximum-adjustment` | S | Ajustement et profil/state ; résolution indirecte. |
| DELETE `SC/active-effects/{effectId}` | S | Effet de ce personnage puis profil/state ; résolution indirecte. |
| PATCH `SC/level-up-permission` | S | Permission puis profil/state ; résolution indirecte. |
| PATCH `SC/progressions/{progressionId}` | S | Progression attribuée au Character et bornes ; synchronisation des ressources, pas d'isolement des règles globales. |
| GET `PC/level-up/options` | T | Catalogue global classes/sous-classes via options ; fuite au-delà des besoins du personnage. |
| POST `PC/level-up` | T + permission level-up | IDs globaux + contraintes métier ; même risque de sélection étrangère que le MJ. |
| GET `PC` | T | Profil et state du token ; pas de catalogue complet direct, mais résultats globaux des resolvers. |
| PATCH `PC` | T | Mise à jour validée par PlayerCharacterStateUpdater puis profil/state ; contrôler la cohérence des références utilisées par les calculateurs, sans transformer cet audit en refonte du PATCH. |

### Actions et repos : 8 routes

| Méthode et chemin | Contrôleur | Auth/contexte | Types, filtre actuel, risque |
|---|---|---|---|
| PATCH `PC/actions/prepared` | CharacterActionController::updatePreparedActions | T | Actions résolues pour le Character ; origine des règles non filtrée. |
| GET `PC/action-targets` | CharacterActionController::actionTargets | T | Cibles de session, pas un catalogue personnel ; vérifier chaque cible selon sa propre campagne lors des calculs. |
| POST `PC/actions/aid` | CharacterActionController::useAid | T | Action autorisée par resolver, ressources/effets ; règle CUSTOM étrangère sur classe officielle potentiellement applicable. |
| POST `PC/actions/heroes-feast` | CharacterActionController::useHeroesFeast | T | Même chaîne, avec cibles de session. |
| POST `PC/actions/flexible-casting/create-spell-slot` | CharacterActionController::createFlexibleCastingSlot | T | Action et ressources résolues, slots standards ; même contexte Character nécessaire. |
| POST `PC/actions/flexible-casting/convert-spell-slot` | CharacterActionController::convertFlexibleCastingSlot | T | Même chaîne ; garder Pact Magic distinct. |
| POST `PC/actions/arcane-recovery` | CharacterActionController::arcaneRecovery | T | Action, ressource, slots ; slug de classe canonique également utilisé dans le profil. |
| PATCH `/rest-requests/{requestId}` | RestRequestController::resolve | S via demande → state | Appelle CharacterRestService si approbation ; ressources courantes ET historiques, recherche globale par slug. La réponse est une demande de repos, la résolution est indirecte. |

### Cinq routes connexes examinées, hors des 74

POST `PC/rest-requests`, GET `PC/rest-requests/latest` (token) et GET `/sessions/{sessionId}/rest-requests` (campagne) manipulent les demandes, sans résoudre les onze types. L'application du repos est dans la route resolve comptée ci-dessus.

GET `/public/sessions/{accessToken}` (token d'affichage) et GET `/sessions/{sessionId}/display` (CampaignVoter VIEW) utilisent `GameSessionController::serializeDisplaySession` : allowlist session/displayState/weather/moon, aucun catalogue de ces onze types. `displayState` est un payload d'affichage, pas une API de résolution à utiliser pour exposer le catalogue personnel.

Les autres routes sessions/campaigns, wallet, médias, quêtes, objets magiques, météo et authentification ne lisent pas directement ces onze catalogues. Weather/MagicItem restent hors périmètre (§16). Les routes de profil/state sont comptées même quand leur opération principale est PV, participation ou effets, car leur réponse sérialise le profil.

## 4. Cartographie repositories/services/resolvers

| Lecteur | Usage actuel | Contexte à appliquer ensuite |
|---|---|---|
| Repositories des huit définitions | Principalement méthodes Doctrine héritées `find`, `findBy`, `findOneBy`, `count` ; aucune politique générale owner | Méthodes explicites selon public, U, admin ou Character ; ne pas modifier globalement `find` ni installer un filtre Doctrine dépendant du visiteur. |
| [CharacterFeatureRuleRepository::findOrdered](../src/Repository/CharacterFeatureRuleRepository.php) | Toutes règles avec sources et capacité | Visibilité de la règle ET compatibilité de toutes ses extrémités ; owner de campagne pour runtime. |
| [TrackableResourceRuleRepository::findOrderedRules](../src/Repository/TrackableResourceRuleRepository.php) | Toutes règles, jointure ressource, ordre ressource/niveau | Même politique ; filtrer avant calcul des bonus/maxima. |
| [CharacterActionClassRuleRepository::findActiveOrdered](../src/Repository/CharacterActionClassRuleRepository.php) | Toutes règles dont action active | `active` ne signifie pas visible pour U ; contrôler règle, classe et action. |
| [CharacterFeatureResolver](../src/Service/CharacterFeatureResolver.php) | Teste acquisitions/niveaux/race/dons/progressions, indexe par slug | Garder `resolve(Character, progressionValues)` et déduire owner du Character ; pas de session implicite. |
| [CharacterResourceResolver](../src/Service/CharacterResourceResolver.php) | Ressources des capacités + règles + bonus/maxima | Même owner que FeatureResolver ; valider aussi la FK directe. |
| [CharacterActionResolver](../src/Service/CharacterActionResolver.php) | Classe/niveau, résultat par slug | Même owner ; les règles CUSTOM sur classe OFFICIAL sont le cas d'attaque minimal. |
| [CharacterLevelUpOptionsService](../src/Service/CharacterLevelUpOptionsService.php) | `findBy([])` classes puis sous-classes de chaque classe, éligibilité calculée | Owner du Character ; limiter les choix renvoyés au joueur selon le niveau et les règles du jeu. |
| [CharacterLevelUpRequestResolver](../src/Service/CharacterLevelUpRequestResolver.php) | Recherche globale classe/sous-classe/don par ID puis vérification mécanique | Valider visibilité avant usage, y compris ID absent des options affichées. Le contrôleur contient aussi d'anciens helpers de résolution ; éviter de corriger seulement un helper inutilisé. |
| CharacterBuilderController / CharacterBuilderService | Acquisition par IDs, cohérence classe/sous-classe, choix raciaux | Owner de la Campaign cible, pas un ownerId du payload ; RaceAbilityModifier doit appartenir à la race/ascendance autorisée. |
| [CharacterProfileSerializer](../src/Service/CharacterProfileSerializer.php) | Relations acquises, trois resolvers, stages des progressions | Point central de sortie Character ; protéger également les relations directes, pas seulement les listes globales. |
| CharacterSessionStateSerializer / Factory / Synchronizer | Voir §8 | Même owner du Character, progressionValues propres à chaque state. |
| CharacterAction/CharacterFlexibleCastingActionService et CharacterArcaneRecoveryActionService | Appels directs au ResourceResolver avec le Character et les valeurs du state ; consommation des ressources/slots | Couvrir ces appels hors sérialisation dans les tests ; owner du même Character. CharacterAidActionService et CharacterHeroesFeastActionService restent liés aux personnages/cibles autorisés par leur parcours d'action. |
| [CharacterRestService](../src/Service/CharacterRestService.php) | Résolution courante + `findBy(['slug' => ...])` pour ressources historiques | Filtrer aussi la récupération historique ; ne pas supprimer une consommation faute de définition visible. |
| CharacterAbilityCalculator, CharacterHitPointCalculator, CharacterSpellSlotCalculator, CharacterRaceMetadataResolver, CharacterMulticlassEligibilityService | Calculs sur relations acquises ; héritage de race, règles de classe/niveau | Vérifier les acquisitions à l'entrée et la cohérence des dépendances ; aucun besoin d'injecter Security dans ces calculateurs. |
| CharacterClassLevelRuleRepository::findForClassAndLevel | Classe + niveau | Parent autorisé ; enfants sans owner indépendant. |
| AdminReferenceCatalogue / AdminFeatureReference / AdminReferenceNavigation | Requêtes dédiées, agrégats, choix, jointures, voisins | OFFICIAL sur racines et relations ; conserver pagination, filtres éditoriaux et ordre nom/ID. |
| ImportClassFeaturesCommand, ImportRacesCommand, ImportFeatsCommand, DndReferenceInitializer | Maintenance ; recherches globales slug/clé, protection origin ajoutée au jalon précédent | Doivent détecter une collision CUSTOM, pas l'ignorer en filtrant OFFICIAL puis tenter une insertion. Pas de contexte utilisateur interactif ni d'import exécuté ici. |
| `backend/tools/test-*` et fixtures de tests | Lectures de diagnostic, préparation des cas, assertions | Choisir explicitement le contexte attendu dans les futurs tests ; ne pas traiter ces lectures comme des API à restreindre automatiquement. |

Les recherches de doublon des contrôleurs de règles utilisent actuellement la clé métier sans owner. Elles devront évoluer avec les index (§13), sinon elles retrouveront l'attribution OFFICIAL ou celle d'un autre User avant même l'insertion.

## 5. Back-office admin

Sources : [AdminReferenceCatalogue](../src/Service/AdminReferenceCatalogue.php), [AdminFeatureReference](../src/Service/AdminFeatureReference.php), [templates admin](../templates/admin).

`counts()` et `dashboard()` comptent sans origin. `search()` et `find()` ne rejettent pas CUSTOM. Les options des filtres et les relations de détail sont également globales. Le filtre legacy `custom` n'est pas un filtre de provenance : il masque à tort les ressources OFFICIAL #4/#53 et conserve à tort les CUSTOM #248/#55 lorsqu'on essaie de l'utiliser comme filtre officiel.

**Les dix définitions sont incluses dans les listes correspondantes**, selon recherche/pagination : sous-classe #20, capacités #247/#248/#249, ressources #54/#55/#56, progressions #1/#2/#3. Cela ne signifie pas qu'elles apparaissent toutes sur la première page. Probes exécutées via les services : recherches exactes par slug retrouvent #20, #55, #2 et les trois capacités. Les autres suivent exactement la même requête sans restriction d'origine, avec existence SQL confirmée.

Cible minimale : restreindre racines, compteurs, listes, choix, voisins/save-next, GET par ID et POST à OFFICIAL. Filtrer aussi les attributions montrées sur une définition officielle : une règle CUSTOM de U pointant une classe OFFICIAL ne doit pas apparaître dans cet éditeur. Une jointure de filtre ne doit pas rendre une capacité OFFICIAL visible dans un résultat uniquement à cause d'une attribution CUSTOM. Ne pas convertir une recherche avec LEFT JOIN en élimination involontaire des définitions sans règle.

L'admin conserve son périmètre éditorial (nom/description et labels de progression), ses whitelists POST et CSRF. Aucune ouverture d'édition CUSTOM. **Filtrer OFFICIAL ne règle pas l'autorisation de modifier OFFICIAL** : aujourd'hui ROLE_USER suffit ; la séparation entre opérateur du catalogue officiel et MJ ordinaire reste à décider avant ouverture multi-user (§14), sans créer de rôle ici.

## 6. Character Builder / Angular

Sources : [DndReferenceApiService](../../frontend/src/app/core/services/dnd-reference-api.service.ts), [builder](../../frontend/src/app/features/character-builder/character-builder.ts), [campaign-characters](../../frontend/src/app/features/campaign-characters/campaign-characters.ts), [routes Angular](../../frontend/src/app/app.routes.ts).

Le builder charge `getReference()` sans contexte de campagne. Il filtre côté client races sélectionnables et sous-classes de la classe, ce qui ne constitue aucune autorisation serveur. `arch-hag` est disponible dans le catalogue reçu et proposée dans le contexte de sa classe parent ; un ID envoyé manuellement suit le même lookup global côté backend.

`campaign-characters` charge aussi les progressions globales. Le modal de progression de session charge `/dnd/progressions` avant attribution ; les trois CUSTOM sont donc proposés à tout MJ authentifié. La liste des progressions déjà attribuées est contextualisée par personnage, mais ne contrôle pas la propriété de la définition elle-même.

Le code actuel contient toujours la route Angular `dnd/reference`, FeatureManager et les managers classes/sous-classes/races/dons/capacités/règles/ressources/progressions. Ils appellent les anciennes API globales. Leur présence est un constat du dépôt, pas une proposition de maintenir deux administrations. Leur devenir doit être explicite dans le futur ticket UI ; aucun changement Angular dans cet audit.

Pour le futur builder, choisir un contrat authentifié U ou lié à Campaign autorisée. Un catalogue U est réutilisable entre campagnes du même owner ; une route liée à Campaign rend le propriétaire métier explicite. Ne pas rendre le catalogue public dépendant du cookie du visiteur pour satisfaire simultanément builder et portail joueur.

## 7. Player Portal

Sources : [CharacterSessionStateController](../src/Controller/CharacterSessionStateController.php), [PlayerCharacterAccess](../src/Service/PlayerCharacterAccess.php), [player-level-up](../../frontend/src/app/features/player-level-up/player-level-up.ts).

Le token identifie un CharacterSessionState et donc un Character puis Campaign.owner. Ce contexte suffit sans authentification User. Le profil public renvoie des acquisitions et des résultats résolus, pas toutes les définitions du propriétaire : conserver cette propriété. Les descriptions/règles MJ de progression ne doivent pas être ajoutées au profil joueur pour faciliter l'implémentation.

Deux exceptions actuelles à corriger : `publicLevelUpOptions` utilise un service chargeant toutes les classes/sous-classes ; l'écran Angular de level-up appelle aussi le catalogue public `getReference()` (dont les dons). Filtrer ce dernier OFFICIAL ne suffit pas à proposer les choix CUSTOM pertinents du personnage. Il faudra un contrat de choix tokenisé contextualisé, avec validation symétrique au POST ; ne jamais renvoyer tout le catalogue CUSTOM du MJ sous prétexte que le token est valide.

### Probes réelles

SQL confirme que Rohunar #22, Eneis #23, Frank #24, Riven #25 appartiennent à Campaign #2, owner User #1. Les trois règles CUSTOM ont respectivement les seuils 10, 15, 50. Les ressources sont liées directement aux capacités, sans règle de ressource CUSTOM.

| Probe | Catalogue/builder/admin | Résolution exécutée et conséquence pour le portail |
|---|---|---|
| Sous-classe #20 `arch-hag` | Catalogue public, choix de sous-classe et admin | Acquise par Riven ; son nom/slug suivent le profil via classLevels. Ne donne pas automatiquement les trois capacités de progression. |
| Progression #1 → règle #243 → feature #247 → resource #54 | Progression listée dans API privée et admin ; feature/resource dans leurs API privées | State #22 : valeur 10, resolver renvoie #247 et `enchevetrement`. State #28 : valeur 1, aucune de ces deux références résolues. |
| Progression #2 → règle #244 → feature #248 → resource #55 | Mêmes chemins ; legacy false ne les protège pas | State #24 : valeur 15, #248 et `entite-symbiotique` résolus. State #26 : valeur 1, absents des résultats résolus. |
| Progression #3 → règle #245 → feature #249 → resource #56 | Mêmes chemins | State #23 : valeur 21 < 50, feature/resource absentes des résultats résolus ; pourtant `deplacement-eclair=5` demeure dans le state historique. State #27 : valeur 0, inactif. |

Les appels exécutés sont les vrais FeatureResolver/ResourceResolver avec les valeurs extraites des huit states (#22 à #29), dans une transaction READ ONLY. Ils confirment les seuils, pas un isolement A/B qui n'existe pas encore. Le serializer transmet le state en plus du profil : absence d'une ressource du résultat actif ne signifie pas disparition de sa clé historique dans la réponse. Préserver ces données ; une éventuelle relation étrangère historique doit faire l'objet d'une politique explicite de sortie et de réparation, sans purge silencieuse.

Les features #365/#368 de Circle of Spores sont des capacités officielles distinctes : ne pas les confondre avec la feature CUSTOM #248 par proximité de nom ni les rattacher à la progression #2. Aucun changement de données dans cet audit.

## 8. Résolution CharacterSessionState

Sources : [serializer](../src/Service/CharacterSessionStateSerializer.php), [factory](../src/Service/CharacterSessionStateFactory.php), [synchronizer](../src/Service/CharacterSessionStateSynchronizer.php), [rest service](../src/Service/CharacterRestService.php).

| Chemin | Contexte existant | Point de contrôle futur |
|---|---|---|
| ProfileSerializer::serialize(Character, progressionValues) | Character et valeurs explicites ; valeurs absentes par défaut | Owner campagne ; sans valeurs, règles de progression inactives. Ne pas charger un state arbitraire. |
| SessionStateSerializer::serialize | Character + state exact | Extraire valeurs du même state et propager ; filtrer la sortie des relations illégales sans modifier le state à la lecture. |
| Factory::create | Character, aucun state antérieur | Owner disponible ; progressions initialisées au minimum, puis ressources correspondantes. |
| Synchronizer::synchronize / synchronizeResources | Character + state | Même visibilité pour ressources et capacités ; garder consommations, storedValues et progressions existantes. |
| synchronizeProgressionAssignment | Attribution/retrait + states du Character | Contrôler d'abord la définition attribuée ; préserver les comportements existants de synchronisation. |
| snapshot(Character, progressionValues, state) | Arguments explicites, valeurs par défaut possibles | Ne pas substituer une autre session ; conserver le même owner pour avant/après. |
| snapshotForLevelUp / synchronizeAfterLevelUp | Itération explicite sur chaque CharacterSessionState du Character | Résoudre chaque snapshot avec ses propres valeurs ; différences de seuil entre sessions attendues. |
| Builder / level-up | Campagne/Character et acquisitions | Valider références avant mutation puis utiliser les mêmes resolvers que le portail. |
| RestService::apply | CharacterSessionState exact | Ressources courantes + historiques visibles pour owner ; progression jamais réinitialisée au repos. |

La clé critique de repos est le lookup des définitions par slugs présents dans le state, même quand leur capacité n'est plus active. Le cas Eneis est réel : le seuil n'est pas atteint mais la ressource existe historiquement. Un simple filtre « ressources actuellement résolues » casserait ce comportement. Distinguer inactivité mécanique autorisée et référence d'un autre propriétaire interdite.

## 9. Déduplication / slugs

| Emplacement | Clé/effet actuel | Conséquence |
|---|---|---|
| FeatureResolver | `resolvedRules[feature.slug]` ; plus haut unlockLevel hors progression, seuil supérieur pour même progression, première règle dans certains cas mixtes | Deux attributions visibles de la même capacité restent une capacité affichée ; le choix de règle/source peut masquer une attribution. Ne pas ajouter une priorité CUSTOM. |
| ResourceResolver | `definitions[slug]`, règle de maximum sélectionnée par niveau, somme des maximumBonus | Un second bonus CUSTOM est additif mais peut doubler un bonus copié ; deux overrides au même niveau nécessitent une décision explicite. |
| ActionResolver | `actions[action.slug] = definition` | Déduplique la même action ; dernière occurrence écrase la même entrée. Aucune sémantique d'override par provenance. |
| ProfileSerializer | Table des ressources puis slots `spell-slot-N` | Réserver les identifiants synthétiques : une ressource réelle portant ce slug peut être remplacée/fusionnée par un slot. |
| State/factory/sync/repos/mapper Angular | Ressources et progressions retrouvées par slug | Slugs stables indispensables ; ne pas renommer les probes ni changer les clés sans migration du state. |
| Import/initialisation | Slug canonique (sous-classe : classe + slug) | Une collision doit être détectée, pas absorbée ; les gardes origin du premier jalon doivent rester. |

Les définitions ont aujourd'hui une unicité par slug dans leur table, **sauf la sous-classe, unique par classe + slug**. Avec des slugs CUSTOM globalement uniques dans le domaine de clé concerné, deux définitions distinctes visibles ne devraient pas se fusionner dans les maps d'un même type. Cela ne garantit ni la visibilité ni l'additivité des attributions d'une même définition. La globalité entre types n'est pas imposée : feature #248 et resource #55 partagent légitimement un slug dans des espaces distincts.

Conserver les IDs pour les acquisitions et relations ; ne jamais fusionner par nom traduit, classe affichée ou similarité éditoriale. Préciser la réservation de `spell-slot-*`, l'immuabilité des slugs après attribution et l'application de la future unicité globale aux sous-classes avant CRUD. Aucun changement de slug proposé ici.

## 10. Direct FK feature / resource

Source : [CharacterFeatureDefinition](../src/Entity/CharacterFeatureDefinition.php), `resourceDefinition` nullable ; FK avec suppression SET NULL. Le contrôleur retrouve aujourd'hui la ressource par ID et le resolver suit directement cette FK.

Matrice impérative : OFFICIAL feature → OFFICIAL resource ; CUSTOM U feature → OFFICIAL ou CUSTOM U resource ; NULL autorisé ; tout autre lien refusé. Vérifier aussi la ressource quand une feature visible est chargée : le seul filtrage des règles de ressource ne couvre pas cette voie.

Contrôle recommandé à la frontière métier de création/modification de la relation, réutilisé par API, import et futur éditeur, plus validation applicative de l'agrégat. Les callbacks actuels ne contrôlent que le couple origin/owner de l'objet. Les écritures DBAL contournent les callbacks et doivent avoir leur contrôle propre.

Une FK simple garantit l'existence, pas la matrice inter-tables. Un CHECK local ne suffit pas pour lire l'origine de la cible. Renforcement DB possible par trigger de contrainte (sur lien et changements de provenance) ou modèle de clés composites/dénormalisation cohérente ; ce dernier est plus complexe avec la branche « OFFICIAL ou même owner ». Arbitrer selon le nombre de chemins d'écriture et le niveau de garantie souhaité. Ne pas remplacer l'invariant par un contrôle frontend.

Probes actuelles : #247→#54, #248→#55, #249→#56 sont CUSTOM owner1 des deux côtés. L'incohérence des booléens legacy n'affecte pas cette matrice.

## 11. Sous-classe / classe

Source : [CharacterSubclass](../src/Entity/CharacterSubclass.php). Une seule `characterClass` obligatoire, FK CASCADE, unicité classe + slug. Conserver ce modèle.

OFFICIAL subclass → OFFICIAL class uniquement. CUSTOM U subclass → OFFICIAL class ou CUSTOM U class. CUSTOM U → CUSTOM V interdit. Pour utiliser une sous-classe officielle sous une classe CUSTOM, créer une copie CUSTOM ; ne pas déplacer l'officielle ni ajouter plusieurs parents.

`CharacterSubclassController` valide l'existence de la classe, builder/level-up la cohérence de la sous-classe choisie avec la classe du niveau ; ces contrôles ne prouvent pas la matrice de provenance. Ajouter ultérieurement une validation métier commune à la création/changement de parent et contrôler les acquisitions. Même choix de renforcement DB que §10. `arch-hag` #20 owner1 sous classe OFFICIAL warlock #9 est un cas autorisé réel.

## 12. Legacy `custom`

Inventaire des usages applicatifs restants sur les huit définitions, distingué des jeux de données, migrations historiques et assertions de tests. Aucun `custom` des payloads ne doit déterminer origin/owner.

| Usage et fichiers | Classement | Traitement futur |
|---|---|---|
| Colonne, propriété, isCustom/setCustom des huit entités | Nécessaire temporairement pour compatibilité | Retrait seulement après migration de tous les lecteurs/contrats ; pas de synchronisation automatique avec origin. |
| CharacterClassController, CharacterSubclassController, CharacterRaceController, FeatController, CharacterFeatureDefinitionController, TrackableResourceDefinitionController, ProgressionController : payloads et sérialisation | Provenance à migrer vers origin ; champ legacy supprimable ensuite | Ne plus proposer un booléen libre comme création officielle/personnelle. Inclut la ressource imbriquée de FeatureDefinitionController. Pas de contrôleur équivalent d'action. |
| AdminReferenceCatalogue : filtre `e.custom`, détails, action liée ; AdminFeatureReference : détail | Doit migrer vers origin | Admin OFFICIAL fixe ; le filtre legacy ne doit pas simuler cette restriction. |
| `templates/admin/catalogue-list.html.twig`, `edit.html.twig` | Doit migrer vers origin | Badges « Personnalisé/Référentiel » et ligne custom actuellement trompeurs. |
| CharacterProfileSerializer : feature.custom ; Angular character-profile.html : badge | Doit migrer vers origin, ou supprimer si non utile au joueur | Le nom du champ n'est pas une preuve de provenance ; #248 contredit le badge. |
| DndReferenceController::serializeRace : `isSelectable() && (isCustom() || slug dans JSON jouable)` | Politique de sélection à séparer de la provenance | Ne pas remplacer mécaniquement par origin sans décider quels CUSTOM sont jouables ; appliquer visibilité indépendamment. Le booléen n'est pas un contrôle d'accès. |
| DndReferenceApiService : interfaces et payloads custom ; managers Angular des sept catégories | Compatibilité temporaire, puis migration/suppression | Formulaires chargent/envoient custom, filtres/badges en dépendent ; coordonner avec devenir de l'administration Angular. |
| ImportClassFeaturesCommand : valeurs custom et protection de mise à jour ; ImportFeatsCommand : hydratation/persistance/valeurs ; ImportRacesCommand : protection race/feature et anciennes règles | Nécessaire temporairement pour contrats d'import et protections historiques | Garder origin comme garde d'appartenance. Ne pas supprimer la protection legacy d'une donnée OFFICIAL historiquement éditée à la main sans décision. |
| RaceImportPlanner::outOfScopeAction / staleTraitRuleAction | Nécessaire temporairement, sémantique de protection | `custom` influence conservation/nettoyage des entrées ; dissocier protection éditoriale et provenance avant retrait. |
| ClassFeatureCatalogueValidator et FeatCatalogueValidator, objets d'entrée et JSON qui transportent custom | Compatibilité des données, pas autorisation runtime | Le validateur des dons exige notamment custom=false pour le catalogue 2014 ; adapter dans un ticket d'import dédié, aucun JSON changé ici. |
| Tests `backend/tools`, tests frontend et migrations historiques | Assertions/archives, pas exposition utilisateur | Mettre à jour les tests actifs au changement de contrat ; ne pas réécrire les anciennes migrations pour masquer le passé. |

Rappels SQL confirmés : ressources #4/#53 `custom=true` mais OFFICIAL/owner NULL ; feature #248 et ressource #55 `custom=false` mais CUSTOM/owner1. Les attributions ont origin/owner mais pas ce booléen legacy. `DndReferenceInitializer` recherche les références globalement et contrôle leur origine ; il ne faut pas y réintroduire une équivalence custom/origin.

## 13. Unicités des attributions

Vérification des mappings et de `pg_indexes` : les unicités métier sont matérialisées par des **index uniques**, pas par des entrées `pg_constraint` de type `u`. Une interrogation de cette seule dernière table ne les retrouve pas.

| Table | Index actuel | Colonnes |
|---|---|---|
| character_feature_rule | uniq_class_feature_level | character_class_id, feature_definition_id, unlock_level |
| character_feature_rule | uniq_subclass_feature_level | character_subclass_id, feature_definition_id, unlock_level |
| character_feature_rule | uniq_race_feature_level | character_race_id, feature_definition_id, unlock_level |
| character_feature_rule | uniq_feat_feature_level | feat_id, feature_definition_id, unlock_level |
| character_feature_rule | uniq_progression_feature_threshold | progression_definition_id, feature_definition_id, progression_threshold |
| trackable_resource_rule | uniq_class_resource_level | character_class_id, resource_definition_id, unlock_level |
| trackable_resource_rule | uniq_subclass_resource_level | character_subclass_id, resource_definition_id, unlock_level |
| trackable_resource_rule | uniq_race_resource_level | character_race_id, resource_definition_id, unlock_level |
| trackable_resource_rule | uniq_feat_resource_level | feat_id, resource_definition_id, unlock_level |
| character_action_class_rule | uniq_character_action_class | action_definition_id, character_class_id |

Origin/owner sont absents de ces dix clés. Exemple feature : une attribution OFFICIAL classe O → feature O niveau 3 bloque la même attribution CUSTOM A ; sans officielle, CUSTOM A bloque CUSTOM B. Même problème pour une ressource O au niveau 3, même si le bonus de A diffère. Pour une action O/classe O, le conflit existe même si unlockLevel diffère, puisqu'il n'entre pas dans l'index. Pour la progression, la clé porte le seuil réel, jamais le dummy unlockLevel=1 ; le conflit théorique O/A existe pour une progression OFFICIAL et une feature OFFICIAL communes.

Stratégies PostgreSQL possibles, à comparer avant migration :

1. Deux index partiels par famille de parent : unicité de la clé métier pour `origin='OFFICIAL'`, et unicité `(owner_id, clé métier)` pour `origin='CUSTOM'`. Exclure les parents NULL de l'index concerné. Lisible, exprime directement le périmètre.
2. Un index par famille sur `(origin, owner_id, clé métier)` avec traitement explicite des NULL, notamment `NULLS NOT DISTINCT` si version PostgreSQL compatible ; **partiel sur parent non NULL**, sinon les colonnes des autres familles pourraient créer des collisions indues. Un UNIQUE standard incluant owner nullable n'empêche pas les doublons OFFICIAL.
3. Une clé de périmètre normalisée/expression contrôlée pour représenter l'officiel et chaque owner, avec index par parent. Nécessite de garantir l'absence de collision avec une valeur sentinelle ; davantage de conventions et de mapping à maintenir.

Aucun choix définitif ici. Conserver les contraintes de cohérence du parent et du couple origin/owner. Réviser simultanément les lookups de doublon des contrôleurs et la sémantique runtime : autoriser deux lignes ne décide pas si un bonus doit s'additionner ni comment gérer deux maxima concurrents (§9). L'unicité d'un enfant technique comme CharacterClassLevelRule reste liée à son parent, sans catalogue d'attributions indépendant.

## 14. Sécurité des futures mutations

Réutiliser CampaignVoter pour autoriser la campagne cible et PlayerCharacterAccess pour identifier le Character du token. Ils ne constituent pas une autorisation de définition : CampaignVoter compare uniquement Campaign.owner au User connecté et ne possède actuellement aucun bypass administrateur.

Contrôles métier spécifiques nécessaires avant CRUD CUSTOM :

1. Création CUSTOM : owner fixé côté serveur au User autorisé, origin fixé CUSTOM. Rejeter les tentatives `ownerId`/origin libres, ne pas les interpréter comme une délégation.
2. Modification/suppression : cible CUSTOM du même User ; OFFICIAL interdit à un MJ ordinaire ; cible étrangère refusée, même si ID/slug connu.
3. Relations : valider règle/définition/source et toutes leurs dépendances avec la matrice §2. Un simple `find(id)` ne suffit pas. Enfants via parent autorisé et vérification de rattachement.
4. Provenance immuable par le CRUD courant : aucun transfert owner, aucune conversion OFFICIAL↔CUSTOM. Les opérations exceptionnelles éventuelles doivent être séparées.
5. Acquisition builder/level-up/progression : owner du Character ou de la campagne de création ; même validation pour token et MJ. Revalider au POST, pas seulement dans les choix affichés.
6. Refus cohérents (404 pour référence hors visibilité est une option à retenir uniformément), sans révéler le nom ni l'owner étranger dans le message de validation. Tester aussi recherches, compteurs et voisins.

Un service de contrôle explicite et quelques méthodes de lecture contextualisées suffisent pour commencer ; aucun besoin immédiat d'un moteur générique de permissions. Ne pas utiliser un filtre Doctrine global lié à Security : il casserait imports, admin officiel et consultation autorisée d'un Character tiers.

**Décision préalable à une ouverture multi-user** : comment autoriser l'opérateur du catalogue officiel alors que `/admin` et les API historiques n'exigent aujourd'hui que ROLE_USER ? Aucune création ROLE_ADMIN/ROLE_GM ici. La restriction admin à OFFICIAL est un chantier de périmètre ; le droit d'éditer OFFICIAL en est un autre. Les anciennes mutations doivent être fermées ou requalifiées avant que des utilisateurs ordinaires puissent en profiter. CSRF et whitelist éditoriale ne remplacent pas cette autorisation.

Autres décisions : comportement face à des acquisitions historiques illégales (erreur métier/refus de sérialiser/quarantaine explicite), politique des maxima concurrents, champs strictement nécessaires aux choix du joueur, et éventuel renforcement DB des relations. Ne pas purger ou corriger silencieusement les CharacterSessionState.

## 15. Plan de tests multi-user

Aucune fixture A/B créée dans la base réelle. Prévoir tests unitaires avec objets en mémoire pour la politique, puis intégration dans une base de test jetable ou tables temporaires isolées. Pour les tests HTTP avec plusieurs connexions, préférer une base jetable : un simple rollback de la connexion du test ne protège pas les autres connexions.

| Scénario | Attendu |
|---|---|
| Huit types : O, CUSTOM A, CUSTOM B ; catalogue A/B | A voit O+A, B voit O+B ; recherche, pagination, filtres, compteurs et ID direct cohérents. |
| Anonyme `/dnd/reference` | Seulement O ; aucune sous-classe/race/don B dans les relations ou choix. |
| Admin officiel | O uniquement dans listes, détails, relations, options, compteurs et navigation ; GET/POST sur A/B refusés. |
| Chaque type d'attribution CUSTOM A/B sur parent OFFICIAL | Character A reçoit règles O+A, Character B O+B ; prouve l'isolation même avec classe/race/don partagé. |
| Character Campaign A consulté par contexte autorisé différent | Résolution A, jamais celle du visiteur ; test service explicite tant qu'il n'existe pas de bypass admin HTTP. |
| Token Character A | Uniquement acquisitions/résultats/choix nécessaires à A ; absence du CUSTOM A inutilisé et de tout B. |
| Builder et level-up : payload ID B forgé | Refus serveur sans mutation, même si choix frontend contourné ; classes/sous-classes/races/modificateurs/dons couverts. |
| Attribution progression B à Character A | Refus ; aucun stage/règle MJ/state B exposé ou ajouté. |
| Deux sessions d'un Character, valeurs 2/3/4 pour seuil 3 | Activation selon chaque state ; sans state aucune activation progression ; classes/races/dons inchangés. |
| FK feature/resource et subclass/class | Toutes combinaisons §10/§11, NULL de ressource inclus ; OFFICIAL→CUSTOM et A→B refusés. |
| Enfants techniques | ID d'enfant d'un parent étranger refusé ; parents OFFICIAL et CUSTOM A autorisés suivant l'opération. |
| Attributions additives | Deux capacités distinctes conservées ; même capacité sans override de provenance ; bonus et maxima selon décision explicite. |
| Unicité | Doublon même périmètre refusé ; O/A/B sur même clé permis selon futur modèle ; OFFICIAL owner NULL réellement unique ; familles de parents indépendantes. |
| Repos et ressources historiques | Ressource A inactive conservée/rechargée selon configuration ; aucune définition B utilisée par un slug forgé ; progressions inchangées. |
| Level-up/synchronisation | PV, consommations, storedValues, tokens et progressions préservés ; aucune session implicite. |
| Slugs | Deux CUSTOM distincts non fusionnés ; même nom autorisé ; collision avec `spell-slot-N` rejetée selon politique retenue. |
| Mutations de provenance | ownerId arbitraire, conversion origin, mutation O ou B refusés ; aucun flush partiel. |
| Legacy divergent | #4/#53 restent officiels, #248/#55 restent CUSTOM ; aucun filtre/badge de provenance basé sur le booléen. |
| Maintenance | Import officiel idempotent et collision CUSTOM explicite ; pas d'écriture CUSTOM par initialisation. |

Les probes locales actuelles restent un jeu de non-régression complémentaire, jamais une preuve multi-user suffisante : leurs quatre Characters ont tous owner1.

## 16. Weather / MagicItem différés

Weather et MagicItem rejoindront ultérieurement OFFICIAL/CUSTOM. Le CUSTOM appartiendra au **User**, sera réutilisable entre ses campagnes et ne sera pas propriété de Campaign. Leur modèle actuel lié à la campagne nécessite une migration spécifique et un examen des occurrences possédées/équipées ou sélectionnées. Ne pas convertir ces occurrences en définitions partagées par simple ajout de colonnes. Aucun changement ni choix de migration dans ce ticket.

## 17. Proposition de séquencement d'implémentation

1. **Prochain ticket minimal : isoler le back-office officiel.** Uniquement services/contrôleurs admin et tests ciblés : OFFICIAL sur listes/compteurs/choix/relations/voisins, refus CUSTOM en GET et POST, conservation des filtres éditoriaux et de save-next. Les dix probes doivent disparaître de l'admin sans changer leurs données. Pas d'API joueur, pas de CRUD CUSTOM, pas de modification des resolvers. Ce ticket ne prétend pas résoudre l'autorisation d'édition multi-user.
2. Définir les petits contrats de lecture public OFFICIAL, catalogue U et options Character ; appliquer au catalogue public et aux listes de sélection avec adaptation coordonnée builder/level-up. Ne pas perdre `arch-hag` dans les choix légitimes de owner1 en ne corrigeant que le public.
3. Isoler les trois attributions runtime, les FK directes et les lectures historiques du repos par owner de campagne. Tests A/B sur parent officiel partagé, states multiples et non-régression des consommations. Valider également les acquisitions directes et le chemin sans state.
4. Fermer/requalifier les anciennes mutations ; décider l'autorisation de maintenance officielle, protéger les acquisitions et préparer validation commune des relations. Aucune ouverture multi-user avant couverture de ces chemins.
5. Arbitrer et migrer les unicités d'attribution, les conflits de maxima et les slugs réservés ; seulement ensuite ouvrir un CRUD CUSTOM owner fixé côté serveur.
6. Migrer les contrats legacy `custom`, puis retirer la colonne dans un ticket distinct ; Weather/MagicItem restent un chantier séparé.

Vérifications de cet audit : routes inspectées, probes SQL et services READ ONLY exécutées, aucun token publié. Contrôles finaux prévus : `git diff --check` et `git status --short`. Aucun test de mutation, build, migration ou import n'est nécessaire pour ce seul document.
