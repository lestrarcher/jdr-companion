# Audit et correction — cinq ressources de domaines de clerc

Ce rapport conserve intégralement la première passe locale du 25 septembre, puis documente la vérification externe autorisée le 26 septembre et la correction. Les conclusions de la première passe ci-dessous décrivent cet état historique.

Audit local du 25 septembre 2026, après les corrections Fougue et Imposition des mains.

## Première passe : décision fondée uniquement sur les données locales

**Aucune correction appliquée et aucune migration créée.** L’inaccessibilité des cinq ressources est certaine, mais les cinq correspondances historique → canonique restent probables au regard des exigences de ce ticket.

La BDD démontre les anciennes liaisons de ressources et les attributions canoniques actuelles. Elle ne conserve plus d’attribution pour les anciennes capacités : leur ancien domaine et leur ancien niveau d’acquisition ne peuvent donc pas être vérifiés par les relations actuelles. Les descriptions canoniques sont génériques et ne permettent pas de confirmer l’effet métier précis décrit par les anciennes capacités.

Cette distinction évite de convertir un constat certain (« aucune origine utilisatrice ») en une correction fondée sur une correspondance seulement probable. Conformément à la consigne « Si UNE correspondance n’est pas certaine : NE LA CORRIGE PAS », aucun transfert n’est exécuté.

## Tableau des correspondances

Tous les domaines ci-dessous appartiennent à la classe `cleric`. Les niveaux sont ceux des **candidates actuelles**, pas des anciennes capacités.

| Ressource | Ancienne capacité / slug | Ancienne attribution | Candidate / slug | Domaine actuel | Niveau / règle | Correspondance certaine ? |
|---|---|---|---|---|---|---|
| #16 — Utilisations de Lueur protectrice — `warding-flare-uses` | #78 — Lueur protectrice — `warding-flare` | Aucune ; ancien domaine/niveau non établis | #541 — Éclat protecteur — `light-domain-warding-flare` | #1 — Lumière — `light-domain` | 1 / #380 | **Non** : identité lexicale forte, effet canonique trop générique |
| #17 — Utilisations d’ regard de la tombe — `eyes-of-the-grave-uses` | #90 — Regard de la tombe — `eyes-of-the-grave` | Aucune ; ancien domaine/niveau non établis | #498 — Yeux de la tombe — `grave-domain-eyes-of-the-grave` | #11 — Tombe — `grave-domain` | 1 / #365 | **Non** : la détection spécifique de morts-vivants n’est pas décrite dans la candidate |
| #18 — Utilisations de Sentinelle aux portes de la mort — `sentinel-at-deaths-door-uses` | #92 — Sentinelle aux portes de la mort — `sentinel-at-deaths-door` | Aucune ; ancien domaine/niveau non établis | #501 — Sentinelle aux portes de la mort — `grave-domain-sentinel-at-death-s-door` | #11 — Tombe — `grave-domain` | 6 / #368 | **Non** : homonymie exacte, mais transformation d’un critique non confirmée par le texte canonique |
| #19 — Utilisations de Colère de l’orage — `wrath-of-the-storm-uses` | #96 — Colère de l’orage — `wrath-of-the-storm` | Aucune ; ancien domaine/niveau non établis | #671 — Colère de la tempête — `tempest-domain-wrath-of-the-storm` | #10 — Tempête — `tempest-domain` | 1 / #412 | **Non** : la candidate ne précise ni déclenchement ni dégâts de foudre/tonnerre |
| #20 — Utilisations de Pas de la nuit — `steps-of-night-uses` | #105 — Pas de la nuit — `steps-of-night` | Aucune ; ancien domaine/niveau non établis | #697 — Pas nocturnes — `twilight-domain-steps-of-night` | #9 — Crépuscule — `twilight-domain` | 6 / #431 | **Non** : le vol sous condition d’éclairage n’est pas confirmé par la candidate |

## Textes exacts présents en base

