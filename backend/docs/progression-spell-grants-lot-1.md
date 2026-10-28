# Lot 1 — acquisitions et caractéristique de progression

État : A, B et C implémentés et vérifiés. Aucune donnée réelle modifiée, aucun commit/push.

## Acquisition et activation

`CharacterFeatureResolver::acquisitionRules()` vérifie la possession de la
progression sans consulter une session. La synchronisation matérialise les
octrois fixes des sources possédées, en respectant leur niveau de déblocage.
Elle n'interprète pas cette possession comme une autorisation de lancement.

Les lectures et le lancement utilisent toujours `applicableRules()` avec
les valeurs explicites de la session. Une acquisition peut donc exister tout
en restant invisible/inactive sous le seuil. Lire une autre session ou appeler
la synchronisation globale ne retire plus cette acquisition à cause du seuil.
Le retrait réel d'une source conserve le comportement historique de retrait
logique des acquisitions, sans suppression des lignes.

La création d'un octroi fixe propage aux personnages possédant la source via
le parcours existant. L'attribution d'une progression appelle désormais une
synchronisation ciblée sur ses octrois fixes, dans la transaction d'attribution.
Les GET restent sans écriture. Les choix ne sont pas générés automatiquement.
La synchronisation conserve les identifiants et dates des acquisitions inchangées.

## Incantation

`ProgressionDefinition.defaultCastingAbility` est nullable, avec les valeurs
de l'enum Ability (`wisdom` pour WIS). L'API du référentiel et le formulaire
des progressions permettent de l'éditer, y compris sur une progression déjà
attribuée. Les autres mécaniques verrouillées restent protégées.

Ordre : caractéristique explicite de l'octroi, puis défaut de la progression.
`null` signifie héritage. Le `CHOICE` historique d'une source progression
utilise également ce défaut : il n'a jamais représenté un choix persisté pour
cette source. Un don conserve son choix persisté dans `CharacterFeat`.
Une configuration absente reste non résolue ; aucune caractéristique de classe
n'est empruntée. Aucun nom de progression/personnage n'est codé en dur.

## Paliers irréversibles — finalisation

Le comportement est configurable par `ProgressionDefinition.irreversible`
(false par défaut). `CharacterProgression.maximumReached` est un entier nullable,
global au personnage. Le resolver utilise exclusivement ce maximum en mode
irréversible ; sans preuve initialisée, aucun seuil n'est considéré acquis.
Le mode réversible conserve la valeur actuelle explicitement transmise.

`CharacterProgressionMaximumRecorder` intervient seulement dans les mutations
joueur et MJ, sous transaction et verrou personnage puis attribution. Il compare
l'ancien et le nouvel état : les valeurs identiques, absentes ou non modifiées
ne produisent aucune preuve. Seule la nouvelle valeur acceptée est enregistrée ;
on ne déduit pas un ancien maximum de la valeur avant mutation. Le maximum
existant ne peut pas diminuer automatiquement. Les lectures, synchronisations,
repos et ouvertures d'anciens états n'appellent pas ce service.

Le PATCH joueur recalcule désormais son merge validé après verrouillage et
vérification de révision. Après enregistrement du maximum, les ressources sont
synchronisées via le mécanisme existant, sans créer de ressources gratuites de
sort ni effacer de consommations. Le PATCH MJ est également transactionnel.

Une initialisation/correction administrative explicite est disponible :

```text
php bin/console app:progressions:correct-maximum ATTRIBUTION MAXIMUM --reason="Preuve vérifiée"
php bin/console app:progressions:correct-maximum ATTRIBUTION MAXIMUM --reason="Preuve vérifiée" --expected=null --apply
```

