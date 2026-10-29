# Sorts de progression — audit du 28 octobre 2026

Branche inspectée : `feature/spell-catalog`. Données : base Docker locale.

## Conclusion et point d'arrêt

Ténèbres manque à Hailee parce que son octroi fixe n'a pas été matérialisé
dans `character_feature_spell`. La capacité est bien active dans sa session.
Le contexte de progression arrive déjà jusqu'au répertoire joueur ; le perdre
à cet endroit n'est donc pas la cause.

Le moteur de lancement des dons est générique et réutilisable. En revanche,
son acquisition et ses ressources ne constituent pas encore un parcours
générique complet pour une progression. Une correction du seul contexte
ne satisfait pas la demande et peut introduire une régression intersessions.

L'implémentation fonctionnelle n'a pas été engagée. C'est l'application
prudente du point d'arrêt demandé pour une évolution majeure du modèle :
il faut distinguer l'acquisition globale de l'activation par session et
définir le cycle de vie des ressources générées par octroi. Ce jugement porte
sur les responsabilités du modèle, pas sur une migration SQL démontrée
complexe. Aucune refonte des sessions ni nouveau moteur de lancement n'est
nécessaire. Le plan ci-dessous vise à limiter cette évolution au système existant.

## Preuves locales : Hailee

| Élément | Valeur vérifiée |
| --- | --- |
| Personnage / état | 41 / 46 |
| Progression attribuée | 4, `etoile-noire`, minimum 1 |
| Valeur de session | 10 |
| Règle de capacité | 1608, seuil 8 |
| Capacité | 1547, Enfant des ténèbres |
| Octroi fixe | 140, déblocage niveau de personnage 1 |
| Sort | 455, Ténèbres, niveau 2 |
| Capacité applicable avec le state explicite | oui, vérifié par `applicableRules()` |
| Acquisition active pour cet octroi | absente |
| Résolution du lancement | `acquisition_missing` |
| Configuration de lancement / ressource | `null` / `null` |
| Caractéristique configurée | `CHOICE` |

Trois niveaux de diagnostic distincts :

1. **Absence de la carte** : `CharacterFeatureSpellService::status()` part des
   acquisitions actives persistées. Sans ligne pour l'octroi 140, il ne retourne
   pas Ténèbres. `PlayerSpellDirectory` n'invente pas une acquisition en lecture.
2. **Éligibilité de la capacité** : acquise et au-dessus du seuil dans l'état 46.
   Elle n'est pas la cause du défaut actuel. Sans contexte explicite, elle reste
   correctement inactive.
3. **Lancement après acquisition** : les méthodes et la ressource manquent.
   De plus, `CHOICE` ne résout une caractéristique que depuis `CharacterFeat`.
   Pour une progression, une configuration explicite INT/WIS/CHA/etc. est
   disponible ; emprunter arbitrairement une caractéristique de classe serait faux.

La recherche locale a retrouvé Armure d'Agathys (23, niveau 1) et Cône de froid
(96, niveau 5), mais aucun sort dont le nom contient « Barovie ». Aucun octroi
de ces trois autres sorts n'est associé aux capacités de la progression 4.
Le niveau de Voracité de Barovie n'est donc pas déterminable depuis ce catalogue.
Ne pas le créer, le remplacer par un sort officiel ou inventer son niveau.

La demande « dès la progression acquise » diffère de la configuration actuelle
au seuil 8. Pour des sorts disponibles dès l'attribution, une capacité dédiée
au seuil minimum 1 permettrait de conserver les autres capacités au seuil 8.
Ne pas abaisser silencieusement la règle existante. Foulée brumeuse et Linceul
d'ombre doivent rester des capacités personnalisées avec leurs propres ressources
bonus de maîtrise / repos long ; aucun octroi de sort n'a été créé pour elles.

## Comparaison des parcours