| Paire | Texte ancien | Texte canonique |
|---|---|---|
| #78 → #541 | Vous imposez un désavantage à une attaque dirigée contre vous en faisant jaillir une lumière divine. | Renforce la defense du personnage ou lui permet de proteger une autre creature. |
| #90 → #498 | Vous détectez brièvement la présence de morts-vivants qui ne sont pas protégés contre la divination. | Developpe les connaissances, la perception ou la preparation magique du personnage. |
| #92 → #501 | Vous pouvez transformer un coup critique contre une créature proche en attaque normale. | Renforce la defense du personnage ou lui permet de proteger une autre creature. |
| #96 → #671 | Lorsqu’une créature proche vous touche, vous pouvez lui infliger des dégâts de foudre ou de tonnerre. | Accorde ou ameliore une option offensive propre a cette specialisation. |
| #105 → #697 | Dans une lumière faible ou dans les ténèbres, vous obtenez temporairement une vitesse de vol. | Ameliore le deplacement ou permet un repositionnement special selon les conditions indiquees. |

Les textes sont compatibles au sens large, sans contradiction manifeste, mais cette compatibilité ne démontre pas une identité métier. Les cinq descriptions de ressources indiquent seulement un nombre d’utilisations, sans formule, minimum ni repos.

## Éléments établis et recherche historique

1. Les cinq anciennes capacités sont les **seuls fournisseurs déclarés** de leurs cinq ressources. Aucune n’a de CharacterFeatureRule.
2. Les cinq candidates ont chacune une attribution au domaine/niveau indiqué, avec les autres origines NULL. Leur resource_definition_id est NULL.
3. Aucune TrackableResourceRule ne cible les cinq ressources : aucun chemin d’attribution autonome ni override ne compense les liens manquants.
4. La seule FK entrante vers CharacterFeatureDefinition est celle de CharacterFeatureRule. Aucune autre relation Doctrine/SQL entrante ne fournit le contexte historique manquant. La recherche des cinq identités dans le code PHP courant n’a trouvé aucun traitement spécifique.
5. L’historique Git consulté (`git log --all -S`, commit `d5b134a`) retrouve l’introduction des identités canoniques dans le catalogue historique. Les noms initiaux incluent « Warding Flare », « Sentinel at Death’s Door », « Wrath of the Storm », « Steps of Night ». Cela renforce les rapprochements lexicaux.
6. Ce catalogue a **legacySlugs=[]** et **resourceSlug=null** pour les cinq candidates ; il ne déclare donc pas les transferts recherchés. Les attributions canoniques historiques correspondent aux domaines/niveaux actuels, mais ne prouvent pas les anciennes attributions supprimées.
7. Les migrations de normalisation `Version20260916213000` et `Version20260917120000` ne contiennent aucun des cinq transferts. Aucune correspondance explicite ni ancienne attribution des cinq capacités n’a été retrouvée dans les fichiers et l’historique Git inspectés.
8. Le catalogue JSON n’a été consulté qu’en tant que trace historique locale, jamais traité comme source de vérité de la BDD, modifié ou importé. Aucune source Internet n’a été consultée.

### Autres candidates et améliorations

La recherche par identité de slug retrouve une candidate canonique directe par ancienne identité, sans deuxième candidate portant ce même suffixe. Elle retrouve aussi #80 `improved-flare` et #539 `light-domain-improved-flare`. Ces améliorations ne sont pas assimilées à Lueur protectrice et ne justifient aucun transfert automatique.

L’absence de deuxième candidate lexicale ne prouve pas la correspondance de la première : la liaison historique, le domaine/niveau ancien et l’effet métier détaillé restent non établis. Le champ reviewStatus historique ne remplace pas ces preuves.

## Maximums et restauration

Les configurations ci-dessous sont **inchangées avant/après cet audit**. « Maximum configuré » désigne le calcul de la définition si elle devenait attribuée ; actuellement le resolver ne produit aucune des cinq ressources.

