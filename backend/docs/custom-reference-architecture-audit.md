# Audit architectural — référentiel OFFICIAL / CUSTOM

Date : 27 septembre 2026. Audit du dépôt et des contraintes PostgreSQL locales, sans modification de code, de schéma ou de données. Seul ce rapport est créé. Aucun import, migration, test écrivant en base, commit, push ou déploiement exécuté.

## 1. Modèle actuel et conclusion

Le modèle permet de conserver les définitions actuelles et leurs PK. Il faut ajouter une provenance et un propriétaire aux définitions réutilisables **et aux attributions indépendantes**. Ajouter seulement un propriétaire aux capacités ne suffit pas : une règle ajoutée à une classe officielle serait aujourd'hui appliquée à tous ses utilisateurs.

Recommandation minimale : huit types de définitions et trois types d'attributions portent `origin` (`OFFICIAL` / `CUSTOM`) et `owner: ?User`. Quatre enfants techniques héritent de leur parent. Garder les identifiants et slugs techniques historiques, générer des slugs réservés pour les nouveaux contenus personnels, filtrer les règles avant résolution et protéger toutes les écritures et sélections par ID.

Le terme `origin` évite la confusion avec `CharacterFeatureRule::sourceType()`, qui signifie déjà classe/sous-classe/race/don/progression, et avec les autres sources métier.

Constats importants :

- Le booléen `custom` des huit définitions ne porte aucune propriété ni sécurité.
- `CharacterFeatureRule`, `TrackableResourceRule` et `CharacterActionClassRule` ont chacun une PK entière. Ce sont de bonnes bases pour des attributions personnelles et un futur masquage.
- `TrackableResourceRule` n'est **pas** un simple enfant de configuration : elle relie une ressource à une origine de personnage et peut elle-même fournir la ressource, imposer un maximum et ajouter un bonus.
- Une sous-classe possède une seule classe par FK ; elle n'est pas réutilisable sous plusieurs classes. La race parente et la ressource d'une capacité sont aussi des FK directes.
- Les progressions narratives fonctionnent déjà avec un contexte explicite de valeurs ; le chantier annoncé comme futur dans AGENTS.md est en partie réalisé. `ProgressionAdjustmentRule` existe également.
- Le dépôt actuel contient à la fois le back-office Twig et une route Angular active `dnd/reference` avec ses gestionnaires. Ne pas supposer que ce dernier a disparu.

### Méthode et portée des preuves

Les **37 entités Doctrine** du répertoire `src/Entity` ont été inventoriées : 15 constituent le noyau de référence D&D, une référence météo est périphérique, 21 entités délimitent les données de campagne, de personnage et d'identité. Les services, contrôleurs, repositories, imports, migrations et consommateurs Angular pertinents ont été inspectés.

Trois lectures PostgreSQL ont été exécutées avec `BEGIN READ ONLY` et `ROLLBACK` : FK/CHECK, index uniques, compteurs des huit définitions. La base locale confirme 54 FK publiques et les unicités citées ci-dessous ; la requête ne retourne aucun CHECK public. Il ne s'agit ni d'une validation de production ni d'un essai fonctionnel de suppression. Les comportements de suppression sont déduits des FK et du code.

## 2. Cartographie des entités

Les liens ci-dessous pointent vers les fichiers réels du dépôt. « Éditorial » signifie modification nom/description, et labels gain/dépense pour les progressions ; ce n'est pas un CRUD mécanique complet.

### Noyau : 15 entités

| Entité / fichier | Table | Rôle et relations principales | Nature | Back-office Symfony/Twig actuel |
|---|---|---|---|---|
| [CharacterClass](../src/Entity/CharacterClass.php) | `character_class` | Dé de vie, sélection de sous-classe, progression magique ; cible des règles et niveaux acquis | Définition racine | Éditorial ; règles consultables |
| [CharacterSubclass](../src/Entity/CharacterSubclass.php) | `character_subclass` | FK obligatoire vers une classe ; progression magique spécifique | Définition avec parent structurel, propriété indépendante nécessaire | Éditorial |
| [CharacterRace](../src/Entity/CharacterRace.php) | `character_race` | Race et sous-race dans la même table ; FK parent facultative, métadonnées et modificateurs | Définition racine, même pour une sous-race | Éditorial ; modificateurs consultables |
| [Feat](../src/Entity/Feat.php) | `feat` | Don, répétabilité, choix/bonus de caractéristique | Définition racine | Éditorial |
| [CharacterFeatureDefinition](../src/Entity/CharacterFeatureDefinition.php) | `character_feature_definition` | Capacité, activation, visibilité, FK facultative vers une ressource | Définition racine | Éditorial dédié ; attributions consultables |
| [TrackableResourceDefinition](../src/Entity/TrackableResourceDefinition.php) | `trackable_resource_definition` | Recharge et formule de maximum | Définition racine | Éditorial ; règles et fournisseurs consultables |
| [ProgressionDefinition](../src/Entity/ProgressionDefinition.php) | `progression_definition` | Jauge narrative, bornes, labels, stages, règles descriptives | Définition racine | Éditorial ; enfants consultables |
| [CharacterActionDefinition](../src/Entity/CharacterActionDefinition.php) | `character_action_definition` | Action contrôlée, `handlerType`, préparation, activation | Définition racine liée à des handlers codés | Lecture indirecte sur fiche classe ; pas d'écran CRUD dédié |
| [CharacterFeatureRule](../src/Entity/CharacterFeatureRule.php) | `character_feature_rule` | Attribution capacité à classe/sous-classe/race/don/progression | Association autonome, niveau/seuil/ordre | Lecture depuis fiches ; API de gestion existante hors Twig |
| [TrackableResourceRule](../src/Entity/TrackableResourceRule.php) | `trackable_resource_rule` | Attribution et maximum/bonus de ressource par classe/sous-classe/race/don | Association autonome | Lecture depuis fiches ; API de gestion existante hors Twig |
| [CharacterActionClassRule](../src/Entity/CharacterActionClassRule.php) | `character_action_class_rule` | Attribution action à classe avec niveau | Association autonome | Lecture sur classe |
| [CharacterClassLevelRule](../src/Entity/CharacterClassLevelRule.php) | `character_class_level_rule` | Choix d'avancement pour un niveau de classe | Enfant technique de classe | Lecture sur classe |
| [RaceAbilityModifier](../src/Entity/RaceAbilityModifier.php) | `race_ability_modifier` | Bonus fixe ou choix racial identifié par `choiceKey` | Enfant technique de race | Lecture sur race |
| [ProgressionStage](../src/Entity/ProgressionStage.php) | `progression_stage` | Intervalle, label, description et icône | Enfant technique de progression | Lecture sur progression ; API enfants existante |
| [ProgressionAdjustmentRule](../src/Entity/ProgressionAdjustmentRule.php) | `progression_adjustment_rule` | Direction, déclencheur descriptif, texte d'ajustement ; aucun moteur automatique | Enfant technique de progression | Lecture sur progression ; API enfants existante |

