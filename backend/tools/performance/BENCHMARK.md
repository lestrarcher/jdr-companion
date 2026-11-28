# Lot 0 — banc de mesure local

Ce répertoire ne change aucun service applicatif. Ne pas enregistrer ces scripts
dans le conteneur de services Symfony ni les exposer sous `public/`.
Exécution uniquement dans le conteneur backend Docker Compose, contre l'hôte
PostgreSQL `database`. Aucun accès distant, migration, import ou extension.

## Relancer

Depuis la racine du dépôt, avec les conteneurs déjà démarrés :

```powershell
docker compose exec -T backend php tools/performance/benchmark.php --self-test
docker compose exec -T backend php tools/performance/test-benchmark.php
docker compose exec -T backend php -l tools/performance/http.php
docker compose exec -T backend php -l tools/performance/verify-http.php

# Baseline complète : 3 états × 10 opérations × 2 modes × (1 + 5) exécutions.
docker compose exec -T backend php tools/performance/benchmark.php --states=27,34,35 --iterations=5 --warmup=1 --output=var/performance/lot0-services-reference.json

# À lancer APRES la fin de la commande précédente, sans benchmark concurrent.
docker compose exec -T backend php tools/performance/http.php --states=27,34,35 --iterations=5 --warmup=1 --output=var/performance/lot0-http-baseline.json

# Vérifier l'équivalence entre les projections isolées et les réponses HTTP.
docker compose exec -T backend php tools/performance/verify-http.php --services=var/performance/lot0-services-reference.json --http=var/performance/lot0-http-baseline.json

# Tableaux de toutes les métriques : médiane / p95 / maximum.
docker compose exec -T backend php tools/performance/report.php --services=var/performance/lot0-services-reference.json --http=var/performance/lot0-http-baseline.json --output=var/performance/lot0-results.md

# Complément à 20 itérations, à exécuter séparément après les autres mesures.
docker compose exec -T backend php tools/performance/benchmark.php --states=27,34,35 --operations=features,abilities,training,resources,ability_choices,inventory --iterations=20 --warmup=1 --output=var/performance/lot0-short-20.json
docker compose exec -T backend php tools/performance/report.php --services=var/performance/lot0-short-20.json --http=var/performance/lot0-http-baseline.json --output=var/performance/lot0-short-results.md
```

Les chemins de sortie sont relatifs à `/app`, donc `backend/var/performance/` sur
l'hôte. Ce répertoire est déjà ignoré par Git. Un fichier existant n'est jamais
écrasé au démarrage : choisir un nouveau nom. Un résultat partiel reste présent
en cas d'échec, sans `complete=true` ; ne pas le prendre pour une baseline.
Les commandes ci-dessus désignent les fichiers de référence de ce lot ; pour
une nouvelle exécution, choisir de nouveaux noms et les reporter dans les
commandes de vérification et de présentation.

Ne pas utiliser l'application pendant les mesures. Éviter builds, tests ou autres
processus consommateurs. Les empreintes de toutes les tables publiques doivent
être identiques au début et à la fin. Cela détecte une modification persistante
du jeu de données ; cela ne détecte pas une modification externe transitoire puis
annulée entre les deux relevés.

Pour vingt itérations sur la matrice entière, remplacer `--iterations=5` par
`--iterations=20`. Pour un lot ciblé, conserver exactement la même sous-matrice
avant/après :

```powershell
docker compose exec -T backend php tools/performance/benchmark.php --states=27,34,35 --operations=features,abilities,resources,profile,full --modes=instrumented,light --iterations=20 --warmup=2 --output=var/performance/lot1a-before.json
# Après optimisation autorisée, même commande et sortie lot1a-after.json.
docker compose exec -T backend php tools/performance/benchmark.php --compare=var/performance/lot1a-before.json --against=var/performance/lot1a-after.json
```

