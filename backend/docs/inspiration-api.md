# Inspiration D&D 5e 2014

> **État de livraison — 14 octobre 2026.** Voir l'[état consolidé](artificer-proficiencies-2014.md#état-de-livraison--14-octobre-2026).
> Migration Bricolages et import artificier réalisés localement ; tests manuels
> LIVE/CLOSED/LIVE satisfaisants, selon confirmation du MJ. Résolution du 503
> médias non confirmée en production. Aucun déploiement effectué.
>
> **Historique :** le contenu ci-dessous conserve les constats, tests et consignes
> du lot à sa date de rédaction. Les mentions d'opérations encore à autoriser,
> de validation manuelle restante ou de l'ancien écran fermé sont historiques.
> Aucun import ni migration supplémentaire n'a été exécuté pour les commits.


## Persistance et initialisation

L'inspiration est un booléen unique par personnage. Sa valeur canonique est
`character.inspiration` en base ; elle est projetée dans **`state.inspiration`**
de chaque réponse de session. Les PV, ressources et progressions restent propres
à leur session. Aucun état d'une session arbitraire n'est utilisé comme référence.

La migration `Version20261012150000` ajoute :

```sql
ALTER TABLE "character" ADD inspiration BOOLEAN DEFAULT false NOT NULL;
```

Les personnages existants et futurs commencent ainsi sans inspiration. La
migration existe déjà. Son application sur la base de jeu n'a pas été vérifiée
pendant ce lot ; elle doit être présente avant utilisation. Aucune nouvelle
migration ni aucun import ne sont nécessaires pour les changements Angular.

Le JSON stocké par session ne contient pas de deuxième valeur canonique.
`CharacterSessionState::getState()` expose toujours celle du personnage ;
`setState()` ignore ce champ. Les changements passent exclusivement par l'opération
contrôlée. Une nouvelle session reprend la valeur du personnage ; les repos,
le retrait d'une session et les anciens instantanés ne la réinitialisent pas.

## API MJ : accorder ou retirer

```http
PATCH /api/sessions/{sessionId}/characters/{characterId}/inspiration
Content-Type: application/json
```

```json
{"inspiration": true, "revision": 4}
```

Utiliser `false` pour retirer l'inspiration. Le MJ doit être autorisé par
`CampaignVoter::MANAGE`, et le personnage doit participer à la session indiquée.
Le MJ peut également agir sur une session non ouverte, comme pour les autres
opérations de préparation MJ. Le corps accepte uniquement les deux champs montrés.

Accorder à nouveau une inspiration déjà possédée est sans effet : aucun cumul,
et aucune incrémentation de révision pour cette affectation identique.

## API joueur : dépenser via le PATCH existant

```http
PATCH /api/public/characters/{accessToken}
Content-Type: application/json
```

```json
{"state": {"inspiration": false}, "revision": 5}
```

La session doit être ouverte et le lien joueur actif. `true → false` dépense
l'inspiration ; le joueur ne peut jamais effectuer `false → true`.

Un instantané peut répéter la valeur actuelle (`true → true` ou `false → false`)
sans accorder ni consommer quoi que ce soit. L'absence du champ conserve sa valeur.
Les autres champs du PATCH gardent leurs validations existantes. Une erreur
sur un champ rejette la requête complète.

## Réponses et concurrence

Les deux opérations renvoient **200** avec la réponse habituelle du sérialiseur
de session : `id`, `revision`, `character`, `state`, etc. La réponse MJ garde
l'exposition du jeton autorisée pour le MJ ; la réponse joueur ne l'ajoute pas.

`state.inspiration` est également présent dans le GET public initial, les listes
MJ et toutes les réponses utilisant `CharacterSessionStateSerializer`.
Les chemins ci-dessus utilisent le préfixe Angular `/api` ; les routes Symfony
directes n'ont pas ce préfixe.

