# Fiabilité des formations — 13 octobre 2026

> **État de livraison — 14 octobre 2026.** Voir l'[état consolidé](artificer-proficiencies-2014.md#état-de-livraison--14-octobre-2026).
> Migration Bricolages et import artificier réalisés localement ; tests manuels
> LIVE/CLOSED/LIVE satisfaisants, selon confirmation du MJ. Résolution du 503
> médias non confirmée en production. Aucun déploiement effectué.
>
> **Historique :** le contenu ci-dessous conserve les constats, tests et consignes
> du lot à sa date de rédaction. Les mentions d'opérations encore à autoriser,
> de validation manuelle restante ou de l'ancien écran fermé sont historiques.
> Aucun import ni migration supplémentaire n'a été exécuté pour les commits.


## Correctifs et contrats

- Une attribution manuelle identique est une répétition du même type, de la
  même cible, du même auteur et du même motif exact. Elle conserve l’ID et la
  date de la première attribution. Un motif différent reste une provenance
  distincte. Aucun nettoyage des doublons historiques n’est effectué.
- Le POST `/api/characters/{id}/manual-grants` conserve son contrat et son
  statut 201, y compris pour une répétition sans effet. Sous le verrou exclusif
  personnage, le contrôleur recharge l’agrégat avant validation. Les appels à
  cet endpoint sont ainsi sérialisés ; une nouvelle provenance invalide toutes
  les révisions de session sans changer leurs valeurs. Une répétition ne les
  invalide pas. Aucun index ni migration supplémentaire.
- Les expertises communes à plusieurs règles conservent leurs origines, sans
  supprimer les autres expertises d’un lot. La maîtrise préalable est vérifiée
  individuellement lors de la résolution. Les choix historiques recouverts par
  une nouvelle attribution peuvent être renvoyés tels quels ; choisir une
  nouvelle expertise déjà obtenue ailleurs reste interdit.
- Une modification de choix qui supprimerait la dernière maîtrise nécessaire
  à une expertise effective est rejetée : « Conflit de formation : la maîtrise
  … est nécessaire à une expertise acquise. » Le statut reste 422, comme les
  autres validations de formation. Retirer une provenance reste possible si
  une autre accorde encore la maîtrise. Les transactions existantes annulent
  les mutations refusées ; le service ne doit pas être utilisé hors transaction
  sur un personnage persistant. Les previews restent des objets éphémères.

## Fichiers de ce lot

- `src/Controller/CharacterTrainingController.php`
- `src/Service/CharacterTrainingService.php`
- `src/Service/CharacterTrainingResolver.php`
- `tools/test-training-reliability.php` (nouveau)
- `tools/test-feature-training.php`
- `../frontend/src/app/app.spec.ts`
- `../frontend/src/app/features/player-portal/components/flexible-casting-action-modal/flexible-casting-action-modal.spec.ts`
- Ce document.

Les changements préexistants d’inspiration et d’historiques sont préservés.
Pas d’import réel, migration, commit, push ou modification des données de jeu.

## Vérifications exécutées

| Commande dans Docker | Résultat |
|---|---|
| `php tools/test-training-reliability.php` | 13 assertions réussies, en mémoire |
| `php tools/test-character-training.php` | 57 assertions réussies |
| `php tools/test-feature-training.php` | 45 assertions réussies, tables temporaires annulées |
| `php bin/console lint:container` | Réussi |
| `npm test -- --watch=false` | 65 tests réussis, 7 fichiers |
| `git diff --check` (hôte) | Réussi |

Le test Angular du titre attendait le texte de démonstration alors que le
template ne contient plus que le routeur. La fixture de Conversion flexible
omettait les signaux inspiration, training et proficiencyBonus utilisés par le
composant. Seuls ces tests ont changé, aucun comportement métier de conversion.

## Limites et validation manuelle

La concurrence du POST manuel repose sur le verrou personnage existant ; ce lot
teste le rejeu après persistance et l’invalidation, mais n’ajoute pas de scénario
à deux connexions simultanées pour ce POST. Un accès direct SQL/ORM contournant
le service n’est pas protégé par une nouvelle contrainte d’unicité. Les setters
d’entité ne deviennent pas un moteur de validation de build : les futurs chemins
de retrait de sources devront réutiliser les contrôles de prérequis.

Les lots dont les options ou cardinalités sont invalides restent inactifs.
Une expertise historique sans maîtrise ne devient jamais effective. Aucun
remplacement automatique ni retrait manuel de provenance n’est ajouté.

Avant commit, sur un personnage de test :

1. Envoyer deux fois le même POST manuel : une seule nouvelle provenance,
   aucune seconde incrémentation de révision ; contrôler le portail au polling.
2. Répéter avec un motif distinct : provenance distincte, maîtrise effective unique.
3. Recouvrir une expertise choisie par une règle automatique : conserver les
   autres choix et vérifier la sauvegarde/relecture du lot.
4. Tenter de retirer la dernière maîtrise nécessaire à une expertise : 422
   explicite, choix persistés inchangés ; vérifier aussi le cas d’une autre
   provenance conservant cette maîtrise.
5. Tester deux POST identiques simultanés et un rafraîchissement MJ/joueur.