Le comparateur renvoie 0 si les sorties fonctionnelles sont identiques et stables,
2 en cas de différence métier, 1 en cas de rapports incomplets/incompatibles.
Il affiche les statistiques avant/après et l'écart de médiane pour chaque métrique.
Il refuse notamment des clés HMAC, jeux de données ou matrices différents.
Les nombres d'itérations restent visibles : une comparaison à effectifs différents
demande une interprétation prudente. Aucune absence de régression n'est déduite
d'un simple gain de temps.

Le même comparateur fonctionne pour deux rapports HTTP :

```powershell
docker compose exec -T backend php tools/performance/benchmark.php --compare=var/performance/lot0-http-baseline.json --against=var/performance/after-http.json
```

## Opérations et périmètres

| Clé | Service / projection |
|---|---|
| `full` | `CharacterSessionStateSerializer::serialize` : profil, état, effets et sorts |
| `features` | `CharacterFeatureResolver::resolve` : identité, attribution, seuil, ordre, provenance du choix |
| `abilities` | `CharacterAbilityCalculator::calculateAll` et `toArray` des six résultats |
| `training` | `CharacterTrainingPresenter::resolved`, sans détails personnels des attributions manuelles |
| `resources` | `CharacterResourceResolver::resolve` : définition, maximum, recharge |
| `profile` | `CharacterProfileSerializer::serialize`, sans répertoire de sorts |
| `spells` | `PlayerSpellDirectory::forCharacter` |
| `management` | `PlayerSpellManagement::view` |
| `ability_choices` | `CharacterFeatureSpellAbilityChoice::list`, caractéristique de lancement des grants |
| `inventory` | Complément : exemplaires, quantités, équipement, harmonisation, emplacement, mains, charges, sort inscrit, activation |

Les choix généraux de capacités sont aussi couverts par le résultat du resolver
(provenance et capacité sélectionnée) ; `ability_choices` désigne spécifiquement
les choix liés aux sorts, pas l'écran complet d'édition des choix de capacités.

Chaque opération charge l'état explicitement par son ID et extrait ses valeurs
de progression. Aucune recherche arbitraire de session. `full` contient aussi les
consommations du state, méthodes de lancement, métamagies et raisons
d'indisponibilité. L'inventaire supplémentaire complète les champs absents du
profil ; ce n'est pas une reproduction de l'endpoint inventaire complet.

Les trois personnages constituent un échantillon, pas une couverture de toutes
les règles. Une fonction absente de leurs données ne se trouve pas testée par
magie : conserver les tests métier du dépôt et ajouter, dans un lot autorisé,
des fixtures dédiées pour les cas manquants.

## Méthode des services isolés

- Un processus enfant par état/opération/mode ; échauffement non comptabilisé.
- Chaque itération recrée connexion, EntityManager, registry, repositories et
  services. Aucun cache de service ne survit entre itérations.
- Les caches de métadonnées et de requêtes ORM en mémoire restent chauds dans
  ce processus. Le runtime PHP/OPcache CLI est indiqué dans le rapport.
- Construction par réflexion des dépendances des services réels, y compris
  les dépendances optionnelles typées. Ce montage n'exécute pas le conteneur
  Symfony et ne reproduit pas automatiquement de futurs decorators/bindings :
  le revoir si la configuration DI évolue. Une signature non supportée échoue.
- Transaction `REPEATABLE READ, READ ONLY`, vérification de
  `transaction_read_only`, timeout local de 15 s par instruction, rollback.
  Aucun changement de configuration PostgreSQL persistant.
- Temps mesuré : recherche de l'état, construction des services, opération et
  encodage JSON. Ouverture de connexion/transaction, empreintes et comparaison
  fonctionnelle sont hors chronométrage. Ne pas additionner les opérations,
  qui ont des dépendances communes.

### Modes

`instrumented` utilise le middleware DBAL installé dans les dépendances pour les
requêtes et leur durée. Le fichier du resolver est chargé **en mémoire** avec
trois compteurs à l'entrée des méthodes `resolve`, `applicableRules` et
`acquisitionRules`. Aucun fichier source n'est réécrit. Les trois signatures sont
vérifiées ; une évolution incompatible fait échouer la mesure. Les compteurs
restent distincts : un `resolve` appelle normalement `applicableRules`, leur
somme ne représente pas le nombre de résolutions indépendantes.