Références d'administration : [AdminReferenceCatalogue](../src/Service/AdminReferenceCatalogue.php), [AdminFeatureReference](../src/Service/AdminFeatureReference.php), [AdminReferenceCatalogueController](../src/Controller/AdminReferenceCatalogueController.php), [AdminFeatureReferenceController](../src/Controller/AdminFeatureReferenceController.php).

### Référence périphérique et exclusions : 22 entités

Toutes les lignes suivantes sont hors du back-office de référence Twig décrit ci-dessus. « C » signifie pas de nouvelle notion OFFICIAL/CUSTOM dans le périmètre minimal.

| Entité | Table | Rôle, dépendance et classification |
|---|---|---|
| `Weather` | `weather` | Référence périphérique : `system` + campagne nullable ; utilisée par `GameSession`. C pour cette livraison ; futur catalogue OFFICIAL/CUSTOM validé, avec audit/migration spécifiques |
| `User` | `user` | Identité, propriétaire ; C |
| `Campaign` | `campaign` | Racine de campagne appartenant à User ; C |
| `Character` | `character` | Personnage de campagne, FK race, agrégat des acquisitions ; C |
| `GameSession` | `game_session` | Session de campagne, FK météo ; C |
| `CharacterSessionState` | `character_session_state` | Association personnage/session et état JSON mutable, token et autorisation level-up ; C |
| `CharacterClassLevel` | `character_class_level` | Niveau acquis, FK classe/sous-classe, PV ; C |
| `CharacterFeat` | `character_feat` | Acquisition de don par personnage, choix associé ; C |
| `CharacterProgression` | `character_progression` | Attribution de progression à un personnage, sans valeur courante ; C |
| `CharacterRaceAbilityChoice` | `character_race_ability_choice` | Choix d'un personnage pour un modificateur racial ; C |
| `CharacterAbilityScore` | `character_ability_score` | Caractéristique du personnage ; C |
| `CharacterAbilityAdjustment` | `character_ability_adjustment` | Ajustement individuel, source métier distincte de la provenance du catalogue ; C |
| `CharacterWallet` | `character_wallet` | Portefeuille individuel ; C |
| `MagicItem` | `magic_item` | Définition actuelle appartenant obligatoirement à une campagne, pas un catalogue global ; C |
| `MagicItemAbilityEffect` | `magic_item_ability_effect` | Enfant technique de MagicItem ; C, droits hérités de la campagne existante |
| `CharacterMagicItem` | `character_magic_item` | Occurrence possédée avec quantité, charges, harmonisation ; C |
| `CharacterActiveEffect` | `character_active_effect` | Effet actif entre personnages source/cible ; C |
| `RestRequest` | `rest_request` | Demande de repos d'un state, User ayant répondu ; C |
| `Media` | `media` | Asset de campagne ; C |
| `Quest` | `quest` | Quête de campagne ; C |
| `Tip` | `tip` | Conseil/texte de campagne ; C |
| `CampaignFigure` | `campaign_figure` | Figure de campagne, personnage/portrait facultatifs ; C |

Les enums (`Ability`, progressions magiques, recharges, handlers, raretés, phases de lune, etc.), tables PHP de slots/prérequis et fichiers JSON de référence ne sont pas des entités Doctrine. Aucun owner à ajouter mécaniquement à ces structures. Les objets magiques réutilisables par User font partie de la vision retenue et demandent une évolution propre ; ne pas convertir silencieusement les objets de campagne.

## 3. Cartographie précise des attributions

