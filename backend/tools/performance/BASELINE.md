# Lot 0 — référence du 28 novembre 2026

Branche `audit/performance`, HEAD `35893e21536e721ae31183197e382eb61840e0d4`.
Aucun fichier applicatif modifié, aucune optimisation, migration, écriture métier,
connexion à la production, publication ou commit.

## Fichiers et reproduction

Voir [BENCHMARK.md](BENCHMARK.md) pour les commandes Docker exactes, le protocole,
les définitions des métriques, la comparaison avant/après et la proposition
détaillée du lot 1A.

- `benchmark.php` : services isolés, instrumentation et comparaison.
- `http.php` : serveur temporaire et véritables endpoints Symfony locaux.
- `verify-http.php` : équivalence des réponses HTTP et des services isolés.
- `test-benchmark.php` : contrôles du comparateur sur données synthétiques.
- `report.php` : présentation Markdown des résultats JSON.
- `BENCHMARK.md` et ce document : documentation du banc et de cette référence.

Les résultats et instantanés restent dans `backend/var/performance/`, ignoré
par Git :

- `lot0-services-reference.json` : matrice complète, 60 groupes de cinq mesures,
  avec un échauffement préalable par groupe.
- `lot0-http-baseline.json` : trois endpoints pour chacun des trois états.
- `lot0-short-20.json` : complément de vingt mesures pour les opérations courtes.
- `lot0-results.md` et `lot0-short-results.md` : toutes les métriques en tableaux,
  médiane / p95 / maximum, avec effectifs et échauffements.

## Résultats principaux des services

États 27, 34, 35 associés respectivement aux personnages 27, 38, 34.
Sérialisation complète : compteurs issus du mode instrumenté, temps CPU et écoulé
issus du mode léger. Ce sont des séries distinctes.

| État | SQL total | Répétitions exactes | Appels `resolve` | CPU médian léger | Écoulé médian léger | p95=max écoulé léger | JSON octets |
|---|---:|---:|---:|---:|---:|---:|---:|
| 27 | 1 243 | 844 | 46 | 4 340 ms | 5 623 ms | 5 793 ms | 119 526 |
| 34 | 680 | 418 | 44 | 3 960 ms | 4 840 ms | 5 051 ms | 88 332 |
| 35 | 2 897 | 2 501 | 40 | 3 965 ms | 6 310 ms | 6 442 ms | 88 863 |

Ces nombres confirment les résolutions répétées et leur coût local. Ils ne
mesurent pas encore le gain d'une mutualisation. Les compteurs SQL et resolver
sont constants dans les cinq échantillons de chaque ligne ; le détail des autres
opérations et des dispersions se trouve dans les rapports locaux.

Le complément à vingt itérations est également terminé : **36 groupes**, couvrant
capacités, caractéristiques, maîtrises, ressources, choix liés aux sorts et
inventaire, dans les deux modes et sur les trois états. Ses instantanés sont
identiques à ceux de la matrice complète et son jeu de données est inchangé.
Les tableaux `lot0-short-results.md` reprennent la même série HTTP de référence ;
ils ne représentent pas une seconde exécution HTTP.

Exemple de dispersion sur vingt mesures, résolution des capacités en mode léger :

| État | Médiane | p95 (rang 19) | Maximum |
|---|---:|---:|---:|
| 27 | 261,41 ms | 332,23 ms | 392,26 ms |
| 34 | 219,79 ms | 241,20 ms | 243,33 ms |
| 35 | 283,40 ms | 333,64 ms | 375,99 ms |

## HTTP et contrôles fonctionnels

Les neuf couples état/endpoint ont renvoyé HTTP 200 sous la contrainte READ ONLY.
Les neuf instantanés HTTP sont identiques à ceux des services isolés après retrait
de l'enveloppe `choices` pour le troisième endpoint. Les sorties sont stables
entre itérations et l'empreinte de la base est inchangée pendant chaque série.

| État | Profil complet médiane / p95=max | Gestion des sorts médiane / p95=max | Choix liés aux sorts médiane / p95=max |
|---|---:|---:|---:|
| 27 | 5 512 / 6 025 ms | 4 046 / 4 127 ms | 413 / 439 ms |
| 34 | 4 635 / 4 994 ms | 3 553 / 3 608 ms | 158 / 204 ms |
| 35 | 6 625 / 6 953 ms | 4 745 / 4 930 ms | 480 / 769 ms |

Cinq mesures et un échauffement par ligne/endpoint. Les octets JSON HTTP diffèrent
parfois du JSON encodé directement par le banc, en raison des options d'encodage ;
les contenus décodés sont équivalents. Ne pas soustraire les médianes HTTP et
services pour estimer un coût fixe de Symfony : les deux séries et leurs caches
sont distincts.

