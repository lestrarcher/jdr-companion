# Écriture du référentiel CUSTOM — conception préalable

Date : 28 septembre 2026. Statut : proposition à arbitrer, aucune implémentation.
Périmètre : une future migration de contraintes/index puis un CRUD personnel MJ.
Seul ce document est créé. Aucun DDL exécuté, aucune donnée modifiée, aucun import,
aucune migration créée, aucun changement de code ou Angular.

## 1. Schéma actuel vérifié

Sources : entités et repositories du dépôt, ReferenceVisibility, resolvers,
synchronizer/factory/rest, contrôleurs actuels, pg_indexes, pg_constraint,
information_schema et probes Doctrine/PostgreSQL en transaction READ ONLY terminée
par ROLLBACK. PostgreSQL 17.10 ; Doctrine ORM 3.6.8, DBAL 4.4.4,
DoctrineBundle 3.3.1.

Les huit définitions et trois attributions possèdent origin non nullable
(VARCHAR(8), défaut OFFICIAL), owner_id nullable, FK User ON DELETE RESTRICT,
index owner et CHECK :
`(origin = 'OFFICIAL' AND owner_id IS NULL) OR (origin = 'CUSTOM' AND owner_id IS NOT NULL)`.
Les callbacks Doctrine valident ce couple, pas toute la matrice relationnelle.
Le booléen historique custom ne sert pas d'autorité de provenance.

La DB garantit l'existence des cibles FK, le couple origin/owner et les index
ci-dessous. Elle ne garantit actuellement ni l'immutabilité, ni la provenance
des cibles, ni « exactement une source ». Aucun trigger utilisateur présent.
Les vérifications de relations de la migration initiale étaient ponctuelles.
ReferenceVisibility protège les lectures/runtime ; ce n'est pas un service
d'autorisation d'écriture.

| Définition | Unicité slug actuelle |
|---|---|
| CharacterClass | UNIQUE(slug), longueur 80 |
| CharacterSubclass | UNIQUE(character_class_id, slug), longueur 80 |
| CharacterRace | UNIQUE(slug), longueur 80 |
| Feat | UNIQUE(slug), longueur 80 |
| CharacterFeatureDefinition | UNIQUE(slug), longueur 100 |
| TrackableResourceDefinition | UNIQUE(slug), longueur 100 |
| ProgressionDefinition | UNIQUE(slug), longueur 80 |
| CharacterActionDefinition | UNIQUE(slug), longueur 100 |

Les dix index d'attribution actuels sont des index uniques ordinaires sans
origin/owner, pas des contraintes uniques nommées dans pg_constraint :

| Table | Index actuel | Clé |
|---|---|---|
| character_feature_rule | uniq_class_feature_level | class, feature, unlock_level |
| character_feature_rule | uniq_subclass_feature_level | subclass, feature, unlock_level |
| character_feature_rule | uniq_race_feature_level | race, feature, unlock_level |
| character_feature_rule | uniq_feat_feature_level | feat, feature, unlock_level |
| character_feature_rule | uniq_progression_feature_threshold | progression, feature, progression_threshold |
| trackable_resource_rule | uniq_class_resource_level | class, resource, unlock_level |
| trackable_resource_rule | uniq_subclass_resource_level | subclass, resource, unlock_level |
| trackable_resource_rule | uniq_race_resource_level | race, resource, unlock_level |
| trackable_resource_rule | uniq_feat_resource_level | feat, resource, unlock_level |
| character_action_class_rule | uniq_character_action_class | action, class |

ActionClassRule : unlockLevel ne fait PAS partie de l'unicité actuelle.
Enfants : UNIQUE(class, level) pour CharacterClassLevelRule ;
UNIQUE(race, choice_key) pour RaceAbilityModifier, NULL distinct ;
pas d'unicité métier comparable pour stages/adjustment rules.

## 2. Matrice relationnelle et garanties futures

| Origine de la ligne porteuse | Cible OFFICIAL | Cible CUSTOM U | Cible CUSTOM V |
|---|---|---|---|
| OFFICIAL | oui | non | non |
| CUSTOM U | oui | oui | non |

Appliquer indépendamment à CHAQUE FK, puis à la chaîne de dépendances :

- Subclass → Class obligatoire.
- Race → parentRace optionnelle ; interdire aussi cycles et auto-parentage.
- FeatureDefinition → ResourceDefinition optionnelle.
- FeatureRule → FeatureDefinition obligatoire + une seule source parmi
  Class/Subclass/Race/Feat/Progression.
- ResourceRule → ResourceDefinition obligatoire + une seule source parmi
  Class/Subclass/Race/Feat. Aucune source Progression dans cette table.
- ActionClassRule → Class et ActionDefinition obligatoires.
- Les quatre enfants techniques → leur parent ; aucun owner/origin ajouté.

La matrice est contrôlée applicativement en lecture aujourd'hui ; une FK simple
ne la garantit pas en DB. Un CHECK ne peut pas consulter correctement une autre
table. Recommandation minimale : service d'écriture transactionnel réutilisant
la politique de visibilité pour les cibles, et exigeant séparément CUSTOM + owner
authentifié pour la racine modifiée. Revalider toutes les relations du résultat
d'un PATCH, pas seulement les nouveaux IDs. Les CLI restent conservés et inchangés
dans ce ticket ; leur qualité de données continue de faire l'objet des probes.

La future migration minimale ne prétendra donc pas garantir cette matrice
inter-table pour un SQL direct arbitraire. Une garantie DB universelle exigerait
des triggers sur chaque relation, y compris les modifications de dépendances,
et sort de la proposition minimale. Origin/owner immuables dans tous les futurs
services ; la protection CRUD repose sur une liste blanche stricte, pas sur
l'absence de champs dans Angular.

## 3. Collisions multi-owner : diagnostic exact

Deux capacités DISTINCTES A et B sur Fighter ne se heurtent pas : feature_id
diffère déjà. En revanche, même Fighter + même capacité OFFICIAL + même niveau :
une attribution OFFICIAL, CUSTOM A ou CUSTOM B se heurte aux autres aujourd'hui.
Même phénomène pour les quatre sources ResourceRule et pour action + class.
Deux niveaux différents sont déjà distincts pour FeatureRule/ResourceRule ;
les actions restent uniques par paire, quel que soit leur niveau.

Pour Progression, même progression OFFICIAL + même feature + même seuil aurait
ce problème. Une progression CUSTOM A ne peut pas servir de parent à B ; deux
progressions propres distinctes n'entrent pas en collision. Le diagnostic ne
justifie aucune exception à la matrice d'ownership.

Après remplacement : doublon dans une même portée interdit ; même tuple O/A/B
autorisé. Cela autorise la coexistence des règles, sans promettre que le resolver
affichera trois fois une même capacité : il déduplique par slug.

## 4. Index recommandés, NULL et mapping Doctrine

Option A : conserver les anciens index et en ajouter avec owner ne résout rien :
les anciens continuent de bloquer. Les remplacer simplement par une clé incluant
owner nullable laisse passer les doublons OFFICIAL (NULL distinct).
Option B recommandée : dix familles, chacune avec un index OFFICIAL et un CUSTOM
incluant owner. Option C : NULLS NOT DISTINCT existe dans PostgreSQL 17, mais
nécessite toujours des familles séparées et offre ici moins de lisibilité ;
pas de raison de préférer cette variante au mapping partiel supporté.

