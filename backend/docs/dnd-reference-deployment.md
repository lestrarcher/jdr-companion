# Référentiels D&D et déploiement — 14 octobre 2026

## Diagnostic et portée

Une migration réussie ne signifie pas que les catalogues ont été importés.
L'incident `Langue raciale inconnue ou invisible : common` est compatible avec
des races renseignées mais une table de langues vide. Le runtime ne doit ni
importer à la demande, ni ignorer silencieusement une langue manquante.

Audit du code local uniquement ; aucune connexion ni validation de la base de
production. Les opérations de production ci-dessous sont une procédure pour
l'opérateur, pas des opérations exécutées dans ce chantier.

## Ordre des imports

Toutes les commandes `app:dnd:import-*` présentes sont ci-dessous. Toutes
acceptent `--dry-run` et `--update-existing`. L'ordre est topologique ; exécuter
la simulation puis, après revue, l'import de chaque étape avant la suivante.
Les simulations ne créent pas les dépendances des simulations suivantes.

| Ordre | Commande | Dépendances et particularités |
|---|---|---|
| 1 | `app:dnd:import-class-features` | Schéma courant ; crée classes, sous-classes, ressources, capacités, actions et règles dans cet ordre. Entrées pending ignorées, rapprochements legacy conservés. |
| 2 | `app:dnd:import-races` | Schéma racial courant ; parents avant enfants, traits créés par cet import. Ne dépend pas des lignes de langues pour enregistrer leurs slugs. |
| 3 | `app:dnd:import-feats` | Schéma des dons ; seulement les entrées approuvées. |
| 4 | `app:dnd:import-proficiencies` | Définitions des compétences, sauvegardes, armes, armures, outils. |
| 5 | `app:dnd:import-backgrounds` | Catalogue PHB par défaut ; exige les maîtrises. |
| 6 | `app:dnd:import-training` | Classes, races canoniques, maîtrises et historiques PHB ; crée langues puis règles de langues/expertises ; vérifie/complète les métadonnées raciales. |
| 7 | `app:dnd:import-backgrounds data/reference/dnd-2014-backgrounds-supplements.json` | Les 19 historiques supplémentaires exigent aussi les langues de l'étape 6. |
| 8 | `app:dnd:import-class-proficiencies` | Classes et maîtrises ; inclut l'artificier initial et multiclassé. |
| 9 | `app:dnd:import-race-proficiencies` | Races et maîtrises ; contrôle la parenté raciale. |
| 10 | `app:dnd:import-feature-proficiencies` | Classes/sous-classes et maîtrises ; Chantelame/Assassin, pas d'inférence depuis les descriptions. |

Le cycle apparent historiques/langues est résolu par **PHB avant training,
suppléments après training**. `app:world:import-weathers` est hors référentiels D&D.

### Idempotence et protection

- Les créations s'appuient sur slugs, clés d'import ou identités composites.
  Les relances identiques ne doivent pas recréer de lignes. Cela ne garantit
  pas que deux importeurs puissent être lancés simultanément : les exécuter
  séquentiellement, hors activité des joueurs.
- Sans `--update-existing`, les différences officielles sont généralement
  conservées et signalées. Un exit code 0 d'import ne certifie donc pas une
  égalité mécanique avec le catalogue.
- Les collisions CUSTOM sont refusées ou préservées selon l'importeur, jamais
  converties automatiquement en OFFICIAL. Les règles de formation vérifient
  aussi propriétaire et visibilité des sources/options. Le drapeau historique
  `custom` est distinct d'`origin` ; l'import des dons et l'initialiseur refusent
  désormais également ce drapeau et un propriétaire non nul.
- Les changements mécaniques des règles de maîtrise utilisées par des choix
  sont refusés ; les règles de langues/expertises existantes imposent une
  mécanique identique. Les identifiants sont conservés lors des mises à jour.
