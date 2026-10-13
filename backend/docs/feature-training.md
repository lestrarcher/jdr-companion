# Formation acquise par montée de niveau — premier lot

## Périmètre

Backend D&D 2014. Aucun changement du calcul des niveaux par classe.

Le catalogue `data/reference/dnd-2014-feature-proficiencies.json` contient trois règles `ANY` :

| Source | Niveau dans la classe | Attribution |
| --- | --- | --- |
| Chantelame **Tasha** | Magicien 2 | Armures légères et Représentation automatiques |
| Chantelame **Tasha** | Magicien 2 | Une arme de corps à corps à une main, au choix |
| Assassin PHB 2014 | Roublard 3 | Accessoires de déguisement et matériel d'empoisonneur automatiques |

La liste d'armes inclut les armes polyvalentes utilisables à une main. Elle contient 20 options ; la lance d'arçon est exclue de ce premier lot par décision du MJ. Ce choix accorde une maîtrise, pas une autorisation d'utiliser Chant des lames avec n'importe quelle arme. Aucun changement des capacités SCAG/Tasha, de leurs ressources ou des restrictions de combat.

Sources : Tasha, *Training in War and Song*, résumé [D&D Beyond](https://www.dndbeyond.com/posts/1048-wizard-101-bladesinging-from-tashas-cauldron-of) ; PHB 2014, Assassin, *Bonus Proficiencies*, p. 97. La source éditoriale de chaque règle est renseignée par `sourceBook` dans le JSON.

## Installation après revue

Aucune migration nécessaire. Les entités existantes représentent déjà ces trois règles.

```sh
docker compose exec -T backend php bin/console app:dnd:import-feature-proficiencies --dry-run
```

L'import réel reste une opération séparée à autoriser : même commande sans `--dry-run`. `--update-existing` doit être explicite pour modifier une règle officielle différente. Une modification mécanique est refusée si des choix ou substitutions persistés utilisent la règle. Les références personnalisées sont protégées ; une correspondance ambiguë avec une autre clé est refusée. Toutes les références sont validées avant écriture, puis le plan est appliqué dans une transaction. Les importeurs existants restent inchangés.

## Contrats Angular

Les routes ci-dessous emploient le préfixe `/api` du proxy Angular. Les routes Symfony sont déclarées sans ce préfixe. Les identifiants des exemples sont **illustratifs** : toujours utiliser ceux de l'API.

### Options et rattrapage MJ

`GET /api/characters/{id}/training/options` retourne les règles applicables au build courant, leurs options, les sélections persistées et les bénéfices effectifs.

Pour prévisualiser un niveau supplémentaire :

`GET /api/characters/{id}/training/options?classId=7&subclassId=42`

Le niveau n'est ni enregistré ni laissé sur le personnage. Les prérequis de multiclassage et le niveau de sélection de sous-classe existants sont réutilisés.

Le contrat du presenter existant est enrichi de `choices` et `definitions` :

```json
{
  "proficiencyRules": [{
    "ruleId": 126,
    "key": "tce-bladesinging-training-weapon",
    "label": "Entraînement à la guerre et au chant : arme",
    "sourceType": "subclass",
    "sourceId": 42,
    "unlockLevel": 2,
    "choiceCount": 1,
    "acquisitionMode": "ANY",
    "optionIds": [31, 32]
  }],
  "choices": [{
    "key": "proficiency:126",
    "kind": "proficiency",
    "ruleId": 126,
    "choiceCount": 1,
    "selected": [],
    "status": "missing"
  }],
  "definitions": {
    "proficiencies": [
      {"id": 31, "slug": "rapier", "name": "Rapière", "type": "WEAPON"},
      {"id": 32, "slug": "longsword", "name": "Épée longue", "type": "WEAPON"}
    ],
    "languages": []
  },
  "languageRules": [],
  "expertiseRules": [],
  "substitutions": [],
  "resolved": {"proficiencies": [], "languages": [], "expertises": []},
  "notImplemented": []
}
```

Extrait simplifié : les options réelles incluent les 20 armes et les autres règles du personnage ; `resolved` contient les bénéfices automatiques dès qu'ils sont applicables.

- `choices` contient uniquement les règles **actives nécessitant un choix**.
- `status` vaut `complete`, `missing` (aucune sélection), ou `invalid` (sélection présente mais non effective).
- `selected` contient des identifiants de maîtrise pour `proficiency`/`expertise`, des slugs pour `language`.
- Une entrée de langue utilise `sourceKey` au lieu de `ruleId` et une clé `language:<sourceKey>`.
- Les règles automatiques restent dans les listes de règles, avec `choiceCount: 0` ; aucune sélection ne doit être envoyée pour elles.
- Les choix historiques inactifs restent en base mais n'apparaissent pas dans les exigences actives et ne donnent aucun bénéfice.
- `definitions` fournit les libellés des options exposées, sans publier tout le catalogue privé du MJ.
- Les aperçus de montée de niveau n'exposent pas le journal des attributions manuelles du MJ.

`PATCH /api/characters/{id}/training/choices` permet au **MJ de la campagne** de compléter ou remplacer une sélection :

```json
{
  "proficiencyChoices": [{"ruleId": 126, "proficiencyIds": [31]}],
  "languageChoices": [],
  "expertiseChoices": []
}
```

Chaque groupe envoyé doit être complet. Un groupe omis ou une liste vide ne supprime aucun choix existant. Seules les sources explicitement envoyées sont remplacées ; les lignes inchangées sont conservées. Réponse 200 : même contrat que `training/options`. Erreur métier : 422 avec `message`. Autorisation : voter `CampaignVoter::MANAGE`.

L'opération utilise une transaction et un verrou personnage. Un changement effectif actualise les révisions des états de toutes les sessions sans réinitialiser leurs valeurs. Rejouer la même sélection ne change ni les lignes ni les révisions. `manualGrants` et `substitutions` ne sont pas acceptés sur cet endpoint ; leurs parcours existants sont distincts. Il n'y a pas encore d'endpoint public de rattrapage hors montée de niveau.

### Montée de niveau et multiclassage

Le joueur utilise toujours :

`GET /api/public/characters/{accessToken}/level-up/options`

Après choix de la classe et, si nécessaire, de la sous-classe, ajouter `?classId=7&subclassId=42`. La réponse habituelle est enrichie d'un objet `training` ayant le contrat décrit ci-dessus. Les contrôles existants de participation, session ouverte et autorisation de montée de niveau sont conservés.

Les deux POST existants acceptent désormais un champ facultatif `training` :

- `POST /api/campaigns/{campaignId}/characters/{characterId}/level-up` — MJ ;
- `POST /api/public/characters/{accessToken}/level-up` — joueur autorisé.

```json
{
  "classId": 7,
  "subclassId": 42,
  "hitPoints": {"method": "average"},
  "training": {
    "proficiencyChoices": [{"ruleId": 126, "proficiencyIds": [31]}]
  }
}
```

Les autres groupes réutilisent les contrats du builder :

```json
{
  "languageChoices": [{"sourceKey": "race:human-phb", "languageSlugs": ["elvish"]}],
  "expertiseChoices": [{"ruleId": 9, "proficiencyIds": [4, 8]}]
}
```

Tout choix **nouvellement débloqué** doit être complet, y compris en multiclassage. Les anciens choix manquants ne bloquent pas automatiquement la progression : ils restent signalés et peuvent être rattrapés. Aucun choix automatique de remplacement n'est effectué. Un refus annule le niveau, ses choix et la synchronisation dans la transaction existante. Les réponses existantes conservent `character.training` résolu.

L'absence de `training` reste valable lorsqu'aucun nouveau choix n'est requis (notamment Assassin 3). En revanche, après import, Chantelame 2 sans choix d'arme renvoie 422 : Angular doit intégrer cet écran avant d'activer ce parcours. La création initiale conserve son POST atomique et sa validation complète.

### Travail Angular nécessaire

1. Recharger l'aperçu après changement de classe/sous-classe.
2. Afficher séparément règles automatiques et choix, avec les libellés de `definitions`.
3. Réhydrater les sélections depuis `choices.selected` et calculer le compteur à partir de ces mêmes valeurs.
4. Envoyer les groupes complets dans `training` du POST de niveau.
5. Consommer le profil renvoyé après succès ; pour le rattrapage MJ, utiliser `resolved` et les révisions actualisées.

## Limites volontaires du lot

Pas d'élargissement à toutes les capacités avant revue de ce lot. Pas de nouvelles langues de sous-classe : cette source nécessite encore une extension du modèle. Pas d'effets temporaires, de conditions de combat, de choix exclusifs compétence/langue ni d'expertises dépendant d'un autre choix. L'aperçu de niveau couvre classe/sous-classe, pas la simulation d'un nouveau don choisi au même niveau. La langue d'Ennemi juré reste une attribution explicite du MJ.

La gestion générique des choix de langues et d'expertises déjà présents devient idempotente ; cela ne signifie pas que l'ensemble du catalogue de capacités a été importé. Les attributions automatiques sont résolues à la lecture : aucune migration des personnages n'est nécessaire. Après import de références, recharger les profils déjà ouverts.

## Vérifications

`tools/test-feature-training.php` couvre le catalogue, les références invalides/protégées, l'import répété, la création, les deux transitions prioritaires, le multiclassage avec choix de compétence/expertise, le retrait et retour de sous-classe, le rattrapage MJ, les révisions, la répétition des choix, le rechargement ORM et le rollback d'un niveau incomplet.

Les tests d'intégration utilisent des tables PostgreSQL temporaires isolées et annulées à la fin ; le test de concurrence utilise un schéma jetable supprimé à la fin pour permettre plusieurs connexions. Aucun import dans le référentiel réel n'est exécuté.

Validation du lot : 1 112 assertions réussies sur les onze scripts de formation, choix, profil, builder, cycle de vie, visibilité, sécurité et concurrence. `lint:container`, `doctrine:schema:validate`, contrôle de la route PATCH et `git diff --check` réussis. L'import à blanc annonce trois créations ; aucun import réel exécuté.

Fichiers de production concernés :

- `data/reference/dnd-2014-feature-proficiencies.json` ;
- `src/Command/ImportFeatureProficienciesCommand.php` ;
- `src/Service/Catalogue/FeatureProficiencyImportPlanner.php` ;
- `src/Service/CharacterTrainingService.php`, `CharacterTrainingPresenter.php` ;
- `src/Service/CharacterLevelUpService.php`, `CharacterLevelUpRequestResolver.php` ;
- `src/Controller/CharacterTrainingController.php`, `CharacterLevelUpController.php`, `CharacterSessionStateController.php`.

Nouveau test : `tools/test-feature-training.php`. Les fixtures et instanciations des services dans les tests existants sont adaptées aux dépendances de formation ; les attentes anciennes du test de cycle de vie incluent désormais le champ Inspiration déjà existant. Aucun fichier Angular, aucune entité Doctrine et aucune migration modifiés.