Un parent NULL ne participe pas à son index familial. En contrepartie, imposer
exactement une source, et un seuil non NULL pour Progression. Ne pas réunir les
cinq parents optionnels dans une énorme clé unique.

SQL conceptuel EXACT des remplacements (à exécuter seulement dans une future
migration transactionnelle, après préflight et avec les mappings correspondants) :

```sql
DROP INDEX uniq_class_feature_level;
DROP INDEX uniq_subclass_feature_level;
DROP INDEX uniq_race_feature_level;
DROP INDEX uniq_feat_feature_level;
DROP INDEX uniq_progression_feature_threshold;
DROP INDEX uniq_class_resource_level;
DROP INDEX uniq_subclass_resource_level;
DROP INDEX uniq_race_resource_level;
DROP INDEX uniq_feat_resource_level;
DROP INDEX uniq_character_action_class;

CREATE UNIQUE INDEX uniq_class_feature_level_off
    ON character_feature_rule (character_class_id, feature_definition_id, unlock_level)
    WHERE origin = 'OFFICIAL' AND character_class_id IS NOT NULL;
CREATE UNIQUE INDEX uniq_class_feature_level_own
    ON character_feature_rule (owner_id, character_class_id, feature_definition_id, unlock_level)
    WHERE origin = 'CUSTOM' AND character_class_id IS NOT NULL;

CREATE UNIQUE INDEX uniq_subclass_feature_level_off
    ON character_feature_rule (character_subclass_id, feature_definition_id, unlock_level)
    WHERE origin = 'OFFICIAL' AND character_subclass_id IS NOT NULL;
CREATE UNIQUE INDEX uniq_subclass_feature_level_own
    ON character_feature_rule (owner_id, character_subclass_id, feature_definition_id, unlock_level)
    WHERE origin = 'CUSTOM' AND character_subclass_id IS NOT NULL;

CREATE UNIQUE INDEX uniq_race_feature_level_off
    ON character_feature_rule (character_race_id, feature_definition_id, unlock_level)
    WHERE origin = 'OFFICIAL' AND character_race_id IS NOT NULL;
CREATE UNIQUE INDEX uniq_race_feature_level_own
    ON character_feature_rule (owner_id, character_race_id, feature_definition_id, unlock_level)
    WHERE origin = 'CUSTOM' AND character_race_id IS NOT NULL;

CREATE UNIQUE INDEX uniq_feat_feature_level_off
    ON character_feature_rule (feat_id, feature_definition_id, unlock_level)
    WHERE origin = 'OFFICIAL' AND feat_id IS NOT NULL;
CREATE UNIQUE INDEX uniq_feat_feature_level_own
    ON character_feature_rule (owner_id, feat_id, feature_definition_id, unlock_level)
    WHERE origin = 'CUSTOM' AND feat_id IS NOT NULL;

CREATE UNIQUE INDEX uniq_progression_feature_threshold_off
    ON character_feature_rule (progression_definition_id, feature_definition_id, progression_threshold)
    WHERE origin = 'OFFICIAL' AND progression_definition_id IS NOT NULL;
CREATE UNIQUE INDEX uniq_progression_feature_threshold_own
    ON character_feature_rule (owner_id, progression_definition_id, feature_definition_id, progression_threshold)
    WHERE origin = 'CUSTOM' AND progression_definition_id IS NOT NULL;

CREATE UNIQUE INDEX uniq_class_resource_level_off
    ON trackable_resource_rule (character_class_id, resource_definition_id, unlock_level)
    WHERE origin = 'OFFICIAL' AND character_class_id IS NOT NULL;
CREATE UNIQUE INDEX uniq_class_resource_level_own
    ON trackable_resource_rule (owner_id, character_class_id, resource_definition_id, unlock_level)
    WHERE origin = 'CUSTOM' AND character_class_id IS NOT NULL;

CREATE UNIQUE INDEX uniq_subclass_resource_level_off
    ON trackable_resource_rule (character_subclass_id, resource_definition_id, unlock_level)
    WHERE origin = 'OFFICIAL' AND character_subclass_id IS NOT NULL;
CREATE UNIQUE INDEX uniq_subclass_resource_level_own
    ON trackable_resource_rule (owner_id, character_subclass_id, resource_definition_id, unlock_level)
    WHERE origin = 'CUSTOM' AND character_subclass_id IS NOT NULL;

CREATE UNIQUE INDEX uniq_race_resource_level_off
    ON trackable_resource_rule (character_race_id, resource_definition_id, unlock_level)
    WHERE origin = 'OFFICIAL' AND character_race_id IS NOT NULL;
CREATE UNIQUE INDEX uniq_race_resource_level_own
    ON trackable_resource_rule (owner_id, character_race_id, resource_definition_id, unlock_level)
    WHERE origin = 'CUSTOM' AND character_race_id IS NOT NULL;

CREATE UNIQUE INDEX uniq_feat_resource_level_off
    ON trackable_resource_rule (feat_id, resource_definition_id, unlock_level)
    WHERE origin = 'OFFICIAL' AND feat_id IS NOT NULL;
CREATE UNIQUE INDEX uniq_feat_resource_level_own
    ON trackable_resource_rule (owner_id, feat_id, resource_definition_id, unlock_level)
    WHERE origin = 'CUSTOM' AND feat_id IS NOT NULL;

CREATE UNIQUE INDEX uniq_character_action_class_off
    ON character_action_class_rule (action_definition_id, character_class_id)
    WHERE origin = 'OFFICIAL';
CREATE UNIQUE INDEX uniq_character_action_class_own
    ON character_action_class_rule (owner_id, action_definition_id, character_class_id)
    WHERE origin = 'CUSTOM';
```

Compléter cette même migration par les invariants de forme :

```sql
ALTER TABLE character_feature_rule
  ADD CONSTRAINT chk_feature_rule_one_source CHECK (
    num_nonnulls(character_class_id, character_subclass_id,
                character_race_id, feat_id, progression_definition_id) = 1),
  ADD CONSTRAINT chk_feature_rule_threshold CHECK (
    (progression_definition_id IS NULL AND progression_threshold IS NULL)
    OR
    (progression_definition_id IS NOT NULL AND progression_threshold IS NOT NULL
     AND progression_threshold >= 0 AND unlock_level = 1)),
  ADD CONSTRAINT chk_feature_rule_level CHECK (unlock_level BETWEEN 1 AND 20);

ALTER TABLE trackable_resource_rule
  ADD CONSTRAINT chk_resource_rule_one_source CHECK (
    num_nonnulls(character_class_id, character_subclass_id,
                character_race_id, feat_id) = 1),
  ADD CONSTRAINT chk_resource_rule_values CHECK (
    unlock_level BETWEEN 1 AND 20
    AND (maximum_override IS NULL OR maximum_override >= 0)
    AND maximum_bonus >= 0);

ALTER TABLE character_action_class_rule
  ADD CONSTRAINT chk_action_rule_level CHECK (unlock_level BETWEEN 1 AND 20);
```

