# Découverte des services sur le montage Windows

## Diagnostic du 12 octobre 2026

La découverte échouait avant toute exécution de migration : le conteneur Symfony
ne contenait pas des services pourtant chargeables par Composer. La déclaration
globale `App\: resource: '../src/'` et le PSR-4 `App\ => src/` étaient corrects.

Le défaut est reproductible sans Symfony sur le montage Windows `/app` :

| Répertoire | `scandir` | `DirectoryIterator` (Alpine) | `DirectoryIterator` (Debian) |
| --- | ---: | ---: | ---: |
| `src/Service` | 43 | 4 | 43 |
| `src/Entity` | 51 | 6 | 51 |
| `src/Service/Catalogue` | 10 | 10 | 10 |

Ces comptes incluent `.` et `..`. Ils décrivent le dépôt au moment du diagnostic.
Un `opendir`, suivi d'un seul `readdir`, puis de `rewinddir`, ne retrouvait que
4 entrées dans `Service` sous Alpine, contre 43 sous Debian sur le **même montage**.
Un parcours initial sans rembobinage trouvait bien toutes les entrées.

`Symfony\Component\Config\Resource\GlobResource` utilise les itérateurs récursifs
SPL pour la découverte des classes. Leur rembobinage déclenchait le parcours
incomplet. Cela explique pourquoi `class_exists()` et `glob()` réussissaient,
pourquoi certains petits dossiers semblaient corrects, et pourquoi déclarer
`Catalogue` explicitement ne faisait que déplacer l'erreur vers un autre service.

La cause opérationnelle est donc le rembobinage des répertoires dans cette
combinaison runtime Alpine / montage Windows Docker Desktop. Le diagnostic
n'attribue pas un bug amont précis à PHP, musl ou Docker : cette attribution
nécessiterait une investigation système supplémentaire. Les runtimes comparés
sont PHP 8.4.25 / Alpine 3.24.1 et PHP 8.4.26 / Debian Bookworm.

## Correction permanente

- `backend/Dockerfile` utilise `php:8.4-cli-bookworm`, avec `libicu-dev` et
  `libpq-dev` pour conserver les extensions `intl` et `pdo_pgsql`.
- `backend/config/services.yaml` retrouve sa découverte globale unique ; la
  déclaration temporaire du dossier `Catalogue` est retirée.
- Le bind mount `./backend:/app`, le volume Composer, le Kernel, l'autowiring
  et l'autoconfiguration sont conservés. Aucun chargement personnalisé ni copie
  du backend n'est nécessaire.

Reconstruire puis recréer uniquement le backend depuis la racine du dépôt :

```bash
docker compose build backend
docker compose up -d --no-deps backend
```

Un simple `restart` ne change pas l'image d'un conteneur existant. Ces commandes
ne lancent ni migration ni import ; les scripts Composer du build effectuent
la préparation du cache et des assets Symfony dans l'image.

## Contrôles reproductibles

```bash
docker compose exec -T backend php tools/check-service-discovery.php
docker compose exec -T backend php bin/console about
docker compose exec -T backend php bin/console lint:container
docker compose exec -T backend php bin/console debug:container
docker compose exec -T backend php bin/console about --env=test
docker compose exec -T backend php bin/console lint:container --env=test
docker compose exec -T backend php bin/console debug:container --env=test
```

Le script vérifie chaque répertoire de `src` par comparaison entre `scandir`
et `DirectoryIterator`, puis contrôle que chaque classe instanciable de
`Service`, `Command`, `Controller`, `Repository` et `Security` possède une
définition Symfony **avant optimisation du conteneur**. Un service privé inliné
ou supprimé par l'optimiseur ne constitue ainsi pas un faux échec. Le script
ne démarre pas le Kernel et n'accède pas à la base. Il échouait sur l'ancien
conteneur dès le parcours de `src/Entity`.

Les contrôles s'exécutent directement dans `/app`, et non sur une copie temporaire.
Cette correction ne valide ni n'applique les migrations métier en attente.

## Résultat de la vérification

Après reconstruction et recréation du backend, directement sur le montage `/app` :

- Contrôle exhaustif : **13 répertoires, 206 fichiers PHP, 130 services applicatifs**,
  aucune classe de service instanciable manquante dans les dossiers contrôlés.
- `about`, `lint:container` et `debug:container --format=json` : succès en `dev`
  et en `test`.
- Vérifications ciblées de `BackgroundImportPlanner`,
  `ClassFeatureCatalogueValidator` et `DndReferenceInitializer` : services présents,
  autowiring et autoconfiguration activés.
- `composer check-platform-reqs` et `git diff --check` : succès.

Le premier démarrage en `test` a rencontré une erreur de suppression d'un ancien
répertoire de cache (`Directory not empty`). La relance a réussi sans modification
supplémentaire. Ce nettoyage transitoire est distinct du défaut de découverte
reproduit avec les seuls itérateurs PHP.

Aucun fichier Angular ni règle métier n'a été modifié. Aucune migration ni import
avec écriture n'a été exécuté. Les changements métier préexistants sont conservés.