| Relation | Représentation actuelle | Identité / contraintes | Conséquence OFFICIAL/CUSTOM |
|---|---|---|---|
| Capacité → classe | `CharacterFeatureRule.characterClass` + `featureDefinition`, deux FK | PK `id`, unique classe/capacité/niveau | Règle CUSTOM du MJ reliant une classe OFFICIAL et une capacité OFFICIAL ou CUSTOM |
| Capacité → sous-classe | Même entité, `characterSubclass` | PK, unique sous-classe/capacité/niveau | Même portée indépendante |
| Capacité → race | Même entité, `characterRace` | PK, unique race/capacité/niveau | Inclut héritage des races parentes ; filtrer aussi ces règles |
| Capacité → don | Même entité, `feat` | PK, unique don/capacité/niveau | Même portée indépendante |
| Capacité → progression narrative | Même entité, `progressionDefinition`, `progressionThreshold` | PK, unique progression/capacité/seuil | Progression CUSTOM pouvant activer une capacité OFFICIAL ou CUSTOM |
| Sous-classe → classe | FK obligatoire sur `CharacterSubclass` | Une seule classe ; unique classe/slug | Sous-classe CUSTOM sous classe OFFICIAL possible sans changer la classe |
| Sous-race → race | FK `parentRace` sur `CharacterRace` | Pas d'association distincte | Sous-race CUSTOM sous race OFFICIAL possible ; valider cycles et accès aux ancêtres |
| Capacité → ressource | FK nullable `CharacterFeatureDefinition.resourceDefinition` | Une seule ressource, aucune PK d'attribution | Capacité CUSTOM → ressource OFFICIAL/CUSTOM possible ; modifier la ressource d'une capacité OFFICIAL modifierait la définition globale |
| Ressource → classe/sous-classe/race/don | `TrackableResourceRule`, FK ressource et une origine | PK ; unique origine/ressource/niveau ; maximum imposé + bonus | L'association doit avoir sa propre portée pour étendre une ressource OFFICIAL à une classe CUSTOM ou ajouter une règle personnelle sur une classe OFFICIAL |
| Action contrôlée → classe | `CharacterActionClassRule`, deux FK | PK ; unique action/classe, **sans niveau dans l'unicité** | Portée indépendante, même si l'action est OFFICIAL |
| Avancement par niveau → classe | `CharacterClassLevelRule` | PK ; unique classe/niveau | Configuration interne héritant de la classe ; aucune surcharge personnelle d'une classe officielle proposée ici |
| Progression narrative → personnage | `CharacterProgression` | PK ; unique personnage/progression | Acquisition de campagne ; la progression reste propriété du User |
| Progression narrative → classe/sous-classe | **Aucune relation actuelle** | Sans objet | Ne pas confondre attribution narrative et progression magique |
| Progression magique → classe/sous-classe | Enum sur la définition, calcul PHP des slots | Pas de table d'attribution | Hérite des droits de la définition ; Pact Magic reste séparé |
| Race → modificateurs | `RaceAbilityModifier.race` | PK ; unique race/choiceKey nullable | Configuration interne de race |
| Progression → stages/règles descriptives | FK enfant → progression, collections OneToMany | PK propres ; pas d'identité d'attribution indépendante | Héritage du propriétaire de progression |
| Personnage → classe/sous-classe/don/race | Niveaux acquis, `CharacterFeat`, FK race directe | Identités d'acquisition distinctes du catalogue | Vérifier la sélection ; pas de source/owner de référence sur les acquisitions |

Aucune ManyToMany implicite ne porte les relations centrales ci-dessus. Les associations nommées sont de vraies entités. Les factories des règles limitent les origines renseignées ; **aucun CHECK SQL observé ne garantit exactement une origine non nulle**.

### Limite classe CUSTOM + sous-classe OFFICIAL

Une sous-classe officielle existante reste liée à sa classe officielle. `CharacterClassLevel` refuse explicitement une sous-classe dont la classe diffère. On ne peut donc pas rattacher Champion officiel à une nouvelle classe CUSTOM sans changer le modèle ou cloner la sous-classe.

Proposition minimale : garder cette contrainte métier, autoriser une copie CUSTOM si souhaité. Si la réutilisation de la même sous-classe officielle sous plusieurs classes devient obligatoire, créer ultérieurement une association classe/sous-classe et adapter acquisitions, options et resolvers. Ne pas ajouter cette complexité avant décision.

### Exemple d'extension personnelle

Conserver la définition officielle Champion et sa règle officielle vers Critique amélioré. Créer une définition CUSTOM appartenant à U puis une `CharacterFeatureRule` CUSTOM appartenant à U vers Champion. Aucun UPDATE sur les lignes officielles. La nouvelle règle ne doit entrer dans la résolution que pour les personnages dont le propriétaire de campagne est U. Avant le futur masquage, les deux capacités coexistent.

## 4. Modèle User actuel

[User](../src/Entity/User.php) est mappé sur `user` (nom SQL cité), PK entière générée `id`. Email de longueur 180, non nullable en DB, contrainte unique `UNIQ_IDENTIFIER_EMAIL`, password haché et tableau de rôles. Les setters ne normalisent pas l'email ; ne pas supposer une unicité insensible à la casse. `getRoles()` ajoute toujours `ROLE_USER`. Pas de soft delete ni collection inverse de contenus.

Relations entrantes actuelles :

- `Campaign.owner`, obligatoire, FK sans cascade de suppression (`NO ACTION`) ; unique `(owner_id, slug)` sur Campaign. Un User ayant une campagne ne peut pas être supprimé directement.
- `RestRequest.resolvedBy`, nullable, `ON DELETE SET NULL`.

Aucun workflow de suppression User trouvé dans `AuthController`/`UserRepository`. Les futurs contenus CUSTOM doivent bloquer une suppression physique du User tant qu'ils subsistent, ou relever d'une procédure explicite d'archivage/transfert ; `SET NULL` convertirait implicitement la propriété et violerait l'invariant CUSTOM.

[security.yaml](../config/packages/security.yaml) utilise le provider Doctrine par email, le login JSON et une session de sécurité. `/public/` et `/dnd/reference` sont publics ; le reste demande `ROLE_USER`. Le [CampaignVoter](../src/Security/Voter/CampaignVoter.php) compare le propriétaire de campagne à l'utilisateur authentifié. Il ne sécurise pas les définitions globales. Les contrôleurs de référence et Twig demandent essentiellement `ROLE_USER`, sans séparation ADMIN/MJ ni propriété.

## 5. Classification source / propriétaire

