# Ressources raciales FTD — audit et correction ciblée

Date : 26 septembre 2026. Référence de départ : `resource-quality-reaudit.md`, constats C1–C3. Ce ticket n'intervient pas sur les sept probables, les quatre points manuels ni Escroc arcanique.

## Diagnostic reproduit avant modification

Les cinq capacités FTD sont bien attribuées, mais leur `resource_definition_id` est NULL. Les trois ressources existent et fonctionnent pour les anciennes races ; aucune règle de ressource indépendante ne donne accès aux races FTD. Les nouvelles races sont des racines distinctes, sans héritage de `dragonborn`.

Une nouvelle lecture PostgreSQL et **42 probes en transaction READ ONLY** (sept races aux niveaux 1/4/5/9/17/20) reproduisent le défaut avec les vrais resolvers. Au niveau 5, les anciennes variantes ont souffle=3 et, selon leur race, protection=1 ou souffle spécial=1. Les nouvelles variantes n'ont aucun de ces compteurs ; le drakéide cristallin conserve bien Vol cristallin=1.

Les textes anciens et canoniques concordent :
- Souffle ordinaire : remplace une attaque ; nombre d'utilisations = bonus de maîtrise ; repos long. Les variantes décrivent des formes/zones différentes, sans changer la mécanique du compteur.
- Protection chromatique : immunité liée à l'ascendance à partir du niveau 5 ; une utilisation par repos long.
- Souffle métallique spécial : choix entre effet affaiblissant et répulsif à partir du niveau 5 ; une utilisation par repos long.

Ces propriétés sont explicitement décrites dans les données locales. Aucune vérification externe n'est nécessaire, aucune règle 2024 ni texte externe n'est importé. Les noms seuls ne fondent pas la décision.

## Les cinq attributions avant modification

BM = bonus de maîtrise du niveau total. Anciennes origines du souffle : `dragonborn`, `chromatic-dragonborn-red`, `metallic-dragonborn-silver`. Rouge et argent héritent de `dragonborn`.

| Mécanique | Ressource | Ancien fournisseur | Ancienne origine | Fournisseur canonique | Origine FTD | Niveau total / règle actuelle | Maximum | Recharge | Correspondance certaine ? |
|---|---|---|---|---|---|---|---|---|---|
| Souffle ordinaire | #57 `breath-weapon-uses` | #251 `breath-weapon` | Les trois anciennes origines | #923 `racial-dragonborn-chromatic-ftd-breath-weapon-2` | `dragonborn-chromatic-ftd` | 1 / #984 | BM | long-rest | Oui |
| Souffle ordinaire | #57 `breath-weapon-uses` | #251 `breath-weapon` | Les trois anciennes origines | #927 `racial-dragonborn-gem-ftd-breath-weapon-2` | `dragonborn-gem-ftd` | 1 / #988 | BM | long-rest | Oui |
| Souffle ordinaire | #57 `breath-weapon-uses` | #251 `breath-weapon` | Les trois anciennes origines | #932 `racial-dragonborn-metallic-ftd-breath-weapon-2` | `dragonborn-metallic-ftd` | 1 / #993 | BM | long-rest | Oui |
| Protection chromatique | #58 `chromatic-warding-use` | #253 `chromatic-warding` | `chromatic-dragonborn-red` | #925 `racial-dragonborn-chromatic-ftd-chromatic-warding-4` | `dragonborn-chromatic-ftd` | 5 / #986 | 1 | long-rest | Oui |
| Souffle métallique spécial | #60 `metallic-breath-weapon-use` | #256 `metallic-breath-weapon` | `metallic-dragonborn-silver` | #934 `racial-dragonborn-metallic-ftd-metallic-breath-weapon-4` | `dragonborn-metallic-ftd` | 5 / #995 | 1 | long-rest | Oui |

**Trois mécaniques = trois attributions du souffle + une protection + un souffle spécial.** Les IDs documentent la lecture locale ; la migration ne les utilise jamais comme identités fixes.

Attributions anciennes vérifiées : `breath-weapon` a trois règles raciales #247/#248/#253 au niveau 1 ; `chromatic-warding` a #255 au niveau 5 ; `metallic-breath-weapon` a #256 au niveau 5. Ces anciennes capacités ne sont donc ni inutilisées ni détachables dans ce ticket.

## Modèle retenu : partage des définitions, préservation des états

