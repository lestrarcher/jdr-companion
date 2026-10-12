# Contrat backend du créateur de personnages (D&D 2014)

## État et périmètre

Le POST existant crée un personnage de **niveau 1**. Il conserve sa transaction
englobante et le premier niveau existant (transaction imbriquée). L'historique,
les choix, substitutions et attributions sont validés avant le commit final.
Une erreur tardive annule aussi le personnage et le premier niveau déjà flushés.

Aucun personnage temporaire n'est enregistré. La prévisualisation est un GET
qui construit uniquement des objets en mémoire. Aucun endpoint Angular ou fichier
Angular n'est modifié. Aucun ancien personnage n'est repris automatiquement.

Les URL suivantes sont les routes Symfony. Avec le proxy Angular, préfixer
généralement par `/api`. Authentification habituelle requise. Les opérations du
créateur et les attributions sont réservées au propriétaire MJ de la campagne
(`CampaignVoter::MANAGE`). Les références officielles et les références
personnelles du propriétaire sont filtrées côté serveur, y compris leurs dépendances.

## 1. Catalogue

`GET /campaigns/{campaignId}/dnd/reference` ajoute aux champs existants :

```json
{
  "backgrounds": [
    {"id": 3, "slug": "criminal-phb", "name": "Criminel", "description": null}
  ],
  "proficiencies": [
    {"id": 501, "slug": "stealth", "name": "Discrétion", "type": "SKILL"}
  ],
  "languages": [
    {"id": 1, "slug": "common", "name": "Commun", "category": "STANDARD", "ordinaryChoice": true},
    {"id": 11, "slug": "draconic", "name": "Draconique", "category": "EXOTIC", "ordinaryChoice": true},
    {"id": 18, "slug": "thieves-cant", "name": "Argot des voleurs", "category": "SECRET", "ordinaryChoice": false}
  ]
}
```

Extraits illustratifs, pas une réponse exhaustive. Les IDs des exemples sont
fictifs : Angular doit toujours employer ceux du catalogue et des options.
Le catalogue global `GET /dnd/reference` expose ces mêmes champs pour les
références officielles uniquement.

Les catégories de langues sont STANDARD, EXOTIC, SECRET, CUSTOM. Les langues
exotiques sont sélectionnables par le MJ sans approbation supplémentaire.
Une langue secrète ne figure pas dans les choix raciaux ou d'historique ;
elle peut être accordée par une règle de classe explicitement configurée ou une
attribution manuelle du MJ.

## 2. Prévisualisation des choix

`GET /campaigns/{campaignId}/characters/build/options?selection=<JSON encodé URL>`

Le paramètre `selection` est un objet JSON :

```json
{
  "raceId": 101,
  "classId": 12,
  "backgroundId": 3,
  "subclassId": null,
  "level": 1,
  "racialFeatChoices": [],
  "training": {}
}
```

- `raceId`, `classId` : entiers positifs obligatoires.
- `backgroundId`, `subclassId` : entier positif ou null/omis.
- `level` : entier 1–20, défaut 1 ; simulation d'une seule classe, pas un
  historique de multiclassage. Le POST de création reste limité au niveau 1.
- `racialFeatChoices` : format du builder existant (`featId`, `ability`).
- `training` : mêmes champs que le POST final ci-dessous. Omettre les groupes
  non encore sélectionnés. Un groupe fourni doit être complet et valide.

Les expertises et substitutions dépendent des maîtrises déjà choisies. Angular
peut rappeler ce GET avec les groupes complétés. Aucun persist/flush n'est effectué.

Structure de réponse (exemple abrégé) :

```json
{
  "proficiencyRules": [
    {
      "ruleId": 201, "key": "phb-2014-rogue-starting-skills",
      "label": "Compétences du roublard", "sourceType": "class",
      "sourceId": 12, "unlockLevel": 1, "acquisitionMode": "STARTING_CLASS",
      "choiceCount": 4, "optionIds": [501, 502, 503, 504, 505]
    }
  ],
  "languageRules": [
    {
      "sourceKey": "race:human-phb", "sourceType": "race", "sourceId": 101,
      "automaticSlugs": ["common"], "choiceCount": 1,
      "optionSlugs": ["elvish", "draconic"]
    }
  ],
  "substitutions": [
    {"ruleId": 203, "originalProficiencyId": 501, "optionIds": [505]}
  ],
  "expertiseRules": [
    {
      "ruleId": 301, "key": "phb-2014-rogue-expertise-1",
      "label": "Expertise : rogue 1", "unlockLevel": 1,
      "choiceCount": 2, "optionIds": [501, 502, 503, 504, 602]
    }
  ],
  "resolved": {
    "proficiencies": [], "languages": [], "expertises": [], "manualGrants": []
  },
  "notImplemented": []
}
```

