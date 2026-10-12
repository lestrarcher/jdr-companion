# Inspiration D&D 5e 2014

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
migration est fournie **mais n'a pas été appliquée** : elle doit être exécutée
avant d'utiliser ce backend contre la base existante. Aucun import n'est requis.

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
  "revision": 6,
  "character": {"id": 7, "name": "Test"},
  "state": {"inspiration": true}
}
```

Il s'agit de données temporaires de test, pas d'un personnage de la base de jeu.
Après dépense réussie, la même propriété vaut `false` et la réponse fournit la
nouvelle révision. Aucun exemple de la base réelle après migration n'est revendiqué,
puisque la migration n'a pas été appliquée.

## Vérification

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