| Ressource | Type | Base | Multiplicateur | Minimum | Caractéristique | Maximum configuré | Restauration | Règles de maximum |
|---|---|---:|---:|---:|---|---|---|---|
| `warding-flare-uses` | ability-modifier | 0 | 1 | 1 | wisdom | max(1, mod. SAG) | long-rest | Aucune |
| `eyes-of-the-grave-uses` | ability-modifier | 0 | 1 | 1 | wisdom | max(1, mod. SAG) | long-rest | Aucune |
| `sentinel-at-deaths-door-uses` | ability-modifier | 0 | 1 | 1 | wisdom | max(1, mod. SAG) | long-rest | Aucune |
| `wrath-of-the-storm-uses` | ability-modifier | 0 | 1 | 1 | wisdom | max(1, mod. SAG) | long-rest | Aucune |
| `steps-of-night-uses` | proficiency-bonus | 0 | 1 | 1 | NULL | max(1, bonus de maîtrise) | long-rest | Aucune |

Les paramètres sont valides pour CharacterResourceResolver. Les textes en base ne précisent pas les nombres d’utilisations : **la conformité sémantique de ces maximums au texte ne peut pas être confirmée**, même si leur calcul technique est déterministe. Aucune règle de maximum perdue n’est démontrée.

Dans le moteur actuel :

- Une attribution de sous-classe s’évalue sur le niveau dans sa classe parente et sur la sous-classe réellement choisie.
- Un maximum ability-modifier utilise la Sagesse effective du personnage, pas son niveau de clerc.
- Un maximum proficiency-bonus utilise le bonus de maîtrise du personnage, donc sa progression de niveau total en multiclassage. Ce choix est déjà encodé pour Pas de la nuit ; il ne doit pas être changé sans décision distincte.
- long-rest signifie que le repos court ne restaure pas ce compteur et que le repos long restaure son maximum résolu. Ceci décrit le service générique ; aucun cycle d’une ressource « corrigée » n’est revendiqué puisque les liens restent inchangés.

## Compteurs autonomes versus Conduit divin

Les cinq ressources sont cinq définitions distinctes. Les anciennes capacités les référencent directement et leurs textes ne mentionnent pas une dépense de Conduit divin.

À l’inverse, les capacités historiques #79 Radiance de l’aube, #85 Préservation de la vie, #91 Voie vers la tombe, #97 Colère destructrice et #104 Sanctuaire du crépuscule mentionnent explicitement une dépense de Canalisation d’énergie divine et référencent la ressource **#15**, également liée à #72 `channel-divinity`.

Le modèle actuel distingue donc les cinq compteurs autonomes du compteur commun. Aucune fusion ni nouvelle liaison avec #15 n’est justifiée ou effectuée.

## Anomalies supplémentaires laissées intactes

- Les cinq candidates canoniques ont activation_type=passive. Les anciennes ont respectivement reaction, action, reaction, reaction et bonus_action. Cette divergence pourrait être une perte éditoriale/de configuration, mais elle n’est pas corrigée dans ce ticket.
- Descriptions canoniques génériques ; formules d’utilisation et restauration absentes des textes de capacité.
- Formulation du nom de la ressource #17 ; aucune correction éditoriale.
- L’existence d’améliorations de Lueur protectrice ne permet pas de supposer leur consommation ou un compteur partagé supplémentaire.
- Aucune modification de Vol diamantin, Escroc arcanique, Conduit divin, des autres capacités orphelines ou des paires homonymes.

## Personnages et sessions actuels

La jointure CharacterClassLevel → CharacterSubclass ne retrouve **aucun personnage actuellement rattaché aux quatre domaines concernés**. Aucun des cinq slugs n’est présent dans les ressources de CharacterSessionState.

Aucun personnage ni état de session n’a été modifié. Puisqu’aucun lien n’a été réparé, une simple resynchronisation ne rendrait pas ces ressources disponibles : la résolution ne possède toujours pas d’origine applicable.

