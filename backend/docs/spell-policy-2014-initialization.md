# Politiques de sorts 2014 — reprise du 27 octobre 2026

## État retrouvé et corrections

Branche `feature/spell-catalog`. Les six fichiers du chantier interrompu étaient
présents. Les tables de quotas et les règles de choix existaient déjà dans
`Dnd2014SpellPolicies` et `Dnd2014AdvancedSpellPolicies` : elles sont réutilisées.
La base locale ne contient que Magicien, Occultiste, Ensorceleur et Chevalier
occulte. L'initialisation réelle n'a pas été appliquée.

La correction dans `AdvancedSpellChoices` ajoute, pour les lanceurs à préparation,
les sorts de leur liste de classe et de ses extensions, dans la limite de leur
niveau de classe. Les règles d'acquisition ne proposaient que les sorts mineurs ;
elles ne constituent pas le catalogue de préparation quotidienne. Les sorts de
grimoire et les sorts connus gardent leur fonctionnement antérieur.

La reprise ajoute un point d'entrée autonome au test officiel, des vérifications
de conservation des politiques et états de session, et des refus de préparation
hors liste/hors niveau, même avec des emplacements multiclasses supérieurs.

L'audit trouve aussi `arcane-trickster.spellcasting_progression = NULL` en base.
La commande prévoit de renseigner `third` si la valeur est absente. Une valeur
explicite différente est un conflit, pas une autorisation d'écrasement. Le
Chevalier occulte a déjà `third`. Aucune ressource de personnage n'est recalculée
ou sauvegardée par cette commande ; la synchronisation normale reste responsable
des états de session.

## Simulation locale

```sh
docker compose exec -T backend php bin/console app:spells:initialize-policies-2014 --dry-run
```

| Opération prévue | Résultat |
| --- | ---: |
| Politiques créées | 10 |
| Politiques conservées | 4 |
| Attributions de sous-classe créées | 381 |
| Règles de substitution créées | 21 |
| Progression de sous-classe complétée | Filou arcanique : NULL → third |
| Références manquantes / conflits | 0 / 0 |
| Application effective | false |

Les quatre politiques conservées sont les identifiants locaux 1 (Chevalier
occulte), 2 (Magicien), 3 (Occultiste), 4 (Ensorceleur). Ces identifiants ne sont
pas codés en dur. La résolution utilise des slugs explicites avec origine
`OFFICIAL` et propriétaire nul, puis les relations d'entités.

Les créations sont Barde, Clerc, Paladin, Rôdeur, Artificier, Druide, Filou
arcanique, et les compositions Collège du Savoir, Cercle de la Terre et Domaine
de la Nature. Les compositions réutilisent leur politique de classe.

## Couverture effective

| Classe | Couverture |
| --- | --- |
| Barde | Sorts connus et mineurs ; remplacement par niveau ; Secrets magiques aux niveaux 10, 14, 18 comptés dans les connus ; deux Secrets supplémentaires au niveau 6 du Savoir hors quota ordinaire. Remplacements des Secrets sur la liste de Barde et budget partagé. |
| Clerc | Préparation = niveau + SAG, minimum 1 ; mineurs ; sorts de domaine toujours préparés et gratuits en quota. Nature : un mineur de Druide distinct du quota de Clerc. |
| Paladin | Incantation à partir du niveau 2 ; préparation = plancher(niveau / 2) + CHA, minimum 1 ; aucun mineur de base ; sorts de serment toujours préparés hors quota. |
| Rôdeur | Incantation à partir du niveau 2 ; connus selon table 2014 ; remplacement à partir du niveau 3 ; aucun mineur de base ; sorts supplémentaires connus hors quota. |
| Artificier | Politique existante du générateur, préparation INT et tables propres ; quatre listes de spécialités toujours préparées. |
| Druide | Politique de préparation SAG, mineurs ; sorts fixes des Spores, du Feu et des Étoiles ; mineur supplémentaire de la Terre. |
| Filou arcanique | Liste de Magicien ; enchantement/illusion ; exceptions aux niveaux 3, 8, 14, 20 ; Main du mage imposée plus deux mineurs au niveau 3 ; progression d'emplacements au tiers. |
| Magicien, Ensorceleur, Occultiste, Chevalier occulte | Définitions persistées conservées, sans nouvelle version ni migration des acquisitions. |

Sous-classes avec attributions fixes :

- Clerc : Knowledge, Life, Light, Nature, Tempest, Trickery, War, Forge, Grave,
  Order, Peace, Twilight.
- Paladin : Ancients, Devotion, Vengeance, Conquest, Redemption, Glory, Watchers.
- Rôdeur : Gloom Stalker, Horizon Walker, Monster Slayer, Fey Wanderer, Swarmkeeper.
- Artificier : Alchemist, Armorer, Artillerist, Battle Smith.
- Druide : Spores, Wildfire, Stars.
- Ensorceleur : Aberrant Mind, Clockwork Soul, Shadow Magic. Les 21 sorts des deux
  premières origines ont des substitutions de même niveau, sur les trois listes
  Ensorceleur/Occultiste/Magicien, avec écoles restreintes et budget partagé.
- Occultiste : Archfey, Fiend, Great Old One, Celestial, Hexblade, Fathomless,
  Undead, Genie (partie commune). Les listes étendues sont des **options à
  apprendre**, pas des sorts automatiquement connus. Les mineurs célestes et
  Tentacules noirs du Fathomless sont des octrois distincts.

Le stockage distingue `SpellcastingPolicyDefinition`, `SpellPolicyChoiceRule`,
`SpellSubclassGrant` et `SpellGrantSubstitutionRule`. Les acquisitions restent
dans leur journal existant. `CharacterSpellAcquisitions` conserve les politiques
déjà utilisées ; il ne migre pas silencieusement un personnage vers une nouvelle
composition. Une révision MJ explicite peut donc être nécessaire pour un
personnage ayant déjà acquis des sorts sous une autre politique.