`optionIds`/`optionSlugs` sont les options **actuellement** disponibles.
Les langues déjà connues et les expertises déjà sélectionnées sont retirées des
listes proposées. Angular conserve son état sélectionné séparément.
`choiceCount = 0` signifie attribution automatique, aucune sélection à envoyer.
`sourceType` des maîtrises : race, class, subclass, background, feat.
Les langues de race utilisent les métadonnées héritées existantes ; la clé
`race:<slug sélectionné>` désigne l'ensemble racial effectif, parents inclus.
Les autres clés sont celles des règles persistées.

Pour le rôdeur, `notImplemented` contient
`favored-enemy-language: manual GM grant required`. Aucune langue d'Ennemi juré
n'est déduite d'un ennemi non structuré.

## 3. POST final atomique

`POST /campaigns/{campaignId}/characters/build`

Les champs existants sont conservés. Nouveaux champs : `backgroundId` et
`training`. `level`, si fourni, doit être exactement 1.

Exemple : humain roublard criminel, avec substitution du doublon Discrétion,
deux expertises et une langue secrète accordée explicitement par le MJ.
Dans cet exemple : 501=Discrétion, 502=Tromperie, 503=Intuition,
504=Investigation, 505=Perception, 601=Dés, 602=Outils de voleur.

```json
{
  "slug": "alden",
  "name": "Alden",
  "playerName": "Camille",
  "type": "player",
  "raceId": 101,
  "classId": 12,
  "subclassId": null,
  "backgroundId": 3,
  "abilities": {
    "strength": 10, "dexterity": 15, "constitution": 13,
    "intelligence": 12, "wisdom": 14, "charisma": 8
  },
  "racialAbilityChoices": [],
  "racialFeatChoices": [],
  "training": {
    "proficiencyChoices": [
      {"ruleId": 201, "proficiencyIds": [501, 502, 503, 504]},
      {"ruleId": 202, "proficiencyIds": [601]}
    ],
    "languageChoices": [
      {"sourceKey": "race:human-phb", "languageSlugs": ["elvish"]}
    ],
    "substitutions": [
      {"ruleId": 203, "originalProficiencyId": 501, "replacementProficiencyId": 505}
    ],
    "expertiseChoices": [
      {"ruleId": 301, "proficiencyIds": [501, 602]}
    ],
    "manualGrants": [
      {"kind": "LANGUAGE", "languageSlug": "druidic", "reason": "Enseignement exceptionnel validé par le MJ"}
    ]
  }
}
```

Les caractéristiques sont les valeurs **avant bonus raciaux**, comme auparavant.
Pour une race nécessitant des bonus/dons à choisir, les listes raciales existantes
doivent être remplies. L'historique peut être null. Le type reste player ou npc.

Dans `training`, seuls les cinq champs documentés sont acceptés ; chacun est
une liste, omise équivaut à vide. **Il n'existe aucun champ d'approbation.**

Validation :
- Toutes les règles à choix applicables doivent avoir exactement leur nombre
  de sélections. Aucune règle absente, future, invisible ou automatique ne peut
  être soumise comme choix.
- Pas de doublon d'option dans une règle ni de groupe répété.
- Les langues choisies ne peuvent pas être déjà connues, même via une autre
  source ; les langues automatiques gardent leurs origines et sont dédupliquées.
- Une substitution cible uniquement une attribution d'historique déjà obtenue
  aussi par une autre règle. Même type SKILL ou TOOL, remplacement non acquis,
  une substitution au plus par attribution originale. Les autres origines
  restent présentes. Aucun remplacement libre depuis une règle raciale/classe.
  Le joueur peut conserver un doublon en omettant une substitution.
  Une attribution manuelle ne crée pas de droit à substitution.
- L'expertise exige une maîtrise effective préalable, y compris une maîtrise
  manuelle explicitement accordée. Deux choix ne peuvent sélectionner la même
  expertise. Les armes, armures et sauvegardes ne sont pas des expertises.
- Attributions manuelles : `kind` PROFICIENCY/EXPERTISE avec `proficiencyId`,
  ou LANGUAGE avec `languageSlug`. `reason` non vide, maximum 500 caractères.
  Auteur et date sont enregistrés côté serveur. Elles ne consomment pas les
  choix dus à une règle.

