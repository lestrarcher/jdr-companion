# Faveur des Fées — correction ciblée d'Anatoly

## Diagnostic local du 26 octobre 2026

Anatoly : personnage 29, état de session 35 (session 5), don 4,
attribution du don 49, choix de caractéristique `wisdom`.

| Octroi | Acquisition conservée | Défaut |
| --- | --- | --- |
| 13 : Foulée brumeuse (sort 219) | 40 | `casting_rules`, ressource et caractéristique absentes |
| 14 : Soins (sort 430) | 39 | `casting_rules` et ressource absentes ; `CHOICE` déjà présent |

La provenance était correctement résolue : capacité 1546, règle 1607,
don Faveur des Fées. Le résolveur retournait `casting_rules_missing`, avec
`ability_not_configured` en plus pour Foulée brumeuse. L'éligibilité devenait
`grant_rules_pending`, et `castingAvailability.methods` était vide.
`availableSpellCastMethods()` ne trouvait donc aucun mode ; `SpellQuickList`
désactivait correctement les cartes, même avec des emplacements disponibles.
Il n'y avait pas de défaut Angular ni de raison d'assouplir la validation.

## Correctif

Commande explicite, aperçu par défaut :

```sh
php bin/console app:spells:repair-fey-touched 29 35
php bin/console app:spells:repair-fey-touched 29 35 --apply
```

Elle refuse les provenances ambiguës, les configurations divergentes et les
octrois/dons partagés avec d'autres personnages. L'opération est transactionnelle
et verrouillée. Les identifiants personnage/état sont obligatoires ; aucune
session implicite ni attribution par nom de personnage.

Chaque octroi reçoit `CHOICE`, un mode `resource` coût 1 à son niveau de base et
un mode `standard-slot`. Deux ressources distinctes de maximum fixe 1 et de
récupération au repos long sont attribuées par le don :

- `fey-touched-grant-13` : Foulée brumeuse, niveau gratuit 2.
- `fey-touched-grant-14` : Soins, niveau gratuit 1.

L'initialisation réutilise le synchroniseur existant et copie uniquement les
deux compteurs manquants dans l'état explicite. Les compteurs présents ne sont
jamais remplis à nouveau. Aucun changement des acquisitions, des filtres de
choix de Soins, des règles du moteur ou du design.

Application locale réalisée après aperçu et test synthétique : état 35,
révision 12 → 13. Ressources créées 62 et 63. Les deux modes sont disponibles
pour les deux sorts ; les emplacements restent 3 au niveau 1 et 2 au niveau 2.
Les empreintes de toutes les acquisitions de classe, de toutes les acquisitions
de capacité et de tous les états des autres personnages sont identiques avant
et après. Aucun lancement réel ni repos réel n'a été exécuté.

## Tests

- `php tools/test-player-spell-directory-eligibility.php --fey-touched-repair` :
  579 assertions réussies sur tables temporaires (socle répertoire compris).
  Deux sorts du même don, Soins volontairement en évocation, caractéristique,
  aperçu sans écriture, idempotence, conservation des acquisitions/compteurs,
  usages gratuits indépendants, emplacements indépendants des usages gratuits,
  épuisement, repos court/long, refus en présence d'un second détenteur du don.
- Angular : `spell-cast-dialog`, `spell-quick-list`, `player-spell-casting` :
  58 tests réussis, dont les deux modes de chacun des sorts de Faveur des Fées.
- `lint:container` et `git diff --check` réussis.
- Build Angular production réussi ; avertissement préexistant de budget sur
  `player-portal.scss` (11,09 kB pour 10 kB).

Recharger le portail pour récupérer la nouvelle disponibilité et les compteurs.
Cette commande répare une configuration existante isolée ; elle n'est pas un
import global du catalogue des dons.
