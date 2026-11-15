# Imprégnations actives — B1 à C2

Le service `ItemInfusions` réutilise `FeatureChoiceReader` et les règles applicables
de `CharacterFeatureResolver`. Seuls les choix persistés, actifs et valides du
groupe `artificer-infusions` autorisent une application. Le niveau d'artificier,
et non le niveau total, détermine le quota (2/3/4/5/6 aux niveaux 2/6/10/14/18).

## Persistance et transactions

`CharacterItemInfusion` est une entité de lecture Doctrine. Les écritures DBAL
sont centralisées dans `ItemInfusions`, comme pour les acquisitions de choix.
La migration additive `Version20261114020000` crée `character_item_infusion` :
créateur, possession nullable après suppression, clé exacte du choix acquis,
nom du support conservé pour l'historique, dates, état et motif de fin.

Deux index uniques partiels interdisent plusieurs applications actives par
possession ou par choix. Une contrainte contrôle la cohérence des dates et de
l'état. Les mutations verrouillent le personnage créateur, sa session et le
support. Le quota est vérifié sous le verrou du créateur ; l'éviction du plus
ancien (date puis ID) et l'insertion sont dans la même transaction.

Les déclencheurs PostgreSQL terminent les applications lorsque le choix devient
inactif ou avant suppression du support, y compris par cascade. La FK du support
devient alors NULL, mais l'historique subsiste. Le retrait volontaire est
idempotent. Aucun changement n'est effectué sur les définitions, l'équipement,
l'harmonisation ou les statistiques. Un repos long conserve les applications.
La mort et son délai exigent un futur contexte explicite de temps de jeu ; aucun
chronomètre réel ou effacement au repos n'est substitué à cette règle.

## API joueur

Préfixe : `/public/characters/{accessToken}/infusions` (préfixé `/api` via Angular).

| Méthode | Chemin | Contrat |
|---|---|---|
| GET | préfixe | `known`, `quota`, `active`, `preparation` |
| GET | `/recipients` | Personnages de la même campagne : ID, nom |
| GET | `/recipients/{recipientId}/items?choiceKey=…` | Supports avec `eligible` et motif de refus |
| POST | préfixe | Désactivé en C2 : 409, utiliser la confirmation globale |
| DELETE | `/{id}` | Désactivé en C2 : 409, utiliser la confirmation globale |
| POST | `/preparation/confirm` | JSON `{phaseId, selections?}` ; confirmation globale et clôture |

Chaque choix connu expose `choiceKey`, `featureKey`, `name`, `mode`. Une application
active expose `id`, `creatorId`, `itemId`, `itemName`, `recipientId`, `choiceKey`,
`createdAt`. Le token identifie le créateur ; aucun ID de créateur n'est accepté
dans le corps. Les mutations exigent une session ouverte et une participation
active. Elles ne donnent aucun droit général sur l'inventaire du destinataire.
Pas de consentement supplémentaire.

## Supports et limites explicites

Les catégories physiques actuelles permettent de valider :

- Défense améliorée : armure ou bouclier.
- Armure de force magique, Armure résistante, Armure de propulsion arcanique : armure.
- Aiguiseur d'esprit : armure (la variante robe nécessite une classification plus précise).
- Bouclier de répulsion : bouclier.

Les autres supports ne sont pas déduits des noms/descriptions : absence de
classification simple/martiale, lancer/munitions, focaliseur, bottes, casque,
anneau ou gemme et valeur. Ces cas sont refusés explicitement jusqu'à la
configuration physique correspondante. Les possessions personnalisées sans
définition, les piles et les anciennes piles sont également refusées.
Les objets déjà magiques ou imprégnés sont refusés.

