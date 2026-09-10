# AGENTS.md --- JdR Companion

## Mission et stack

JdR Companion est un monorepo de gestion JdR : dashboard MJ privé, écran
partagé et portails joueurs individuels. Campagnes actives : Curse of
Strahd, Vecna: Eve of Ruin et Sorcelume.

Backend : PHP 8.4, Symfony 7.4, Doctrine ORM/DBAL, PostgreSQL, API JSON.
Frontend : Angular 22 standalone, TypeScript, SCSS. Développement :
Docker Compose. Production envisagée sur Alwaysdata.

## Méthode de travail

-   Le dépôt réel est la source de vérité. Lire les fichiers et leurs
    usages avant toute modification.
-   Puisque le workspace est accessible, ne pas demander au développeur
    de lancer grep/cat/sed si Codex peut inspecter le code lui-même.
-   Avancer par petites étapes vérifiables, idéalement 2--3 fichiers
    maximum.
-   Éviter les refactorings périphériques et abstractions prématurées.
-   Préserver les changements/assets intentionnels. Ne pas restaurer
    automatiquement ce que Git signale comme supprimé.
-   Ne pas utiliser `git add .` par défaut ; préférer des commits
    ciblés.
-   Un build vert n'est pas une preuve fonctionnelle suffisante quand un
    comportement peut être testé.
-   Ne pas inventer une session implicite : un service qui ne reçoit
    qu'un Character ne doit jamais aller chercher arbitrairement un
    CharacterSessionState.

Vérifications usuelles :

``` bash
docker compose exec backend php bin/console lint:container
docker compose exec backend php bin/console doctrine:schema:validate
docker compose exec backend php bin/console debug:router
docker compose exec frontend npm run build
git status
git diff --check
```

Warning connu : `session-characters.scss` dépasse légèrement le budget
SCSS ; sans rapport avec les travaux actuels.

## Architecture Character

L'agrégat `Character` est progressivement normalisé : niveaux de
classes, caractéristiques, races/sous-races, choix raciaux,
classes/sous-classes, dons, capacités, ressources, portefeuille/objets
magiques, progressions personnelles et `CharacterSessionState`.

Les niveaux incomplets peuvent volontairement produire un maximum de PV
`null`.

`ResourceMaximumType` contient exactement : - `fixed` -
`proficiency-bonus` - `ability-modifier`

Les emplacements standards sont calculés par
`CharacterSpellSlotCalculator`. Pact Magic reste une ressource
normalisée séparée. Les progressions de multiclassage D&D 2014
(full/half/artificer/third/pact et floor/ceil) sont déjà
implémentées/testées.

## CharacterSessionState

Relie une session et un personnage et porte l'état mutable, token,
`participating` et `levelUpAllowed`. L'ajout/retrait d'une session doit
préserver état et token.

Le state contient notamment :

``` text
hitPoints
hitDice
resources
progressions
```

`CharacterSessionStateFactory` initialise PV, dés de vie, progressions,
ressources et slots. `CharacterSessionStateSynchronizer` maintient les
états sans perdre les consommations/valeurs existantes.

## Level-up

Le level-up joueur + autorisation MJ existe déjà. Services :
`CharacterLevelUpOptionsService`,
`CharacterMulticlassEligibilityService`,
`CharacterLevelUpRequestResolver`. Les prérequis de multiclassage sont
implémentés (ex. Paladin STR+CHA).
`CharacterSessionState.levelUpAllowed` autorise un level-up. Ne pas
reconstruire ce système sans nécessité.

## Capacités et règles

Catalogue : `CharacterFeatureDefinition`, `CharacterFeatureRule`,
`CharacterFeatureResolver`.

Sources possibles : - classe - sous-classe - race - don - progression

Le resolver retourne une règle applicable par capacité. Historiquement,
pour plusieurs règles, il privilégie le plus grand `unlockLevel`.
ATTENTION : cette comparaison ne doit pas être appliquée aveuglément aux
règles de progression. `unlockLevel` et `progressionThreshold` sont deux
sémantiques différentes.

## Progressions personnelles

Une progression est une jauge numérique narrative indépendante du niveau
: corruption, infestation, charges, points d'ombre, etc. Elle peut
augmenter/diminuer, commencer à 0/1/autre, avoir un maximum nullable et
des stages. Les trous entre stages sont autorisés ; les chevauchements
interdits.

Modèle :

