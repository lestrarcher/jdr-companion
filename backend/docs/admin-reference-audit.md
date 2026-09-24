# Référentiel administrable — audit du 24 septembre 2026

Mesures SQL locales avant extension, sans import ni écriture dans le référentiel réel.
Les effectifs comprennent le contenu personnalisé et les entrées historiques/non sélectionnables.

## Périmètre et politique d’édition

- A — éditorial : nom et description des sept définitions ; gainLabel et spendLabel des progressions.
- B — mécanique potentiellement éditable : aucune modification activée dans cette livraison. Les setters isolés ne prouvent pas la compatibilité avec les personnages et états existants.
- C — identité, structure et mécanique : ID, slug, dates, custom, FKs, enums, bornes, nombres de choix, règles et drapeaux restent en lecture seule.
- Les textes imbriqués des règles/paliers sont consultables. Leur édition demande un formulaire dédié et reste hors de cette livraison, comme l’édition des règles.
- Aucun calcul de personnage/session dans Twig ; aucune session implicite. Null reste « Non renseigné », zéro reste zéro.

## Définitions

Toutes sauf les actions possèdent `createdAt`, `updatedAt`, `custom` ; les sept écrans exposent les identifiants et les textes. Les dates restent automatiques.

| Catégorie | Entité / table | Entrées | A : éditable | C : métadonnées et relations consultables |
|---|---|---:|---|---|
| Capacités | CharacterFeatureDefinition / character_feature_definition | 1531 | name (150), description | id, slug, activationType, visible, custom ; resourceDefinition nullable ; attributions multiples via CharacterFeatureRule |
| Classes | CharacterClass / character_class | 13 | name (120), description | id, slug, hitDie, subclassSelectionLevel, spellcastingProgression ; sous-classes, capacités, ressources, règles de niveau et actions |
| Sous-classes | CharacterSubclass / character_subclass | 105 | name (120), description | id, slug, characterClass obligatoire, spellcastingProgression nullable (héritage) ; capacités et ressources propres ; lien vers la classe pour ses attributions |
| Races | CharacterRace / character_race | 160 | name (120), description | id, slug, parentRace nullable, selectable, sizeOptions, walkingSpeed, movementSpeeds, languages, languageChoiceCount, senses, damageResistances, damageImmunities, conditionImmunities, featChoiceCount ; abilityModifiers, capacités, ressources et variantes |
| Dons | Feat / feat | 83 | name (120), description | id, slug, repeatable, requiresAbilityChoice, chosenAbilityIncrease, allowedAbilities ; capacités et ressources |
| Ressources | TrackableResourceDefinition / trackable_resource_definition | 60 | name (150), description | id, slug, rechargeType, maximumType, baseMaximum, multiplier, minimumMaximum, scalingAbility ; capacités utilisatrices, sources qui les attribuent, règles de maximum |
| Progressions | ProgressionDefinition / progression_definition | 3 | name (120), description, gainLabel (80), spendLabel (80) | id, slug, minimumValue, maximumValue nullable, accentColor, bulkAdjustmentEnabled ; stages, adjustmentRules, seuils de capacités et ressources via capacités |

Les nombres entre parenthèses sont les limites de colonnes/validation des textes courts.
Les descriptions utilisent une colonne TEXT nullable ; les formulaires acceptent le texte vide, normalisé en null par les setters existants.

### Dépendances runtime et absences du modèle

- Classes/sous-classes : utilisées par CharacterClassLevel, progression magique, éligibilité et level-up. Pas de champ Doctrine « caractéristiques principales ». Les conditions de multiclassage vivent dans CharacterMulticlassEligibilityService, pas dans une table éditoriale.
- Races : les personnages et CharacterRaceAbilityChoice référencent les définitions/modificateurs. Les métadonnées affichées sont propres à la race ; le parent est navigable, sans recalcul d’héritage dans le dashboard.
- Dons : attribution via CharacterFeat ; aucun champ autonome de prérequis dans Feat. `getAllowedAbilities()` reste le getter existant (liste effective autorisée).
- Capacités : CharacterFeatureResolver applique les niveaux/seuils. La fiche n’applique aucune règle à un personnage ; les seuils de progression ne deviennent pas des niveaux.
- Ressources : CharacterResourceResolver et les services de state/repos consomment leurs identités. `storedValues` appartient au state de session. Pour `des-de-presage`, PortentResourceConfiguration ajoute une configuration runtime de valeurs stockées ; elle ne constitue pas une colonne de TrackableResourceDefinition. Les fiches exposent les règles persistées, pas un maximum résolu de personnage.
- Progressions : définitions autonomes attribuées par CharacterProgression ; valeur courante uniquement dans CharacterSessionState. Les progressions ne sont pas réinitialisées par les repos.

