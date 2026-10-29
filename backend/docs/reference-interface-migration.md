# Migration des interfaces du référentiel — bilan du 18 octobre 2026

> Suite livrée : [unification de l'interface](reference-interface-unification.md).
> `/custom-content` est désormais redirigé ; le contexte Officiel est également
> consultable par les MJ, sans droit de mutation globale.

Branche `feature/spell-catalog`. Travaux précédents conservés, sans commit,
import, migration appliquée ni modification des données applicatives persistantes.

## Parcours livrés

Le même gestionnaire historique Angular propose dix onglets à
`/dnd/reference` (MJ : OFFICIAL consultable et CUSTOM du compte) et
`/dnd/reference/official` (administration OFFICIAL). Le rôle
`ROLE_REFERENCE_ADMIN` est contrôlé par Symfony, y compris en lecture officielle.
Un administrateur ne gagne aucun droit sur les CUSTOM d'autrui. Le périmètre
de création impose origin/owner côté serveur, sans champ d'identité modifiable.

Cette décision remplace l'ancienne restriction « édition OFFICIAL uniquement
dans Twig ». Les écrans Twig existants restent disponibles ; les nouvelles
façades JSON réutilisent les mêmes services de sorts et d'historiques.

| Onglet | Contrat HTTP (préfixe Angular `/api`) | Fonctionnalités |
|---|---|---|
| Classes | `/reference/{scope}/classes/editor` | Liste, création, édition, dés de vie et progression |
| Races | `/reference/{scope}/races/editor` | Métadonnées locales/héritées, parent à la création, bonus identifiés |
| Sous-classes | `/reference/{scope}/subclasses/editor` | Classe parente explicite, progression nullable héritée |
| Dons | `/reference/{scope}/feats/editor` | Caractéristiques autorisées et paramètres structurés |
| Historiques | `/reference/{scope}/backgrounds` | Éditeur existant, règles de langues/maîtrises, CRUD protégé |
| Sorts | `/reference/{scope}/spells` | Éditeur existant, filtres, listes de classes et attributions |
| Progressions | `/reference/{scope}/progressions/editor` | Bornes, bulk, paliers et règles descriptives identifiés |
| Capacités | `/reference/{scope}/features/editor` | Activation, visibilité, ressource liée |
| Ressources | `/reference/{scope}/resources/editor` | Maximum, caractéristique, recharge, attributions et override |
| Attributions | `/reference/{scope}/feature-rules/editor` | Sources réelles, niveau ou seuil, ordre, modification/suppression |

Les attributions de ressources utilisent `resource-rules/editor`. Les adaptateurs
`RaceCatalogueApiService`, `ReferenceCatalogueEditorApiService` et
`ReferenceFeatureEditorApiService` remplacent les transports historiques dans les
gestionnaires. Les fichiers legacy restent utilisés pour leurs types ; aucun
gestionnaire historique n'injecte leur ancien service HTTP. Le catalogue public
du builder est conservé : son contrat ne devient pas un contrat d'administration.

Le design sombre et doré, les cartes et formulaires existants sont conservés.
La navigation accueille les dix onglets avec défilement horizontal et focus clavier.
Les formulaires officiels sont désactivés dans le parcours MJ. Les contrôles serveur
restent autoritaires même si une requête est fabriquée directement.

## Protections et corrections

- Les mutations sont transactionnelles ; inconnus, identités forgées, références
  étrangères et dépendances OFFICIAL vers CUSTOM sont refusés.
- Classes, sous-classes, dons et progressions référencés verrouillent leurs
  mécaniques ; les races utilisées, y compris via un descendant, verrouillent
  métadonnées et bonus. Les corrections éditoriales restent possibles.
- Les gardes existants des capacités, ressources, règles, historiques et sorts
  restent utilisés. Une sauvegarde inchangée de vitesse raciale JSON `9` reste
  équivalente à la valeur PHP `9.0`.
- Les valeurs nulles explicites d'activation/visibilité des capacités sont refusées.
- L'ancien espace `/custom-content` est conservé. Son éditeur de définitions
  recharge maintenant ses données quand le type change ; ses requêtes sont
  annulées à la destruction. Les brouillons et conflits restent visibles.
- Tests anciens actualisés : onze catégories dans cet espace personnel, distinct
  des dix onglets regroupés du gestionnaire historique. Aucune règle métier
  assouplie pour faire passer un test. La fixture de visibilité du builder possède
  désormais sa propre langue fictive temporaire, exigée par le validateur actuel.

## Vérifications exécutées

| Vérification | Résultat |
|---|---|
| `test-reference-race-editor.php` (couvre les dix contrats) | 139 assertions OK |
| `test-custom-reference-features.php` | 199 OK |
| `test-custom-reference-resources.php` | 192 OK |
| `test-custom-reference-feature-rules.php` | 400 OK |
| `test-custom-reference-resource-rules.php` | 421 OK |
| `test-custom-reference-backgrounds.php` | 44 OK |
| `test-reference-selection-visibility.php` | 178 OK : catalogue, builder, level-up, tokens, progressions |
| `test-character-builder-options.php` | 10 OK |
| `test-character-builder-transaction.php` | 8 OK |
| `test-builder-language-roundtrip.php` | 19 OK |
| `test-spell-catalogue-crud.php` | 277 OK |
| `test-character-spell-access.php` | 101 OK |
| `test-character-spell-acquisitions.php` | 264 OK, sans option concurrence |
| Angular : contenu personnel, races, dix onglets, historiques, sorts, grimoire/révisions | 46 tests OK, sept fichiers exécutés ensemble |
| Build Angular | OK ; warning préexistant `player-portal.scss`, 11,51 kB / 10 kB |
| `lint:container`, `debug:router`, `git diff --check` | OK |
| `doctrine:schema:validate` | Mapping OK ; schéma non synchronisé, détail ci-dessous |

Les tests d'intégration DB utilisent des tables temporaires annulées. Le test
unifié contrôle aussi que les mutations du référentiel préservent le personnage
de sa fixture. Les logs de refus attendus ne sont pas des échecs.
Pas de nouvelle campagne de concurrence ni de validation visuelle manuelle dans
un navigateur réel ; les tests Angular utilisent le DOM de test. La suite complète
des slots et son échec FTD historique ne sont pas requalifiés comme réussis.

## Limites et intervention distincte

Les suppressions de classes, races, sous-classes, dons et progressions ne sont pas
ouvertes par les nouveaux adaptateurs (`deletable=false`). Le formulaire historique
de bonus raciaux permet l'ajout, pas une nouvelle API de suppression de bonus.
Ces limites sont explicites ; aucun bouton ne prétend effectuer une opération
backend inexistante. Le gel des mécaniques est conservateur, parfois dès une
relation de configuration, avant même une attribution à un personnage.

Le delta Doctrine lu avec `doctrine:schema:update --dump-sql` correspond au chantier
antérieur `Version20261017140000` : table `character_spell_revision`, colonnes
`revision_id`/`retirement_id` des acquisitions, index et FK. Aucun SQL exécuté.
Les tests du grimoire passent sur leur schéma temporaire complet ; cela ne prouve
pas que les parcours utilisant ces nouvelles colonnes fonctionnent sur la base
de développement encore non migrée. La vérification et simulation de cette
migration restent nécessaires avant leur utilisation réelle. Elle n'est pas
nécessaire à la migration des écrans du référentiel et reste non appliquée.

## Préparation de l'import des sorts

Le modèle convient à un catalogue massif de l'ordre de 490 entrées : pagination,
relations séparées, `importKey`, unicité `(edition, import_key)`, slug unique,
OFFICIAL sans propriétaire, CUSTOM avec propriétaire, CHECK `edition = '2014'`.
Le modèle interdit donc actuellement 2024, plutôt que de mélanger les éditions.

**L'importeur n'est pas encore implémenté.** Aucun `ImportSpellsCommand`, validateur
de corpus ou planner de sorts trouvé. Les contraintes SQL ne suffisent pas à
prouver l'idempotence d'un futur traitement. Prérequis :

1. Figer le corpus exact et son manifeste (490 est la cible annoncée, pas un
   inventaire local vérifié), provenance et conditions de réutilisation comprises.
2. Fournir un export normalisé complet : descriptions, surincantation, composantes,
   coûts conditionnels sans approximation, écoles traduites vers les codes internes.
3. Définir une identité d'import stable ; jamais de rapprochement par nom seul.
   Collision avec un CUSTOM ou identité ambiguë : conflit sans écriture.
4. Planner déterministe create/unchanged/keep/update/conflict, dry-run sans mutation,
   mise à jour OFFICIAL seulement sur demande, aucun effacement, IDs conservés.
5. Réutiliser validations et gel des sorts acquis ; tester second import identique,
   rollback, doublons, concurrence, personnalisation intacte et rejet de 2024.
6. Mapper les classes par identités explicites et distinguer leurs listes de base
   des extensions optionnelles de Tasha et des attributions de sous-classes.

La [page AideDD consultée](https://www.aidedd.org/dnd-filters/sorts.php) distingue
un lien 2024 et une option de listes étendues TCoE. Une collecte future devra fixer
ces paramètres ; leur présence ne vaut ni corpus complet ni autorisation d'import.
Seule consultation de la page d'index : aucun téléchargement de corpus, fichier
de données créé ou import exécuté dans cette mission.