| Statut | Cas |
| --- | --- |
| 400 | JSON invalide ; enveloppe `state` invalide pour le PATCH public |
| 403 | MJ non autorisé ; joueur tentant de s'accorder l'inspiration |
| 404 | Lien joueur invalide/inactif ; personnage absent de la session |
| 409 | Révision périmée ; session non ouverte pour le joueur |
| 422 | Inspiration non booléenne ; révision absente ou non entière positive |

L'authentification MJ reste soumise au firewall existant. Aucun token, rôle ou
mécanisme d'autorisation supplémentaire n'est créé.

Les opérations suivent l'ordre de verrouillage existant : personnage, session,
état. Une modification effective actualise **toutes les révisions des états de
ce personnage**, y compris les sessions inactives, dans la même transaction.
Chaque session conserve ses autres données. Deux dépenses concurrentes provenant
de sessions différentes ne peuvent donc pas réussir avec leurs anciennes révisions.
Sur 409, recharger l'état avant de réessayer. Un rollback restaure la valeur et
toutes les révisions. Le rafraîchissement des interfaces utilise leurs mécanismes
existants ; aucun canal temps réel supplémentaire n'est ajouté.

## Exemple réellement produit par les tests

Extrait de réponse sérialisée dans le scénario d'intégration PostgreSQL isolé :

```json
{
  "id": 12,
  "revision": 8,
  "character": {"id": 7, "name": "Test"},
  "state": {"inspiration": true}
}
```

Il s'agit de données temporaires de test, pas d'un personnage de la base de jeu.
Après dépense réussie, la même propriété vaut `false` et la réponse fournit la
nouvelle révision. Aucun exemple provenant d'un personnage réel n'est revendiqué.

## Vérifications du lot initial backend (historique)

- `tools/test-player-security.php` : scénarios de sécurité existants plus
  `test-inspiration-scenarios.php` : 315 assertions au total, dont 46 ciblées
  (valeurs, droits,
  révisions, persistance, sessions, repos et rollback).
- `tools/test-player-concurrency.php` : 50 assertions, dont la dépense simultanée depuis deux sessions,
  une réponse 200 et une réponse 409 ; les deux révisions changent une seule fois.
- Régressions de profil (20 assertions), visibilité (278) et emplacements de
  sorts/repos (1760) : succès.
- Mapping Doctrine validé avec `--skip-sync` ; comparaison du SQL attendu en
  lecture seule, sans exécution ; conteneur validé en dev/test et route inspectée.

Les tests utilisent des tables temporaires annulées ou un schéma isolé supprimé.
Aucun personnage existant, import ou frontend Angular n'a été modifié.

## Livraison Angular — 13 octobre 2026

Le panneau MJ de gestion des personnages de session affiche une commande
« Accorder l’inspiration » ou « Inspiration disponible — retirer ». La commande
utilise la révision affichée, bloque les doubles clics et adopte la réponse du
serveur. Elle fonctionne en DRAFT, LIVE et CLOSED. Un conflit 409 déclenche une
actualisation, sans réessayer automatiquement une intention devenue obsolète.

Le joueur conserve son bouton Dépenser en LIVE. Hors LIVE, l’écran de session
fermée indique également si l’inspiration est disponible, sans proposer de
dépense. La fiche permanente ne fait pas partie de ce lot.

Les deux interfaces interrogent leurs endpoints existants toutes les cinq
secondes. Le statut de session est adopté même à révision d’état identique.
Les lectures joueur dépassées par une sauvegarde, une modification locale ou
un rechargement sont ignorées ; un seul rafraîchissement joueur est en vol.
Le panneau MJ conserve, pour chaque état, la réponse de révision la plus élevée.
Le résultat de sa propre commande est immédiat ; la propagation à l’autre
interface intervient au prochain polling, selon réseau et activité de sauvegarde.
Les abonnements périodiques sont arrêtés à la destruction des composants.

### Fichiers de ce lot