- **Ne pas automatiser `--update-existing`** : l'import racial peut désactiver
  des races hors périmètre et retirer des règles de traits gérées ; l'import
  des capacités peut changer des paramètres et relations OFFICIAL. Ces actions
  nécessitent une revue dédiée, même si aucune ligne Character n'est supprimée.
- Pour training, `--update-existing` peut compléter des métadonnées nulles et
  mettre à jour des libellés. Des langues raciales déjà renseignées mais
  différentes, une parenté incompatible ou une catégorie de langue différente
  restent des conflits. Ne jamais les résoudre par suppression des données.

### Place de `initialize-reference`

`app:dnd:initialize-reference` est un ancien seed partiel, sans dry-run. Il
réécrit notamment noms, paramètres de classes, dons et ressources OFFICIAL,
et renseigne les règles d'augmentation de caractéristiques de sept classes.
Il ne remplace aucun des imports ci-dessus et ne résout pas l'incident common.
**Ne pas le lancer sur une base existante ni dans deploy.sh.**

Pour une installation neuve, son usage éventuel précède les catalogues et
nécessite une validation séparée : ses races legacy et ses relations ne sont
pas un socle complet. Les règles de niveaux qu'il apporte ne sont pas toutes
couvertes par les catalogues. Une initialisation neuve complète doit traiter
ce point dans le chantier de baseline décrit ci-dessous, sans rejouer ce seed
pour « réparer » une campagne existante.

## Contrôle de livraison

Depuis `backend/` :

```sh
APP_ENV=prod php bin/console app:dnd:check-reference --no-interaction
```

Commande strictement consultative : aucune persistance ORM, aucun import,
aucune réparation. Exit 1 en cas d'absence, de collision protégée, de schéma
incompatible ou d'erreur de lecture. Exit 0 si les contrôles suivants passent :

- Présence OFFICIAL sans propriétaire des classes, races canoniques,
  maîtrises, langues, 32 historiques et clés de règles de formation attendues
  par les catalogues versionnés (pas des totaux codés en dur).
- Métadonnées raciales de langues non nulles lorsqu'elles sont attendues.
  Visibilité des langues fixes de toutes les races, y compris les références
  historiques et personnalisées ; une langue privée du même propriétaire reste
  autorisée pour une race privée.
- Liaison et configuration de la ressource Bricolages magiques.

Ce contrôle n'est **pas** un diff mécanique exhaustif : il ne certifie pas les
options/source de chaque règle existante, tous les dons, toutes les capacités,
les règles de niveaux ou les ressources raciales historiques. Pour cela,
examiner aussi les dry-runs et exercer les fiches. Aucun endpoint public ne
publie les diagnostics ni les slugs privés.

`deploy.sh` exécute ce contrôle après les migrations, avant la publication du
frontend et le message de succès. Un défaut arrête le script, sans import
implicite. Le contrôle est volontairement strict pour les catalogues livrés,
y compris les suppléments. Ce n'est pas un test de disponibilité HTTP minimal.

**Limite opérationnelle :** le script existant reste non atomique : le pull,
Composer (dont les auto-scripts) et les migrations ont déjà eu lieu lors du
contrôle. Un échec ne revient pas en arrière. Prévoir maintenance, sauvegarde
et validation sur une copie isolée avant publication. Une prochaine évolution
pourra préparer une release séparée et ne basculer qu'après ces contrôles.

## Base existante : procédure opérateur

1. Sauvegarder et tester la restauration ; relever le commit, les migrations
   appliquées et le plan des migrations à venir. Répéter la procédure sur une
   copie isolée. Ne pas exposer les credentials dans les logs.