### A — colonnes directes : 11 entités

**Huit définitions** : `CharacterClass`, `CharacterSubclass`, `CharacterRace`, `Feat`, `CharacterFeatureDefinition`, `TrackableResourceDefinition`, `ProgressionDefinition`, `CharacterActionDefinition`.

**Trois associations** : `CharacterFeatureRule`, `TrackableResourceRule`, `CharacterActionClassRule`.

Chacune reçoit conceptuellement `origin` et `owner_id` nullable. L'action contrôlée peut entrer dans ce schéma tout en restant non créable par les MJ dans un premier temps : un nouveau texte d'action ne crée pas un nouveau handler métier. Les quatre handlers actuels ne constituent pas un moteur de règles custom universel.

Pour `TrackableResourceRule`, hériter uniquement de la ressource interdirait des règles personnelles sur une ressource officielle ; hériter uniquement de la classe interdirait des règles personnelles sur une classe officielle. Sa portée propre est donc justifiée, contrairement à l'exemple hypothétique d'une ResourceRule purement enfant.

### B — héritage implicite : quatre entités

- `CharacterClassLevelRule` → `CharacterClass`.
- `RaceAbilityModifier` → `CharacterRace`.
- `ProgressionStage` et `ProgressionAdjustmentRule` → `ProgressionDefinition`.

Un MJ édite ces enfants seulement si le parent est son CUSTOM. Modifier les stages d'une progression OFFICIAL ou les bonus d'une race OFFICIAL reste interdit. Les besoins de surcharge de ces configurations seraient des tickets séparés.

### C — aucune nouvelle provenance dans cette évolution

Les 21 entités d'identité/campagne/personnage et `Weather` restent hors de la conversion du noyau. Weather et MagicItem font partie de la vision future : définitions OFFICIAL globales et CUSTOM appartenant à un User, réutilisables dans toutes ses campagnes. Leurs modèles actuels nécessitent un audit et une migration spécifiques ; ils restent hors de cette première migration. Le backfill du noyau suit les exceptions personnelles validées en section 11 ; il ne requalifie pas les campagnes, personnages ou états mutables.

## 6. Identité, slugs et compatibilité

### Unicités réelles

| Type | Contrainte actuelle vérifiée en base |
|---|---|
| Classe, race, don, capacité, ressource, progression narrative, action | `UNIQUE(slug)` par table |
| Sous-classe | `UNIQUE(character_class_id, slug)` ; slug seul non global |
| Campagne | `UNIQUE(owner_id, slug)` ; hors conversion |
| Personnage | `UNIQUE(campaign_id, slug)` ; hors conversion |

Les PK entières existent déjà. Aucun nouvel identifiant technique universel n'est requis pour les relations SQL.

### Dépendances constatées

- `CharacterFeatureResolver` déduplique par slug de capacité ; `CharacterResourceResolver` agrège par slug de ressource ; `CharacterActionResolver` indexe les actions par slug.
- `CharacterSessionState.state.resources[*].id` et `progressions[*].id` sont des slugs, pas les PK. Les actions préparées utilisent aussi des identités textuelles.
- Factory, synchronizer, repos, sérialisation et frontend consomment ces clés. Le repos recherche les définitions de ressources historiques par slug, même si elles ne sont plus actives.
- Les slots calculés utilisent `spell-slot-1` à `spell-slot-9`, sans définition Doctrine correspondante : réserver cet espace de noms.
- `PortentResourceConfiguration` reconnaît `des-de-presage` ; Flexible Casting utilise ses ressources dédiées ; Arcane Recovery et le sérialiseur reconnaissent la classe `wizard`.
- `CharacterMulticlassEligibilityService` indexe les prérequis par slug de classe ; une classe inconnue n'a actuellement aucun prérequis configuré.
- `DndReferenceInitializer`, `ImportClassFeaturesCommand`, `ImportRacesCommand`, `ImportFeatsCommand` et les migrations historiques retrouvent les lignes par slug (classe + slug pour les sous-classes). Certaines protections utilisent encore `custom`.
- Les routes de gestion de référence utilisent surtout des IDs numériques ; les endpoints d'actions et les payloads runtime conservent des slugs. Les contrats Angular exposent à la fois ID et slug.
- Les fixtures/harnesses sous `backend/tools` et les catalogues JSON reposent sur ces identités canoniques : futurs tests d'isolation doivent les compléter, sans renommer les clés historiques.

### Recommandation minimale

**Conserver les unicités actuelles et traiter le slug comme une clé technique stable**, indépendante du nom éditorial. Générer côté serveur les nouveaux slugs CUSTOM dans un espace réservé, par exemple `custom-<identifiant-aleatoire>`, compatible avec les longueurs de 80/100 caractères et les regex existantes. Interdire la modification du slug après création et réserver les identités mécaniques officielles. La réservation doit aussi s'appliquer aux imports futurs. Les noms affichés peuvent être identiques chez plusieurs MJ.

Ne pas remplacer simplement `UNIQUE(slug)` par `UNIQUE(owner_id, slug)` : un MJ pourrait posséder une ressource personnelle portant le même slug qu'une officielle, et les deux se confondraient dans son state. Le problème existe même avec un seul utilisateur.

Alternative si des slugs éditoriaux identiques sont indispensables : conserver une clé runtime immuable distincte et migrer toutes les clés d'état, actions, resolvers, repos et sérialisations ; utiliser des index partiels séparés OFFICIAL et CUSTOM pour les slugs. Coût nettement supérieur, aucune nécessité produit établie ici. Un index nullable simple ne suffit pas à assurer l'unicité des lignes officielles.

## 7. Hypothèses runtime globales et changements nécessaires