Le dummy unlock_level=1 reste uniquement technique pour Progression ; ne jamais
le comparer au niveau pour activer une capacité. Les colonnes obligatoires
existantes restent NOT NULL : un CHECK évalué UNKNOWN n'est pas un rejet.

ORM UniqueConstraint accepte options: ['where' => ...]. Le vendor installé transmet
ces options à DBAL ; PostgreSQLPlatform les émet. Une génération SQL en mémoire,
sans exécution, a confirmé un CREATE UNIQUE INDEX ... WHERE origin='CUSTOM'
avec parent non NULL. Prévoir vingt déclarations nommées cohérentes avec le SQL,
retirer les dix anciennes. Les CHECK et éventuelles exclusions nécessitent du
DDL explicite. Vérifier le diff de schéma dans une base jetable après la future
migration ; ne pas présumer qu'un outil d'auto-diff préservera un objet non mappé.

Migration future unique : index + CHECK ci-dessus + traitement subclass §5 +
durcissement FK §12. Préflight avant DDL ; aucun nettoyage automatique.
Rollback : refuser de recréer les anciens index si les nouvelles portées
contiennent des doublons globaux ; jamais supprimer ces lignes pour réussir down().

Références techniques :
[PostgreSQL — index partiels](https://www.postgresql.org/docs/17/indexes-partial.html),
[contraintes et NULL](https://www.postgresql.org/docs/17/ddl-constraints.html),
[Doctrine ORM — UniqueConstraint](https://www.doctrine-project.org/projects/doctrine-orm/en/3.6/reference/attributes-reference.html#uniqueconstraint).
Le choix B est une recommandation issue du schéma local.

## 5. Slugs techniques et exception subclass — arbitrage ciblé actualisé

Mise à jour du 28 septembre 2026 : cette section remplace la recommandation
initiale btree_gist et fait autorité pour les renvois à cet arbitrage ailleurs
dans le document. Le reste est conservé conformément au périmètre demandé.
Les deux autres arbitrages sont désormais VALIDÉS : bonus CUSTOM additifs,
aucune nouvelle override CUSTOM, aucune priorité implicite ; identité immuable
dès création, relations/mécanique modifiables avant usage selon validations,
puis gelées après première utilisation, name/description éditables, pas de versioning.
Les anciennes mentions « à arbitrer » sur ces deux points dans les autres sections
sont donc historiques ; elles ne rouvrent pas les décisions validées.

### 5.1. Schéma et cas réels, vérifiés en READ ONLY

CharacterSubclass possède les colonnes suivantes :

| Colonne | Type PostgreSQL | NULL / défaut |
|---|---|---|
| id | integer, identité | non NULL, PK |
| slug | varchar(80) | non NULL |
| name | varchar(120) | non NULL |
| spellcasting_progression | varchar(255) | nullable |
| description | text | nullable |
| custom | boolean historique | non NULL |
| created_at, updated_at | timestamp without time zone | non NULL |
| character_class_id | integer | non NULL |
| origin | varchar(8) | non NULL, défaut OFFICIAL |
| owner_id | integer | nullable |

Index réels : character_subclass_pkey(id), idx_cff91fd4b201e281(character_class_id),
uniq_subclass_class_slug(character_class_id, slug), idx_character_subclass_owner(owner_id).
FK classe : fk_cff91fd4b201e281, ON DELETE CASCADE actuellement.
FK owner : fk_character_subclass_owner, ON DELETE RESTRICT.
CHECK chk_character_subclass_origin_owner : OFFICIAL/NULL ou CUSTOM/owner non NULL.
Le mapping ORM porte bien le UniqueConstraint composite, pas UNIQUE(slug).

| ID | Nom réel | Slug | Classe ID / slug | Origin | Owner |
|---|---|---|---|---|---|
| 3 | Voie de la magie sauvage | wild-magic | 3 / barbarian | OFFICIAL | NULL |
| 28 | Magie sauvage | wild-magic | 12 / sorcerer | OFFICIAL | NULL |
| 20 | L’Archi-guenaude | arch-hag | 9 / warlock | CUSTOM | 1 |

Les deux wild-magic coexistent car (3, wild-magic) et (12, wild-magic) sont deux
clés différentes. UNIQUE(slug) sur toute la table rejetterait ces lignes.
Aucun renommage, aucune modification de données nécessaire ou proposé.

### 5.2. Besoin exact et audit des usages

Unicité CUSTOM globale **entre owners d'un même type**, sans collision avec
OFFICIAL ; noms duplicables ; slugs techniques générés serveur, jamais acceptés
dans POST/PATCH, immuables. Les espaces de types restent distincts : aucune
unification des slugs feature/resource existants.

Recherche backend/frontend des références à subclass, getSubclass/getCharacterSubclass,
getSlug, subclassSlug, SQL sur character_subclass, comparaisons et collections :

| Usage actuel | Identité effectivement utilisée / hypothèse |
|---|---|
| CharacterSubclass et CharacterSubclassRepository | Mapping composite classe+slug ; repository standard, pas de recherche métier globale par slug |
| DndReferenceInitializer::subclass | findOneBy(characterClass, slug), puis contrôle OFFICIAL |
| ImportClassFeaturesCommand | SELECT avec character_class_id ET slug ; dictionnaire classSlug:subclassSlug, y compris aliases historiques |
| ClassFeatureCatalogueValidator | Validation/déduplication par classSlug:slug ; règles référencent le couple |
| Character::getSubclassFor, CharacterClassLevel | Classe et sous-classe objets ; vérification que la sous-classe appartient à la classe |
| CharacterFeatureResolver / CharacterResourceResolver | Objet sous-classe + classe parente + niveau ; la déduplication par slug porte sur FEATURE/RESOURCE, pas subclass |
| CharacterSpellSlotCalculator | Classe identifiée par ID ; progression magique de l'objet sous-classe, sans comparaison de son slug |
| Builder / CharacterLevelUpRequestResolver / CharacterLevelUpService | IDs de sous-classe, objets, parenté et visibilité ; pas de recherche globale subclass.slug |
| CharacterProfileSerializer | Expose subclassId, subclassSlug, subclassName dans le contexte de classe ; aucune résolution par ce slug |
| DndReferenceController / CharacterLevelUpOptionsService | Sérialisent id/slug/name ; options filtrées par classe |
| AdminReferenceCatalogue / AdminFeatureReference / repositories de règles | Navigation, filtres et jointures par ID/objet, aucun index métier global de sous-classes par slug |
| Angular DndReferenceApiService / CharacterApiService | Slug typé/transmis comme donnée ; subclassSlug apparaît dans les interfaces, sans branchement métier trouvé |
| Angular character-builder et character-level-up | Sélection par subclass.id, track subclass.id, payload subclassId, listes de la classe choisie |

Aucune comparaison runtime à wild-magic ni déduplication de sous-classes sur leur
slug seul trouvée dans backend/src, frontend/src et templates. Le JSON officiel
contient les deux formes contextualisées par classe ; les migrations historiques
consultées emploient IDs et/ou classe+slug. Les outils de test ne constituent pas
des chemins runtime et n'ont pas été exécutés.

Conclusion : l'officiel suppose au plus une unicité DANS une classe ; le runtime
de jeu travaille principalement par identité relationnelle. Le format opaque
custom-<uuid> ne change ni sélection, ni capacités, ni calcul magique, ni affichage
du nom. Ce choix est cohérent avec un identifiant technique indépendant du nom.
Il ne confère aucune mécanique codée d'une classe officielle à une copie.

### 5.3. Pourquoi l'exclusion avait été proposée

La proposition A combinait :

- l'index existant UNIQUE(character_class_id, slug) ;
- UNIQUE(slug) WHERE origin='CUSTOM' ;
- EXCLUDE USING gist (slug WITH =, origin WITH <>), nécessitant btree_gist.

L'exclusion interdit une paire dont le slug est égal ET l'origine différente.
Elle ne suffit donc PAS seule pour CUSTOM/CUSTOM.

| Ligne existante + candidate | Résultat de A |
|---|---|
| OFFICIAL barbarian/wild-magic + OFFICIAL sorcerer/wild-magic | Autorisé : origines égales, classes distinctes |
| OFFICIAL barbarian/wild-magic + autre OFFICIAL barbarian/wild-magic | Interdit par l'index classe+slug |
| OFFICIAL wild-magic + CUSTOM U wild-magic dans n'importe quelle classe | Interdit par EXCLUDE |
| CUSTOM U arch-hag + OFFICIAL arch-hag dans une autre classe | Interdit par EXCLUDE ; protection symétrique |
| CUSTOM U slug X + CUSTOM U même slug dans une autre classe | Interdit par l'index unique CUSTOM |
| CUSTOM U slug X + CUSTOM V même slug dans une autre classe | Interdit par l'index unique CUSTOM |
| CUSTOM U slug X + CUSTOM V slug Y distinct | Autorisé si les relations respectent la matrice |

Sa valeur était de gérer des slugs libres et mélangés sans renommage ni liste de
réservation historique. Elle reste correcte, mais cette liberté n'est pas requise
pour les futures créations techniques.

### 5.4. Comparaison des quatre stratégies

| Stratégie | Invariant et concurrence : dernier rempart DB | Deux wild-magic |
|---|---|---|
| A. btree_gist + EXCLUDE + index CUSTOM | Complet pour slugs libres : EXCLUDE protège O/C, index protège C/C, composite protège O/O dans la classe ; sûr en concurrence | Préservés |
| B. Index partiels classiques seuls (O par classe, C global) | C/C protégé entre tous owners ; O/C dans des classes différentes reste possible, même sans concurrence ; aucun index proposé ne le rejette | Préservés, mais invariant incomplet |
| C. Validation PHP + index classiques | SELECT préalable détecte des collisions existantes, mais O/C concurrents passent dans deux transactions ; C/C protégé par index seulement | Préservés, mais invariant incomplet |
| D. Namespaces disjoints imposés par CHECK + index CUSTOM | CHECK rend O/C impossible pour toute écriture, index arbitre C/C concurrent ; composite garde O/O contextualisé | Préservés |

C ne devient sûr qu'avec un protocole commun de verrouillage/sérialisation pour
TOUS les writers, imports/SQL compris, ou une table de réservation. Un verrou de
ligne ne verrouille pas un slug absent ; une transaction ordinaire ne suffit pas.
Cette complexité n'est pas justifiée ici. Des index d'expression équivalents à
une réservation sont envisageables mais ne rendent pas les simples index
partiels O/C suffisants.

| Stratégie | Migration / Doctrine | Dépendance / clean install / maintenance |
|---|---|---|
| A | Extension + index + exclusion ; index partiel mappable, exclusion en SQL manuel, attention au schema diff | PostgreSQL + btree_gist à provisionner avant DDL et restauration ; flexible, dépendance supplémentaire à maintenir |
| B | Index partiels simples, mapping ORM options.where | PostgreSQL sans extension ; installation simple, invariant incomplet |
| C | Même DDL que B + validation PHP ; si renforcée, protocole de tous les writers | Sans extension ; fragilité des chemins futurs, tests concurrence indispensables |
| D | Un CHECK et un index partiel ; mapping de l'index, CHECK SQL manuel | PostgreSQL natif, aucune extension ; réserver le préfixe et une exception historique finie, préflight à chaque évolution |

Pour toutes les variantes, conserver les CHECK origin/owner et NOT NULL.
Les checks/indexes ne rendent pas à eux seuls slug immuable : le futur contrat
d'écriture le garantit, comme prévu. Aucun comportement de setter modifié ici.

### 5.5. Recommandation : D, namespace DB réservé sans extension

Nouvelles sous-classes CUSTOM : **custom- suivi d'un UUID aléatoire représenté par
32 chiffres hexadécimaux minuscules**, soit 39 caractères, compatible avec
varchar(80) et la regex de l'entité. Pas de nom dans cet identifiant technique ;
name reste lisible et librement éditable. UUID généré dans le serveur applicatif,
pas de dépendance à pgcrypto/uuid-ossp. Aléatoire ne signifie pas garantie d'unicité :
l'index reste obligatoire et une collision entraîne une nouvelle génération bornée.

OFFICIAL : tout préfixe custom- interdit par CHECK. C'est cette réservation DB,
et NON une convention de nommage des imports, qui rend les espaces disjoints.

**Cas legacy à traiter explicitement : arch-hag.** Le seul CUSTOM subclass
existant est hors namespace. Un CHECK CUSTOM strict casserait la migration.
Recommandation : conserver arch-hag dans une liste de réservation historique
fermée, autorisée côté CUSTOM et interdite côté OFFICIAL. Ce n'est ni une règle
par owner, ni un renommage, ni une exception pour wild-magic. Le serveur ne
générera jamais de nouveau slug legacy.

Cette réservation subsiste même après suppression de la ligne, afin de ne pas
recycler une identité historique côté OFFICIAL. Elle peut être codée dans le
CHECK de migration puisqu'il s'agit d'une donnée historique figée, déjà identifiée
par la migration de provenance ; aucune logique métier applicative sur arch-hag.
Un SQL direct pourrait recréer un CUSTOM arch-hag après suppression : la DB
garantit ici unicité/provenance de l'espace, pas l'interdiction de réutilisation
temporelle de toute identité. Le CRUD, lui, produit uniquement le nouveau format.

SQL conceptuel recommandé, **NON exécuté** :

```sql
-- Conserver uniq_subclass_class_slug (character_class_id, slug).
-- Conserver NOT NULL et chk_character_subclass_origin_owner.
ALTER TABLE character_subclass
  ADD CONSTRAINT chk_subclass_slug_namespace CHECK (
    (origin = 'OFFICIAL'
      AND slug NOT LIKE 'custom-%'
      AND slug <> 'arch-hag')
    OR
    (origin = 'CUSTOM'
      AND (slug ~ '^custom-[0-9a-f]{32}$' OR slug = 'arch-hag'))
  );

CREATE UNIQUE INDEX uniq_subclass_custom_slug
  ON character_subclass (slug)
  WHERE origin = 'CUSTOM';
```

Preuve de disjonction : O exclut tous custom-* et arch-hag ; C n'accepte que le
format custom-UUID ou arch-hag. Leur intersection est vide.
Deux requêtes CUSTOM avec le même UUID, même owner ou owners différents :
une seule ligne peut être validée par l'index, y compris dans des classes
différentes. Une écriture OFFICIAL concurrente avec ce slug échoue au CHECK,
sans dépendre de ce qu'une autre transaction voit. Un CUSTOM wild-magic échoue
au CHECK ; les deux OFFICIAL wild-magic passent et restent distingués par classe.

Préflight READ ONLY réalisé sur toutes les sous-classes : **zéro ligne rejetée
par l'expression exacte du CHECK ci-dessus**. L'inventaire CUSTOM ne contient que
arch-hag ; aucun OFFICIAL n'a le préfixe réservé ni ce slug. Aucune collision
CUSTOM globale observée. Aucun INSERT/DDL de test, même suivi de rollback.
La sécurité concurrente est déduite des contraintes proposées ; des essais
concurrents sur base jetable seront requis lors du futur ticket de migration.

La future migration doit vérifier cet inventaire et échouer si un autre legacy
apparaît, sans l'ajouter automatiquement à la liste ni renommer de données.
Le CHECK impose aussi la réserve aux imports futurs. Un nouveau catalogue officiel
ayant custom-* ou arch-hag devra être rejeté et revu explicitement, pas accepté
par contournement. La liste n'est pas destinée à croître avec chaque nouvelle création.

L'index reste représentable par UniqueConstraint options.where ; le CHECK est
maintenu explicitement dans la migration. Après installation, vérifier introspection
et diff Doctrine ; pas d'EXCLUDE/GiST à protéger ni extension à activer.
Le namespace décrit ici précise la génération pour les sous-classes ; il ne
renomme ni ne réorganise les sept autres types. Les réservations runtime
spell-slot-*, racial-properties et identifiants spéciaux restent respectées.

### 5.6. PostgreSQL, Alwaysdata et clean install

btree_gist est une extension fournie avec PostgreSQL, mais doit être disponible
sur le serveur puis activée dans chaque base. PostgreSQL 17 la classe trusted :
CREATE sur la base suffit normalement à un non-superuser pour l'installer, sous
réserve des restrictions de l'hébergeur. Localement : disponible en version 1.7,
non installée. Sources :
[btree_gist](https://www.postgresql.org/docs/17/btree-gist.html),
[CREATE EXTENSION](https://www.postgresql.org/docs/17/sql-createextension.html).

Le dépôt ne prouve pas la configuration d'un compte Alwaysdata. Toutefois la
[documentation publique de son API](https://api.alwaysdata.com/v1/database/doc/?type=POSTGRESQL)
liste explicitement btree_gist parmi les extensions ; son
[guide PostgreSQL](https://help.alwaysdata.com/en/docs/web-hosting/databases/postgresql/)
décrit leur activation depuis l'administration. Disponibilité proposée confirmée
par documentation, pas activation ni droits SQL du compte du projet. Aucun accès
au compte, aucune modification ou sollicitation du support effectuée.

A : clean install/restauration exige extension avant exclusion ; prévoir activation
par l'administration si CREATE EXTENSION SQL n'est pas permis au rôle applicatif.
D : sur une base au schéma attendu, le CHECK accepte une table vide et l'index
se crée sans extension ni lignes spécifiques ; arch-hag reste simplement réservé.
Les deux wild-magic peuvent ensuite être importés dans leurs classes respectives.

Cela ne certifie pas le rejeu intégral des anciennes migrations sur une base
vierge : Version20260927120000 exige explicitement User #1 et des lignes historiques,
dont subclass #20. Ce prérequis antérieur est indépendant du choix A/D et n'est
pas corrigé dans ce ticket. Aucun clean install lancé. La future contrainte de D
n'ajoute aucun besoin de seed ni d'ID fixe.

**Conclusion de l'arbitrage : recommander D.** Même invariant requis, conservation
de toutes les lignes actuelles, concurrence protégée en DB, format technique
adapté aux usages observés, et moins de dépendances que A. Le coût explicite est
la réservation permanente d'un seul slug legacy, pas une faiblesse de concurrence.
btree_gist apporte surtout la liberté de mélanger les espaces de noms, dont le
produit n'a pas besoin.

## 6. MaximumOverride / maximumBonus : politique V1 proposée

Comportement actuel de CharacterResourceResolver :

- Les bonus de TOUTES les règles applicables se cumulent.
- L'override retenue correspond au plus grand unlockLevel ; à égalité, la première
  rencontrée gagne. Pas de priorité CUSTOM/OFFICIAL. L'ordre repository
  resource.id/unlockLevel ne résout pas totalement les égalités.
- Des sources différentes peuvent être simultanément applicables : classe,
  sous-classe, race, don. Un contrôle « même parent/même niveau » est insuffisant.
- Un override remplace le maximum de définition et contourne son minimumMaximum ;
  les bonus sont ensuite ajoutés. Les comparaisons de niveaux de sources
  différentes ne constituent pas une règle d'override produit.
- Sans override : max(minimumMaximum, baseMaximum + composante variable), puis bonus.
  Types existants : fixed, proficiency-bonus, ability-modifier.

| Option | Utilité et limite |
|---|---|
| Autoriser override si pas d'autre au même parent/niveau | Ne détecte pas les conflits race/classe/don ou futures acquisitions ; rejetée |
| Interdire override uniquement sur ressource OFFICIAL | Protège l'officiel, mais laisse des égalités entre sources CUSTOM sur sa propre ressource |
| Autoriser sur sa ressource avec une seule famille de source | Permet des courbes par niveau, exige un invariant supplémentaire durable et une politique des cumuls |
| Interdire toute nouvelle override CUSTOM V1 | Déterministe sans masking, garde les bonus et les formules ; recommandée |

**Proposition à valider : maximumOverride CUSTOM non exposé et toujours NULL.**
Les 95 overrides OFFICIAL existantes restent intactes. Aucun nouveau choix de
priorité, aucun changement de resolver. Si retenue, ajouter dans la future migration :

```sql
ALTER TABLE trackable_resource_rule
  ADD CONSTRAINT chk_custom_resource_no_override
  CHECK (origin <> 'CUSTOM' OR maximum_override IS NULL);
```

maximumBonus CUSTOM entier >=0, autorisé sur ressource OFFICIAL ou CUSTOM propre.
0 signifie attribution sans bonus. Exemple OFFICIAL +2 et CUSTOM +1 : +3.
Deux paliers CUSTOM +1 niveaux 3 et 5 : +2 au niveau 5, pas « remplacer +1 ».
L'éditeur doit parler de bonus supplémentaire, présenter un aperçu des cumuls.
Cette attribution peut aussi donner accès à la ressource avant une attribution
officielle ; c'est une création de mécanique volontaire, pas une mutation OFFICIAL.

Utilité conservée : ressources privées fixes ou basées sur maîtrise/caractéristique,
bonus par niveau, recharge, capacités liées aux progressions. Limites explicites :
pas de diminution du maximum officiel, pas de courbe arbitraire non additive,
pas de remplacement de règle. Si ce besoin est indispensable au lancement,
arbitrer la variante « une famille de sources pour override sur ressource propre »
AVANT de figer la migration ; ne pas ouvrir les overrides ambigus par défaut.

## 7. FeatureRule : validations V1

Payload métier explicite : sourceType, sourceId, featureDefinitionId,
unlockLevel OU progressionThreshold, displayOrder. Interdire les FK parallèles
arbitraires. Le serveur construit la seule source autorisée.

CUSTOM U → Fighter OFFICIAL + feature CUSTOM U : oui.
CUSTOM U → classe CUSTOM U + feature OFFICIAL : oui.
CUSTOM U → classe CUSTOM U + feature CUSTOM U : oui.
Toute cible CUSTOM V : non. Même règle pour subclass/race/feat/progression.
La racine attribution reste CUSTOM U même lorsque ses DEUX cibles sont OFFICIAL.

Sources ordinaires : unlockLevel entier 1..20, progressionThreshold NULL.
Progression : seuil entier >=0, parent assignable visible, dummy unlockLevel=1
construit par factory, aucun niveau envoyé ; seuil dans les bornes de la progression
pour une nouvelle règle (si maximum renseigné). displayOrder entier >=0.
Valider aussi feature.resource et les autres dépendances transitives.

Doublon = même portée + même source + même feature + même niveau/seuil.
Un conflit de ce type renvoie 409 ; deux propriétaires ne se bloquent pas.
Les capacités de progression restent inactives sans valeurs de state explicites.

Le resolver garde son arbitrage actuel : plus grand niveau pour sources ordinaires,
plus grand seuil pour une même progression, déduplication par slug ; certains
cas de sources hétérogènes gardent la première règle. La V1 ne permet pas de
personnaliser le texte d'une feature OFFICIAL via son attribution : créer sa
propre définition si un résultat distinct est voulu. Ordre/nom peuvent influencer
des égalités historiques ; ne promettre aucune priorité via displayOrder.

## 8. ResourceRule et définition de ressource

Même validation de provenance et d'unicité ; quatre sources seulement.
Niveau 1..20, bonus entier >=0, override NULL selon §6. Pas de formule libre,
de PHP/JS, de seuil progression ou de recharge sur la règle.
Une ressource de progression passe par FeatureRule → FeatureDefinition.resource.

Définition CUSTOM : exposer nom, description, rechargeType (none, short-rest,
long-rest), maximumType, baseMaximum>=0, multiplier>=1, minimumMaximum>=0,
scalingAbility parmi les enums du domaine. AbilityModifier exige une caractéristique ;
les autres types exigent NULL. Formulaire atomique, ne pas dépendre de l'ordre
des setters. Fixed : canonicaliser les paramètres inutiles côté serveur.
Pas d'édition d'une définition OFFICIAL pour changer sa recharge.

Le CRUD V1 ne doit pas exposer des configurations spéciales absentes de l'entité,
telles que le stockage Portent piloté par code. Un simple nouveau slug ne crée
ni handler ni stockage de dés.

## 9. Actions : fermées V1

Aucun CRUD ActionDefinition CUSTOM ni ActionClassRule CUSTOM.
Conserver provenance et nouveaux index pour cohérence/futur.
Handlers codés : aid, heroes-feast, flexible-casting, arcane-recovery ;
certains attendent classes/ressources précises. Réattribuer une action officielle
ne garantit donc pas son exécution correcte sur une classe custom.
Pas d'alternative générique exécutable sans un ticket métier dédié.
Aucun nouvel endpoint ni entrée Angular Actions.

## 10. Enfants techniques

| Enfant | Autorité et validation |
|---|---|
| CharacterClassLevelRule | Class CUSTOM possédée ; niveau 1..20, une ligne/niveau, enum advancementChoice, notes ; niveau/choix gelés si classe utilisée |
| RaceAbilityModifier | Race CUSTOM possédée ; bonus/enum/choix validés selon modèle ; cohérence choice_key/choix ; ne pas déplacer vers une autre race |
| ProgressionStage | Progression CUSTOM possédée ; intervalle valide, bornes compatibles, pas de chevauchement, trous permis ; sauvegarde du couple min/max atomique |
| ProgressionAdjustmentRule | Progression CUSTOM possédée ; direction enum, triggerType, description, adjustmentLabel, displayOrder ; descriptif, pas de moteur automatique |

Enfants OFFICIAL non modifiables via CRUD MJ. Parent dérivé de la route et revalidé ;
un ID enfant appartenant à un autre parent/owner renvoie 404. Aucun transfert
d'enfant entre parents. RaceAbilityModifier utilisé par CharacterRaceAbilityChoice
ne peut être supprimé ni transformé. Les mises à jour de collections ne font pas
un remplacement destructif global.

## 11. Édition et effet immédiat

Pas de snapshot/versioning. Une édition autorisée s'applique à tous les personnages
utilisateurs, dans toutes les campagnes du owner, à la prochaine lecture/recalcul.
Le frontend doit recharger le profil après succès ; « immédiat » ne suppose pas
un système de push nouveau ni un recalcul implicite hors contexte de session.

| Champs | Proposition V1 |
|---|---|
| origin, owner, slug | Immuables dès création ; IDs/timestamps non éditables |
| nom/description, labels/couleurs/icônes | Éditables ; longueurs/enums/URL selon modèle, propagation immédiate |
| parent subclass, parent race, feature.resource | Modifiables seulement avant usage (§12) ; sinon 409 |
| mécanique class/race/feat | Paramètres existants seulement, gelés après usage : dés de vie, magie, bonus/choix, répétabilité, déplacements, résistances, etc. |
| resource maximum/recharge | Configurables avant usage ; gelés dès acquisition indirecte ou trace historique |
| source/cible/niveau/seuil/bonus d'une attribution | Modifiables seulement avant usage potentiel ; ensuite créer une nouvelle règle intentionnelle |
| progression minimum/maximum | Gelés après acquisition ou trace historique, même si aucun personnage actif |
| progression stages | Labels/icônes et intervalles éditables avec validation globale ; changement immédiat de stage affiché, sans modifier currentValue ni seuils de capacités |
| adjustment rules, labels bulk, bulkAdjustmentEnabled | Éditables ; règles descriptives, aucune modification automatique du state |

Politique maxima conservatrice proposée, à arbitrer : leur édition après usage
peut tronquer une valeur lors d'une synchronisation ou changer une recharge
historique. Le synchronizer borne les ressources actives, préserve les inactives ;
le repos historique utilise la définition sans retrouver tous les anciens bonus.
Un simple PATCH ne doit pas promettre une réconciliation sûre de tous les states.
Le gel évite cette perte sans inventer des consommations restaurées. Si l'édition
de maxima après usage est requise, prévoir d'abord une politique explicite de
recalcul par session et de préservation des consommations ; aucun purge/reset.

Les nouvelles attributions sur un parent déjà acquis sont autorisées : c'est le
besoin produit d'enrichissement, effet immédiat explicite. Afficher l'impact avant
soumission. Une fois créée, leur usage potentiel interdit leur modification
mécanique/retrait ; même sous le seuil, un personnage peut l'atteindre.
Synchroniser avec les valeurs de CHAQUE state concerné ; ne jamais choisir une
session arbitraire. Une ressource nouvellement active peut être ajoutée, une
ancienne inactive reste présente, une progression ne se réinitialise jamais.

## 12. DELETE V1 : définition précise de l'usage

Usage = dépendance entrante structurelle, acquisition directe/indirecte, ou trace
historique pertinente. Pas uniquement « actuellement résolue et active ».
Un lien depuis une autre référence bloque même si aucun personnage ne l'a encore
acquise. Enfants purement composés d'un agrégat inutilisé peuvent être retirés
avec cet agrégat ; aucune attribution indépendante n'est purgée en cascade.

| Définition | Ce qui bloque |
|---|---|
| Class | CharacterClassLevel ; Subclass ; FeatureRule ; ResourceRule ; ActionClassRule |
| Subclass | CharacterClassLevel.subclass ; FeatureRule ; ResourceRule |
| Race | Character.race ; races filles ; FeatureRule ; ResourceRule ; choix raciaux pointant ses modifiers |
| Feat | CharacterFeat ; FeatureRule ; ResourceRule |
| FeatureDefinition | FeatureRule ; par prudence, personnages atteignables via ces règles, même sous seuil |
| ResourceDefinition | ResourceRule ; FeatureDefinition.resource ; state.resources contenant son slug |
| ProgressionDefinition | CharacterProgression ; FeatureRule ; state.progressions contenant son slug |
| ActionDefinition | ActionClassRule ; CRUD fermé V1, aucune promesse de suppression d'effets déjà matérialisés |

Pour DELETE d'une attribution : bloquer si un personnage a acquis la source,
directement ou via ses relations (sous-classe, race parente, don, progression),
même sous le seuil ; inclure les traces de ressource accessibles à partir de
sa cible. Une attribution sans aucun tel usage peut être supprimée si possédée.
Une liste d'usage serveur précède la mutation mais ne remplace pas la revalidation
transactionnelle. Ne révéler que les campagnes/personnages du propriétaire ;
une anomalie externe doit bloquer sans détails inter-owner.

État des FK actuel :

- RESTRICT : CharacterClassLevel.class, CharacterFeat.feat,
  CharacterProgression.progression ; owner → User également RESTRICT.
- CharacterClassLevel.subclass : NO ACTION, bloquante par défaut.
- SET NULL : Character.race, Race.parentRace, FeatureDefinition.resource.
- CASCADE : Subclass.class ; toutes sources et cibles des trois tables de règles ;
  ClassLevelRule.class, RaceAbilityModifier.race, ProgressionStage/AdjustmentRule.parent ;
  CharacterRaceAbilityChoice.modifier.

Ces SET NULL/CASCADE ne respectent pas seuls le DELETE V1. Recommandation pour
la migration unique : remplacer par RESTRICT les FK entre références indépendantes
(Subclass.class, Race.parentRace, FeatureDefinition.resource, toutes FK des trois
attributions) ainsi que Character.race et CharacterRaceAbilityChoice.modifier.
Conserver les cascades d'enfants composés (level rules, modifiers, stages,
adjustment rules), avec garde de l'agrégat ; une acquisition de modifier bloque
alors la cascade. Conserver les RESTRICT/NO ACTION déjà bloquantes.
Pas de changement de donnée requis. Reprendre les noms réels depuis pg_constraint
au moment de rédiger la migration, mettre les onDelete ORM en cohérence.

Une FK bloquante protège la concurrence relationnelle mais ne fournit pas un bon
message : les services doivent traduire la violation en 409 utilisé.
Le state JSON n'a pas de FK : sa protection reste applicative et nécessite la
coordination décrite ci-dessous. Ne pas ouvrir DELETE avant ces garanties.

## 13. State historique et concurrence

Décision recommandée : présence du slug dans state.resources ou state.progressions
d'une session dont la campagne appartient au owner bloque DELETE, y compris
currentValue=0, capacité inactive, personnage non participant ou autre campagne.
Tester la présence de id, pas la vérité de la valeur. Inclure tous les states
persistés, sans filtre de date/participating. Ne pas rechercher en substring dans
le JSON : parcourir les tableaux attendus et comparer exactement le slug.
Jointure d'autorité via la campagne de la session ; vérifier aussi les anomalies
de cohérence Character/Campaign.

Pas de purge automatique. Une donnée mal formée affectant l'analyse d'usage
donne un conflit explicite, jamais une permission de suppression par défaut.
Les noms/slugs dans les définitions personnelles legacy doivent être inspectés
par structure connue lors du ticket DELETE ; pas de recherche textuelle aveugle
ni d'invention d'une nouvelle FK. Une trace externe anormale découverte bloque
conservativement la suppression sans être divulguée.

Class/Subclass/Race/Feat n'ont pas les mêmes marqueurs normalisés de state que
resources/progressions : leurs acquisitions et dépendances restent le garde
principal. Ne pas assimiler toute chaîne JSON égale à un slug à une utilisation.

Course entre contrôle d'usage et création/acquisition/state : transaction obligatoire.
Pour les relations, verrouiller la définition et laisser RESTRICT arbitrer.
Pour le JSON, le futur ticket DELETE doit faire participer tous les writers de
state concernés à un protocole commun de verrouillage, avec ordre stable des IDs,
et relecture d'usage avant DELETE. Un verrou pris seulement par DELETE ne suffit
pas. Tant que cette coordination n'est pas testée, DELETE ressources/progressions
reste fermé. Aucun changement de ces writers dans le présent ticket.

## 14. Contrat serveur de création et autorisation

User authentifié suffit ; aucun ROLE_GM nouveau, aucun CampaignVoter pour la
bibliothèque personnelle. Les opérations sur une campagne continuent à employer
leur contrôle actuel. L'admin OFFICIAL Symfony reste distinct.

POST : listes blanches par type, origin=CUSTOM et owner=User authentifié imposés
ensemble par constructeur/factory métier futur, slug généré serveur.
Refuser les champs origin/ownerId/owner/slug/custom/id et propriétés inconnues.
Pour les anciens champs custom requis en interne, choisir une valeur serveur
cohérente sans les utiliser comme autorité. PATCH ne réaffecte jamais l'identité.
Aucun mass-assignment, désérialisation automatique d'entité ou clone conservant
une provenance OFFICIAL.

Relations : IDs typés, existants, OFFICIAL ou CUSTOM propre, dépendances valides,
cycles interdits, résultat complet revalidé en transaction.
La lecture de la racine CUSTOM est filtrée par owner avant sérialisation.
404 pour ID absent/non possédé/non gérable ; 422 pour payload ou relation invalide
sans révéler l'existence du CUSTOM tiers ; 409 pour conflit d'unicité/usage ;
401 sans authentification. Conserver la protection auth/CSRF applicable aux
mutations de l'application. Pas d'accès public/player à ce CRUD.

## 15. Endpoints futurs minimaux

Convention applicative /api/reference/custom/... côté Angular ; comme les routes
actuelles, vérifier le préfixe du proxy avant de doubler /api dans Symfony.
Contrôleurs explicites, aucun contrôleur de mutation polymorphe.

Pour classes, subclasses, races, feats, features, resources, progressions :
GET collection paginée/filtrée CUSTOM propre ; POST ; GET /{id} ;
PATCH /{id} ; DELETE /{id} uniquement dans le ticket suppression sécurisée.
GET /{id}/usage pour présenter impacts/blocages, sans autorité de mutation.

Feature rules : /reference/custom/feature-rules et /{id}.
Resource rules : /reference/custom/resource-rules et /{id}.
Collections filtrables par sourceType/sourceId, racines toujours CUSTOM propres.
Sélecteurs de cibles : GET /reference/custom/choices/classes (et six autres types
nécessaires), OFFICIAL + CUSTOM propre, lecture seule, sans Campaign fictive.

Enfants sous le parent : /classes/{id}/level-rules,
/races/{id}/ability-modifiers, /progressions/{id}/stages,
/progressions/{id}/adjustment-rules ; identifiants enfants vérifiés sous le parent.
Pas de routes actions V1. Aucun retour des anciens writers globaux /dnd/*.

## 16. Angular MJ futur

Entrée « Contenu personnalisé » : Classes, Sous-classes, Races, Dons, Capacités,
Ressources, Progressions. Catalogue personnel puis éditeur du type.
Attributions intégrées aux fiches sources/cibles, enfants à leur parent ;
pas de nouvel administrateur global. Sélecteurs distinguent OFFICIAL et personnel.
Aucun champ origin/owner/slug, aucune priorité d'override, aucune page Actions.
Afficher effet sur toutes les campagnes, usage et raison d'un verrouillage.
Les contrôles Angular accompagnent les validations serveur ; ils ne les remplacent pas.

## 17. Compatibilité avec les données actuelles

Résultats des probes READ ONLY du 28/09/2026 :

| Table | OFFICIAL | CUSTOM (owner 1) |
|---|---:|---:|
| character_class | 13 | 0 |
| character_subclass | 104 | 1 |
| character_race | 160 | 0 |
| feat | 83 | 0 |
| character_feature_definition | 1528 | 3 |
| trackable_resource_definition | 57 | 3 |
| progression_definition | 0 | 3 |
| character_action_definition | 4 | 0 |
| character_feature_rule | 1497 | 3 |
| trackable_resource_rule | 104 | 0 |
| character_action_class_rule | 9 | 0 |

Dix définitions CUSTOM : subclass #20 arch-hag ; features #247–249 ;
resources #54–56 ; progressions #1–3. Trois FeatureRule CUSTOM #243–245.
Aucune requalification ni réécriture proposée.

- Zéro doublon par origin/owner/source/cible/niveau ou seuil dans les dix familles.
- Zéro relation rejetée par ReferenceVisibility avec le owner de chaque ligne,
  sur les onze types chargés via Doctrine ; OFFICIAL évalué sans owner.
- Zéro FeatureRule/ResourceRule avec nombre de sources incorrect ; zéro incohérence
  progressionThreshold/dummy ; niveaux existants 1..20 (actions 1..11).
- Aucun override/bonus négatif ; 95 overrides, un bonus non nul :
  règle #85 sorcery-points, bonus 2, override NULL. Zéro override CUSTOM.
- Trois ressources CUSTOM ont chacune une trace dans un state du même owner :
  le futur DELETE doit les refuser, même si leur capacité est inactive.
- Aucun slug de ressource spell-slot-* ni feature racial-properties en table.
- Seul doublon subclass global observé : wild-magic OFFICIAL #3/#28 ;
  aucune collision subclass inter-origines. Le plan §5 les conserve.

Les vingt index et CHECK de forme proposés sont compatibles avec les lignes
observées ; le passage de FK à RESTRICT ne nécessite pas de réécriture.
L'exclusion subclass est compatible avec les valeurs observées, sous réserve de
l'installation autorisée de btree_gist et d'un essai DDL en base jetable.
Aucune migration n'a été exécutée : compatibilité préflight, pas preuve d'exécution.

Probes reproductibles : transaction BEGIN READ ONLY ; GROUP BY origin, owner_id
pour comptes ; pour chaque famille, GROUP BY origin, owner_id, source, cible,
niveau/seuil HAVING count(*)>1 ; num_nonnulls pour formes ; jointure subclass
sur slug avec origines différentes ; chargement Doctrine + allows(reference,
reference.owner) ; ROLLBACK. La génération d'un index DBAL a été faite en mémoire.
Les hypothèses FK/index ont été comparées aux catalogues PostgreSQL, pas seulement
aux annotations. Aucun test d'insertion, même suivi de rollback, dans cet audit.

## 18. Prochains tickets, dans cet ordre

1. **Arbitrages ci-dessous**, sans code : maxima, gel mécanique et dépendance subclass.
2. **Une migration index/invariants** : vingt index, CHECK, protection subclass,
   FK RESTRICT et mappings cohérents ; préflight bloquant ; essais sur base jetable,
   tests concurrence O/A/B, NULL, down refusé si ambigu ; schema diff contrôlé.
   Aucun import ni réécriture des dix CUSTOM.
3. **Socle écriture + definitions simples** : factories atomiques, ownership,
   champs réservés, slugs, matrice, usage lecture. Première tranche features/resources
   (sans DELETE), tests multi-user dès ce ticket.
4. **Progressions et enfants**, puis **classes/subclasses**, puis **races/feats et
   enfants**, en tickets séparés ; listes blanches et gel après usage.
   Vérifier les limites des mécaniques liées aux slugs avant d'annoncer leur support.
5. **FeatureRule**, puis **ResourceRule** en deux tickets : collisions par portée,
   seuil explicite, bonus cumulés, impact/synchronisation par state ; pas d'actions.
6. **Catalogue Angular personnel** et sélecteurs ; puis éditeurs par tranche métier,
   réutilisant les composants locaux appropriés, sans reconstruire l'admin OFFICIAL.
7. **Suppression sécurisée** : usages complets, gardes enfants, traces JSON,
   coordination des writers et concurrence, traduction 409, aucun nettoyage state.
   Ouvrir les boutons DELETE seulement après les tests serveur.
8. **Validation intégrée multi-user** : matrice exhaustive A/B/O, plusieurs campagnes,
   profil/builder/level-up/repos historique, 0/inactifs, ajout sur parent utilisé,
   conflits simultanés, frontend ; les tests ciblés accompagnent déjà chaque ticket.
9. **Plus tard** : masking explicite, archive/versioning, actions CUSTOM et nouveaux
   handlers ; éventuelles courbes d'override et édition mécanique avancée.

Pour les futurs tickets de code : tests ciblés + lint:container,
doctrine:schema:validate et build Angular selon périmètre. Dans ce ticket documentaire,
uniquement probes READ ONLY, git diff --check et git status --short.

## 19. Arbitrages réellement ouverts

- **Maxima :** valider bonus additif autorisé / aucune nouvelle override CUSTOM V1.
  Si une courbe privée non additive est indispensable, choisir avant migration
  l'alternative strictement contrôlée de §6.
- **Édition mécanique :** valider le gel des maxima/recharge et relations après usage,
  tout en autorisant édition descriptive, stages et nouvelles attributions.
  Autoriser les changements de maxima après usage exige d'abord un contrat state.
- **Sous-classes :** accepter btree_gist + exclusion inter-origines pour une garantie
  DB complète préservant wild-magic, et vérifier les droits de la future cible.
  Sinon, concevoir la réservation concurrente avant la migration unique.

Ne sont PAS ouverts : propriété User, réutilisation inter-campagnes, slugs CUSTOM
globaux immuables, origin/owner immuables, matrice A/B/O, absence d'override implicite,
DELETE bloqué si utilisé, aucune purge, actions CUSTOM fermées V1, Angular MJ,
pas de CampaignVoter pour le catalogue personnel.