``` text
ProgressionDefinition
├── id, slug, name, description
├── minimumValue, maximumValue?
├── accentColor?
├── gainLabel?, spendLabel?
├── bulkAdjustmentEnabled
├── custom
└── stages[]
    ├── label
    ├── minimumValue
    ├── maximumValue?
    ├── iconUrl?
    └── displayOrder
```

Attribution :

``` text
CharacterProgression
├── character
└── progressionDefinition
```

La valeur courante n'est PAS dans `CharacterProgression`. Elle est dans
`CharacterSessionState.state.progressions`, par slug :

``` json
[{"id":"corruption-draconique","currentValue":5}]
```

### Invariant critique

Une progression NE SE RÉINITIALISE PAS lors d'un repos court ou long.
Une capacité débloquée par progression peut avoir une ressource séparée
qui, elle, se recharge.

La synchronisation ajoute uniquement les progressions manquantes au
minimum et préserve toujours les valeurs existantes.

### Bulk adjustment

`ProgressionDefinition.bulkAdjustmentEnabled` est un booléen explicite,
false par défaut. Ne pas l'inférer du maximum ou des labels. - false :
contrôles normaux `- valeur +` - true : ajoute le champ d'ajustement
important `gainLabel` / `spendLabel` servent à ce bulk adjustment.

## Attribution de capacité par progression --- déjà réalisée

`CharacterFeatureRule` possède désormais :

``` php
?ProgressionDefinition $progressionDefinition
?int $progressionThreshold
```

Factory :

``` php
CharacterFeatureRule::forProgression(
    CharacterFeatureDefinition $featureDefinition,
    ProgressionDefinition $progressionDefinition,
    int $progressionThreshold,
    int $displayOrder = 0,
)
```

Contrainte unique : progression_definition_id + feature_definition_id +
progression_threshold.

Le contrôleur et le frontend de gestion supportent
`sourceType = progression` et `progressionThreshold`. L'utilisateur a
réussi à créer une attribution depuis l'UI.

Dette transitoire : le constructeur historique exige encore
`unlockLevel`. La factory progression utilise temporairement
`unlockLevel = 1` comme dummy technique. NE JAMAIS utiliser ce dummy
pour décider si une règle progression est active.

## CHANTIER ACTUEL --- activation runtime par seuil

Exemple :

``` text
Capacité : Souffle corrompu
Progression : Corruption draconique
Seuil : 3
```

Valeur 2 =\> capacité absente. Valeur 3 ou plus =\> capacité active.

`CharacterFeatureResolver` accepte actuellement seulement
`resolve(Character $character)`, alors que la valeur courante est
session-specific.

Architecture envisagée :

``` php
resolve(Character $character, array $progressionValues = [])
```

avec par exemple :

``` php
['corruption-draconique' => 5]
```

Sans contexte de state, une règle `progression` doit être non active. Le
resolver ne doit pas charger lui-même une session.

Chaîne souhaitée :

``` text
CharacterSessionState
       ↓
progressionValues
       ↓
CharacterProfileSerializer
       ├── CharacterFeatureResolver
       └── CharacterResourceResolver
```

`CharacterSessionStateController::serializeState()` possède le Character
et son state : bon point pour construire/transmettre les valeurs.

`CharacterResourceResolver` appelle aussi `CharacterFeatureResolver`. Il
faut propager le même contexte, car une capacité débloquée par
progression peut elle-même donner une ressource.

`CharacterSessionStateSynchronizer::synchronize(Character, array $state)`
possède déjà le state : il peut résoudre les ressources avec les valeurs
de progression.

Inspecter séparément les contextes de `snapshot()`,
`synchronizeAfterLevelUp()`, factory, builder et rest service : ils
n'ont pas tous un state. Ne pas injecter un repository de session dans
le resolver pour contourner cela.

Tests attendus : 1. sous le seuil =\> capacité absente ; 2. au seuil =\>
présente ; 3. au-dessus =\> présente ; 4. capacités classe/race/don
inchangées ; 5. ressource d'une capacité progression ajoutée
correctement ; 6. comportement lors d'une redescente sous le seuil
explicite. Ne pas supprimer silencieusement une ressource historique
sans décision produit.

## Portail joueur

Le composant visuel des progressions existe déjà : nom, stage/icône,
jauge, `- valeur +`, bulk optionnel. Ne pas le reconstruire.

`CharacterStateService.adjustProgression()` borne localement la valeur
min/max. Les repos ne touchent pas aux progressions.

À vérifier après le backend : le profil contenant les capacités peut
nécessiter un re-fetch quand la valeur de progression change pour
afficher immédiatement une capacité nouvellement débloquée.

## Sécurité portail --- dette volontairement différée