| Zone actuelle | Hypothèse / impact futur |
|---|---|
| `CharacterFeatureRuleRepository::findOrdered()` | Charge toutes les règles ; filtrer OFFICIAL + CUSTOM du propriétaire avant applicabilité et déduplication |
| `TrackableResourceRuleRepository::findOrderedRules()` | Charge toutes les règles ; filtre identique avant somme des bonus et choix de maximum |
| `CharacterActionClassRuleRepository::findActiveOrdered()` | Filtre seulement les actions actives ; ajouter la portée des règles et définitions |
| Repositories des définitions, `find`, `findBy`, `findOneBy` | Aucun owner disponible aujourd'hui ; créer des requêtes de visibilité explicites et contrôler les accès directs par ID |
| `DndReferenceController` | Catalogue public global classes/races/sous-classes/dons ; ne jamais y renvoyer tous les CUSTOM |
| Contrôleurs `CharacterClass`, `CharacterSubclass`, `CharacterRace`, `Feat`, `CharacterFeatureDefinition`, `CharacterFeatureRule`, `TrackableResourceDefinition`, `TrackableResourceRule`, `Progression` | Listes, lookups, relations et mutations aujourd'hui globales ; contrôles de portée indispensables sur chaque chemin |
| `AdminReferenceCatalogue`, `AdminFeatureReference` | Recherche, compteurs, listes de choix et édition sans périmètre propriétaire ; futur chemin OFFICIAL réservé à l'administration |
| `CharacterBuilderController` / `CharacterBuilderService` | Voter de campagne présent, mais race/classe/don/sous-classe résolus par ID global ; vérifier qu'ils sont utilisables par le propriétaire de campagne |
| `CharacterLevelUpOptionsService`, `CharacterLevelUpRequestResolver`, `CharacterLevelUpService` | Options globales et récupération par ID ; filtrer options et revalider la soumission, y compris via token joueur |
| `CharacterProgressionController`, contrôleurs de profil/personnage | Valider les attributions sélectionnées par ID et leur propriétaire ; les droits sur campagne ne suffisent pas |
| `CharacterProfileSerializer`, `CharacterSessionStateSerializer` et contrôleurs public/state | Résoudre dans le catalogue du propriétaire du personnage, même sans User connecté ; conserver les données MJ exclues des profils joueurs |
| `CharacterSessionStateFactory` | Initie progressions et ressources ; même contexte de catalogue que les autres chemins |
| `CharacterSessionStateSynchronizer` | `synchronize`, `synchronizeResources`, `snapshot`, `snapshotForLevelUp`, `synchronizeAfterLevelUp` doivent partager la portée ; conserver les consommations et entrées historiques |
| `CharacterRestService` | Scope du resolver et de la recherche de définitions historiques ; ne jamais résoudre une clé privée d'un autre MJ ; conserver les règles de recharge historiques et les progressions indépendantes des repos |
| `CharacterAbilityCalculator`, `CharacterHitPointCalculator`, `CharacterSpellSlotCalculator`, `CharacterRaceMetadataResolver` | Calculent surtout depuis les relations déjà acquises : pas besoin d'injecter un User partout, mais garantir que les objets/ancêtres sélectionnés sont autorisés |
| Actions contrôlées et `CharacterActionController` | Vérifier l'action résolue dans le contexte du personnage avant exécution ; ne pas autoriser un handler arbitraire par payload |
| Angular `DndReferenceApiService`, builder, level-up, gestionnaires `features/dnd-reference` | Contrats sans propriétaire, catalogue commun ; fournir des options contextualisées, rafraîchir après changement de compte et empêcher toute réutilisation de données privées entre contextes |
| Imports / initialiseur / futures migrations de données | Lookups et upserts strictement OFFICIAL ; ne jamais modifier une association personnelle via une clé métier globale |

### Quel User fait autorité ?

Pour le catalogue personnel consulté par un MJ : l'utilisateur authentifié. Pour calculer un personnage : **`Character → Campaign → owner`**, y compris via token joueur, tâche CLI ou consultation par un administrateur. Ne pas utiliser le User connecté au hasard : un admin regardant le personnage de U doit obtenir les règles de U.

Proposition : résolution de cette portée depuis la campagne déjà reliée au Character, puis paramètre explicite User/owner aux requêtes concernées. Aucun repository de session ni dépendance au Security Token dans les resolvers. Les valeurs de progression restent un contexte distinct, explicitement fourni ; sans valeurs, les capacités de progression restent inactives.

Le catalogue anonyme doit rester OFFICIAL seulement. Les options privées du portail joueur doivent passer par un endpoint lié au token/personnage et limité à l'usage nécessaire, pas par un `ownerId` libre. Séparer les descriptions/règles MJ des informations publiques et éviter les caches HTTP partagés pour les réponses privées.

## 8. Frontières de sécurité futures

Sans créer de rôle ni de voter maintenant :

| Opération | Autorisation future |
|---|---|
| Lire une définition OFFICIAL | MJ autorisé ; public seulement selon le contrat existant |
| Créer/modifier/supprimer une définition ou attribution OFFICIAL | Administration uniquement, côté serveur |
| Lire/créer/modifier/supprimer un CUSTOM | Son propriétaire uniquement, sous réserve des usages pour supprimer |
| Modifier un enfant technique | Même autorisation que le parent |
| Créer une attribution CUSTOM sur une définition OFFICIAL | Autorisé au propriétaire de la nouvelle attribution ; aucune mutation de la définition officielle |
| Référencer un contenu CUSTOM | Même User propriétaire ; interdiction des relations privées entre deux propriétaires distincts |
| Sélection joueur | Via personnage/token et catalogue de son MJ, sans droit d'édition du catalogue |

