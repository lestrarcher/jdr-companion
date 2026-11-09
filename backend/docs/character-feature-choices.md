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
indisponibles, jamais ignorés. Les conditions de combat ne sont pas implémentées.
Le lot 2 ajoute `anyOf`
(alternatives de prérequis) et `spellsKnown` (clés de sorts effectivement acquis,
non remplacés/non retirés, ou octrois actifs provenant d'une capacité résolue).

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

## Lot 2 : catalogue 2014 installé

Source versionnée : `data/reference/dnd-2014-feature-choices.json`. Les définitions
sont résolues par slug ; aucun ID local n'est transporté dans le catalogue.

| Famille | Options concrètes | Acquisitions et remplacement |
| --- | --- | --- |
| Styles | 13 styles distincts PHB/TCE, répartis dans 5 listes de classe/sous-classe | Guerrier 1, paladin/rôdeur 2, collège des épées 3, champion 10 : un choix par droit. Unicité par personnage. |
| Métamagies | 8 PHB + 2 TCE | Deux à ensorceleur 3, une à 10, une à 17. Aucun échange PHB automatique. |
| Manifestations | 32 PHB + 14 XGE + 8 TCE | Deux à occultiste 2, une à 5/7/9/12/15/18 : huit au total. Un échange par nouveau niveau d'occultiste. |
| Totems | Ours, aigle, loup, élan, tigre à chacun des trois paliers | Un choix indépendant à barbare 3/6/14, exclusivement Voie du guerrier totémique. Aucun remplacement automatique. |
| Pactes, support aux prérequis | Lame, chaîne, grimoire, talisman | Un choix à occultiste 3 ; les prérequis des manifestations lisent ces capacités. |

Les options donnent nom, description, source bibliographique, prérequis et raisons
d'indisponibilité. `alreadyAcquired` signale une capacité déjà issue d'une source
existante ; elle peut être régularisée sans recopier une attribution manuelle.
Les paliers d'une famille sont cumulés avant la déduplication finale.

Les remplacements facultatifs TCE nécessitent une capacité explicitement attribuée
par le MJ : `sorcerous-versatility` ou `martial-versatility-{fighter,paladin,ranger}`.
Ces définitions n'ont aucune attribution automatique. Les fenêtres correspondent
aux niveaux d'ASI de la classe ; le champion partage le budget de remplacement
du guerrier. Le remplacement de métamagie est refusé si un tour de magie a déjà
été remplacé lors de ce même niveau. Les choix de sorts sont donc validés avant
les choix de capacités, dans la même transaction de montée de niveau.

Le style Défense configure le moteur existant : +1 CA avec armure, jamais avec un
bouclier seul, sans cumul du même style. Les métamagies référencent la ressource
`sorcery-points` existante : aucune seconde réserve et aucune consommation automatique.

### Installation et réinstallation

Après les migrations et l'import du catalogue de classes 2014 :

```sh
php bin/console app:features:initialize-choices-2014
php bin/console app:features:initialize-choices-2014 --apply
php bin/console app:features:initialize-choices-2014
```

Sans `--apply`, le plan n'écrit rien, pas même les séquences. L'application prend un
verrou de catalogue et préserve les niveaux pendant la capture historique. Elle
est transactionnelle et additive : collisions personnelles ou configuration
divergente provoquent un refus, pas un écrasement. Les descriptions des définitions
réutilisées restent intactes. La commande ne synchronise aucun personnage.

Migration structurelle `Version20261108030000` : fenêtres/budget de remplacement,
code source des options, tables de rattachement des anciennes règles automatiques
aux choix et de conservation de leurs bénéficiaires historiques.

DEV : 95 nouvelles définitions, 5 définitions réutilisées, 11 groupes, 121 options
de groupe, 19 droits, 6 règles de palier ajoutées et 15 règles automatiques encadrées.
Dix droits historiques totémiques sont conservés, sans sélection arbitraire.
Deuxième aperçu : aucune création restante. Les empreintes des possessions, états
de session, attributions manuelles et acquisitions de sorts sont identiques avant/après.

### Personnages existants et totems

L'ancien catalogue attribuait tous les animaux automatiquement. Les règles ne sont
pas supprimées : `feature_choice_rule_gate` les rend dépendantes du mécanisme de
choix pour les nouveaux droits. `feature_choice_legacy_rule` conserve leurs anciens
bénéficiaires, capturés une seule fois à l'installation. Le GET expose ces cas dans
`legacyAttributions`. Tant que le palier n'est pas régularisé, ces anciennes capacités
restent disponibles. Une sélection explicite du MJ remplace ces attributions
automatiques pour ce palier ; les attributions manuelles restent indépendantes.

Les identités animales historiques sont réutilisées au niveau 3. Des définitions
distinctes aux niveaux 6 et 14 évitent de confondre des effets différents portant le
même animal. Les données de provenance historique ne sont jamais effacées.

### Frontières de cette livraison

La sélection, les prérequis, quotas, remplacements et la résolution des options
concrètes fonctionnent. Cela ne constitue pas une automatisation de tous leurs
effets en combat. Les invocations à volonté ou consommant à la fois un emplacement
de pacte et une utilisation limitée ne disposent pas encore de méthode de lancement
compatible : aucun octroi de sort incomplet ou coût approximatif n'est créé.
Leurs effets sont décrits, mais ne deviennent pas des boutons de lancement utilisables.

Restent à raccorder : sous-choix de sorts des styles Guerrier béni/druidique et des
pactes, rituels du Livre des secrets anciens, manœuvre de Technique supérieure,
maîtrises d'Influence trompeuse et du totem tigre 6, automation des autres bonus,
réactions et consommations. Les familles ont leurs options concrètes sélectionnables,
mais ces effets/sous-choix ne doivent pas être présentés comme terminés.
Le changement de pacte par Polyvalence occulte TCE reste hors de ce catalogue.

La visibilité réutilise `ReferenceVisibility` et les propriétaires existants ;
`source_code` est une référence bibliographique, pas une nouvelle autorisation de livre
par campagne. Aucun système d'activation des suppléments par campagne n'est inventé.
Pas d'interface Angular ni d'éditeur des familles dans cette livraison.

Références de vérification : règles de classes
[2014](https://www.dndbeyond.com/sources/dnd/basic-rules-2014/classes),
[manifestations 2014](https://www.aidedd.org/dnd-filters/eldritch-invocations.php),
[métamagies et polyvalence](https://dnd5e.wikidot.com/sorcerer),
[totems](https://dnd5e.wikidot.com/barbarian:totem-warrior).

Tests : CharacterFeatureChoicesTest utilise exclusivement des tables PostgreSQL
temporaires vérifiées, et rollback. Exécuter avec php bin/phpunit.

Validation du 8 novembre 2026 : suite complète, 169 tests / 4 553 assertions ;
puis test additionnel du budget partagé guerrier/champion, 1 test / 12 assertions.
`lint:container` et `git diff --check` réussis. Aucune migration en attente en DEV.
Le frontend est inchangé ; aucun build Angular n'est requis pour ces lots backend.

## Validation globale du schéma DEV

`doctrine:schema:validate` valide les mappings, mais signale une divergence globale.
Le diagnostic en lecture seule `doctrine:schema:update --dump-sql` demande notamment
de supprimer les tables DBAL (choix et acquisitions de sorts), ainsi que deux tables
de sauvegarde locales. Il signale également des différences antérieures d'identité
et de noms d'index sur les attributions, possessions et usages de sorts d'objets.
Ce SQL ne doit pas être exécuté : il détruirait des données hors mapping ORM.
La migration additive gère explicitement les quatre nouvelles tables ; la validation
globale n'est donc pas annoncée comme verte. Les tests isolés valident leurs contraintes.

## Lot 3 — Raccordement Angular

Le composant partagé `CharacterFeatureChoices` consomme les aperçus backend dans
la création au niveau 1, la montée de niveau MJ/joueur et la régularisation MJ
depuis l'onglet des capacités et ressources de la fiche. Il transmet les clés
stables des options et des choix remplacés aux commandes existantes ; aucun
effet mécanique n'est calculé côté Angular.

L'API des options de création expose désormais `featureChoices` sur le personnage
transitoire. Le resolver ignore ses attributions persistées tant qu'il n'a pas
d'identifiant. Les nouveaux choix obligatoires bloquent la validation ; les choix
historiques manquants restent régularisables par le MJ sans bloquer un niveau.

Les descriptions, prérequis et erreurs proviennent du backend. Les dépendances
entre choix du même formulaire sont vérifiées ensemble à l'enregistrement.
Un changement de classe annule l'ancien aperçu et efface sa sélection ; une erreur
de validation conserve le brouillon. Une régularisation périmée (409) impose un
rechargement explicite, avec avertissement de perte du brouillon.

Validation Angular : 48 tests ciblés (éditeur partagé, création, montée de niveau
et fiche), build réussi. Les budgets SCSS de `player-portal` et
`character-magic-items` restent dépassés, sans changement de ces styles.
Aucune migration, configuration de catalogue ou nouvelle mécanique dans ce lot.

## Prérequis de sorts pendant la montée de niveau

La validation conserve les choix valides avant toute modification des sorts.
Une perte de prérequis annule toute la transaction de niveau, sauf si les choix
dépendants sont remplacés légalement dans la même soumission. Les anciennes
incohérences déjà présentes restent régularisables sans bloquer arbitrairement
les personnages historiques.

Un remplacement de sort connu conserve le budget ordinaire d'une manifestation.
Un remplacement de sort mineur validé par la politique Tasha portant le budget
`eldritch-versatility` impose aussi le remplacement de toutes les manifestations
devenues inéligibles à cause de ce sort. Ces remplacements obligatoires sont
tracés séparément ; ils n'ouvrent pas de remplacements libres supplémentaires.
Le changement de pacte reste hors périmètre.

## Livre des ombres — liaison physique

La migration additive `Version20261109010000` ajoute à `CharacterMagicItem`
`pact_owner_id` (titulaire initial) et `pact_choice_key` (acquisition historique).
Les deux identifiants sont uniques ; une FK conserve la référence au choix.
Aucun inventaire existant n'est modifié par la migration.

Après validation du Pacte du grimoire, la transaction de niveau ou de
régularisation crée un livre individuel vide et sa liaison. Les opérations
répétées retrouvent le même livre, même si son détenteur a changé. Les inscriptions
restent dans `PactTomeEntry` ; aucun sort n'est attribué par cette liaison.

Le POST MJ `/campaigns/{campaignId}/characters/{characterId}/feature-choices`
accepte le champ facultatif `pactTomeBookId`, en plus de `fingerprint`, `reason`
et `selections`. Il permet de rattacher un livre historique lors de l'acquisition,
ou avec `selections: []` si le pacte est déjà acquis. Le GET/POST expose
`pactTome: {ownedItemId, choiceKey, holderCharacterId}` ou `null`.

Le livre doit appartenir au personnage, contenir des inscriptions de pacte et
aucune inscription de magicien. Un grimoire vide non identifié ne sera pas
converti sur la seule foi de son nom. La présence d'un ancien Livre des ombres
non lié exige ce rattachement explicite au lieu de créer un doublon. Une autre
liaison déjà établie est refusée ; le remplacement d'un livre est hors périmètre.
Le motif et l'empreinte utilisent le contrôle MJ existant ; les inscriptions
historiques sont conservées. Pas d'écran Angular supplémentaire dans ce lot.

Les écritures de magicien excluent aussi les livres liés encore vides. Les
autorisations de lancement, octrois et copie de rituels restent à raccorder dans
les lots suivants ; cette liaison ne prétend pas achever leurs règles.

Le catalogue distingue Maléfice des capacités de malédiction : connaître
Malédiction ne remplace pas la capacité Signe de mauvais présage. Les tests
utilisent les politiques réelles et des tables temporaires, et comparent les
données persistées avant/après les transactions refusées.
