# Import des sorts 2014 : préparation et blocages

## Intégration des listes de classes — 19 octobre 2026

Le pipeline prépare désormais ensemble les 487 définitions et leurs 1 235
associations. Cette section remplace les constats historiques indiquant que les
listes ne sont pas importées. Aucun import réel n'a été exécuté.

Décision validée : les 1 170 associations `base` et les 65 `TCoE` utilisent toutes
`SpellClassListEntry`, sans migration. Les extensions Tasha sont accessibles par
défaut. Le JSON source conserve chaque provenance et son label ; la base ne stocke
que la paire sort/classe. Elle ne permettra donc pas de distinguer ou désactiver
TCoE sans évolution ultérieure. Le fichier source doit être conservé pour celle-ci.

`Spell2014ClassAssignments` contrôle version, empreinte du corpus, correspondance
exacte des 487 identités, statut, URL (avec l'alias Maelström déjà reconnu), classes,
labels, provenance et absence de doublons. La commande exige les totaux validés.
Les classes sont résolues par slug stable, origine OFFICIAL et propriétaire nul :

| Classe | Slug | Base | TCoE |
|---|---|---:|---:|
| Barde | bard | 137 | 13 |
| Clerc | cleric | 115 | 6 |
| Druide | druid | 155 | 13 |
| Ensorceleur | sorcerer | 205 | 10 |
| Magicien | wizard | 321 | 4 |
| Occultiste | warlock | 128 | 5 |
| Paladin | paladin | 50 | 3 |
| Rôdeur | ranger | 59 | 11 |

Le plan refuse une classe absente ou ambiguë, sans substitution CUSTOM. Il conserve
les définitions existantes, leurs corrections et toutes les associations déjà
présentes, y compris celles absentes du fichier. Il insère uniquement les paires
manquantes. Une nouvelle association sur un sort déjà acquis est refusée pour
respecter le verrouillage de ses listes ; une paire existante est conservée.

Le chemin d'écriture, testé exclusivement avec fixtures synthétiques temporaires,
recalcule le plan sous verrous dans une transaction commune. Le verrou sur les
définitions sérialise les imports et éditions ; les classes sont stabilisées par
verrou SHARE et les relations par SHARE ROW EXCLUSIVE. Une erreur de relation
annule aussi les nouvelles définitions. Aucun UPDATE, DELETE ou flush ORM global.
Les identités des relations conservées restent inchangées. Aucun changement de
personnage, acquisition, préparation, sous-classe ou Artificier.

Vérifications exécutées :

- `test-import-spells-2014.php` : **2 791 assertions OK**. Validation du fichier,
  doublons, classes absentes/CUSTOM, idempotence, conservation des relations et IDs,
  verrou des sorts acquis, rollback sur erreur SQL de relation, dry-run sans écriture.
  Empreintes de toutes les tables publiques identiques avant/après les tests.
- `test-spell-catalogue-crud.php` : **277 assertions OK**, refus HTTP attendus.
- `lint:container` et `git diff --check` : **OK**.
- Dry-run : **487 sorts à créer, 0 conservé, 0 conflit ; 1 235 associations à créer,
  0 existante ; 1 170 base et 65 TCoE ; 3 sorts exclus, 0 erreur de préparation**.
  Code de sortie **1 attendu** : droits des résumés et traductions toujours non
  établis. Ce contrôle reste bloquant ; le catalogue n'est pas autorisé à l'import réel.

Fichiers de cette reprise : `Spell2014ImportPlan.php`, `ImportSpells2014Command.php`,
nouveau `Spell2014ClassAssignments.php`, `test-import-spells-2014.php`, nouveau
`test-spell-import-class-cases.php` et ce document. Données JSON inchangées.
Pas de migration, `--apply`, commit, push ni accès production. Aucune modification
frontend : pas de build Angular nécessaire. Les verrous sont présents, mais aucun
nouveau test à deux processus concurrents n'a été exécuté pour cet import.

## Complément : convention de consommation validée

Ce complément remplace les conclusions ci-dessous concernant les quatre blocages
matériels. Le bilan initial est conservé comme historique de l'audit.

La décision utilisateur conserve `materialConsumed` comme indicateur de consommation
habituelle à l'incantation. Pour Interdiction, les deux convocations de démons et
Convocations instantanées de Drawmij, il reste **false**. La revue contient désormais
une `materialDescriptionNote` originale, ajoutée après le texte matériel intégral :
dernière incantation des 30 jours dans la même zone, cercle optionnel et consommation
à la fin du sort, ou destruction du saphir lors du rappel ultérieur, respectivement.
Cette note est une donnée interne du pipeline : aucun champ d'API n'est ajouté.

Pour la cohérence de cette convention, Globe d'invulnérabilité et Possession passent
également à false dans les seuls candidats d'import, avec une note de destruction
en fin de sort. Les 36 consommations à l'incantation restantes incluent les
consommations partielles décrites en texte ; les 72 coûts scalaires sont inchangés.
Aucune modification de donnée CUSTOM ou de définition persistante.

Angular et Twig affichent « Consommation habituelle à l’incantation » et précisent
que la description fait foi pour les cas conditionnels, partiels ou différés.
Une case décochée ne signifie donc pas que le matériel ne peut jamais être détruit.
Pas de migration, de changement du booléen JSON ou de moteur de consommation.

`Version20261017140000` a été relue : elle crée `character_spell_revision`, ajoute
`revision_id` et `retirement_id` à `character_spell_acquisition`, avec index et FK.
Elle ne modifie pas `spell_definition`. Le plan et l'insertion éventuelle utilisent
uniquement le catalogue via DBAL, sans flush des acquisitions : cette migration
**n'est pas nécessaire avant l'import des définitions**. Elle reste en attente
pour les fonctionnalités de correction/transition d'acquisition concernées.

Le dry-run prépare 487 candidats, exclut les trois sorts prévus et trouve zéro
erreur de données et zéro collision. **Seul le blocage des droits reste actif** ;
la sortie 1 est attendue tant qu'il n'est pas résolu. Le JSON source des 490 sorts
et son empreinte sont inchangés ; les compléments sont dans la revue séparée.

Les résultats des vérifications de ce complément figurent à la fin du document.

## Bilan initial (avant la décision de conserver le booléen)

Audit du 19 octobre 2026 sur `feature/spell-catalog`. **Aucun import réel.**
Le catalogue source et les caches AideDD préexistants ne sont pas modifiés.
La migration `Version20261017140000` reste non appliquée. Le référentiel unifié,
ses API, l'entité et les services de mutation des sorts ne sont pas refondus.

## Périmètre contrôlé

Fichier : `backend/data/jdr_companion_490_sorts_2014_durees.json`.
SHA-256 : `8e0660e06914d4cce961cc9df0f82341d3d69ca55015c2e9101914b3818fe66c`.

- 490 entrées, 487 candidats OFFICIAL/2014, trois exclusions par identité stable :
  Invocation d'ombres, Projectile élémentaire et Vents contraires.
- 487 identités d'import et slugs uniques ; aucune collision dans la base de
  développement lors du dry-run : 487 créations proposées, zéro conservé.
- Niveau entier 0–9, booléens stricts, huit écoles, durées non vides et accord de
  la concentration avec la durée ; V/S/M identiques au détail des composantes.
  Ces contrôles établissent la cohérence du fichier, pas une comparaison mot à mot
  de ses 487 durées avec tous les ouvrages originaux.
- `invocation` devient `conjuration`, `enchantement` devient `enchantment`,
  `évocation` devient `evocation`, `nécromancie` devient `necromancy`.
- Temps et portée détaillés privilégiés ; durée intégralement conservée.
- Un alias URL vérifié pour Maelström : `vf=maelström` devient `vf=maelstrom`,
  sans renommer `aidedd-2014:maelstrom`. Aucun rapprochement par nom.
- `sourceCode` désormais renseigné pour rendre le filtre source exploitable ;
  le libellé original reste dans `sourceReference`.

| Code | Candidats | Source annoncée par le fichier |
|---|---:|---|
| PHB | 361 | 319 étiquetés SRD, 11 BR+, 31 sans suffixe |
| XGtE | 95 | Xanathar |
| TCoE | 21 | Tasha |
| FToD | 7 | Fizban |
| SCC | 1 | Strixhaven |
| SatO | 2 | Planescape |

Les mentions SRD et `source_book_verified` ne prouvent pas les droits sur un texte
ou sa traduction. « 2014 » désigne ici le corpus de règles antérieur à 2024,
suppléments compris. Aucune variante 2024 n'est acceptée.
Les champs `classes` du fichier sont vides : cet import ne crée **aucune liste
de classe ni attribution**. Les définitions seules ne constituent pas un catalogue
prêt aux acquisitions des personnages. Ne pas déduire ces relations des noms.

## Composantes : revue explicite

`backend/data/spells-2014-material-review.json` contient 260 décisions par identité,
avec empreinte du texte examiné, coût en pc, consommation, statut et justification.
Les 227 autres candidats n'ont pas de composante M. L'empreinte globale lie la
revue à la version exacte du fichier ; toute modification impose une nouvelle revue.
Aucune regex n'infère prix ou consommation à l'exécution. Une regex enlève seulement
l'enveloppe `V, S, M (...)` ; l'intégralité du texte intérieur reste conservée.

Les 82 candidats présentant un montant ou une consommation ont été examinés.
Ce repérage trouve 83 lignes dans le fichier complet, dont Projectile élémentaire
qui reste exclu. La revue couvre aussi les autres composantes : pièce de cuivre,
quantités variables, destruction différée et conditions présentes dans le texte
local. Les caches de descriptions servent uniquement à l'examen, jamais à l'import.

- 72 coûts scalaires en pc, dont deux appartiennent à des cas conditionnels bloqués.
- 188 coûts matériels à `null` : absence de prix chiffré, prix multiple ou variable.
  **Null ne signifie pas gratuit.** Aucun prix marchand n'est inventé.
- 38 indicateurs de consommation explicite, partielle ou différée.
- Quatre cas conditionnels non représentables fidèlement par le booléen existant.
  Leur valeur `false` de candidat est **provisoire et non importable** ; leur statut
  `conditional-unrepresentable` bloque le lot entier. Elle n'affirme pas que ces
  composants ne peuvent jamais être consommés.

| Cas | Traitement |
|---|---|
| Clone | Coût null : diamant 100 000 pc consommé avec la chair, récipient 200 000 pc conservé ; consommation partielle true |
| Mythes et légendes | Coût null : encens 25 000 pc consommé, quatre bâtonnets de 5 000 pc conservés ; true |
| Emprisonnement | Coût null : 50 000 pc par dé de vie, dépend de la cible et de la variante ; false |
| Lien de protection | Deux anneaux de 5 000 pc, soit 10 000 pc requis ; false |
| Manoir somptueux | Trois objets de 500 pc, soit 1 500 pc requis ; false |
| Coffre secret | Deux objets aux prix distincts 500 000 et 5 000 pc ; coût null, false |
| Création d'homoncule | Consommables sans prix et poignard conservé à 100 000 pc ; coût null, true |
| Création de mort-vivant | 15 000 pc par cadavre ; coût null, false |
| Projection astrale | 110 000 pc par cible, plusieurs composants ; coût null, true |
| Préservation des morts | Pièce de cuivre par œil ; quantité variable, coût null, false |
| Globe d'invulnérabilité / Possession | Destruction au terme du sort ; true ne signifie pas consommation immédiate |
| Interdiction | Consommation lors de la dernière incantation de la séquence de 30 jours ; bloqué |
| Convocations de démons majeur/mineurs | Sang consommé seulement si le cercle optionnel est tracé ; bloqué |
| Convocations instantanées de Drawmij | Saphir détruit au rappel optionnel ultérieur ; bloqué |

Le coût scalaire décrit une exigence matérielle et n'est jamais un débit automatique.
Le booléen true indique qu'au moins une composante est consommée : il ne permet pas
de facturer la totalité du matériel. Aucun moteur de consommation n'est ajouté.

## Droits et textes

Le pipeline accepte uniquement `description_short_fr`. Il n'utilise plus
`description` comme repli et refuse tout `description` ou `higher_level` non vide.
Le fichier séparé contenant les textes intégraux n'est jamais chargé par l'importeur.

Parmi les 487 candidats, 481 résumés sont étiquetés `user-provided-aidedd-html`,
trois `user-provided-html-paste` et trois `original-summary`. Le caractère court
ou fourni par l'utilisateur ne constitue pas une licence. La revue indique donc
`rightsStatus: unverified` et bloque aussi l'application pour ce motif.
Passer arbitrairement le statut à verified ne suffit pas : une preuve documentée
et une attribution sont exigées, liées au fichier examiné. Leur validité doit être
contrôlée humainement, y compris pour les traductions des composantes.

Sources consultées : [SRD 5.1 officiel sous CC BY 4.0](https://media.dndbeyond.com/compendium-images/srd/5.1/SRD_CC_v5.1.pdf),
[présentation officielle des SRD](https://www.dndbeyond.com/srd),
[page de contact AideDD](https://www.aidedd.org/site/contact/).
La licence du SRD 5.1 n'est pas une autorisation générale pour les textes français
AideDD ni pour tous les suppléments. Aucune autorisation générale applicable à ce
corpus n'a été établie dans cet audit ; aucun contact n'a été envoyé.

## Idempotence et protection des données

`Spell2014ImportPlan` est partagé par la simulation et l'écriture éventuelle :

- Identité exacte OFFICIAL, sans owner, édition 2014, même importKey et slug :
  conserver intégralement la ligne, même corrigée depuis le premier import.
- Collision de slug, identité contradictoire ou CUSTOM : conflit, aucune mise à jour.
- Nouvelle identité : insertion uniquement. Aucun update, delete ou rapprochement
  par nom. Pas d'option permettant d'écraser des données existantes.
- Relecture du plan dans la transaction sous verrou `SHARE ROW EXCLUSIVE` du
  catalogue ; les contraintes UNIQUE couvrent également les collisions SQL.
- Insertion DBAL pour ne jamais flusher d'autres entités gérées par Doctrine.
  Une erreur annule tout le lot. Le verrou est conservateur et bloque brièvement
  les autres écritures du catalogue pendant un éventuel import.

## Vérifications exécutées

- `test-import-spells-2014.php` : **2 764 assertions OK**. Mapping des 487 candidats,
  préservation du détail matériel, cas particuliers, entrées invalides, revue
  périmée/absente, idempotence, conservation des corrections, collision CUSTOM,
  identités contradictoires, rollback SQL et commande en dry-run.
- Seules des fixtures **fictives** sont insérées dans des tables/séquences
  PostgreSQL temporaires, puis annulées. Aucun candidat AideDD n'est inséré, même
  dans ces tables. Empreintes COUNT/MD5 de toutes les tables publiques inchangées.
- `test-spell-catalogue.php` : **91 assertions OK**.
- `test-spell-catalogue-crud.php` : **277 assertions OK** ; refus HTTP attendus.
- `lint:container` et `git diff --check` : **OK**.
- `app:spells:import-2014 --dry-run` : **487 préparés, 3 exclus, 0 erreur de données,
  487 créations proposées, 0 collision**. Code de sortie **1 volontaire** à cause
  des cinq blocages avant écriture. Ce n'est pas un feu vert d'import.
- Aucun test de concurrence à deux processus ajouté ici ; idempotence séquentielle,
  replanification sous verrou et rollback sont testés. Aucun changement Angular
  ou modèle Doctrine ; aucun build frontend ou migration nécessaire à ce correctif.

## Avant d'autoriser un import réel

**Les 487 sorts ne sont pas encore prêts pour un import réel.**

1. Établir les droits du corpus effectivement importé, ou remplacer les textes
   concernés par un corpus autorisé avec attribution et nouvelle empreinte.
2. Décider comment représenter les quatre consommations conditionnelles sans les
   convertir en consommations systématiques ni les masquer. Le modèle actuel ne
   possède ni condition ni troisième état pour ce booléen ; aucune migration ou
   refonte n'est imposée par cet audit.
3. Relancer le dry-run sur le corpus et la base définitifs après ces corrections,
   examiner le plan, puis obtenir l'autorisation explicite d'exécuter `--apply`.

Pour l'utilisation ultérieure en jeu, fournir séparément les listes de classes
et les attributions validées. Cette absence ne doit pas être masquée par l'import.

Fichiers du changement : commande existante `ImportSpells2014Command.php`, services
`Catalogue/Spell2014Catalogue.php` et `Catalogue/Spell2014ImportPlan.php`, revue JSON,
test et ce bilan. Aucun commit, push, accès production, import réel ou migration.

## Vérifications après décision sur les consommations

- `test-import-spells-2014.php` : **2 771 assertions OK**, préservation du texte
  source et ajout exact des notes, booléens false sur les six cas particuliers,
  rejet de notes invalides, idempotence et empreintes des tables publiques inchangées.
- `test-spell-catalogue-crud.php` : **277 assertions OK**.
- `spell-editor.spec.ts` : **16 tests OK**, dont vérification de l'explication
  affichée en consultation OFFICIAL et conservation des protections de mutation.
- Build Angular **OK**, seul warning préexistant `player-portal.scss` 11,51 kB/10 kB.
- `lint:container`, lint Twig du formulaire et `git diff --check` : **OK**.
- Dry-run final : **487 préparés, 3 exclus, 0 erreur, 0 collision** ; 260 avec M,
  72 coûts scalaires et 36 consommations habituelles à l'incantation. Sortie 1
  uniquement pour les droits non établis. Aucun `--apply` exécuté.

Fichiers de ce complément : revue JSON, `Spell2014Catalogue.php`, libellé du bilan
dans `ImportSpells2014Command.php`, test d'import, formulaire Twig, formulaire et
libellés Angular, test Angular et ce document. Aucun modèle, contrat API ou fichier
de migration modifié. Aucun sort CUSTOM ni donnée applicative persistante modifié.