Succès HTTP 201 : réponse existante `{"character": {...}}` enrichie de :

```json
{
  "background": {"id": 3, "slug": "criminal-phb", "name": "Criminel"},
  "training": {
    "proficiencies": [
      {
        "id": 505, "slug": "perception", "name": "Perception",
        "grants": [
          {
            "ruleId": 203, "key": "phb-2014-background-criminal-phb-skills",
            "label": "Criminel : compétences", "sourceType": "background",
            "sourceId": 3, "unlockLevel": 1, "choiceCount": 0,
            "acquisitionMode": "ANY", "optionIds": [501, 502]
          }
        ],
        "manualGrantIds": [],
        "substitutions": [{"ruleId": 203, "originalProficiencyId": 501}]
      }
    ],
    "languages": [
      {"id": 18, "slug": "thieves-cant", "name": "Argot des voleurs", "sources": ["phb-2014-rogue-secret-language"]}
    ],
    "expertises": [
      {"id": 501, "slug": "stealth", "name": "Discrétion", "sources": ["phb-2014-rogue-expertise-1"]}
    ],
    "manualGrants": [
      {
        "id": 71, "kind": "LANGUAGE", "proficiencyId": null,
        "languageSlug": "druidic", "reason": "Enseignement exceptionnel validé par le MJ",
        "grantedById": 1, "createdAt": "2026-10-12T12:00:00+02:00"
      }
    ]
  }
}
```

Les listes illustrées sont abrégées. `grants` conserve chaque règle d'origine,
même en présence d'une seule maîtrise effective. Pour une substitution, les
options de la règle restent originales : `substitutions` explique le remplacement.
Les sources manuelles de langues/expertises ont la forme `manual:<id>`.
Dans une preview, les IDs manuels sont nulls (aucun enregistrement).

Échec de validation : HTTP 422, `{"message":"..." }`. Référence inexistante ou
personnelle inaccessible : 422. Accès non autorisé : 401/403 selon la sécurité
Symfony. JSON malformé du POST : 400 standard Symfony. Le succès d'une preview
ne dispense jamais de la revalidation du POST final.

## 4. Consultation et attributions permanentes du MJ

- `GET /characters/{id}/training` : même objet training résolu, campagne visible.
- `POST /characters/{id}/manual-grants` : campagne gérée par le MJ.
  Seul le champ `manualGrants`, liste non vide au format ci-dessus, est accepté.
  Transaction et verrou du personnage ; HTTP 201 avec training résolu.
  Les attributions existantes ne sont pas supprimées. Aucun endpoint de suppression
  n'est ajouté dans ce jalon.

## 5. Installation et imports (non exécutés avec écriture)