Appliquer ces frontières dans les contrôleurs Twig et JSON, services de mutation/attribution, requêtes de catalogue et validations d'IDs étrangers. Ne pas faire confiance au formulaire, au booléen `custom`, au filtrage Angular ni à un `ownerId`/`origin` envoyé par le client. Fixer owner et origin côté serveur lors de la création. Empêcher leur changement par PATCH ordinaire.

Les formulaires Twig ont déjà des whitelists et des jetons CSRF ; les conserver, mais ils ne remplacent pas les droits. Les routes Angular/API actuellement actives doivent être couvertes avant ouverture multi-utilisateur, même si la future administration officielle reste Symfony/Twig. La refonte générale du PATCH de state joueur reste un chantier distinct.

## 9. Suppression et risques réels

| Objet supprimé physiquement | Effet actuel des FK locales | Risque / recommandation |
|---|---|---|
| Capacité | Suppression CASCADE de ses `CharacterFeatureRule` ; aucune acquisition directe de capacité par personnage ne bloque | Disparaît des profils sans empêcher la suppression ; ressources JSON historiques subsistent. Bloquer si attribuée/utilisée, proposer archivage |
| Sous-classe | `CharacterClassLevel.subclass_id` sans cascade : bloque si utilisée ; règles capacité/ressource CASCADE | Sans niveau acquis, les attributions pourraient disparaître ; expliciter les usages avant suppression |
| Race | `Character.race_id` SET NULL ; sous-races `parent_race_id` SET NULL ; modificateurs et règles CASCADE ; choix raciaux CASCADE via modifier | Suppression permise mais altération de personnages, héritage et choix. Garde explicite indispensable |
| Progression | `CharacterProgression` RESTRICT ; stages, règles descriptives et attributions de capacité CASCADE | L'API actuelle appelle remove/flush sans garde d'usage ; une FK peut produire une erreur serveur. L'historique JSON seul ne bloque rien |
| Ressource | FK capacité SET NULL ; règles ressource CASCADE | Perte de lien et de recharge/calcul, état JSON orphelin ; bloquer les usages et historiques |
| Classe | Niveaux acquis RESTRICT dans la base locale ; sous-classes, règles de niveau/capacité/ressource/action CASCADE | Suppression pouvant effacer un sous-arbre non acquis ; analyser tous les usages, pas seulement niveaux directs |
| Don | Acquisition `CharacterFeat` RESTRICT ; règles capacité/ressource CASCADE | Garde d'usage ; conserver les acquisitions |
| Action contrôlée | Attributions classe CASCADE | Préparations JSON non protégées par FK ; ne pas effacer une action utilisée sans traitement explicite |
| User | Campagnes NO ACTION ; demandes de repos SET NULL | Futurs owner de catalogue en RESTRICT ; pas de purge automatique des contenus |

Les routes DELETE de définition ne sont pas toutes disponibles aujourd'hui : les risques ci-dessus décrivent les effets du modèle, pas l'existence d'un bouton de suppression pour chaque type. Les APIs de suppression de règles et de progression existent réellement.

Stratégies possibles :

1. **Blocage explicite**, réponse métier avec usages : solution initiale la plus simple. Conserver des FK restrictives là où une suppression ne doit jamais casser une acquisition ; examiner les CASCADE/SET NULL existants avant ouverture du CRUD custom.
2. **Archivage** (`archivedAt` par exemple) : exclure des nouvelles sélections, garder la résolution des personnages existants et les historiques. Recommandé pour le contenu utilisé. Archiver une règle ne doit pas implicitement la masquer dans tous les personnages si l'intention était seulement d'arrêter les nouvelles acquisitions.
3. **Soft delete** : possible mais pas avec un filtre Doctrine global aveugle ; il pourrait rendre illisibles les relations historiques. Définir explicitement les lectures autorisées après retrait.
4. **Versionnement/copie** : utile si les modifications mécaniques doivent épargner les personnages existants ; hors solution minimale tant que ce comportement n'est pas demandé.

Ne pas décider CASCADE par défaut. Les futurs masquages, usages indirects et `CharacterSessionState` doivent faire partie de la détection d'usage. La suppression d'une attribution personnelle ne doit pas supprimer sa définition officielle ni purger silencieusement les ressources consommées.

## 10. Compatibilité du futur masquage

Première cible : `CharacterFeatureRule.id`. Une future association concrète `User + CharacterFeatureRule + hidden`, unique par paire, permettrait de masquer une attribution officielle et non la définition. Filtrer les règles masquées **avant** `CharacterFeatureResolver::shouldReplaceRule()` et avant la déduplication par capacité ; sinon une autre attribution valable de la même capacité pourrait être perdue.

Autres cibles techniquement possibles : `TrackableResourceRule.id`, `CharacterActionClassRule.id`. Utiliser des relations concrètes si le besoin apparaît, sans table universelle polymorphe. Pour les enfants techniques avec PK, l'identité existe mais la sémantique de masquage serait une nouvelle règle produit.

Les FK sous-classe/classe, race/parente et capacité/ressource n'ont **pas d'identité d'attribution indépendante** : l'ID de la définition porteuse ne remplace pas un ID d'association. Un masquage de ces liens exigerait une autre conception ; le cas Champion/capacité n'en a pas besoin.

**Stabilité à garantir** : les PK sont stables tant que la ligne est préservée, pas des identifiants métier portables entre réinstallations. Les imports ont des upserts, mais `ImportRacesCommand` peut supprimer des règles obsolètes ; des migrations historiques consolident/suppriment des attributions. Avant le masquage, interdire de supprimer/recréer une règle inchangée, conserver les IDs lors des mises à jour, et décider du traitement d'une attribution officielle retirée (blocage, archivage ou remappage explicite). Une FK de masquage RESTRICT serait un garde possible, pas une décision implémentée.