- `backend/tools/test-inspiration-scenarios.php` : cas supplémentaires CLOSED et DRAFT.
- `backend/docs/inspiration-api.md` : contrat, livraison et procédure de validation.
- `frontend/src/app/core/services/character-session-state-api.service.ts` : commande MJ typée.
- `frontend/src/app/features/control-dashboard/components/session-characters/session-characters.ts`
  et `.html` : commande, feedback et actualisation périodique.
- `frontend/src/app/features/control-dashboard/components/session-characters/active-character-card/active-character-card.ts`
  et `.html` : affichage et bouton, styles existants réutilisés.
- `frontend/src/app/features/player-portal/player-portal.ts` et `.html` : dépense,
  protection des réponses et suivi du statut hors LIVE.
- Nouveau `frontend/src/app/features/control-dashboard/components/session-characters/session-characters-inspiration.spec.ts`.
- Nouveau `frontend/src/app/features/player-portal/player-portal-inspiration.spec.ts`.

Le backend applicatif existant satisfait les règles et a été réutilisé sans
changement de stockage ni de contrôleur. Les modifications préexistantes des
historiques sont conservées. Aucun commit, push, import ou migration exécuté.

### Résultats effectivement obtenus

- `docker compose exec -T backend php tools/test-player-security.php` :
  **319 assertions**, dont **50 inspiration**, succès ; tables temporaires annulées.
- `docker compose exec -T backend php tools/test-player-concurrency.php` :
  **50 assertions**, succès ; connexions indépendantes et schéma jetable.
- `docker compose exec -T frontend npm test -- --watch=false --include='**/*inspiration.spec.ts'` :
  **12 tests**, succès (8 joueur, 4 MJ).
- `docker compose exec -T frontend npm run build` : succès ; avertissement
  `player-portal.scss` à 11,47 kB pour un seuil d’avertissement de 10 kB.
- `git diff --check` : succès.
- Suite Angular générale : 51 succès et 2 échecs lors de son exécution avant
  l’ajout des nouveaux tests. `app.spec.ts` attend encore un titre « Hello » ;
  le scénario de succès de `flexible-casting-action-modal.spec.ts` utilise un
  objet de test sans signal `inspiration`. Les chemins correspondants ne sont
  pas modifiés par ce lot ; ces échecs restent à traiter séparément.
- Dernière tentative de `lint:container` : non exécutée par Symfony, Docker
  répond « Docker Desktop is unable to start ». À relancer après rétablissement.

### Limites conservées

L’API MJ exige une participation existante, même si la session n’est pas LIVE.
Pour un personnage jamais inscrit, l’ajouter à une session DRAFT permet d’utiliser
le parcours actuel. Aucun endpoint indépendant des participations n’a été ajouté.
Pas de transfert ni de journal. Le polling n’est pas un mécanisme temps réel et
peut être ralenti par un onglet en arrière-plan. Les tests automatisés ne
remplacent pas une validation visuelle dans deux navigateurs.

### Validation manuelle avant commit

1. Ouvrir la gestion MJ d’une session DRAFT contenant un personnage et son lien
   joueur dans un autre navigateur. Accorder puis retirer l’inspiration : vérifier
   la réponse immédiate MJ et l’affichage joueur après environ cinq secondes.
2. Accorder de nouveau et ouvrir la session : le portail doit passer en LIVE
   sans rechargement, même si la révision du personnage n’a pas changé.
3. Dépenser côté joueur : disponibilité retirée immédiatement après succès,
   puis mise à jour MJ au polling. Double-cliquer ne doit envoyer qu’une commande.
4. Fermer la session : le portail doit revenir à l’écran fermé sans rechargement.
   Le MJ peut toujours accorder/retirer ; le joueur ne peut plus dépenser.
5. En LIVE, effectuer un repos puis consulter le même personnage dans une autre
   participation : l’inspiration doit être conservée. Sur deux vues obsolètes,
   vérifier qu’un conflit affiche un message et recharge sans dépense automatique.

La procédure manuelle modifie volontairement l’état du personnage choisi :
utiliser un personnage de test. Elle n’a pas été exécutée sur les campagnes réelles.
