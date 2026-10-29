# Réconciliation additive des sorts — 26 octobre 2026

## Diagnostic et comportement

Le premier parcours imposait un choix pour chaque niveau d’origine. Il était
adapté à un historique exact, mais ne permettait pas de retrouver une liste
actuelle après des remplacements légaux. Les quotas actuels seuls ne suffisent
pas non plus : douze sorts de niveau 5 ne sont pas accessibles à un occultiste
13 avec les seuls apprentissages et remplacements ordinaires.

Le parcours « Compléter les sorts manquants » propose désormais une sélection
multiple par classe et catégorie, au niveau actuel : sorts mineurs, sorts
connus, arcanums séparés et autres catégories déclarées par la politique.
Recherche, filtre par niveau, sélection récapitulative, quotas, acquisitions
conservées et nombre de choix manquants sont affichés. Les styles de la révision
globale sont réutilisés ; son opération destructive n’est jamais appelée.

Une confirmation explicite est requise pour chaque lot. Après succès, les
quotas et les sorts sont rechargés. Une sélection refusée reste modifiable.
Le portail conserve les choix de capacités, mais masque les anciens formulaires
de classe déjà couverts par la réconciliation. Les endpoints classiques et les
services de montée de niveau gardent leurs règles.

Lecture seule des données locales :

| Personnage / classe | Niveau | Mineurs manquants | Connus manquants | Arcanums |
| --- | ---: | ---: | ---: | --- |
| Jakharis / occultiste | 13 | 4 | 12 | Un niveau 6, un niveau 7 |
| Frank / ensorceleur | 11 | 6 | 11 | Aucun |
| Frank / occultiste | 2 | 2 | 2 | Aucun |

Frank a déjà un sort acquis dans chacune de ses classes. Les politiques
persistées restent les versions avancées 3 (occultiste) et 4 (ensorceleur).

## Validation et traçabilité

Les critères d’éligibilité par niveau et les règles de remplacement proviennent
de `CharacterSpellAcquisitions::options()` / `AdvancedSpellChoices`, via
l’inventaire existant. Le validateur cherche un témoin concret de faisabilité :

- opportunités d’apprentissage non consommées par les acquisitions préservées ;
- apprentissage direct d’un sort final, ou remplacement ultérieur d’un sort
  provisoire réellement éligible au niveau d’origine ;
- budgets de remplacement partagés et déjà consommés ;
- quotas à chaque niveau, incluant les octrois automatiques ;
- absence de doublon entre sorts provisoires, choix finaux et historique ;
- conservation des décisions existantes et des dates limites des réconciliations
  précédentes, ainsi que des listes / restrictions propres à chaque règle.

Ce témoin n’est pas enregistré comme un historique réel. Le moteur d’acquisition
existant ajoute uniquement les sorts choisis au niveau actuel, avec provenance
`manual-reconciliation`, `historical_validation=false`, règle explicite et note
`Réconciliation additive v1` contenant le témoin et l’empreinte du lot.
Aucun sort provisoire n’est persisté, aucun sort existant n’est retiré.

Le personnage et les références sont verrouillés ; le lot est atomique. La liste
des acquisitions attendues protège contre une vue périmée. Un rejeu exact renvoie
les mêmes acquisitions sans ajout. Le joueur conserve le contrôle de révision,
de token, de participation et de session ouverte existant. Aucun nouveau reçu,
UUID, mécanisme de ressource ou cycle de révision n’est créé.

Routes conservées : GET `/{policyId}/historical-preview`, POST
`/{policyId}/historical-completion` sous `/characters/{id}/spell-acquisitions` ;
GET `/historical`, POST `/historical-completion` sous
`/public/characters/{accessToken}/spells`.
Payload : `choices: [{spellId, ruleId}]`, `expectedAcquisitionIds`, `confirmed`.
Le joueur ajoute `policyId` et `revision`. Aucun niveau historique n’est demandé.

## Limites conservatrices

Le validateur démontre un apprentissage direct ou un remplacement par opportunité.
Il ne prétend pas reconstruire tous les historiques possibles : les cas exigeant
des chaînes intermédiaires complexes, une réutilisation de sorts provisoires,
des options facultatives non confirmées ou plus de 20 000 étapes de recherche
sont refusés avec demande d’intervention MJ. Une sélection refusée n’est donc
pas toujours illégale ; elle peut être indémontrable par ce parcours limité.

Les reprises manuelles antérieures non tracées, les révisions globales et les
historiques incohérents nécessitent également une intervention MJ. Un nouveau
rattrapage après remplacement d’une acquisition réconciliée est signalé comme
ambigu ; la montée de niveau et son remplacement restent opérationnels.
Les règles avancées exigeant une qualification de provenance la conservent.
La copie de grimoire reste indépendante et ne consomme pas d’opportunité.

## Validation exécutée

- `php -d memory_limit=1G tools/test-character-spell-acquisitions.php --historical-concurrency`
  : 317 assertions ; sélection sans sort de niveau 1 pour l’occultiste 13,
  refus de douze sorts de niveau 5, arcanums, multiclassage, lots partiels,
  quotas, traçabilité, conservation, rejeu et concurrence en schéma isolé.
- `php -d memory_limit=1G tools/test-player-spells.php --historical`
  : 94 assertions HTTP sur copies TEMP de Frank et Jakharis ; confirmation,
  autorisations, concurrence optimiste, rejeu sans nouvelle révision et absence
  de double comptabilisation par l’apprentissage classique. Empreintes des
  tables et séquences réelles inchangées.
- `php tools/test-level-up-spell-application.php` : 165 assertions réussies,
  uniquement en tables temporaires.
- Tests Angular ciblés : sélection globale, filtres, confirmations, refus,
  rafraîchissement, intégrations MJ/joueur et masquage des anciens choix.
- Build Angular production réussi. Les styles `spells-placeholder*` inutilisés
  ont été retirés : 11,09 kB contre 12,10 kB pour `player-portal.scss`.
  L’avertissement de 10 kB reste non bloquant ; limite de 12 kB respectée.
- `lint:container` réussi ; aucun changement de schéma ou de référence requis.

Diagnostic reproductible en lecture seule :
`php tools/preview-historical-spells.php 21 24`.
Aucun commit ni changement des données réelles.

## Fichiers concernés par cette évolution

Backend : `src/Service/CharacterSpellHistoricalChoices.php`,
`CharacterSpellAcquisitions.php`, `AdvancedSpellChoices.php`,
`PlayerSpellManagement.php` ; contrôleurs `CharacterSpellAcquisitionController.php`
et `PlayerSpellController.php` ; outils `preview-historical-spells.php`,
`test-character-spell-acquisitions.php`, `test-spell-historical-cases.php`,
`test-player-spell-historical-cases.php` ; ce rapport.

Frontend : `character-detail/components/character-spells/spell-historical.ts`,
`.html`, `.spec.ts` ; `player-portal/components/player-spells/player-spells.ts`,
`.html`, `.spec.ts` ; `player-portal/player-portal.scss`.

Les autres changements déjà présents dans le workspace ont été conservés.