Le masquage d'une règle de capacité ne supprimera pas une attribution de la même capacité par une autre origine, ni une ressource encore fournie directement par `TrackableResourceRule`. C'est cohérent avec « masquer cette attribution », mais à rendre clair dans l'interface future.

## 11. Architecture minimale proposée

### Champs et invariants

- Enum concret `ReferenceOrigin` avec deux valeurs OFFICIAL/CUSTOM ; champ `origin` non nullable sur les onze entités A.
- FK `owner_id → user.id`, nullable, suppression RESTRICT.
- CHECK local : `(origin = 'OFFICIAL' AND owner_id IS NULL) OR (origin = 'CUSTOM' AND owner_id IS NOT NULL)` ; contrôler aussi les valeurs autorisées de l'enum dans la DB.
- Index sur owner pour les accès personnels ; garder les PK et slugs historiques.
- Pas d'owner sur les quatre enfants B ; pas de table `content`, de hiérarchie Doctrine ou de mécanisme générique d'overlay.
- Une attribution OFFICIAL ne peut référencer que des définitions OFFICIAL. Une attribution CUSTOM de U peut référencer des définitions OFFICIAL et/ou CUSTOM de U, y compris deux définitions officielles. Elle ne peut jamais référencer le CUSTOM de V.
- Pour une FK interne à une définition (parent de sous-classe/race ou ressource de capacité), une définition OFFICIAL ne dépend pas d'une définition CUSTOM. Une définition CUSTOM de U peut dépendre d'une OFFICIAL ou d'une CUSTOM de U. La demande de mélange est couverte dans la limite des cardinalités existantes.
- Les validations transversales d'owner/origin entre tables sont métier, pas garanties par un simple CHECK local. Les appliquer également aux imports et empêcher les changements de propriété/provenance qui invalideraient des relations.
- Ajouter à terme les CHECK d'origine unique sur les règles : une seule FK d'origine renseignée ; seuil présent si et seulement si progression. Ne jamais utiliser le dummy `unlockLevel=1` pour activer une progression.

### Unicité des attributions

Les unicités actuelles ignorent owner. Les conserver telles quelles empêcherait deux MJ de créer la même association personnelle entre deux définitions officielles.

Proposer, pour chaque origine de `CharacterFeatureRule` et `TrackableResourceRule`, deux index uniques partiels : clé métier actuelle pour OFFICIAL, et `(owner_id, clé métier actuelle)` pour CUSTOM. Même principe pour `CharacterActionClassRule` avec `(action_definition_id, character_class_id)`. Les cinq origines des capacités incluent la clé progression/capacité/seuil ; les quatre origines des ressources utilisent le niveau. Limiter chaque index aux lignes de son origine renseignée.

Ainsi une attribution personnelle peut coexister avec l'officielle sans UPDATE global. Cette coexistence ne signifie pas « remplacement » : appliquer la politique actuelle de résolution jusqu'à décision contraire. Les bonus de ressources sont additionnés ; des doublons fonctionnels peuvent donc cumuler des effets même avec des contraintes correctes. Définir une priorité déterministe en cas de maximums concurrents au même niveau, sans décider que CUSTOM écrase automatiquement OFFICIAL.

### Conséquence de la migration de l'ancien `custom`

Comptage local en lecture seule :

| Définition | Total | `custom=true` actuel |
|---|---:|---:|
| Classes | 13 | 0 |
| Sous-classes | 105 | 1 |
| Races | 160 | 0 |
| Dons | 83 | 0 |
| Capacités | 1531 | 2 |
| Ressources | 60 | 4 |
| Progressions | 3 | 3 |
| Actions | 4 | 0 |

La qualification définitive retient **10 définitions CUSTOM / owner User #1** : sous-classe #20 `arch-hag` ; capacités #247 `vignes-de-venlee`, #248 `entite-symbiotique`, #249 `deplacement-eclair` ; ressources #54 `enchevetrement`, #55 `entite-symbiotique`, #56 `deplacement-eclair` ; progressions #1 `corruption-draconique`, #2 `infestation-fongique`, #3 `bombe-electrique`.

Les **trois CharacterFeatureRule #243, #244 et #245** deviennent CUSTOM / owner User #1. Toutes les autres lignes des onze tables deviennent OFFICIAL / owner NULL. Les ressources #4 et #53 restent OFFICIAL malgré `custom=true`. La capacité #248 et la ressource #55 sont CUSTOM malgré `custom=false` : ces deux faux négatifs confirment que le flag legacy ne suffit pas. Les acquisitions, PK, slugs, liens et states sont conservés intégralement, notamment `deplacement-eclair=5` dans le state #23 sous le seuil d'activation.

L'ancien booléen peut être conservé temporairement comme donnée legacy, sans servir aux droits, le temps de remplacer ses usages : filtres Twig, payloads, protection des imports et sélection des races. Il ne doit pas devenir une deuxième vérité permanente. Préserver les comportements de sélection/import intentionnels avant de le retirer ; les protections d'import historique ne sont pas équivalentes à la propriété utilisateur. Sauvegarder les anciennes valeurs pour un retour arrière maîtrisé.

## 12. Plan de migration futur par étapes vérifiables