La première commande est un aperçu sans écriture. `--expected` doit correspondre
au maximum relu sous verrou (`null` pour une initialisation, sinon l'entier).
Une correction à la baisse est possible uniquement par cette voie explicite ;
la valeur précédente, la nouvelle, le motif et la date sont conservés dans
`maximumCorrections`. Réappliquer la même valeur ne rajoute pas d'historique.
La commande ne parcourt aucune session et ne modifie aucun compteur ni acquisition.
Elle relève de l'accès administrateur au serveur, pas du portail joueur.

La migration additive `Version20261028020000` ajoute le booléen, le maximum
nullable et le journal JSON. Aucun backfill, aucune migration appliquée.
`Version20261028010000` (caractéristique) reste également non appliquée.

## Fichiers de ce lot

Ajouts de la finalisation : `src/Entity/CharacterProgression.php`,
`src/Service/CharacterProgressionMaximumRecorder.php`,
`src/Controller/CharacterSessionStateController.php`,
`src/Command/CorrectProgressionMaximumCommand.php`,
`migrations/Version20261028020000.php`. Les fichiers de définition, resolver,
référentiel et formulaire ci-dessous ont été complétés avec le mode irréversible.

- `src/Service/CharacterFeatureResolver.php` : possession séparée de l'activation.
- `src/Service/CharacterFeatureSpellService.php` : acquisition par possession,
  synchronisation ciblée d'une progression.
- `src/Controller/CharacterProgressionController.php` : attribution atomique.
- `src/Entity/ProgressionDefinition.php` : caractéristique par défaut.
- `src/Service/CharacterFeatureSpellCastingResolver.php` : héritage et surcharge.
- `src/Service/CustomReferenceCatalogueService.php` et
  `src/Controller/ProgressionController.php` : édition et exposition.
- `migrations/Version20261028010000.php` : colonne nullable additive,
  sans backfill ; **migration non appliquée à la base locale**.
- `tools/test-fixed-feature-spell-sync.php` et
  `tools/test-progression-spell-acquisition-cases.php` : fixtures isolées.
- `frontend/src/app/core/services/legacy-dnd-reference-api.service.ts` : contrat.
- `frontend/src/app/features/dnd-reference/components/progression-manager/`
  (`.ts`, `.html`, `.spec.ts`) : formulaire et test de modification/effacement.
- Ce bilan. Les deux fichiers d'audit du lot précédent sont conservés.

## Vérifications exécutées

Finalisation : seuil franchi puis baisse, maximum global partagé, absence de
backfill, ancien état à 99 consulté sans effet, synchronisations répétées,
mode réversible, aperçu/correction/initialisation administrative, journal,
idempotence, GET et PATCH publics et PATCH MJ authentifiés. Les tests HTTP
vérifient les valeurs persistées et les capacités sérialisées. Les fixtures
et séquences sont temporaires ; aucune migration n'a été lancée sur la base réelle.
Le test Angular couvre aussi l'activation du mode irréversible. Les suites
PHP de non-régression, le test Angular, le harness de référence et le build
listés ci-dessous ont été réexécutés pendant cette finalisation.

- `test-fixed-feature-spell-sync.php` : **154 assertions**. Acquisition,
  absence sans source, seuil exact/au-dessus/en dessous, lectures de deux
  contextes distincts, idempotence, WIS hérité, surcharge, CHOICE des dons,
  édition persistée du défaut et absence de réécriture des acquisitions.
- `test-player-spell-directory-eligibility.php --feature-cast-execution` :
  **640 assertions**, tables temporaires uniquement.
- Même harness avec `--arcanum-cast-execution` : **643 assertions**.
- Test Angular `progression-manager.spec.ts` : **1 test réussi**, sélection
  dans le DOM, sauvegarde et effacement du défaut.
- `node tools/test-progression-reference.cjs` : réussi.
- Build Angular production : réussi ; warning préexistant `player-portal.scss`
  à 11,09 kB pour un budget de 10 kB.
- `lint:container` : réussi.
- `doctrine:schema:validate` : mappings valides, base non synchronisée.
  `schema:update --dump-sql` confirme la colonne nouvelle non appliquée et les
  tables DBAL/backup hors ORM déjà existantes. Aucun SQL de synchronisation
  automatique exécuté, notamment aucune des suppressions suggérées.
- `git diff --check` : réussi.

Le premier test Angular a échoué en lisant le select avant stabilisation de
ngModel ; le test corrigé attend `whenStable()` et sa réexécution est verte.

## Avant utilisation réelle / cas Hailee

La migration additive doit être appliquée avant d'utiliser le nouveau mapping
ORM avec la base locale ; elle ne configure ni Étoile noire ni Hailee.
Pour rendre Ténèbres lançable, il reste à activer le mode irréversible après
validation des migrations, initialiser le maximum sur preuve explicite, configurer WIS sur Étoile noire, réconcilier explicitement
l'acquisition manquante après aperçu, puis configurer méthodes et ressources
dans un lot ultérieur. Aucune de ces écritures réelles n'a été effectuée.
Ténèbres n'est pas annoncé fonctionnel à ce stade.