## Tables associées intégrées aux fiches

| Entité / table | Entrées | Champs et relations | Présentation |
|---|---:|---|---|
| CharacterFeatureRule / character_feature_rule | 1500 | id, featureDefinition, characterClass/characterSubclass/characterRace/feat/progressionDefinition, unlockLevel, progressionThreshold, displayOrder | Capacités et fiches d’origine ; niveaux/seuils + liens |
| TrackableResourceRule / trackable_resource_rule | 84 | id, resourceDefinition, classe/sous-classe/race/don, unlockLevel, maximumOverride nullable, maximumBonus | Ressource et fiches d’origine ; zéro distingué de null |
| CharacterClassLevelRule / character_class_level_rule | 68 | id, characterClass, level, advancementChoice, notes | Tableau de la classe |
| CharacterActionDefinition / character_action_definition | 4 | id, slug, name, description, handlerType, requiresPreparation, active, custom | Détails des actions dans les classes ; pas de formulaire d’action |
| CharacterActionClassRule / character_action_class_rule | 9 | id, actionDefinition, characterClass, unlockLevel | Tableau des actions de classe |
| RaceAbilityModifier / race_ability_modifier | 156 | id, race, ability nullable, value, choiceKey nullable | Tableau des modificateurs propres à la race |
| ProgressionStage / progression_stage | 17 | id, progressionDefinition, label, description, minimumValue, maximumValue nullable, iconUrl, displayOrder | Tableau des paliers |
| ProgressionAdjustmentRule / progression_adjustment_rule | 0 | id, progressionDefinition, direction, triggerType, description, adjustmentLabel, displayOrder | Tableau des règles narratives lorsque présentes |

Les actions sont distinctes des capacités : aucun lien n’est déduit de noms ou slugs similaires.
Pas d’écran pour les tables d’association des personnages ou états de session. MagicItem dépend d’une campagne ; il ne constitue pas ici un catalogue officiel global.

## Sources historiques conservées

- `backend/data/reference/dnd-2014-class-features.json` et `ImportClassFeaturesCommand` : catalogue de capacités de classes, attributions et configuration d’actions associée à cet import.
- `dnd-2014-races.json`, `dnd-2014-races-manifest.json`, `ImportRacesCommand` : races, métadonnées, traits et attributions.
- `dnd-2014-feats.json`, `ImportFeatsCommand` : dons et éléments associés.
- `InitializeDndReferenceCommand` / `DndReferenceInitializer` : initialisation historique des classes, sous-classes, races, dons, ressources et règles de niveau.
- Migrations historiques : normalisations et ajouts (dont actions, progressions, paliers, règles narratives et Présage). Aucun nouvel import/migration n’est nécessaire pour ces écrans.
- Aucun JSON autonome de progressions n’a été trouvé dans `backend/data/reference`.

Ces fichiers/commandes/migrations ont été inspectés uniquement. Le dashboard écrit directement dans les définitions Doctrine, sans synchronisation JSON.

## Architecture livrée

- Capacités : service et contrôleur existants conservés ; liens ajoutés aux origines et ressources.
- Six nouvelles catégories : AdminReferenceCatalogue + AdminReferenceCatalogueController. Liste fermée d’entités et de filtres ; détails explicitement construits par catégorie ; aucune réflexion/hydratation automatique.
- Templates liste/fiche partagés uniquement pour les comportements identiques. Layout et CSS existants conservés ; JS du brouillon couvre aussi les deux libellés de progression.
- GET avec recherche nom/slug, 25 résultats, ordre name/id, filtre custom ; filtre classe pour sous-classes, parent/selectable pour races, recharge pour ressources.
- POST limité à la whitelist éditoriale, validation entière avant mutation, CSRF par catégorie/ID, PRG 303 et flash. Protection ROLE_USER existante ; aucune évolution auth.
- Liens de retour conservant la liste filtrée ; liens croisés directs vers les fiches. Les relations volumineuses d’une fiche ne sont pas paginées séparément dans cette version.