## Vérifications

- 20 sondes avec les vrais resolvers, dans une transaction READ ONLY et avec personnages en mémoire seulement : pour chaque ressource, avant acquisition, au niveau d’acquisition, avec cinq niveaux d’une autre classe et dans le domaine de la Vie. **Ressource absente dans les 20 cas**, comme attendu pour reproduire le défaut actuel.
- Pour les acquisitions au niveau 1, le cas précédent utilise un personnage synthétique sans niveau de clerc. Ce n’est pas une validation de création d’un personnage de niveau 0.
- Harnais ressources/synchronisation : **542 assertions OK**, dont 53 Fougue et 78 Imposition des mains ; fixtures dans les tables temporaires.
- Harnais sécurité/Présage : **256 assertions OK**, tables temporaires annulées.
- Aucun scénario prétendant valider les cinq corrections ajouté : aucune correction n’a été faite.
- Contrôles finaux réussis : lint:container, doctrine:schema:validate et git diff --check. Les empreintes des 38 tables publiques sont identiques avant et après les vérifications. migrations:status confirme 45 migrations exécutées, aucune nouvelle ni indisponible.
- Les non-régressions concernant les autres ressources, notamment Second souffle, Inflexible, supériorité, ki, sorcellerie et Rage, reposent ici sur l’absence de changement de code métier et de données, pas sur un nouveau transfert testé.
- Aucune migration nouvelle. La version locale vérifiée reste `Version20260925130000`.
- Seul fichier créé pour ce ticket : `backend/docs/cleric-domain-resource-audit.md`. Les fichiers des tickets précédents sont préservés.

## Preuve manquante pour autoriser une correction certaine

Il manque, pour chaque paire, soit une trace locale des anciennes attributions avec domaine/niveau et identité métier, soit une correspondance ancienne/canonique explicitement validée dans le projet. Les seuls IDs, noms, suffixes de slug et textes génériques ne satisfont pas le seuil de preuve demandé.

Les transferts proposés dans le premier tableau constituent donc des candidats documentés, **pas une migration approuvée ou exécutée**. Aucun commit, push, déploiement, snapshot SQL ou import.

## Deuxième passe : vérification externe D&D 2014 — 26 septembre 2026

L'utilisateur autorise désormais la vérification par les règles publiées. Le refus initial était correct dans le périmètre alors limité aux preuves locales ; il n'est pas effacé par cette nouvelle source de preuve.

### Sources et éditions réellement consultées