2. Après disponibilité du code compatible avec le schéma, lancer le contrôle
   ci-dessus. Pour chaque famille manquante, suivre l'ordre du tableau. Exemple :

   ```sh
   APP_ENV=prod php bin/console app:dnd:import-proficiencies --dry-run
   # Après revue uniquement :
   APP_ENV=prod php bin/console app:dnd:import-proficiencies
   APP_ENV=prod php bin/console app:dnd:import-backgrounds --dry-run
   # Après revue uniquement :
   APP_ENV=prod php bin/console app:dnd:import-backgrounds
   APP_ENV=prod php bin/console app:dnd:import-training --dry-run
   ```

   Si training exige un complément racial :

   ```sh
   APP_ENV=prod php bin/console app:dnd:import-training --update-existing --dry-run
   # Seulement si le plan revu est acceptable :
   APP_ENV=prod php bin/console app:dnd:import-training --update-existing
   ```

   Pour les autres étapes du tableau : ajouter `--dry-run` à la commande exacte,
   examiner toutes les créations/différences/conflits, puis retirer uniquement
   `--dry-run` après validation. Aucun flag global de mise à jour.
3. Répéter les simulations après import : aucune création inattendue, mêmes
   identifiants, aucun conflit. Un « conservé » exige une analyse, pas un ajout
   automatique de `--update-existing`.
4. Relancer `app:dnd:check-reference`, puis reprendre la procédure de déploiement
   validée. Ne pas simplement contourner le contrôle après un échec.
5. Tester l'API de liste des personnages avec authentification et une fiche
   joueur LIVE/CLOSED. Actualiser les portails : les imports de catalogues ne
   changent pas nécessairement les révisions d'état. Les choix joueurs manquants
   se complètent par l'interface MJ, sans sélection automatique.

La sauvegarde de la base ne remplace pas celle des médias. Les paramètres de
connexion, versions PostgreSQL/pg_dump et état réel de production n'ont pas
été vérifiés ici.

## Base neuve : précondition bloquante et procédure de référence

Ne pas promettre `doctrine:migrations:migrate` depuis zéro : les migrations
historiques contiennent des préconditions de données. Par exemple,
`Version20260926120000` exige les références FTD et leurs ressources ;
`Version20260927120000` exige User #1 et des correspondances ID/slug locales ;
`Version20261014120000` exige la capacité officielle magical-tinkering.
L'ordre des imports seul ne permet pas de satisfaire ces identifiants locaux.

**Aucune migration historique n'est modifiée.** La création d'une installation
entièrement vide reste bloquée jusqu'à une baseline de schéma et de données
officielles validée séparément sur une base jetable. Ne pas fabriquer les IDs
attendus, marquer aveuglément les migrations exécutées, ni importer un dump de
campagnes réelles comme seed.

Une fois un schéma courant provisionné par cette baseline approuvée, la
procédure reproductible des référentiels est l'ordre 1–10 du tableau, chaque
simulation suivie de son import validé, puis deuxième passage des simulations,
contrôle de livraison et tests de création/niveaux/fiches. Ce chantier fournit
le contrôle et l'ordre des référentiels ; il ne livre pas cette baseline.

## Vérifications automatisées locales

Les scripts `tools/test-training-import-order.php` et
`tools/test-dnd-reference-readiness.php` couvrent les dépendances manquantes,
les relances, les métadonnées absentes, common absent/privé, les références
privées compatibles, les imports partiels et l'échec de schéma. Les premiers
scénarios sont en mémoire ; le contrôle SQL utilise exclusivement des tables
temporaires et rollback. Les tests existants des importeurs complètent les
vérifications d'identité, de collisions, d'idempotence et de rollback.

Résultats de cette reprise : 1 022 assertions réussies sur dix scripts PHP
(ordre des imports 251, readiness 12, dons 18, ownership 7, capacités 26,
maîtrises de classe 169, historiques PHB 134, suppléments 257, maîtrises
raciales 96, planificateur racial 52). `lint:container` réussit.
`tools/test-deploy-reference-gate.sh` exerce le vrai script avec des doublures
de toutes les commandes réseau/base/build : succès et échec du contrôle,
absence d'import automatique, aucune publication ni annonce de succès après
échec. Aucun déploiement réel n'est exécuté par ce test.