`light` charge le resolver original, sans middleware SQL ni compteur ajouté.
Il mesure temps, CPU, mémoire et JSON. Les métriques SQL et appels sont absentes,
et non présentées comme zéro. Les instantanés sont comparés entre les deux modes.

### Métriques

- CPU PHP : différence `getrusage`, utilisateur + système, en millisecondes.
- Temps écoulé : horloge monotone `hrtime`.
- DBAL : somme des durées instrumentées × 1000 ; inclut les échanges client/DB,
  ne mesure ni le CPU PostgreSQL ni l'intégralité de l'hydratation PHP.
  CPU PHP et durée DBAL ne sont pas des composantes disjointes : ne pas les
  additionner pour reconstituer le temps écoulé.
- SQL total : exécutions après initialisation de la transaction.
- Répétitions exactes : total moins nombre de tuples distincts SQL/paramètres/types.
- Répétitions de forme : total moins nombre de SQL distincts après normalisation
  des espaces. Les paramètres ne sont pas pris en compte ; ce n'est pas un
  analyseur SQL généralisant toutes les variantes `IN (...)`.
- Les formes dominantes sont enregistrées par SHA-256, jamais en SQL brut ;
  paramètres et valeurs ne sont jamais écrits.
- Mémoire : pic PHP alloué (`memory_get_peak_usage(true)`) réinitialisé à chaque
  itération, et différence avec la mémoire avant l'opération. Ce n'est ni le RSS
  du conteneur, ni une mesure de fuite ; les caches, le profiler et l'instantané
  normalisé retenu pour comparaison sont inclus dans l'empreinte du processus.
  L'allocateur conserve des blocs entre itérations : un delta nul ne signifie
  pas que l'opération ne consomme aucune mémoire. La fabrication de l'instantané
  est hors temps mesuré, mais son occupation persistante n'est pas soustraite.
- JSON : octets avant anonymisation, non compressés. Pour les objets retournés
  par les resolvers, taille de la projection explicite du tableau ci-dessus.
- Médiane : valeur centrale, moyenne des deux centrales si effectif pair.
- p95 : rang `ceil(0,95 × n)` dans les valeurs triées (nearest rank).
  Avec cinq itérations, p95 = maximum ; ce n'est pas une estimation robuste de la
  latence de queue. Vingt itérations donnent le rang 19.
- Toutes les mesures individuelles sont conservées, avec médiane, maximum, p95,
  effectif et nombre d'échauffements par groupe.

## HTTP réel local

`http.php` lance temporairement un vrai serveur PHP sur une adresse loopback et
un port libre **à l'intérieur du conteneur**. Il exécute les routes applicatives,
firewalls, contrôleurs, services et réponses Symfony, en environnement `prod`
avec debug désactivé. Ce n'est pas une connexion à la production.

Seuls trois GET sont autorisés : profil public, `/spells`, `/spells/ability-choices`.
Les tokens sont lus dans une transaction READ ONLY, restent en mémoire, ne sont
ni arguments de processus, ni données des rapports. Les redirections sont
désactivées. Les journaux du serveur contenant les URL sont envoyés vers
`/dev/null`, le logger Symfony remplacé par `NullLogger` pour ce kernel seulement.

Le processus HTTP reçoit `PGOPTIONS=-c default_transaction_read_only=on`, vérifie
le réglage via Doctrine et retourne un en-tête de contrôle. Aucune requête métier
mutante n'est envoyée. Les caches du kernel de benchmark sont sous
`/tmp/jdr-perf-http-*`, jamais dans le cache applicatif normal. Le serveur est
arrêté en `finally`; ces caches temporaires peuvent être éliminés en recréant le
conteneur après sauvegarde de la clé HMAC.