Les emplacements viennent toujours de `CharacterSpellSlotCalculator`. Ils
n'augmentent ni les quotas ni les niveaux de sorts accessibles dans chaque
classe. Pact Magic reste séparé. La préparation est un état de session ; elle
n'écrit aucune acquisition et ne consomme aucun emplacement. Catalogue et liste
rapide continuent d'utiliser les projections existantes.

## Limites précises

- **Terre : terrain non enregistré par ce chantier.** Seul le mineur bonus est
  proposé. Les sorts de terrain ne sont pas attribués ; les huit listes ne sont
  surtout pas fusionnées. Il faut une sélection explicite et persistée du terrain
  avant de configurer les octrois correspondants.
- **Génie : type élémentaire non enregistré.** Seule la liste commune, dont
  Souhait au niveau 17 pour l'Arcanum, est ajoutée. Les listes Dao, Djinni, Efreeti
  et Marid restent absentes jusqu'au choix explicite du patron.
- **Âme divine : non complétée.** La liste alternative de Clerc et le sort bonus
  dépendant de l'affinité ne sont pas configurés. La politique normale
  d'Ensorceleur reste disponible, mais elle ne suffit pas à représenter cette
  origine. Aucun choix d'affinité n'est inventé.
- **Modes de lancement spéciaux : non configurés ici.** Les sorts fixes sont
  accessibles par leur mode ordinaire. Les lancements gratuits, coûts en points
  de sorcellerie, absence de composantes/concentration, durée ou portée modifiées
  des capacités nécessitent une configuration distincte. Exemples : Star Map,
  Fey Reinforcements, Grasping Tentacles, Psionic Sorcery, Eyes of the Dark,
  Spare the Dying du Grave. Le choix d'élément eau de Summon Elemental du
  Fathomless reste une restriction à respecter à la table. D4 n'est pas modifié.
- **Options Tasha de classe non activées implicitement** : styles de combat à
  mineurs, Primal Awareness, polyvalences et autres choix optionnels. Les sorts
  fixes des sous-classes Tasha ci-dessus sont couverts ; cela n'active pas toutes
  les options du livre.
- **Autres sous-classes/capacités** : ce catalogue couvre les références nommées
  ci-dessus, pas tous les suppléments officiels. Les domaines Arcana/Death, les
  serments Crown/Oathbreaker et le patron Undying, notamment, ne sont pas ajoutés
  au référentiel. Invocations, pactes et capacités à choix restent dans leurs
  mécanismes existants, sans octroi arbitraire.
- Une politique existante est conservée, même personnalisée. La commande ne
  prétend pas réviser sa conformité ; une attribution de sous-classe existante
  incompatible bloque l'ensemble au lieu d'être écrasée.

## Vérifications

Les tests d'intégration utilisent des tables et séquences temporaires avec
rollback ; aucun personnage réel n'est utilisé comme fixture.

```sh
docker compose exec -T backend php -d memory_limit=1G tools/test-character-spell-acquisitions.php --official-policies
docker compose exec -T backend php -d memory_limit=1G tools/test-official-spell-policy-cases.php
docker compose exec -T backend php tools/test-player-spells.php
docker compose exec -T backend php tools/test-spell-policy-templates.php
docker compose exec -T backend php -d memory_limit=1G tools/test-character-spell-acquisitions.php --historical
docker compose exec -T backend php tools/test-spell-slot-calculation.php
docker compose exec -T backend php bin/console lint:container
docker compose exec -T frontend npm run build
```

Résultats : politiques officielles 433 assertions après le complément de
progression du Filou arcanique ; portail joueur 341 ; modèles 94 ; historique
311 ; emplacements 3 064 ; lint conteneur et build production réussis.

Les empreintes avant/après la simulation finale sont identiques pour les
acquisitions, les états de session (ressources et préparations comprises), les
politiques et les sous-classes. `applied` reste `false`.

Angular : 65 tests réussis, 3 échecs préexistants sur 68 tests ciblés (six fichiers).
Les trois échecs sont dans `spell-initialization.spec.ts` : attente de `Quota : 3`,
attente d'`Acquisition enregistrée`, recherche d'un enfant `SpellInitialization`
dans `CharacterSpells` alors que l'initialisation est désormais une page séparée.
Ce dernier test omet aussi de répondre à `/feature-spells`. Le frontend est
identique à `HEAD` et ces tests HTTP simulés n'exécutent pas le backend modifié.
Ils n'ont pas été masqués ou modifiés pour faire passer le chantier.

Le build émet seulement le budget SCSS connu de `player-portal.scss` (11,09 ko
pour 10 ko). `doctrine:schema:validate` valide le mapping mais signale le schéma
hors synchronisation. L'inspection en lecture seule `--dump-sql` ne propose que
la suppression des tables D4 `feature_spell_grant` et `character_feature_spell`
et de leurs dépendances hors ORM : **aucun SQL proposé n'a été exécuté**.

## Application après validation explicite

Sans option, la commande simule. `--dry-run` explicite ce comportement.
`--apply` et `--dry-run` ensemble sont refusés. Toute référence absente ou conflit
empêche l'application entière. L'application est transactionnelle ; un deuxième
passage ne crée pas de doublons. Aucun import de sorts ni migration de schéma
n'est nécessaire sur la base locale auditée.

```sh
docker compose exec -T backend php bin/console app:spells:initialize-policies-2014 --apply
```

Cette commande n'a pas été exécutée sur la base réelle. L'accord utilisateur
est requis avant cette étape. Aucun commit ni reset n'a été effectué.
