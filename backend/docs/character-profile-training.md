# Formation résolue dans le profil joueur

`CharacterProfileSerializer` expose désormais `character.training`, en réutilisant
`CharacterTrainingPresenter::resolved()` et les résolveurs existants. Aucun choix
de formulaire n'est interprété une deuxième fois. Aucune migration ni import
supplémentaire n'est nécessaire pour ce changement de présentation.

## Contrat

Les trois propriétés sont toujours des listes JSON, éventuellement vides :

- `proficiencies` : une entrée par maîtrise effective, avec `id`, `slug`, `name`,
  `type`, `grants`, `manualGrantIds` et `substitutions`.
  `type` vaut `SKILL`, `SAVING_THROW`, `WEAPON`, `WEAPON_GROUP`, `ARMOR` ou `TOOL`.
- `languages` : `id`, `slug`, `name`, `sources`.
- `expertises` : `id`, `slug`, `name`, `type`, `proficiencyId`, `sources`.
  **`id` et `proficiencyId` désignent tous deux la définition de maîtrise**, pas
  la ligne de choix d'Expertise. Faire la jointure avec `proficiencies[].id`.

Le format de `grants` reste celui du builder : `ruleId`, `key`, `label`,
`sourceType`, `sourceId`, `unlockLevel`, `choiceCount`, `acquisitionMode`,
`optionIds`. Les `optionIds` décrivent la règle d'origine : ils ne sont pas des
maîtrises supplémentaires acquises. Seules les entrées de `proficiencies` sont
effectivement accordées. Plusieurs règles peuvent figurer dans la même entrée.

Les `sources` des langues et Expertises conservent les clés du contrat existant :
clé d'import de règle, `race:<slug>` ou `manual:<id>`. Les substitutions conservent
`ruleId` et `originalProficiencyId`. Les attributions manuelles restent visibles
dans les résultats effectifs, avec leur identifiant de provenance.

Le profil public omet la liste d'audit `manualGrants` (motif libre du MJ, identité
de l'auteur, date). Le contrat MJ du builder la conserve : le nouvel argument
`includeManualGrantDetails` du présentateur vaut `true` par défaut. `type` et
`proficiencyId` sont des ajouts compatibles au contrat résolu déjà existant.

Les bonus numériques de compétence et de sauvegarde ne sont pas calculés ici.
Angular dispose toujours de `character.abilities` et `character.proficiencyBonus`.

## Réponses concernées

Chemins côté Angular, avec le préfixe proxy `/api` :

- `GET /api/public/characters/{accessToken}` : chargement initial.
- `PATCH /api/public/characters/{accessToken}` : réponse après mise à jour.
- `GET /api/campaigns/{campaignId}/characters/{characterId}` : profil authentifié.
- `POST /api/campaigns/{campaignId}/characters/{characterId}/level-up`.
- `PUT /api/campaigns/{campaignId}/characters/{characterId}/hit-points/history`.
- Les réponses d'états de session (`/api/sessions/{sessionId}/characters...`) et
  les réponses d'actions qui passent par `CharacterSessionStateSerializer`.

Toutes partagent le même sérialiseur de profil. Les contrats de requête, les
contrôles d'accès et la gestion de révision ne changent pas. Aucun endpoint de
lecture ne crée ni ne modifie de choix de formation.

## Exemple réel : Riven Bis

Le fichier [riven-bis-training.json](riven-bis-training.json) est le contenu
**complet** de `character.training` obtenu le 12 octobre 2026 via le Kernel et
la route publique GET, réponse **200**, pour le personnage **30**, campagne **2**.
La connexion PostgreSQL était explicitement en lecture seule. Aucun jeton
d'accès n'est inclus dans cet exemple.

Résultat : 16 maîtrises, 3 langues (Commun, Halfelin, Argot des voleurs),
2 Expertises (Escamotage `proficiencyId: 16`, Discrétion `proficiencyId: 17`).
Les outils de voleur (`id: 104`) n'apparaissent qu'une fois, avec les attributions
de classe et d'historique.

## Validation

- `php tools/test-character-profile-training.php` : 20 assertions, dont le
  contrat commun, les choix persistés, les types, les doublons, les liens
  d'Expertise compétence/outil, le niveau, les origines manuelles, la non-exposition
  des notes MJ, les états initiaux/mis à jour et les listes vides.
- `php tools/test-character-training.php` : 57 assertions.
- `php tools/test-player-security.php` : 269 assertions, dont GET/PATCH publics
  avec `character.training`.
- `php tools/test-reference-runtime-visibility.php` : 278 assertions.
- `php tools/test-spell-slot-synchronization.php` : 1760 assertions.
- `php tools/test-player-concurrency.php` : 46 assertions.
- `php tools/test-character-builder-options.php` : 5 assertions.
- `php bin/console lint:container` (dev et test) et `git diff --check` : succès.

Les tests SQL utilisent des tables temporaires annulées ou un schéma de test
isolé supprimé. Les données des personnages existants n'ont pas été modifiées.

## Limites conservées

Le calcul dépend du référentiel installé et des règles applicables au niveau
actuel. Les lots de choix incomplets ou devenus invalides n'accordent rien ;
le profil ne complète pas les anciens personnages automatiquement. Les groupes
d'armes restent des groupes, sans expansion en maîtrises individuelles.

Les substitutions restent explicites et limitées aux historiques. La langue
d'Ennemi juré du rôdeur reste une attribution manuelle du MJ tant que ce choix
n'est pas structuré. Les autres limites du moteur existant sont inchangées :
ce changement expose son résultat, il n'ajoute aucune nouvelle règle D&D.