Les trois définitions existantes sont conservées. Le souffle ordinaire partage légitimement sa définition entre trois capacités canoniques aux origines distinctes et l'ancien fournisseur. Les deux compteurs spéciaux ont chacun leur définition propre, partagée entre l'ancien fournisseur et la capacité FTD correspondante. **Aucune fusion ni duplication de définition.**

Le resolver indexe par slug de ressource : même si une origine héritée donne plusieurs règles d'une même aptitude, le compteur n'est pas doublé. L'état mutable reste dans chaque CharacterSessionState ; partager une définition ne partage aucune consommation entre personnages. Le souffle ordinaire et le souffle métallique spécial restent deux compteurs indépendants.

Le souffle PHB n'est pas relié : sa mécanique locale diffère et son point manuel reste intact. Vol cristallin est indépendant et reste fourni par sa capacité canonique actuelle.

| Paramètre | Souffle ordinaire | Protection chromatique | Souffle spécial |
|---|---|---|---|
| Type | proficiency-bonus | fixed | fixed |
| Base | 0 | 1 | 1 |
| Minimum | 1 | 0 | 0 |
| Multiplicateur | 1 | 1 | 1 |
| Caractéristique | NULL | NULL | NULL |
| Règles TrackableResourceRule | Aucune | Aucune | Aucune |
| Maximum calculé | max(1, BM), donc BM aux niveaux jouables | 1 | 1 |
| Recharge | long-rest | long-rest | long-rest |

Aucun de ces paramètres n'est modifié. Le BM dépend du niveau total ; les acquisitions raciales également. Il n'existe pas de « niveau racial » distinct à inventer.

## Migration

`Version20260926120000.php`, après `Version20260926110000.php`.

- Vérifie les six identités raciales et les parents attendus.
- Vérifie les trois définitions et leurs paramètres exacts, ainsi que l'absence de règles de ressource autonomes.
- Vérifie les trois anciens fournisseurs, leurs liens et leurs cinq attributions au total ; les conserve intégralement.
- Vérifie les cinq capacités canoniques, chacune avec une unique attribution à la bonne race/niveau et aucune autre origine.
- Refuse une liaison à une autre ressource ou un fournisseur non prévu ; autorise les fournisseurs multiples légitimes de la liste explicite.
- Accepte les liens déjà corrects, y compris un état partiellement corrigé.
- Ajoute uniquement les cinq liens canoniques. Les contrôles et UPDATE sont dans un bloc atomique : une exception tardive annule le bloc entier.
- Aucun retrait automatique en down, car des liens corrects peuvent préexister.

Les anciennes races restent sélectionnables. Deux personnages actuels utilisent les anciennes variantes (un rouge, un argent) ; aucun personnage n'utilise directement les trois racines FTD. Aucun personnage, session ou consommation n'est modifié directement.

## Tests ciblés

Les fixtures restent dans les tables et séquences temporaires du harnais existant, avec rollback.

- Les cinq attributions sont reproduites inaccessibles avant migration ; les trois anciennes origines fonctionnent déjà.
- Races FTD, anciennes races, PHB témoin, descendante FTD synthétique : niveaux 0/1/4/5/9/17/20. Le niveau 0 est uniquement un objet de test pour le seuil 1, pas une création joueur.
- Multiclassage 2+2 et 2+3 ; seuils fondés sur le niveau total.
- Souffle FTD partagé : max 2 au niveau 1/4, 3 à 5, 4 à 9, 6 à 17/20.
- Protection et souffle spécial : absents avant 5 et des autres variantes ; max 1 à partir de 5.
- Acquisition/init, consommation, synchronisation, repos court et long pour les anciennes et nouvelles origines.
- Consommer le souffle spécial ne consomme pas le souffle ordinaire, et réciproquement. Deux personnages partageant une définition conservent des états indépendants.
- Passage total 4→5 : maximum du souffle 2→3 ; synchronisation ordinaire conserve la valeur ; level-up applique le delta selon le moteur existant (1/2→2/3). Le compteur spécial devient disponible si la race le prévoit.
- Anciennes règles et liens inchangés ; les capacités historiques ne sont jamais nécessaires au runtime FTD.
- Migration appliquée deux fois aux fixtures, première capacité déjà reliée ; contrôles négatifs d'identités, origines, héritage, fournisseurs concurrents et paramètres.
- Comparaison des autres ressources de toutes les fixtures, notamment Vol cristallin : identiques.

## Fichiers et portée