Nouvelle migration : `Version20261012104643.php`, neuf tables (sept entités,
deux tables d'options), FK et unicités, CHECK de source exclusive, de cible manuelle
et de propriété des références. Aucun UPDATE/INSERT sur les anciens personnages.
Les migrations précédentes des maîtrises et historiques restent des prérequis.

Après revue et application **manuelle** des migrations, référentiels requis :

1. Définitions : `app:dnd:import-proficiencies`.
2. Règles existantes : `app:dnd:import-class-proficiencies`,
   `app:dnd:import-race-proficiencies`, `app:dnd:import-backgrounds`.
3. Nouveau : `app:dnd:import-training --dry-run --update-existing` pour revue.
   L'exécution avec écriture exige une décision explicite du développeur.

Le nouveau catalogue contient 18 langues, 8 règles de langues (6 historiques,
druide et roublard) et 4 règles d'expertise (roublard 1/6, barde 3/10).
Les 37 métadonnées raciales manquantes ont été complétées dans le JSON racial
canonique. L'import training lit ce JSON et **ne remplit que les champs de langue
manquants** avec `--update-existing` ; toute valeur existante différente est
refusée. Il n'importe ni traits, ni caractéristiques, ni autres métadonnées raciales.
Pas de déduction raciale parallèle au runtime.

Idempotence par slug/clés stables ; validation du plan entier avant mutation.
Références personnalisées protégées. Les mises à jour mécaniques des règles de
langue/expertise existantes sont refusées, même avec `--update-existing` ;
les libellés peuvent être actualisés. Aucun élément absent n'est supprimé.
Les imports de maîtrises préexistants prennent désormais aussi en compte les
substitutions persistées avant d'autoriser une modification mécanique.

## 6. Limites explicites

- Langue d'Ennemi juré : attribution MJ, décision validée.
- Les langues temporaires (Astral Knowledge) et changements au repos restent
  hors modèle permanent ; pas d'attribution automatique inventée.
- Les expertises conditionnelles (Connaissance de la pierre, etc.) ne deviennent
  pas des expertises générales. Le moteur accepte des règles automatiques,
  mais le catalogue officiel de ce jalon ne contient que les quatre choix de
  roublard/barde.
- Le POST crée au niveau 1 ; la preview et les résolveurs respectent les niveaux
  ultérieurs. Les endpoints existants de montée de niveau ne sont pas étendus
  pour enregistrer de nouveaux choix d'expertise/langue dans cette mission.
- Véhicules terrestres/aquatiques toujours absents des définitions actuelles ;
  les historiques concernés restent incomplets sur ce point. Les variantes
  d'historique et les alternatives de traits raciaux non structurées restent
  celles signalées dans les catalogues précédents.
- Les références personnalisées sont utilisables lorsqu'elles existent ; aucun
  nouveau CRUD d'administration n'est créé pour les langues/règles.
- La fiche joueur existante n'est pas modifiée : Angular doit consommer les
  nouveaux champs ou le GET training. Aucun calcul de bonus de compétence ni
  d'attaque n'est ajouté.
- Les tests d'atomicité utilisent la vraie méthode Doctrine wrapInTransaction
  avec une connexion d'enregistrement en mémoire et un double du level-up.
  Ils ne constituent pas un test PostgreSQL de bout en bout. Aucune migration
  ou écriture SQL de test n'a été autorisée/exécutée.

Sources de règles : [classes 2014](https://www.dndbeyond.com/sources/dnd/basic-rules-2014/classes),
[historiques et langues 2014](https://www.dndbeyond.com/sources/dnd/basic-rules-2014/personality-and-background)
et éditions raciales du catalogue canonique (PHB, MPMM, FTD, VRGR, SCC, ERLW).

## 7. Fichiers et vérifications de livraison

Fichiers de ce jalon, en conservant les travaux précédents :

- Entités : `src/Entity/Character.php`, `LanguageDefinition.php`,
  `LanguageGrantRule.php`, `ExpertiseGrantRule.php`, `CharacterLanguageChoice.php`,
  `CharacterExpertiseChoice.php`, `CharacterProficiencySubstitution.php`,
  `CharacterManualGrant.php` (les sept derniers sous `src/Entity/`).
- Résolution/validation : `src/Service/CharacterProficiencyResolver.php`,
  `CharacterTrainingReferences.php`, `CharacterTrainingResolver.php`,
  `CharacterTrainingService.php`, `CharacterTrainingPresenter.php`,
  `CharacterBuilderService.php`, `ReferenceVisibility.php` (sous `src/Service/`).
- API : `src/Controller/CharacterBuilderController.php`,
  `DndReferenceController.php`, `CharacterTrainingController.php`
  (sous `src/Controller/`).
- Import : `src/Service/Catalogue/TrainingImportPlanner.php`,
  `src/Command/ImportTrainingCommand.php` et protections supplémentaires dans
  `ImportBackgroundsCommand.php`, `ImportClassProficienciesCommand.php`,
  `ImportRaceProficienciesCommand.php` (sous `src/Command/`).
- Données : `data/reference/dnd-2014-training.json`,
  `data/reference/dnd-2014-races.json`.
- Migration : `migrations/Version20261012104643.php`.
- Tests : `tools/character-training-fixture.php`,
  `tools/test-character-training.php`, `tools/test-character-builder-options.php`,
  `tools/test-character-builder-transaction.php`.
- Contrats : ce document.

Résultats : 57 assertions de formation, 5 assertions du contrôleur de preview,
8 assertions transactionnelles, 457 assertions de régression des maîtrises et
historiques : **527 assertions réussies**. JSON décodés et références vérifiées
par les fixtures et planificateurs. `git diff --check` réussi, routes inspectées.

Le montage Docker n'énumérait qu'une partie des fichiers pour Symfony. Les
vérifications `lint:container`, `doctrine:schema:validate --skip-sync`, routes et
génération de migration ont donc utilisé une copie temporaire complète du backend
dans le même conteneur, avec les mêmes dépendances. Aucune configuration applicative
n'a été modifiée pour contourner ce problème. Le dry-run réel de l'import training
s'arrête sur « Migration requise », sans écriture. L'import complet et ses réimports
ont été validés en mémoire ; aucune exécution SQL de l'import n'est revendiquée.