| Étape | Faveur des Fées | Progression |
| --- | --- | --- |
| Source | `CharacterFeat` et `CharacterFeatureRule::forFeat` | `CharacterProgression` et règle avec `progressionThreshold` |
| Activation | possession du don et niveau | possession, valeur explicitement transmise, seuil atteint |
| Sorts | `feature_spell_grant`, fixe ou choix | même table, attachée à la capacité |
| Acquisition | `character_feature_spell`, synchronisation fixe ou choix explicite | même stockage, mais synchronisation sans valeurs de progression |
| Caractéristique | explicite ou choix du don | explicite ; `CHOICE` non supporté |
| Méthodes | `resource`, `standard-slot`, ou les deux | moteur compatible avec une provenance progression résolue |
| Ressource attribuée | règles de ressources du don | ressource directe de capacité possible, mais une seule par capacité ; les ressources des octrois ne sont pas automatiquement collectées |
| Répertoire | acquisition active et source applicable | même garde, contexte de session déjà transmis |
| Consommation | disponibilité, validation serveur, exécuteur transactionnel | même moteur réutilisable, aucune raison de le dupliquer |

Les stages de progression décrivent des plages d'affichage. Les capacités sont
débloquées par `CharacterFeatureRule.progressionThreshold`, pas par une association
de sorts directement portée par un stage.

### Anatoly vérifié, sans exécuter la réparation

Personnage 29, état 35, don 4, attribution 49 :

- Octroi 13 : Foulée brumeuse (sort 219), niveau gratuit 2, ressource 62,
  `fey-touched-grant-13`, maximum 1, repos long, compteur actuel 0.
- Octroi 14 : Soins (sort 430), niveau gratuit 1, ressource 63,
  `fey-touched-grant-14`, maximum 1, repos long, compteur actuel 1.
- Les deux résolutions sont `resolved`, caractéristique `wisdom`, avec
  `resource` coût 1 et `standard-slot` à partir du niveau du sort.

`FeyTouchedCastingRepair` a un rôle spécifique de configuration : il crée les
ressources, les attribue au don via `TrackableResourceRule::forFeat`, configure
les octrois et initialise uniquement les compteurs manquants. Le runtime de
lancement qui suit est générique. Cette commande n'est pas un formulaire
générique et ne doit pas être réutilisée en remplaçant le nom du personnage.
Soins reste l'attribution volontaire existante du MJ.

Le dialogue propose les méthodes transmises par le serveur. La commande envoie
source, méthode, niveau, révision et identifiant de requête ; l'exécuteur résout
le coût et le compteur. Il verrouille les données, valide la révision et
enregistre consommation et reçu atomiquement. Un rejeu identique retourne le
reçu sans nouvelle consommation. Les emplacements standards restent distincts
de Pact Magic et des Arcanums.

## Lacunes précises et risque d'un correctif trop court

- `CharacterFeatureSpellService::propagateFixedGrant()` et `synchronize()`
  appellent `applicable()` sans valeurs de progression. Le premier ignore donc
  l'octroi 140 ; le second peut retirer une acquisition de progression existante.
- `synchronize()` peut passer `active=false` sur toute acquisition jugée
  inapplicable. Lui fournir la valeur d'une seule session ne résout pas le problème :
  une session sous le seuil pourrait retirer une acquisition utilisée ailleurs.
- `CharacterProgressionController::add()` synchronise les progressions et leurs
  ressources, mais ne matérialise pas les acquisitions de sorts.
- Les parcours MJ `status`, `options`, `choose` n'ont pas de contexte de session.
  Aucune recherche implicite de session ne doit être ajoutée.
- `CharacterResourceResolver` collecte les ressources directes de capacités et
  les règles de ressources existantes. Il ne collecte pas
  `feature_spell_grant.resource_definition_id`. Lier ce champ seul ne suffit pas
  à attribuer le compteur au personnage.
- L'API des octrois accepte déjà des règles de lancement avec une ressource
  existante, mais ne génère pas la ressource. Le PATCH n'autorise que
  `castingRules` et `resourceDefinitionId`, pas la correction de `castingAbility`.
- Le formulaire et son contrat TypeScript ne proposent pas les méthodes,
  utilisations, récupération ou niveau gratuit ; ils créent l'attribution seule.
- Le portail possède `hiddenFromTracker`, mais la projection des ressources
  d'octroi ne fournit pas un marquage explicite et fiable de compteur technique.
  Masquer tous les compteurs de capacités casserait les capacités ordinaires.

## Plan d'implémentation proposé

