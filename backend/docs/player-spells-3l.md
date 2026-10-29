# Lot 3L — autonomie magique du joueur

## Audit et réutilisation

- `CharacterSpellAcquisitions` : politique versionnée, acquisitions append-only, quotas, initialisation, grimoire et copie MJ.
- `AdvancedSpellChoices` : écoles, listes, opportunités par niveau et remplacements conditionnels.
- `CharacterLevelUpSpells` : choix de sorts dans la transaction de montée de niveau, déjà accessible par le parcours joueur autorisé.
- `CharacterFeatureSpellService` : choix indépendants par `grantId`, restrictions de niveau/liste/école, refus des choix déjà actifs. Les octrois ne dépensent pas les quotas de classe.
- `CharacterSpellAccessResolver` : listes et octrois de sous-classe.
- `PlayerCharacterAccess` : token, participation, session LIVE, ordre de verrouillage personnage/session/state.
- `CharacterSpellSlotCalculator` et les ressources existantes restent responsables des emplacements. Aucune consommation ajoutée.

La préparation quotidienne n'était pas implémentée (`dailyChoicesManaged: false` dans les projections d'acquisition MJ). Les projections MJ restent inchangées ; la nouvelle projection joueur possède son contrat de préparation dédié.

## 3L-A

Routes distinctes des routes MJ, sous `/public/characters/{accessToken}/spells` :

| Méthode | Suffixe | Corps |
| --- | --- | --- |
| GET | aucun | aucun |
| POST | `/choose` | `revision`, `grantId`, `spellId` |
| POST | `/learn` | `revision`, `policyId`, `ruleId` nullable, `spellId` |
| POST | `/prepare` | `revision`, `policyId`, `spellIds` |

Les écritures verrouillent le personnage, la session et son state, puis revérifient la révision. Le serveur renvoie immédiatement le répertoire, les choix restants et la préparation. Les champs inconnus sont refusés ; aucun override, niveau historique, acteur ou remplacement ne vient du client.

`learn` délègue au moteur d'acquisition existant, avec le niveau actuel de la classe imposé par le serveur. Une reprise manuelle au niveau courant reste du ressort du MJ. Les remplacements continuent de passer par le parcours de level-up existant. Le propriétaire est enregistré comme acteur technique avec une note identifiant l'accès joueur, comme pour le level-up public existant.

La préparation est stockée dans `CharacterSessionState.state.preparedSpells`, indexée par identifiant de politique, avec une liste d'identifiants de sorts. Elle ne crée aucune acquisition et reste propre à la session. Les repos et la synchronisation préservent cette clé ; le PATCH global joueur ne l'autorise pas.

Le quota utilise la base de préparation de la politique plus le modificateur effectif, minimum 1, moins les octrois explicitement comptés. Les sorts toujours préparés ne sont pas modifiables par ce contrôle. Un magicien sélectionne dans les acquisitions actives de son grimoire ; une classe à liste préparée sélectionne dans sa liste autorisée. Une caractéristique absente ou un quota ambigu bloque la préparation.

Les codes d'incantation stockés par les octrois (`INT`, `CHA`, etc.) sont traduits vers l'enum métier pour les statistiques, sans modification du référentiel. `CHOICE` n'est pas résolu arbitrairement : une caractéristique non définie reste sans statistiques.

Fichiers : `PlayerSpellController.php`, `PlayerSpellManagement.php`, `CharacterSpellPreparation.php`, `PlayerSpellDirectory.php`, `CharacterFeatureSpellService.php`, `CharacterSessionStateSerializer.php` ; côté Angular, `player-spell-api.service.ts`, `character-session-state-api.service.ts`, `player-portal.ts/html`, `player-spells.ts/html`, et les tests dédiés.

## 3L-B

`player-spells.ts/html/scss` fournit une barre horizontale défilante, des symboles par école, une fiche détaillée sélectionnable et les sections connus/préparés/grimoire. Une même entrée peut apparaître dans deux sections sans duplication persistée. Les descriptions sont interpolées en texte, sans HTML injecté.

La projection `[spell-modifiers]` réserve l'emplacement de futures commandes de métamagie ; vide, elle est masquée. La sélection d'un sort est locale et distincte des commandes d'apprentissage/préparation. Aucun bouton de lancement ni de consommation n'est affiché.

## Validation et limites

`php tools/test-player-spells.php` copie les tables locales dans des tables TEMP avec leurs propres séquences. Il vérifie les parcours publics et compare les empreintes de toutes les tables d'origine ainsi que leurs séquences. Toutes les copies sont annulées en fin de test.

Cas locaux : Test initié (40), Sylveon (36), Nikita (28). Le Sylveon 34 est un autre personnage et n'a pas été modifié. Sylveon 36 possède 3 tours de magie de classe + 1 octroyé et 6 sorts de niveau 1 au grimoire. Le test vérifie les deux choix restants d'Initié à la magie, les limites de liste/niveau, l'indépendance des quotas, la préparation et les refus de token/révision/session/participation.

Commandes complémentaires :

```text
docker compose exec backend php tools/test-level-up-spell-application.php
docker compose exec backend php bin/console lint:container
docker compose exec backend php bin/console doctrine:schema:validate
docker compose exec frontend npx ng test --watch=false --include=src/app/features/player-portal/components/player-spells/player-spells.spec.ts --include=src/app/features/player-portal/player-portal-readonly.spec.ts
docker compose exec frontend npx ng build
git diff --check
```

Limites : la copie payante de parchemins reste dans le parcours MJ existant ; les choix historiques non reconstruits ne sont pas inventés. Le contrôle de schéma signale les tables préexistantes `feature_spell_grant` et `character_feature_spell`, gérées en DBAL hors mappings ORM ; aucune suppression n'est appliquée. Le budget indicatif de `player-portal.scss` reste dépassé de 1,51 kB.

Résultats finaux : 195 assertions pour les parcours publics, 165 assertions pour le level-up existant, 15 tests Angular, lint du conteneur et build production réussis. Le rendu n'a pas été inspecté visuellement dans un navigateur mobile ; les tests frontend utilisent le DOM de test Angular.

## Liste des fichiers du lot

3L-A :

- `backend/src/Controller/PlayerSpellController.php` (nouveau)
- `backend/src/Service/PlayerSpellManagement.php` (nouveau)
- `backend/src/Service/CharacterSpellPreparation.php` (nouveau)
- `backend/src/Service/PlayerSpellDirectory.php`
- `backend/src/Service/CharacterFeatureSpellService.php`
- `backend/src/Service/CharacterSessionStateSerializer.php`
- `backend/tools/test-player-spells.php` (nouveau)
- `frontend/src/app/core/services/player-spell-api.service.ts` (nouveau)
- `frontend/src/app/core/services/character-session-state-api.service.ts`
- `frontend/src/app/features/player-portal/player-portal.ts`
- `frontend/src/app/features/player-portal/player-portal.html`
- `frontend/src/app/features/player-portal/components/player-spells/player-spells.ts`
- `frontend/src/app/features/player-portal/components/player-spells/player-spells.html`
- `frontend/src/app/features/player-portal/components/player-spells/player-spells.spec.ts` (nouveau)

3L-B : finalisation de `player-spells.ts/html/scss/spec.ts` et branchement de la révision dans `player-portal.html`. Documentation : le présent fichier `backend/docs/player-spells-3l.md`.