Le portail sauvegarde actuellement un state global via
`PATCH /public/characters/{accessToken}`. Un joueur technique pourrait
fabriquer une requête pour modifier des champs non autorisés. Les clamps
frontend ne sont pas de la sécurité.

Cette dette est volontairement différée pour l'application personnelle.
À terme, deux voies cohérentes : A. PATCH global avec validation
différentielle backend ; B. endpoints métier dédiés (HP,
resources/{slug}, progressions/{slug}, rests, level-up).

Ne pas sécuriser arbitrairement uniquement les progressions.

## ProgressionRule --- évolution prévue

Après l'activation runtime :

``` text
ProgressionRule
├── id
├── progressionDefinition
├── label
├── description?
├── valueChange
├── displayOrder
└── active
```

Règles descriptives pour le MJ, ex. « action X = +1 ». PAS de moteur
automatique/triggers.

## Futur panneau MJ

Panneau principalement en consultation : jauges des personnages, valeur,
stage courant, ProgressionRules. Le MJ n'a pas besoin de modifier les
valeurs depuis ce panneau ; il peut utiliser le portail joueur si
nécessaire. Un endpoint GM de modification existe déjà et peut rester.

## Bugs/dettes progression connus

-   Modifier min+max d'un stage peut provoquer une validation
    transitoire selon l'ordre des setters ; envisager `setRange()` si le
    bug revient.
-   Supprimer une `ProgressionDefinition` utilisée devrait avoir un
    garde explicite ; la FK RESTRICT peut actuellement finir en 500.
-   Message frontend saveStage peut dire « ajouté » pendant une édition
    car le formulaire est reset trop tôt. Ne corriger que dans un jalon
    approprié.

## Multi-utilisateur / commercialisation --- futur

À terme : catalogue officiel/global + catalogue personnel réutilisable
du MJ + données de campagne. `custom` ne suffira probablement pas :
owner nullable, visibilité, slug unique par owner, etc. Ne pas
implémenter maintenant.

## Routes / dev proxy

Routes importantes :

``` text
GET    /api/sessions/{sessionId}/characters
POST   /api/sessions/{sessionId}/characters/{characterId}
DELETE /api/sessions/{sessionId}/characters/{characterId}
GET    /api/public/characters/{accessToken}
PATCH  /api/public/characters/{accessToken}
```

Depuis Angular : `/api/...` via proxy dev. En curl direct vers
`http://localhost:8000`, omettre généralement `/api`. 401 sans
authentification via curl peut être attendu à cause des voters.

## Assets

Progressions : `/assets/status/progressions/<personnage>/<fichier>`. Des
assets ont été déplacés récemment ; ne pas restaurer les anciens chemins
sans vérifier l'intention.

## Cas de test

Rohunar (character id historiquement 22) possède
`Corruption draconique`, valeur testée 5, et sert de cas principal pour
l'attribution par progression. Ne jamais réintroduire un ancien concept
« Pacte de Venlee » : ce n'est pas la progression actuelle. Ne hardcoder
aucun personnage dans la logique métier.

## Principes permanents

1.  Définition/configuration != état mutable.
2.  Pas de session implicite.
3.  Pas de logique métier importante uniquement frontend.
4.  Préserver les états existants et les consommations.
5.  Une progression est indépendante des repos.
6.  Le code actuel prévaut sur ce document en cas de divergence
    structurelle ; les invariants métier restent à respecter.

## Ordre recommandé

1.  Activation runtime des capacités par progression.
2.  Tests seuils + ressources associées.
3.  Réactivité/re-fetch du portail après changement de progression.
4.  `ProgressionRule`.
5.  Panneau MJ de consultation.
6.  Cleanup des bugs/guards connus.
7.  Évolutions larges ensuite.

Différé : sécurisation complète PATCH joueur, architecture
multi-user/catalogues, commercialisation, nettoyage du dummy
unlockLevel.

## État au moment de ce document

Le CRUD des progressions, leurs stages, l'attribution aux personnages,
l'affichage portail, le bulk adjustment et l'attribution d'une capacité
à une progression fonctionnent. Le prochain travail part de
`CharacterFeatureResolver` pour rendre l'activation dépendante des
valeurs explicites du state sans lookup implicite de session.

Avant le patch, inspecter dans le workspace les usages actuels de
`CharacterFeatureResolver`, `CharacterProfileSerializer`,
`CharacterResourceResolver`, `CharacterSessionStateSynchronizer`,
`CharacterSessionStateFactory`, `CharacterRestService` et les
contrôleurs de profil/state.
