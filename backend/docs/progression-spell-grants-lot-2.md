# Lot 2 — Lancement des sorts octroyés

## Périmètre livré

Le formulaire des octrois dans le référentiel des capacités configure désormais
les lancements gratuits, les emplacements classiques et la caractéristique
d'incantation. Il réutilise les règles `FeatureSpellCastingRules`, le résolveur,
l'exécuteur de lancement, les reçus et les repos existants. Aucune logique ne
dépend du nom d'un personnage, d'une progression ou d'un sort.

Les modifications préexistantes du lot 1 sont conservées. Aucune migration n'a
été appliquée et aucune configuration réelle de personnage n'a été modifiée.
Les vérifications fonctionnelles utilisent des fixtures isolées.

## Contrat de configuration

POST et PATCH sur `/reference/{scope}/features/{featureId}/spell-grants` (PATCH
avec l'identifiant de l'octroi) acceptent le bloc suivant :

```json
{
  "castingAbility": null,
  "casting": {
    "freeUses": 1,
    "rechargeType": "long-rest",
    "castingLevel": 2,
    "standardSlot": true
  }
}
```

`freeUses: 0` désactive la méthode gratuite. Au moins une méthode doit rester
active. Le niveau gratuit doit être compris entre le niveau du sort et 9 (0
pour un tour de magie). Les repos admis sont `short-rest` et `long-rest`.
L'interface remplace un niveau gratuit laissé vide par le niveau de base du
sort. Les emplacements disponibles et les niveaux autorisés sont déterminés
par le moteur existant ; Pact Magic reste distinct.

`castingAbility: null` utilise l'héritage du lot 1 ; une caractéristique explicite
le remplace. Le mode `CHOICE` historique reste disponible. Les anciens champs
`castingRules` / `resourceDefinitionId` restent compatibles, mais ne peuvent
pas être combinés avec `casting`. Une ressource automatique ne peut pas être
réaffectée à un autre octroi par ces anciens champs.

À la création, le MJ sélectionne le sort ou les filtres de choix. En édition,
le sort, le mode et le niveau d'acquisition restent immuables : créer un nouvel
octroi pour changer leur identité. Le bouton de configuration charge les
méthodes existantes ; une case explicite active leur modification. Une édition
de la caractéristique seule conserve ainsi les règles anciennes intactes.

## Ressources et cycle de vie

- `managed_resource_id` identifie la ressource créée pour l'octroi et reste
  conservé même si la méthode gratuite est désactivée. Index unique et FK
  RESTRICT : pas de partage entre octrois, pas de suppression du pool référencé.
- `resource_definition_id` reste le lien utilisé par le moteur de lancement.
  Désactiver la méthode gratuite retire ce lien actif, sans supprimer l'identité
  du pool ni ses anciens compteurs. La réactivation réutilise le même pool.
- Les nouvelles ressources sont fixes, coûtent une unité par lancement et
  reprennent le propriétaire et l'origine de la capacité. Le serveur les crée.
- `hidden_from_tracker` est une métadonnée explicite, false par défaut. Les pools
  créés automatiquement la portent à true. Le profil et son mapper la
  transmettent au filtre existant du portail. Les ressources restent dans le
  state et disponibles pour le dialogue et l'exécuteur.
- Une sauvegarde synchronise uniquement le pool concerné dans les sessions des
  bénéficiaires. Les compteurs existants sont conservés, une hausse du maximum
  ne les remplit pas ; une baisse borne la valeur au nouveau maximum selon le
  synchroniseur existant. Les nouveaux compteurs sont initialisés au maximum.
- Les compteurs restent propres à chaque session. Cette règle est indépendante
  du maximum irréversible de progression, global au personnage dans le lot 1.
- `enabled` désactive l'octroi. DELETE réalise cette même désactivation logique.
  L'interface permet de le réactiver. Acquisitions, choix, ressources, états et
  reçus sont conservés ; aucun nouveau lancement n'est autorisé lorsqu'il est
  désactivé. Un rejeu déjà enregistré retourne toujours son reçu sans consommer.
- Les repos utilisent le mécanisme existant. Les pools devenus inapplicables
  restent historiques et ne sont pas rechargés comme des ressources actives.
- Les changements de configuration, créations de pool et synchronisations sont
  transactionnels. Les personnages sont verrouillés avant les états. Une
  synchronisation d'acquisition ne verrouille que le personnage concerné ; une
  modification globale d'octroi verrouille ses bénéficiaires dans l'ordre des ID.

Les ressources externes historiques (notamment Faveur des Fées) ne sont ni
recréées ni converties. Leur coût et leurs restrictions sont conservés. Le
formulaire refuse de modifier leur maximum ou leur récupération via l'octroi,
et refuse de retirer leur méthode gratuite : désactiver l'octroi entier ou
utiliser leur gestion historique. Leur visibilité actuelle est conservée.

## Migrations nécessaires — non appliquées