Le temps HTTP comprend le trajet loopback et la pile serveur ; ce script ne mesure
pas CPU/mémoire/SQL du serveur HTTP. Les valeurs du client ne sont pas attribuées
au serveur. Pas de navigateur, proxy Angular, TLS, compression, réseau mobile ou
FPM/Alwaysdata ; serveur PHP mono-worker. Aucune conclusion sur la concurrence
1/5/10 utilisateurs ne découle de ce lot.

## Instantanés et confidentialité

Les rapports conservent des **instantanés de comparaison**, non des réponses
réversibles : chaque texte et chaque clé associative est remplacé par un HMAC
SHA-256. Les nombres, booléens et null sont conservés. Ainsi noms, descriptions,
notes, slugs personnalisés et clés narratives ne sont jamais écrits en clair.
Un simple hash public des noms aurait permis des essais par dictionnaire ; le
HMAC utilise une clé aléatoire de 32 octets, fichier 0600 :

`/tmp/jdr-performance-hmac.key` dans le conteneur backend.

Ne jamais copier cette clé dans le dépôt ni l'afficher. Les rapports n'en
contiennent que l'identifiant SHA-256. La même clé est indispensable pour les
comparaisons. La perdre invalide toute comparaison avec les anciens instantanés.
Sauvegarde durable recommandée dans le répertoire personnel, hors dépôt :

```powershell
docker compose cp backend:/tmp/jdr-performance-hmac.key "$env:USERPROFILE/jdr-performance-hmac.key"
# Après recréation du conteneur :
docker compose cp "$env:USERPROFILE/jdr-performance-hmac.key" backend:/tmp/jdr-performance-hmac.key
docker compose exec -T backend chmod 600 /tmp/jdr-performance-hmac.key
```

Normalisation : clés des maps triées ; ordre des listes inchangé ; aucun timestamp,
révision, état, maximum, consommation ou raison métier neutralisé. Les données
persistées sont censées rester stables dans ce lot.

Exclusions explicites : `accessToken` et `spellCastReceipts` ne sont jamais
enregistrés. Le sérialiseur public omet déjà les reçus. La projection `training`
désactive les détails personnels des attributions manuelles, comme le profil
public ; les maîtrises effectives et leurs sources restent comparées. Aucun autre
champ des réponses array des services/API n'est retiré.

Les empreintes des tables sont également HMAC, calculées en flux sur toutes les
lignes publiques triées, sans sauvegarde des lignes. Elles servent à vérifier la
stabilité du jeu de données et ne remplacent pas une sauvegarde de la base.
Les IDs des états restent visibles, car ils sont les identifiants du scénario.

## Comparaison après un lot

1. Même base inchangée, même clé, même matrice et mêmes ressources Docker.
2. Capturer `git rev-parse HEAD` et l'état des changements locaux ; les rapports
   contiennent aussi les empreintes des sources/config et de `composer.lock`.
3. Lancer services puis HTTP, jamais simultanément.
4. Exiger `complete`, `dataset_unchanged`, stabilité inter-itérations/inter-modes.
5. Comparer les instantanés avant/après, puis vérifier HTTP contre services.
6. Examiner séparément CPU léger, SQL instrumenté et latence HTTP. Conserver
   les effectifs et le p95, sans masquer un cas qui régresse.
7. Exécuter les tests métier ciblés du lot. Les snapshots des trois personnages
   ne prouvent pas la correction de tous les contextes et mutations.

Une différence métier ou de données bloque la conclusion « optimisation
équivalente ». Examiner la cause avant d'accepter une nouvelle référence.

## Proposition de lot 1A — à valider, non implémentée

Périmètre : partager les capacités résolues dans **une lecture cohérente**. Pas de
cache persistant, pas de modification du polling, des index, des règles de choix,
du calcul des sorts ou des transactions.

Étape 1 : définir un contexte de lecture explicite portant le personnage et une
copie des valeurs de progression. Une absence de contexte de session reste une
absence, jamais un lookup implicite. La visibilité dépend du propriétaire ;
inclure cette identité dans le contrat même si elle est accessible via le
personnage. Garder séparés résolution runtime, règles d'acquisition et inclusion
des choix. Ne pas fusionner leurs sémantiques.