1. **Figer les décisions et l'état de référence.** Confirmer le périmètre météo/objets/actions, slugs techniques, suppression et parenté de sous-classe. Inventaire des PK, FK, valeurs legacy `custom`, doublons et empreintes de `CharacterSessionState` (état, tokens, participation, level-up). Sauvegarde restaurable. Validation : aucune écriture, référence avant/après disponible.
2. **Ajouter le schéma de provenance sans ouvrir les créations CUSTOM.** Onze tables A, origin/owner, backfill OFFICIAL / owner NULL sauf les dix définitions et trois attributions personnelles explicitement qualifiées ci-dessus. Préserver PK, FK, slugs, données et JSON. Ajouter contraintes locales et index après précontrôle. Validation : mêmes comptes et références, exactement dix définitions CUSTOM et trois CharacterFeatureRule CUSTOM appartenant à User #1, égalité des empreintes de state ; schéma ORM/DB cohérent.
3. **Adapter imports et contrats legacy.** Upserts officiels explicites, aucune écriture sur custom, IDs d'attribution préservés ; distinguer provenance et protections historiques. Remplacer progressivement le booléen legacy. Validation : dry-run puis idempotence sur base jetable ; zéro mutation des données privées et zéro renumérotation des attributions inchangées. Ne pas rejouer aveuglément les anciennes migrations sur une base déjà migrée.
4. **Installer les frontières d'accès.** Lecture OFFICIAL + propre CUSTOM, droits serveur d'édition, validation de toutes les FK sélectionnées ; sécuriser APIs existantes et Twig. Catalogue anonyme officiel seulement ; chemin token contextualisé. Validation : matrice admin/U/V/anonyme/joueur, IDs devinés et payloads owner falsifiés. Aucune création custom disponible tant que tous ces chemins ne sont pas protégés.
5. **Propager la portée runtime.** Repositories de règles, resolvers, builder, level-up, serializers, factory, snapshots, synchronisation et repos utilisent le propriétaire de campagne. Validation : règles de U invisibles aux personnages de V, résultat identique en MJ et via token, seuils progression, ressources partagées, recharge et états historiques conservés.
6. **Ouvrir progressivement le CRUD personnel.** D'abord définitions et enfants techniques, puis attributions indépendantes et leurs index par portée. Génération de slugs, gardes de suppression/archivage ; conserver la cardinalité classe/sous-classe. Validation : mélanges demandés autorisés, relations entre deux owners refusées, aucune ligne officielle modifiée, essais dans deux campagnes d'un même User.
7. **Adapter les écrans consommateurs et clôturer la transition.** Catalogue et sélections Angular contextualisés ; administration officielle Twig ; retirer l'usage de `custom` legacy lorsque tous ses usages ont un remplacement vérifié. Validation : parcours réels builder/level-up/portail, switch de compte, absence de fuite de catalogue, régression des données officielles. Checks ciblés puis lint/container/schema/build/diff selon changements.
8. **Ticket ultérieur : masquage.** Ajouter uniquement les cibles utiles et filtrer avant résolution ; tester une attribution masquée et une autre active de la même capacité, indépendance des User, et préservation des ressources historiques. Rien à créer dans la première livraison.

Chaque étape se valide séparément sur une base de test. Les étapes de transition ne sont pas des états autorisant l'exposition prématurée de données privées. Prévoir un retour arrière applicatif ; après création de CUSTOM, ne pas supprimer les colonnes de propriété pour revenir en arrière sans export/restauration dédié.

## 13. Risques et décisions produit nécessaires

1. **Sous-classe officielle sous classe custom** : conserver l'appartenance unique actuelle et permettre une copie CUSTOM, ou exiger une réutilisation multiclasse de la même définition ? La seconde option augmente réellement le chantier.
2. **Identité** : accepter des slugs techniques générés/immuables pour CUSTOM, avec noms libres identiques, ou demander des slugs éditoriaux locaux impliquant une migration des clés runtime ? Recommandation : première option.
3. **Suppression** : blocage du contenu utilisé, archivage ou versionnement ? Recommandation : blocage initial et archivage permettant la continuité des personnages existants. Décider également suppression du compte et éventuel transfert de campagne.
4. **Périmètre validé** : Weather et MagicItem rejoindront ultérieurement OFFICIAL/CUSTOM avec des définitions personnelles réutilisables entre campagnes ; leur audit/migration spécifique reste hors de la première étape. CharacterActionDefinition reçoit la provenance sans ouvrir de création CUSTOM.
5. **Effet des éditions** : une modification mécanique custom doit-elle affecter immédiatement tous les personnages des campagnes du propriétaire ? C'est l'effet du modèle partagé actuel ; isoler les personnages demanderait snapshots/versionnement.
6. **Attributions concurrentes** : additions personnelles seulement en première version, sans remplacement implicite ? Définir notamment la priorité des maximums de ressources au même niveau et prévenir les bonus involontairement doublés. Le masquage reste un ticket ultérieur.
7. **Exposition au joueur** : quelles options custom et descriptions sont nécessaires au builder/level-up public ? Garder les règles narratives réservées au MJ et limiter le catalogue accessible par token.
8. **Classement validé** : les trois progressions restent personnelles, avec leurs capacités, ressources et attributions qualifiées. Origin est la vérité de provenance ; le booléen legacy reste temporairement inchangé, sans synchronisation artificielle.

Risques techniques prioritaires : fuite depuis le catalogue public, oubli d'un contrôleur de mutation existant, collisions de clés d'état, import touchant une attribution personnelle, suppression en cascade d'usages indirects et résolution dans le contexte du visiteur au lieu du propriétaire du personnage.

### Validation de cet audit

État Git initial propre. Lectures de code et de métadonnées PostgreSQL uniquement, puis création de ce rapport. Aucun test applicatif/build lancé : l'audit ne modifie aucun comportement. `git diff --check` sans erreur ; contrôle explicite du fichier non suivi : 13 sections attendues, aucun lien local cassé, aucune ligne avec espace final. Le statut Git vérifié contient uniquement `?? backend/docs/custom-reference-architecture-audit.md` et aucun autre changement.