1. `Version20261028010000` : lot 1, caractéristique de progression.
2. `Version20261028020000` : lot 1, maximum global et irréversibilité.
3. `Version20261028030000` : ce lot, `hidden_from_tracker`, `enabled`,
   `managed_resource_id`, FK et index unique.

La nouvelle migration est additive : les octrois existants restent activés,
sans ressource gérée, et les ressources existantes restent visibles. Aucun
backfill métier ni changement d'acquisition. Son rollback destructif est
volontairement refusé pour préserver les identités historiques.

## Fichiers du lot 2

Backend :

- `migrations/Version20261028030000.php`
- `src/Service/FeatureSpellGrantConfiguration.php`
- `src/Controller/FeatureSpellGrantController.php`
- `src/Entity/TrackableResourceDefinition.php`
- `src/Service/CharacterFeatureSpellService.php`
- `src/Service/CharacterFeatureSpellCastingResolver.php`
- `src/Service/CharacterResourceResolver.php`
- `src/Service/CharacterProfileSerializer.php`
- `tools/feature-grant-fixture-schema.php`
- `tools/test-managed-grant-cases.php`
- `tools/test-progression-spell-acquisition-cases.php`
- `tools/test-fixed-feature-spell-sync.php`
- `tools/test-player-spell-directory-eligibility.php`
- `tools/test-feature-spell-availability-cases.php`
- `tools/spell-cast-services-fixture.php`
- `tools/test-spell-cast-concurrency.php`

Frontend, sous `frontend/src/app/` :

- `core/mappers/character-api.mapper.ts`
- `core/services/reference-feature-editor-api.service.ts`
- `features/dnd-reference/components/feature-definition-manager/feature-definition-manager.ts`
- `features/dnd-reference/components/feature-definition-manager/feature-definition-manager.html`
- `features/dnd-reference/components/feature-definition-manager/feature-definition-manager.spec.ts`
- `features/player-portal/player-spell-casting.spec.ts`

Les autres fichiers modifiés du workspace appartiennent au lot 1 préalable.

## Validation

Commandes exécutées dans les services Docker :

| Vérification | Résultat |
|---|---|
| `php tools/test-fixed-feature-spell-sync.php` | 191 assertions |
| `php tools/test-player-spell-directory-eligibility.php --feature-cast-execution` | 640 assertions |
| `php tools/test-player-spell-directory-eligibility.php --arcanum-cast-execution` | 643 assertions |
| `php tools/test-player-spell-directory-eligibility.php --cast-http` | 753 assertions |
| `php tools/test-spell-cast-concurrency.php` | 213 assertions |
| Angular : feature-definition-manager, spell-cast-dialog, player-spell-casting | 73 tests |
| Angular : reprise des deux suites modifiées en dernier | 45 tests |
| `npm run build` | Succès ; warning existant player-portal.scss, 11,09 kB pour 10 kB |
| `php bin/console lint:container` | Succès |
| `php bin/console doctrine:schema:validate` | Mapping valide ; base non synchronisée, migrations en attente et tables DBAL existantes |
| `git diff --check` | Succès |

Les nouveaux cas HTTP couvrent création automatique, édition, indépendance des
pools, deux sessions, consommation gratuite puis avec emplacement, slots épuisés,
repos court/long, sauvegardes répétées, désactivation/réactivation, conservation
des reçus, héritage et modification de caractéristique, rollback invalide. La
suite concurrente utilise un pool géré et plusieurs workers : dernier usage,
rejeu, commandes distinctes, conflits et compétition avec PATCH du state.
Les contrôles des dons, sorts de classe et Arcanums réutilisent leurs suites.
Le test HTTP volontairement invalide émet un log BadRequest attendu.

Les tests Angular couvrent les trois configurations, le niveau de base,
l'héritage et l'édition conservatrice des anciens octrois. Le masquage est testé
avec un slug arbitraire, tout en conservant les ressources ordinaires visibles.
Pas de vérification visuelle manuelle dans un navigateur.

## Étapes restantes pour Ténèbres chez Hailee

Ténèbres n'est pas annoncé fonctionnel dans les données réelles. Après validation
explicite seulement : appliquer les migrations, vérifier l'initialisation
prudente du maximum global du lot 1, définir Sagesse sur la progression et
configurer son octroi Ténèbres avec héritage, un usage gratuit/repos long, niveau
de base et emplacements classiques autorisés. Réconcilier explicitement
l'acquisition persistante manquante relevée par l'audit du lot 1.

Vérifier ensuite dans la session choisie : seuil acquis, DD/attaque avec Sagesse,
gratuit consommé une fois, emplacement disponible ensuite, recharge au repos
long, indépendance de l'autre session et conservation après baisse des points.
Ces opérations sur les données réelles restent à autoriser.

L'audit préalable signale Voracité de Barovie absente du catalogue : elle n'a
pas été créée. Les quatre octrois peuvent être configurés indépendamment si
leurs sorts existent. Foulée brumeuse et Linceul d'ombre restent hors périmètre.
