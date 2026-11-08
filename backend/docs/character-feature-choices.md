# Choix de capacités — socle backend

Branche : feature/character-feature-choices. Aucun catalogue de famille n'est livré.

## Persistance

Migration additive Version20261108020000 : quatre tables DBAL, comme les acquisitions de sorts.
Les tables sont vides après migration. Aucun personnage ni attribution manuelle n'est modifié.

- feature_choice_group : clé stable, nom, owner_id nullable (global ou personnel), unicité
  group/character/none, remplacement none/class-level/grant-level, enabled.
- feature_choice_option : clé stable, groupe, référence CharacterFeatureDefinition,
  prérequis JSON, enabled. Une capacité apparaît une fois par groupe.
- feature_choice_grant : clé stable, groupe, référence CharacterFeatureRule, nombre de
  nouveaux choix à ce palier (1 à 20), enabled. Les paliers s'additionnent.
- character_feature_choice : clé opaque aléatoire stable, personnage, droit, option,
  emplacement stable 1..quota, position globale et niveau de classe à l'enregistrement,
  provenance descriptive conservée, acteur MJ éventuel, motif, date, active,
  replaces_key et event_key. Aucun CharacterFeatureAssignment parallèle.

Les identifiants API sont les clés de catalogue, pas les ID locaux des capacités/règles.
Les FK internes restent locales ; un futur import doit résoudre ces références.
Les suppressions de références utilisées sont RESTRICT. Désactiver un droit/une option
invalide son effet sans supprimer l'historique. Le rollback de migration est interdit.
Ne pas utiliser doctrine:schema:update pour gérer ces tables DBAL.

## Lecture et résolution

FeatureChoiceReader reçoit les règles avant déduplication. Il ne rappelle jamais le
resolver : pas de dépendance circulaire. Il calcule les droits, options, sélections,
prérequis manquants et choix invalides. Lecture sans écriture.

CharacterFeatureResolver ajoute les choix valides à applicableRules et acquisitionRules,
via des règles virtuelles portant choiceProvenance. resolve conserve sa déduplication.
Les attributions manuelles et autres sources restent indépendantes. L'API d'historique
conserve toutes les provenances même lorsque le profil final n'affiche qu'une capacité.
Le profil CA réutilise son raccordement existant. Les sorts exposent aussi choiceKeys.

Prérequis actuellement reconnus (conjonction) :

    {"classLevels":{"class-key":3},"subclasses":{"class-key":"subclass-key"},
     "features":["feature-key"],"feats":["feat-key"]}

Une option peut dépendre d'une autre sélection dans la même soumission. La résolution
par point fixe rejette les cycles et l'autojustification. Les prérequis inconnus sont
indisponibles, jamais ignorés. Les conditions de sort connu, les alternatives OR et
les conditions de combat ne sont pas encore implémentées.

Les références sont filtrées par ReferenceVisibility et le propriétaire du groupe.
Pas de nouvelle interprétation des restrictions de campagne.
Les droits à acquérir utilisent applicableRules, pas la variante historique :
un seuil de progression réversible n'est donc pas considéré atteint sans contexte
explicite. Les API de ce lot concernent les acquisitions permanentes ; aucun état
de session n'est recherché implicitement pour ouvrir un droit.

## API (préfixe /api côté proxy Angular)

- GET /campaigns/{campaignId}/characters/{characterId}/feature-choices
- POST même URL : régularisation MJ contrôlée par CampaignVoter::MANAGE.
- POST même URL + /preview : projection de la prochaine classe/sous-classe.
- GET /public/characters/{accessToken}/feature-choices : personnage du token participant.
- POST même URL + /preview : session LIVE et montée autorisée par le MJ.

Le GET renvoie fingerprint, grants (quota, remaining, selected, options/prerequisites/
reasons), history et issues. Aucun choix arbitraire n'est créé.

Projection :

    {"classKey":"class-key","subclassKey":null}

Elle utilise CharacterLevelUpOptionsService, ajoute un niveau en mémoire puis le retire
dans finally. Elle renvoie newAtLevel, replacementAvailable, projectedPosition et
baseFingerprint. Elle n'anticipe pas un don ou une augmentation de caractéristique
non encore sélectionnés ; la validation finale de niveau reste autoritaire.

Régularisation MJ :

    {"fingerprint":"empreinte-du-GET","reason":"Correction validée",
     "selections":[{"grantKey":"grant-key","optionKey":"option-key","slot":1}]}

Remplacement MJ : ajouter replacesKey (clé d'une sélection active du même emplacement).
Le motif est obligatoire ; aucune attribution manuelle n'est effacée.

Écriture joueur : utiliser l'endpoint de montée de niveau déjà existant, avec
featureChoices dans son payload. Même liste de sélections. Pas d'endpoint public
de régularisation libre. Le créateur MJ accepte également featureChoices.

La transaction de niveau n'accepte les nouvelles sélections que pour les droits
nouvellement débloqués. Les anciens choix manquants restent à régulariser par le MJ ;
ils ne sont pas inventés ou supprimés. Un nouveau droit incomplet annule le niveau.
Les remplacements sont possibles uniquement au cours d'un nouveau niveau de la classe
source : class-level, ou grant-level si un nouveau droit du même groupe est débloqué.
Un remplacement par groupe et événement de niveau, enregistré et non réutilisable.
Les politiques facultatives supplémentaires devront être modélisées explicitement.

## Transactions et compatibilité

Verrou personnage partagé avec level-up ; empreinte relue sous verrou pour la
régularisation (409 si périmée). Index unique partiel personnage/droit/emplacement
actif et unicité des événements de remplacement. Toute soumission est validée
en entier avant les écritures. Les dépendances d'un choix déjà valide ne peuvent
pas être invalidées silencieusement par un remplacement.

La synchronisation des sorts et ressources utilise les services existants.
Pas de réinitialisation des progressions, inventaires ou consommations. Le retrait
d'une source n'efface pas les acquisitions historiques. Les choix dont la source
est désactivée sont signalés ; la correction de catalogue est une opération séparée.

## Lot 2

Ajouter les définitions et options de styles, leur configuration CA, puis les écrans
Angular. Le modèle n'implique ni choix automatique de Défense ni mécanique de classe.
Prévoir l'administration/import des groupes et les prérequis supplémentaires avant
les familles plus complexes. Aucun éditeur de catalogue n'est livré dans ce lot.

Tests : CharacterFeatureChoicesTest utilise exclusivement des tables PostgreSQL
temporaires vérifiées, et rollback. Exécuter avec php bin/phpunit.

## Validation globale du schéma DEV

`doctrine:schema:validate` valide les mappings, mais signale une divergence globale.
Le diagnostic en lecture seule `doctrine:schema:update --dump-sql` demande notamment
de supprimer les tables DBAL (choix et acquisitions de sorts), ainsi que deux tables
de sauvegarde locales. Il signale également des différences antérieures d'identité
et de noms d'index sur les attributions, possessions et usages de sorts d'objets.
Ce SQL ne doit pas être exécuté : il détruirait des données hors mapping ORM.
La migration additive gère explicitement les quatre nouvelles tables ; la validation
globale n'est donc pas annoncée comme verte. Les tests isolés valident leurs contraintes.