- Player's Handbook (2014), domaines Lumière et Tempête : sections Warding Flare et Wrath of the Storm, consultées via les transcriptions [Lumière](https://dnd5e.wikidot.com/cleric:light) et [Tempête](https://dnd5e.wikidot.com/cleric:tempest).
- Xanathar's Guide to Everything (extension 2017 compatible 2014), domaine Tombe : sections Eyes of the Grave et Sentinel at Death's Door, consultées via la transcription [Tombe](https://dnd5e.wikidot.com/cleric:grave).
- Tasha's Cauldron of Everything (extension 2020 compatible 2014), domaine Crépuscule : section Steps of Night, consultée via la transcription [Crépuscule](https://dnd5e.wikidot.com/cleric:twilight).
- Recoupements : [D&D Beyond, Cleric 101: Light Domain](https://www.dndbeyond.com/posts/868-cleric-101-light-domain), [discussion Tempest datée de 2016 et étiquetée 2014](https://rpg.stackexchange.com/questions/84182/tempest-cleric-when-does-wrath-of-the-storm-trigger), [Tabletop Builds, Twilight Domain](https://tabletopbuilds.com/flagship-build-twilight-domain-cleric/).

Les transcriptions Wikidot sont communautaires, pas des publications officielles de Wizards. Les URL des chapitres payants D&D Beyond ont redirigé vers la boutique : aucune prétention d'avoir consulté les livres sous licence via ces URL. Les passages ciblés des transcriptions identifient les livres, les capacités et toutes les propriétés comparées. Aucun texte long n'est importé.

Les versions 2024, adaptations 2024 et Unearthed Arcana sont exclues. En particulier, on conserve Warding Flare acquis au niveau 1 avec recharge longue et Steps of Night publié dans Tasha, limité par le bonus de maîtrise.

### Comparaison fonctionnelle et décision avant modification

SAG = modificateur de Sagesse ; BM = bonus de maîtrise du niveau total. Dans les cinq cas, il existe un compteur autonome limité, distinct de Conduit divin.

| Capacité originale | Domaine / livre | Niveau clerc | Ancienne locale | Canonique locale | Ressource | Formule locale | Formule vérifiée | Recharge locale | Recharge vérifiée | Confirmée ? |
|---|---|---:|---|---|---|---|---|---|---|---|
| Warding Flare | Lumière / PHB 2014 | 1 | #78 `warding-flare` | #541 `light-domain-warding-flare` | `warding-flare-uses` | max(1, SAG) | max(1, SAG) | long-rest | repos long | Oui |
| Eyes of the Grave | Tombe / Xanathar | 1 | #90 `eyes-of-the-grave` | #498 `grave-domain-eyes-of-the-grave` | `eyes-of-the-grave-uses` | max(1, SAG) | max(1, SAG) | long-rest | repos long | Oui |
| Sentinel at Death's Door | Tombe / Xanathar | 6 | #92 `sentinel-at-deaths-door` | #501 `grave-domain-sentinel-at-death-s-door` | `sentinel-at-deaths-door-uses` | max(1, SAG) | max(1, SAG) | long-rest | repos long | Oui |
| Wrath of the Storm | Tempête / PHB 2014 | 1 | #96 `wrath-of-the-storm` | #671 `tempest-domain-wrath-of-the-storm` | `wrath-of-the-storm-uses` | max(1, SAG) | max(1, SAG) | long-rest | repos long | Oui |
| Steps of Night | Crépuscule / Tasha | 6 | #105 `steps-of-night` | #697 `twilight-domain-steps-of-night` | `steps-of-night-uses` | max(1, BM) | BM | long-rest | repos long | Oui |

Le minimum technique de 1 pour Steps of Night ne change pas la formule jouable : un personnage au niveau requis a déjà un BM au moins égal à 3. Aucune modification de maximum, restauration ou règle n'est nécessaire.

La preuve est le croisement, pour chaque paire, du slug original et de l'effet ancien avec le domaine/niveau canonique et la capacité publiée : protection lumineuse contre une attaque ; détection de morts-vivants ; annulation du caractère critique d'un coup ; riposte foudre/tonnerre ; vol conditionné à l'obscurité. Ces identités fonctionnelles distinctes lèvent l'ambiguïté laissée par les descriptions canoniques génériques. Les attributions canoniques locales correspondent aux domaines et niveaux publiés, sans seconde candidate directe trouvée lors de la première passe. Il ne s'agit pas de reconstituer les anciennes lignes d'attribution supprimées.

**Décision : les cinq correspondances sont confirmées, aucune n'est refusée.** Seuls les liens ressource sont transférés. Les types d'activation et descriptions, même incomplets, restent hors périmètre.

### Migration et tests

Migration `Version20260926100000.php` : identités exclusivement par slug ; vérifications de classe parente, domaine, attribution canonique unique et niveau, absence d'attribution historique, configuration exacte du compteur, absence de règle autonome, absence d'autre fournisseur et de nouvelle relation entrante. Un lien déjà transféré est accepté ; aucun fournisseur, deux fournisseurs, autre ressource ou configuration inattendue provoquent une exception. Le bloc SQL est atomique, y compris si un contrôle échoue sur la dernière paire. Les cinq définitions historiques sont conservées ; aucun personnage ni état n'est modifié.

Tests isolés ajoutés dans `test-cleric-domain-resource-scenarios.php`, inclus dans le harnais existant : seuil avant/au niveau, autre domaine, SAG 16/18/8, multiclassage, ressource absente avant correction, identité canonique seule, consommation, synchronisation et repos. Pour une acquisition au niveau 1, le cas précédent est un guerrier sans niveau de clerc. Pour Pas de la nuit, clerc 6 + guerrier 5 donne 4 utilisations, contre 3 pour clerc 6 ; la SAG ne change pas ce compteur.

Résultat final : **237 assertions domaines**, **779 assertions** au total, incluant **53 Fougue** et **78 Imposition des mains**. La migration est exécutée deux fois dans les tables temporaires, avec une première paire déjà transférée ; les lignes sont comparées pour vérifier que seuls les dix liens attendus changent.

### Application locale et vérifications finales

- Dry-run Doctrine : une seule nouvelle migration. Application réelle réussie de `Version20260926100000` ; version précédente `Version20260925130000`, 46 migrations exécutées, aucune nouvelle ni indisponible.
- Les cinq liens canoniques pointent désormais vers les ressources #16 à #20 respectives. Les cinq anciens liens sont NULL. Les 1 531 définitions sont conservées.
- Comparaison des **38 tables publiques** par nombre de lignes et empreinte de leur contenu trié, sans snapshot SQL : **36 inchangées** ; `character_feature_definition` change uniquement sur les dix liens ; `doctrine_migration_versions` gagne une ligne. Les empreintes des autres champs de toutes les capacités et des lignes intégrales hors des dix slugs sont identiques.
- Aucune mutation de personnage, niveau, caractéristique, attribution, définition/règle de ressource ou session. Les corrections Fougue et Imposition des mains restent intactes.
- Non-régression par sondes en transaction READ ONLY, personnages/sessions uniquement en mémoire : 13 configurations de niveau 20 couvrant guerrier, paladin, magicien, moine, ensorceleur, barbare, clerc et sous-classes ciblées. Comparaison avant/après des maxima, de la synchronisation à zéro et des repos court/long, en excluant seulement les cinq compteurs corrigés. Empreinte SHA-256 identique : `fd2a61831e263d92e20618af24a41e705749fecf0767b14928caaa849c407092`.
- Dix ressources effectivement couvertes par ces sondes : Récupération arcanique, Conduit divin, ki, Rage, sorcellerie, dés de supériorité, Fougue, Inflexible, Second souffle, Imposition des mains. Les cinq nouvelles résolutions sont présentes après migration (SAG 16 : maximum 3 pour les quatre compteurs Sagesse ; niveau total 20 : maximum 6 pour Pas de la nuit). Présage est vérifié séparément par le harnais dédié.
- Harnais ressources/synchronisation après migration : **779 assertions OK**. Harnais sécurité/Présage : **256 assertions OK**. `lint:container`, `doctrine:schema:validate` et contrôles de whitespace : OK.
- Les premiers essais des sondes en mémoire ont été ajustés pour utiliser la moyenne du dé de vie de chaque classe, puis limités aux configurations de non-régression demandées ; la sonde exhaustive a été arrêtée. Les résultats ci-dessus concernent uniquement les exécutions ciblées terminées avec succès.

Fichiers de ce ticket : migration nouvelle `backend/migrations/Version20260926100000.php`, tests nouveaux `backend/tools/test-cleric-domain-resource-scenarios.php`, ajout de leur inclusion dans `backend/tools/test-spell-slot-synchronization.php`, mise à jour du présent rapport. Les autres fichiers déjà présents dans le statut Git appartiennent aux tickets précédents et sont préservés.

Décision finale : **cinq transferts appliqués, aucune correspondance refusée, aucun paramètre mécanique modifié**. Vol diamantin, Escroc arcanique, descriptions/placeholders, autres capacités historiques, JSON et importers sont intacts. Aucun import, snapshot SQL, commit, push ou déploiement.