Étape 2 : faire calculer une fois les capacités runtime par ce contexte, puis les
réutiliser d'abord dans `CharacterProfileSerializer`, `CharacterResourceResolver`
et `CharacterAbilityCalculator`. Propager ensuite le même contexte aux PV et
maîtrises appelés par cette projection. Avancer par sous-étapes vérifiables plutôt
que modifier d'un coup tous les appels du dépôt. L'API existante des resolvers
reste disponible pour les appelants non migrés.

Étape 3 : intégrer au sérialiseur de session. Ne partager avec le répertoire de
sorts que les lectures ayant **exactement** la même sémantique de progression ;
certains chemins actuels de statistiques appellent le calcul sans progression.
L'optimisation ne doit pas corriger implicitement ce comportement dans le lot 1A.
Un résultat sans contexte et un résultat avec contexte ne sont pas interchangeables.
Même vigilance pour `CharacterResourceResolver::resolveAbilityMaximum` : le
calcul du modificateur de ressource n'y reçoit actuellement pas les progressions.
Conserver ce contrat dans le lot 1A, ou isoler sa correction fonctionnelle dans
un autre lot explicitement validé.

Durée de vie : création au début de la lecture, destruction à sa fin. Après une
mutation de choix, progression, équipement, niveau ou acquisition, créer un
nouveau contexte même dans la même requête HTTP. Une simple mémoïsation globale
par ID de personnage serait incorrecte ; un EntityManager identique ne garantit
pas un état métier identique. Ne pas mettre des entités mutablement partagées
dans un cache interrequêtes.

Fichiers probables : nouveau contexte de lecture, resolver, sérialiseurs profil et
session, calculateurs de caractéristiques/PV, resolver de ressources et chaîne de
maîtrises. Découper après cartographie des signatures exactes ; ne pas ajouter des
paramètres facultatifs partout sans contrat commun.

Points d'entrée observés dans la baseline :

| Fichier / méthode | Lecture à mutualiser ou frontière à préserver |
|---|---|
| `CharacterProfileSerializer::serialize` | Résout ressources, capacités, maîtrises, PV puis les six caractéristiques ; le contexte commun doit être explicite |
| `CharacterAbilityCalculator::calculateAll` / `calculate` | Six résolutions pour six caractéristiques ; ne pas mélanger les simulations de level-up avec la lecture courante |
| `CharacterResourceResolver::resolve` | Deux usages directs du resolver ; conserver les contrats des calculs de maximum |
| `CharacterHitPointCalculator::calculate` | Constitution puis effets des capacités, avec les mêmes valeurs de progression |
| `CharacterProficiencyResolver::resolve` et chaîne de maîtrise | Réutiliser les capacités sans changer les sources, détails manuels ou règles de visibilité |
| `CharacterSessionStateSerializer::serialize` | Porte l'état explicite ; séparer la projection et une éventuelle mutation/synchronisation |
| `CharacterFeatureResolver::acquisitionRules` | Droits historiques distincts de l'activation runtime ; aucun remplacement par le résultat de `resolve` |

Validation : matrice de ce lot, résultats bit-à-bit normalisés ; tests seuil -1,
seuil, seuil +1, absence de contexte, deux sessions du même personnage, deux
propriétaires, choix sélectionnés et droits historiques ; lecture avant/après
mutation dans une même requête ; ne pas perdre consommations ni ressources
historiques. Vérifier aussi personnages incomplets et PV null.

Critère de performance : pour une projection utilisant un contexte homogène,
ne plus relancer la même résolution runtime dans chaque consommateur. Mesurer
directement `resolver_resolve_calls`, SQL répété, CPU léger, mémoire et latence
HTTP. Aucun pourcentage de gain promis. Le coût d'une résolution individuelle
peut rester élevé : le batching interne de `FeatureChoiceReader` peut faire
l'objet d'un lot 1B distinct.
