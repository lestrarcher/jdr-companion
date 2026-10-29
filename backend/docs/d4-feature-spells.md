# D4 — sorts accordés par les dons et capacités

## Reprise du 26 octobre 2026

Le diff D4 non commité a été conservé. Aucun référentiel ni personnage réel
n'a été modifié et aucune migration n'a été exécutée pendant cette reprise.
La correspondance ci-dessous décrit les étapes techniques présentes dans le
diff ; aucun document définissant formellement les intitulés D4-B à D4-F
n'a été trouvé dans le dépôt.

| Étape | Existant conservé |
| --- | --- |
| D4-B — configuration | Migration `Version20261026010000`, configuration nullable des octrois, validation `FeatureSpellCastingRules`, GET/POST/PATCH dans `FeatureSpellGrantController`. Aucun backfill implicite. |
| D4-C — résolution | `CharacterFeatureResolver::applicableRules`, `CharacterFeatureSpellCastingResolver`, projection par `CharacterFeatureSpellService` : provenance unique, caractéristique explicite ou choix du don, niveau de lancement et restrictions. |
| D4-D — disponibilité | `CharacterSpellCastEligibility`, `CharacterSpellCastAvailability`, `PlayerSpellDirectory` : modes explicitement autorisés, compteur affecté au personnage, coût et état courant. |
| D4-E — exécution | `CharacterSpellCastExecutor`, `SpellCastReceipts` : verrouillage, révision, revalidation serveur, consommation atomique, rejeu idempotent et concurrence. |
| D4-F — joueur | Complété dans cette reprise : contrat Angular `resource`, choix capacité/emplacement dans le dialogue existant, coût sans confusion avec les emplacements, quantité restante et motifs d'indisponibilité, tests du parcours HTTP. |

Les octrois fixes sont déjà synchronisés automatiquement par le service
existant. Les lectures de disponibilité et l'ouverture du dialogue ne créent
pas d'acquisition et ne consomment rien. La commande transmet uniquement la
source, le mode, le niveau, la révision et l'identifiant de requête : le serveur
détermine le compteur et le coût. Une sélection multiple de modes impose un
choix explicite ; la confirmation est toujours nécessaire.

## Règles conservées et limites

- Un lancement gratuit limité correspond à une utilisation de ressource,
  sans emplacement. Le coût peut dépasser un. Le maximum, l'attribution et la
  récupération restent ceux du système de ressources existant.
- `standard-slot` est proposé seulement si configuré, à partir du niveau du
  sort et parmi les emplacements accessibles. Aucun accès implicite aux
  emplacements de pacte, aux rituels ou aux lancements à volonté n'est ajouté.
- Les restrictions textuelles, une provenance ambiguë, une caractéristique
  manquante ou une configuration absente bloquent le lancement automatique.
  Il faut une configuration MJ explicite ; aucun comportement n'est déduit du
  nom d'un don. Les sources imposant des restrictions supplémentaires non
  représentées par ces deux modes restent hors de l'automatisation actuelle.
- Aucun formulaire d'administration supplémentaire ni remplissage du catalogue
  n'est ajouté. Le backend de configuration existant est conservé.
- Le design Magie, le sélecteur historique et les règles d'acquisition sont
  inchangés. Le test de portail utilise désormais le libellé accessible des
  cases d'emplacement validées, au lieu de l'ancien texte `2/2`.

## Vérifications

Tests PHP sur données synthétiques temporaires ou schéma isolé :

- `php tools/test-fixed-feature-spell-sync.php` : 112 assertions, incluant
  octroi fixe, configuration, provenance, disponibilité et repos court/long.
- `php tools/test-player-spell-directory-eligibility.php --feature-cast-execution` :
  624 assertions, ressources indépendantes, coût, niveaux, refus, rejeu.
- `php tools/test-player-spell-directory-eligibility.php --cast-http` : 737 assertions.
- `php tools/test-spell-cast-concurrency.php` : 213 assertions avec workers et
  observation des résultats persistés ; schéma supprimé par le harness.
- `php tools/test-spell-cast-receipts.php` : 44 assertions sans base de données.
- `php tools/test-character-spell-acquisitions.php --historical` : 311 assertions.

`lint:container` réussi. Mappings Doctrine valides. `schema:validate` signale
les deux tables gérées en DBAL (`feature_spell_grant`,
`character_feature_spell`) absentes des mappings ORM : le SQL proposé est leur
suppression et celle de leur séquence/contraintes, pas une migration D4 à
appliquer. Aucun SQL de synchronisation n'a été exécuté.

Build Angular production réussi, avec le warning préexistant de
`player-portal.scss` (11,09 kB pour 10 kB). Aucun changement SCSS de cette reprise.

Les suites Angular concernées sont `player-spell-casting`, `spell-cast-dialog`,
`spell-quick-list`, `player-spells` et `spell-historical` : 82 tests réussis.
`git diff --check` réussi.

Fichiers modifiés pendant cette reprise (en plus du diff D4 conservé) :

- `frontend/src/app/core/models/spell-cast.model.ts`
- `frontend/src/app/features/player-portal/components/spell-cast-dialog/spell-cast-dialog.ts`
- `frontend/src/app/features/player-portal/components/spell-cast-dialog/spell-cast-dialog.html`
- `frontend/src/app/features/player-portal/components/spell-cast-dialog/spell-cast-dialog.spec.ts`
- `frontend/src/app/features/player-portal/components/spell-quick-list/spell-quick-list.spec.ts`
- `frontend/src/app/features/player-portal/player-spell-casting.spec.ts`
- `backend/tools/test-feature-spell-availability-cases.php`
- ce bilan.

Le lot D4 est destiné à un commit ciblé après revue ; aucun commit automatique.
L'activation effective pour un don réel dépend de sa configuration et de sa
ressource, à traiter séparément avec l'autorisation de modifier ces données.