Vérifications réussies : quantiles, normalisation des maps, conservation de
l'ordre des listes, détection d'une valeur métier modifiée, refus d'un rapport
partiel ou d'un jeu de données différent, comparaison d'un rapport à lui-même,
cohérence des statistiques et absence de texte/clé narrative en clair dans
les instantanés. Les syntaxes PHP des scripts ont également été contrôlées.

## Environnement et limites

PHP CLI 8.4.26, PostgreSQL 17.10. OPcache CLI désactivé, JIT désactivé.
Le cgroup du backend expose six CPU logiques, sans quota CPU ni limite mémoire
propre au conteneur ; les limites de la VM/hôte restent applicables. Ce n'est
pas une reproduction de l'offre Alwaysdata à un CPU et 1 Go.

Les projections lourdes ont cinq mesures compte tenu du coût de la matrice.
Le p95 est le rang `ceil(0,95*n)` : il est donc égal au maximum pour n=5 et
au rang 19 pour n=20. Ne pas interpréter cinq observations comme une estimation
robuste des latences extrêmes. Les groupes sont exécutés séquentiellement,
sans autre benchmark concurrent ; il ne s'agit pas d'un test de charge.

La pile HTTP utilise un vrai serveur PHP local et Symfony `prod`, mais pas FPM,
le proxy Angular, le réseau mobile ou Alwaysdata. Ses mesures portent sur
la latence et les octets ; aucun CPU, SQL ou pic mémoire serveur HTTP n'est
déduit des métriques client. Les sources sont identiques aux services isolés,
mais leurs montages de dépendances diffèrent : la vérification d'équivalence
est indispensable après chaque lot.

Les instantanés normalisés conservent les valeurs métier et l'ordre des listes.
Les chaînes et clés narratives sont remplacées par HMAC ; seuls les tokens et
reçus techniques sont exclus explicitement. Aucun nom, token, paramètre SQL ou
secret n'est enregistré dans le dépôt. L'échantillon ne couvre pas des règles
absentes de ces trois personnages ; conserver les tests métier dédiés.

## Problèmes rencontrés et conservation de la référence

La série précédente a été interrompue après 32 groupes. Sa clé HMAC temporaire
n'était plus disponible à la reprise ; ses instantanés ne sont pas comparables
à ceux de cette nouvelle référence. Les fichiers `smoke.json`, `http-smoke.json`,
`lot0-services.json` et `lot0-services-baseline.json` sont exploratoires ou
incomplets et ne doivent pas servir de baseline.

La clé de cette référence est dans `/tmp/jdr-performance-hmac.key` du conteneur.
Une copie identique a été sauvegardée **hors du dépôt** dans
`%TEMP%/jdr-performance-20261128-hmac.key`. Ne pas afficher ni versionner ce
fichier. Le répertoire temporaire pouvant être nettoyé, conserver une copie
durable hors dépôt avant de supprimer/recréer le conteneur ; voir le protocole.

Restauration depuis la copie de cette exécution si le conteneur a été recréé :

```powershell
docker compose cp "$env:TEMP/jdr-performance-20261128-hmac.key" backend:/tmp/jdr-performance-hmac.key
docker compose exec -T backend chmod 600 /tmp/jdr-performance-hmac.key
```

Le serveur HTTP temporaire a nécessité une adaptation du front controller et
un cache Symfony isolé dans `/tmp`. Ces adaptations concernent uniquement
le script de mesure. Aucun endpoint ni service applicatif n'a été corrigé.

## Premier lot proposé

Le lot 1A doit partager la résolution des capacités dans un **contexte de lecture
explicite et cohérent**, créé au niveau de la projection. Commencer par le profil,
les six caractéristiques, les ressources, les PV et les maîtrises ; étendre
ensuite aux chemins de session ayant exactement les mêmes progressions.

Préserver la distinction activation/acquisition, les contrôles de visibilité,
les lectures sans contexte et les simulations de level-up. Créer un nouveau
contexte après une mutation. Ne pas introduire de cache interrequêtes, de session
implicite ni de correction fonctionnelle des chemins qui omettent actuellement
les progressions. Le batching SQL des choix reste un lot distinct.

Acceptation : instantanés inchangés, mêmes données, tests des seuils et des
frontières de contexte, baisse mesurée des résolutions répétées et comparaison
CPU/SQL/latence HTTP. Aucun pourcentage de gain annoncé. **Lot 1A non commencé ;
validation requise avant toute modification applicative.**