1. **Séparer acquisition et activation, sans état de session implicite.**
   Les octrois fixes suivent la possession de leur source ; les choix existants
   restent persistés. La lecture et le lancement vérifient toujours le seuil
   de la session explicite. Une baisse de progression suspend l'accès sans
   supprimer acquisition, historique ou consommation. Couvrir deux sessions
   aux valeurs différentes avant de brancher les événements d'attribution,
   création d'octroi et level-up. Réconciliation des données existantes : aperçu
   puis application explicite, jamais lors d'un GET.
2. **Gérer une ressource indépendante par octroi dans le référentiel.**
   Réutiliser `TrackableResourceDefinition` et ses repos, maximum fixe et owner.
   Identifier explicitement les ressources gérées par un octroi, distinctes des
   ressources partagées préexistantes. Définir modification, désactivation et
   suppression sans réinitialisation ni perte de reçus. Une migration additive
   de métadonnées peut être utile ; son besoin exact reste à décider, aucune
   migration complexe n'a été démontrée par cet audit.
3. **Compléter l'API et le formulaire existants.**
   Sort, caractéristique explicite, gratuit activé, nombre d'utilisations,
   repos court/long, niveau gratuit et emplacements activés. Créer/configurer la
   ressource côté serveur dans la transaction de l'octroi. Pour le cas demandé,
   utiliser le niveau catalogue pour le gratuit et les niveaux standards
   autorisés pour les emplacements. Ne pas inventer de règle de surclassement
   depuis le nom ou un texte libre. Permettre de modifier un octroi existant.
4. **Brancher ressources et présentation.**
   Résoudre les ressources des octrois applicables ; initialiser seulement les
   compteurs absents dans les parcours de mutation prévus. Réutiliser
   factory/synchronizer/rest et préserver les compteurs historiques. Exposer
   le marquage technique au mapper et à `hiddenFromTracker`, sans retirer le
   compteur de l'état ou du contexte de lancement. Conserver le dialogue et
   l'exécuteur existants.
5. **Valider puis traiter séparément le catalogue réel.**
   Fixtures isolées : acquis/non acquis, sous/au-dessus du seuil, deux sessions,
   gratuit seul, slot seul, les deux, épuisements indépendants, plusieurs sorts,
   repos court/long, rejeu, concurrence, multiclassage, dons, classes et Arcanums.
   Tests HTTP et formulaire compris. Ensuite seulement, aperçu ciblé des données
   d'Étoile noire ; aucune application aux personnages sans demande explicite.

## Vérifications de cet audit

- `php tools/audit-progression-spell-grants.php 41 46` et `29 35` : succès.
  Transaction PostgreSQL `REPEATABLE READ, READ ONLY`, rollback systématique ;
  aucun `flush`, aucune synchronisation, aucun token dans la sortie.
- `php -l tools/audit-progression-spell-grants.php` : succès.
- `php tools/test-fixed-feature-spell-sync.php` : **112 assertions**.
- `php tools/test-player-spell-directory-eligibility.php --feature-cast-execution` :
  **640 assertions**, tables temporaires uniquement.
- `php tools/test-spell-cast-receipts.php` : **76 assertions**, sans base.
- `php tools/test-player-spell-directory-eligibility.php --cast-http` :
  **753 assertions**, tables temporaires uniquement.
- `git diff --check` : succès ; seuls les deux nouveaux fichiers d'audit sont
  présents dans le statut Git.

Ces tests vérifient le socle existant, pas l'acceptation du parcours progression
demandé : celui-ci reste à implémenter. Aucun build Angular n'est nécessaire pour
ces deux ajouts d'audit, aucun code Angular ou métier n'a été modifié.

## Fichiers livrés et limites

- `backend/tools/audit-progression-spell-grants.php` : diagnostic reproductible,
  paramétré par personnage et état, en lecture seule.
- `backend/docs/progression-spell-grants-audit.md` : preuves, comparaison et plan.

Pas de changement des personnages, du référentiel, des acquisitions ou des
compteurs réels ; pas de migration, commit ou push. Ténèbres reste absent tant
que le parcours et sa configuration ne sont pas corrigés. La caractéristique
d'incantation voulue pour Étoile noire et le catalogue de Voracité de Barovie
restent à préciser lors de la configuration, sans les déduire des dons d'Anatoly.