- Nouvelle migration : `backend/migrations/Version20260926120000.php`.
- Nouveaux scénarios : `backend/tools/test-ftd-resource-scenarios.php`.
- Une inclusion ajoutée à `backend/tools/test-spell-slot-synchronization.php`.
- Ce rapport : `backend/docs/ftd-resource-audit.md`.

Le rapport de ré-audit préexistant est conservé comme diagnostic avant correction. Les corrections précédentes, les sept probables et les quatre points manuels sont intacts. Aucun nettoyage historique, modification de description, JSON/importer, import, snapshot SQL, commit, push ou déploiement.

## Application locale et résultats finaux

Migration appliquée avec succès : `Version20260926120000`. Doctrine confirme **48 migrations exécutées, aucune nouvelle ni indisponible**. Le dry-run préalable annonçait une seule migration.

La lecture finale confirme huit fournisseurs légitimes : quatre pour le souffle ordinaire (l'ancien et les trois FTD), deux pour la protection chromatique, deux pour le souffle spécial. Aucun fournisseur étranger au modèle attendu.

### Probes avant/après sur la base réelle

Les **42 mêmes configurations** ont été rejouées après migration. Les anciens modèles et PHB ont des résultats strictement identiques ; seules les nouvelles ressources attendues apparaissent pour les races FTD. Aucune autre ressource résolue n'est modifiée.

| Race au niveau total 5 | Avant : compteurs ciblés | Après : compteurs ciblés |
|---|---|---|
| dragonborn | souffle 3 | souffle 3 |
| chromatic-dragonborn-red | souffle 3, protection 1 | souffle 3, protection 1 |
| metallic-dragonborn-silver | souffle 3, souffle spécial 1 | souffle 3, souffle spécial 1 |
| dragonborn-chromatic-ftd | aucun | souffle 3, protection 1 |
| dragonborn-gem-ftd | aucun des trois | souffle 3 ; Vol cristallin reste 1 |
| dragonborn-metallic-ftd | aucun | souffle 3, souffle spécial 1 |
| dragonborn-phb | aucun | aucun |

Les niveaux 1/4/5/9/17/20 confirment la progression BM et les seuils. Les protections restent absentes des variantes non concernées ; le souffle ordinaire est volontairement commun aux trois variantes FTD. Le total de définitions reste **60**, celui des règles de ressources **104**, celui des règles de capacités **1 500**.

### Tests après migration

| Vérification | Résultat |
|---|---|
| Scénarios FTD (62 configurations raciales initiales, puis évolutions de niveau et gardes) | **830 assertions OK** |
| Harnais global ressources/synchronisation | **1 749 assertions OK** |
| Fougue, inclus dans le harnais | **53 OK** |
| Imposition des mains, inclus | **78 OK** |
| Cinq domaines de clerc, inclus | **237 OK** |
| Vol cristallin, inclus | **140 OK** |
| Sécurité / Présage | **256 assertions OK** |
| lint:container | OK |
| doctrine:schema:validate | Mapping et schéma valides |
| Contrôles whitespace Git, nouveaux fichiers compris | OK |

### Intégrité des 38 tables

**36 tables inchangées**. Les seules différences sont cinq colonnes `resource_definition_id` dans cinq lignes de `character_feature_definition`, et une ligne de suivi Doctrine supplémentaire. Aucun autre champ de capacité n'a changé : empreinte de tous les champs hors lien identique (`1f37b86af4594716052f25ce7eb778c3`). Toutes les autres capacités sont intégralement identiques (`7b21e7b6395ef3f487f94d579b0e95d5`). Les anciens fournisseurs, sept probables et quatre points manuels sont donc préservés.

Les empreintes après les harnais finaux sont strictement identiques à celles juste après migration. Aucune mutation persistante par les tests. Aucun personnage ou état de session réécrit ; les consommations existantes sont conservées. Les éventuels nouveaux compteurs FTD seront initialisés par la synchronisation habituelle, pas par cette migration.

Les MD5 portent sur le contenu de toutes les lignes triées ; aucun snapshot SQL n'a été créé.

| Table | Lignes avant → après | Empreinte avant | Empreinte après |
|---|---:|---|---|
| `campaign` | 2 → 2 | `9707cec75e27ee685876658736ba8abb` | identique |
| `campaign_figure` | 13 → 13 | `90f54ebe0200e6ec3f888f039c7bd331` | identique |
| `character` | 9 → 9 | `376c41b59bc6f477bd0d3a7e7f19e44e` | identique |
| `character_ability_adjustment` | 17 → 17 | `8125ce17d49bf32d9c43b15f9e546bae` | identique |
| `character_ability_score` | 54 → 54 | `b7e144a7e86ccd959d1cfc3226f888ff` | identique |
| `character_action_class_rule` | 9 → 9 | `8c707e4289521be18f8052aaec69138c` | identique |
| `character_action_definition` | 4 → 4 | `05ca701ceac3666824b5d951510b4782` | identique |
| `character_active_effect` | 0 → 0 | `d41d8cd98f00b204e9800998ecf8427e` | identique |
| `character_class` | 13 → 13 | `d07e18c45b64347a35ae6774300dacbd` | identique |
| `character_class_level` | 103 → 103 | `1da63241ac70221991ae3f30cc182367` | identique |
| `character_class_level_rule` | 68 → 68 | `7c364028be04c90693e7f6756c8bf11b` | identique |
| `character_feat` | 11 → 11 | `e9f0bf9366d17b4c13c1a02667ab580f` | identique |
| `character_feature_definition` | 1531 → 1531 | `068f04bf5fe432a8dcc93a1527718045` | `30826299d6b9a4386bbdbfa9d16b25d9` |
| `character_feature_rule` | 1500 → 1500 | `4552e38823dde96f98c4e2acd27add6c` | identique |
| `character_magic_item` | 3 → 3 | `1285296ece7221cda7a8dd6fee4735b0` | identique |
| `character_progression` | 3 → 3 | `7d809738397d8b803fe99f20a5de645a` | identique |
| `character_race` | 160 → 160 | `ae6e7c3d46f0dbcba4640e225f6b0529` | identique |
| `character_race_ability_choice` | 12 → 12 | `261158bf88f404853b78b9a4e71fdda7` | identique |
| `character_session_state` | 15 → 15 | `d4f3677c4a06cb98e18791ab66663122` | identique |
| `character_subclass` | 105 → 105 | `5c8ecb99a4a70336f0a787cd1b50304a` | identique |
| `character_wallet` | 9 → 9 | `6367f42c0aa80d176c4bbf3d8215b091` | identique |
| `doctrine_migration_versions` | 47 → 48 | `f0ae387b1620275afed2c5725623d91a` | `d7c770664106e1512bd88dd715055fd6` |
| `feat` | 83 → 83 | `ef105c10ca05d085d794473a69206033` | identique |
| `game_session` | 5 → 5 | `de695a75b8e5bace335e92b2f5c2199b` | identique |
| `magic_item` | 4 → 4 | `9bade96a27a17de358d2efbd5287d21f` | identique |
| `magic_item_ability_effect` | 1 → 1 | `be916a6de92fd7808e22392dbeb19595` | identique |
| `media` | 24 → 24 | `5c8265c866ae74c8e55b16447b899a2d` | identique |
| `progression_adjustment_rule` | 0 → 0 | `d41d8cd98f00b204e9800998ecf8427e` | identique |
| `progression_definition` | 3 → 3 | `021c6c4a16d07f0c3f1ced873d38ac44` | identique |
| `progression_stage` | 17 → 17 | `d4603e852449b2eb8ada7d96f8706417` | identique |
| `quest` | 10 → 10 | `a237785f4f399a05f1821f21213cdeae` | identique |
| `race_ability_modifier` | 156 → 156 | `de2d45f9a5fc46eaf90c8474175c07e4` | identique |
| `rest_request` | 18 → 18 | `50ca0ef92cf71b284a9027e51c9f91d6` | identique |
| `tip` | 3 → 3 | `381d9615ee51799c8e83ffb9ec34dcb8` | identique |
| `trackable_resource_definition` | 60 → 60 | `defd26ee02dc6840dceb33e8fafb6dfd` | identique |
| `trackable_resource_rule` | 104 → 104 | `3869a7ff8f1bba0cf5472bad18ffe7db` | identique |
| `user` | 1 → 1 | `e8b9d2f54308c45de5ba795780cec60e` | identique |
| `weather` | 8 → 8 | `75211d4948bc75d749055db85b140572` | identique |

Conclusion : **les trois constats C1–C3 et les cinq attributions FTD sont corrigés, sans casser les anciennes variantes et sans changer les maximums/recharges**. Les huit corrections antérieures restent validées. Seul ce ticket a été implémenté ; le ré-audit antérieur conserve sa valeur de constat avant correction.