Les 49 reproductions exposent `mode: create-object` et sont refusées à l'application
(B4). Aucun objet n'est créé. B2 raccordera les effets ; B3 fournira l'interface.
Les conditions narratives (toucher le support, fin d'un repos long) restent à
confirmer par le joueur : B1 n'invente pas de suivi de temps ou de distance.

## Validation

`ItemInfusionsTest` utilise uniquement des tables/fonctions PostgreSQL temporaires,
avec rollback. Il exécute la migration réelle dans ce schéma isolé et couvre
l'API, les droits, supports, quotas, multiclassage, unicités, éviction, retraits,
perte du choix, suppression, rollback, repos long et absence d'effets mécaniques.
La migration doit être exécutée dans DEV avant utilisation des nouveaux endpoints.

## B2 — Défense améliorée et CA

`ItemInfusionArmorEffects::resolve()` fournit les effets contextuels au
`CharacterProfileSerializer`. Une seule requête groupée lit les applications
actives, les choix encore actifs, la possession actuelle équipée/utilisable et
le nombre actuel de niveaux d'artificier de chaque créateur. Aucun bonus n'est
persisté ou mis en cache. Les définitions déjà magiques sont exclues.

Le calculateur de CA existant ajoute +1, ou +2 à partir du niveau 10 d'artificier,
sans remplacer l'armure de base ni le +2 physique du bouclier. L'identité stable
de l'application évite de compter deux fois le même effet contextuel. Le contrat
des contributions est inchangé : label « Défense améliorée », source comprenant
le nom du créateur, valeur numérique du bonus. Les autres imprégnations ne
produisent aucun effet dans ce raccordement.

La valeur est actualisée à chaque lecture du profil après persistance des
changements. B2 n'ajoute pas de notification interpersonnages ou d'interface :
les clients utilisent leur rafraîchissement existant. Aucun transfert général
de bouclier n'est ajouté ; la résolution suit son propriétaire persistant.

## C1 — Phase après repos long

Le parcours d'approbation MJ existant (`RestRequestController::respond`) appelle
`CharacterRestService` dans sa transaction et sous verrou. Un repos long ouvre
`state.infusionPreparation = {id, open}` uniquement avec la capacité acquise
`infusions-known` et au moins deux niveaux d'artificier. Le niveau total ne
remplace pas le niveau de classe. Sans état explicite, la phase est fermée.
Un repos court ne change pas cette autorisation. Aucun repos ne retire les
applications existantes. La préparation des sorts reste indépendante.

`GET /public/characters/{accessToken}/infusions` expose `preparation`.
Les POST d'application et DELETE de retrait B1 exigent désormais cette phase,
en plus des contrôles de participation, session active et propriété existants.
`POST .../infusions/preparation/confirm`, avec uniquement `{phaseId}`, ferme
la phase sans changer les objets. Une confirmation répétée est sans effet ;
un ancien identifiant ne peut fermer la phase du repos suivant. Le PATCH joueur
générique interdit de modifier cette autorisation. Aucun nouveau bypass MJ :
le MJ utilise son parcours existant de validation du repos.

Réappliquer une formule sur un autre support retire son ancienne application
dans la même transaction (`formula_reapplied`). Une répétition sur le même
support est sans effet. Le FIFO, les quotas et l'historique restent conservés.
La perte d'un choix lors d'une montée de niveau déclenche le garde B1
`choice_lost` ; aucun nouveau catalogue ni migration n'est nécessaire.

### Reprise en C2

L'état est lié à la session explicite, comme la préparation des sorts ; aucune
session n'est recherchée implicitement. C1 conserve les mutations unitaires B1
uniquement pendant la phase et n'ajoute pas de mutations de brouillon. C2 devra
faire valider le plan complet et fermer cette même phase dans une transaction
unique, avec les verrous créateur/supports et validations B1, puis retirer ou
rediriger les voies unitaires pour empêcher de contourner la validation globale.
Ne pas simplement boucler sur les endpoints : valider d'abord le plan entier.

L'interface B3 n'est pas modifiée dans C1 : elle reçoit une erreur explicite
hors phase. L'affichage de la phase et sa confirmation seront raccordés en C2.
Il n'y a pas d'expiration horaire automatique : la phase reste ouverte jusqu'à
confirmation, suivant la convention de préparation existante.

## C2 — Confirmation globale (remplace les mutations unitaires C1)

Le brouillon reste côté client. Aucun nouvel endpoint ni table de préparation.
La confirmation existante accepte l'état souhaité complet :

```json
{
  "phaseId": "identifiant de phase de 32 caractères hexadécimaux",
  "selections": [
    {"choiceKey": "clé du choix acquis", "recipientId": 12, "itemId": 34}
  ]
}
```

- `selections` absent : conserver les applications actuelles et fermer la phase.
- `selections: []` : retirer toutes les applications et fermer la phase.
- Liste présente : les applications absentes sont retirées. Chaque couple
  choix/support déjà actif est conservé avec son ID et sa date d'origine.
- Maximum de 100 entrées ; clés strictes, identifiants positifs et liste ordonnée.
  Une formule ou un support ne peut apparaître deux fois.

Réponse : `known`, `quota`, `active`, `preparation`, `replayed`, `evicted`.
`evicted` contient les couples exclus par le FIFO, avec `id` pour les anciennes
applications. Les erreurs de forme produisent 400/422, les conflits métier 409,
les destinataires/supports non accessibles 404. Les messages sont en français.

### Ordre FIFO prévisible

1. Applications conservées, dans leur ancien ordre `(created_at, id)`.
2. Nouvelles applications, dans l'ordre explicite du tableau `selections`.
3. Retirer le début de cette liste jusqu'au quota du niveau d'artificier.

Réordonner les applications conservées dans la requête ne change donc pas leur
ancienneté. Une réapplication sur un autre objet est nouvelle. Une nouvelle
entrée elle-même éliminée par le FIFO n'est jamais créée, ni inscrite comme
application historique : elle figure dans `evicted`. Toutes les entrées sont
validées, même celles que le FIFO éliminerait. C3 devra montrer ce résultat
prévisible avant la confirmation, en reprenant cet ordre fourni par `active`.

### Atomicité et concurrence

`ItemInfusions::finishPreparation` utilise la transaction DBAL existante et
`PlayerCharacterAccess::lockForMutation` (créateur, session de jeu, état joueur).
Les supports concernés, anciens et nouveaux, sont verrouillés par ID croissant
et relus. Les droits, choix connus, prérequis, propriétés physiques et conflits
avec un autre créateur sont vérifiés avant toute écriture. Les supports déjà
imprégnés par le même créateur sont proposés pour permettre les remplacements.

Les retraits précèdent les créations pour permettre les permutations de supports
sans violer les index uniques B1. Historique : `preparation_removed`,
`formula_reapplied` ou `quota_replaced`. Les insertions et la fermeture de phase
sont validées ensemble ; toute erreur annule l'ensemble. Les objets possédés et
leurs définitions restent inchangés. Le calcul B2 lit le résultat au prochain profil.

La phase conserve l'empreinte canonique de la commande confirmée et le résultat
FIFO. Une répétition identique retourne la vue courante avec `replayed: true`,
sans mutation ; une commande différente après fermeture est refusée. Une requête
d'un ancien repos ne peut confirmer le nouveau. Les verrous sérialisent les
confirmations concurrentes ; les index B1 protègent également les supports.
Les phases restent propres à la session explicite, conformément à C1.

Les routes unitaires POST/DELETE B1 refusent désormais toute mutation, pendant
comme hors phase. Les primitives de service B1 sont conservées pour compatibilité
interne et tests historiques, sans route joueur les exposant. La consultation
reste disponible. Aucun accès administratif supplémentaire n'est créé.

### Validation et suite C3

Tests temporaires PostgreSQL avec rollback : conservation, ajout, réapplication,
remplacement, FIFO, quotas multiclassés, doublons, droits, rejeu et état obsolète,
conflit de support, fermeture, refus des routes unitaires, rollback après une
erreur SQL forcée, CA, repos et préparation des sorts. Les scénarios de requêtes
obsolètes sont séquentiels ; aucun test de charge multiprocessus n'est ajouté.

C3 raccordera le brouillon, la présentation du FIFO et la confirmation globale,
puis rafraîchira profil et imprégnations. L'interface B3 n'est pas modifiée ici :
ses anciennes actions reçoivent 409 en attendant C3. Aucun nouvel effet, objet,
catalogue, migration ou mécanisme de repos n'est introduit.
