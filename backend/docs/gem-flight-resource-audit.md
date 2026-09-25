# Vol diamantin / Vol cristallin — audit et correction ciblée

Date : 26 septembre 2026. Périmètre : seul lien de la ressource `gem-flight-use`. Les corrections Fougue, Imposition des mains et domaines de clerc sont préservées.

## Identités et preuve locale avant correction

| Objet | Identité stable | État observé |
|---|---|---|
| Ressource #59 | `gem-flight-use` — Utilisation de Vol diamantin | Fournie uniquement par l'ancienne capacité ; aucune TrackableResourceRule |
| Ancienne capacité #255 | `gem-flight` — Vol diamantin | Porte #59, aucune CharacterFeatureRule |
| Capacité canonique #930 | `racial-dragonborn-gem-ftd-gem-flight-5` — Vol cristallin | Aucun lien ressource ; une attribution raciale |
| Race #41 | `dragonborn-gem-ftd` — Drakéide cristallin | Race racine, sélectionnable, sans parent ni descendant actuel |
| Attribution #991 | Canonique → `dragonborn-gem-ftd`, niveau 5 | Aucune autre origine de classe, sous-classe, don ou progression |

Le texte ancien décrit des ailes spectrales déclenchées par une action bonus à partir du niveau 5, avec vitesse de vol égale à la marche et une utilisation par repos long. Le texte canonique décrit les mêmes propriétés et précise une durée d'une minute et le vol stationnaire. Ces précisions ne contredisent pas le texte ancien plus court. Le texte de la ressource indique également une utilisation récupérée au repos long.

**Correspondance confirmée par les textes locaux, l'identité Gem Flight et l'attribution à la variante cristalline.** Contrairement à la première passe des domaines de clerc, le texte canonique n'est pas générique. La recherche par identité de slug et noms ne retrouve aucune autre candidate Gem Flight ; Ascendance cristalline est un autre trait, pas un fournisseur concurrent. Aucun traitement spécifique de ces slugs dans le code métier courant. La seule relation SQL entrante vers CharacterFeatureDefinition est CharacterFeatureRule, dont aucune ligne ne cible #255.

Sources effectivement utilisées : données locales `character_feature_definition`, `character_feature_rule`, `character_race`, `trackable_resource_definition`, `trackable_resource_rule` et contraintes SQL ; `CharacterFeatureResolver` et `CharacterResourceResolver`. Le slug FTD identifie le contenu racial modélisé. Aucune vérification externe n'a été nécessaire ni revendiquée ; aucun texte de règles externe importé et aucune règle 2024 utilisée.

## Mécanique conservée

| Propriété | Configuration locale | Vérification |
|---|---|---|
| Type de maximum | fixed | Compteur fixe, pas un bonus de maîtrise ou une caractéristique |
| Base | 1 | Conforme aux deux textes de capacité et au texte de ressource |
| Minimum | 0 | Le calcul max(0, 1) donne 1 |
| Multiplicateur / caractéristique | 1 / NULL | Aucun scaling utilisé pour fixed |
| Recharge | long-rest | Conforme aux trois textes ; aucune recharge au repos court |
| Acquisition | Niveau total 5 | Attribution raciale et textes concordants |

Le resolver racial utilise le niveau total du personnage et accepte sa race ou un ancêtre. Ainsi guerrier 2 + paladin 3 débloque l'aptitude, guerrier 2 + paladin 2 non. Une descendante de la race cristalline hériterait du trait ; aucune descendante n'existe actuellement. Les variantes héritières des tests sont synthétiques et ne créent aucune race persistante.

Le type d'activation canonique est actuellement `passive`, alors que le texte décrit une action bonus et que l'ancienne définition indique `bonus_action`. Cette différence éditoriale/de configuration est constatée, mais laissée intacte conformément au périmètre demandé.

## Modèle et migration

Avant : `gem-flight` → `gem-flight-use`, sans attribution ; canonique attribuée au niveau 5 → aucun compteur.

Après : `gem-flight` conservée sans lien ; `racial-dragonborn-gem-ftd-gem-flight-5` → `gem-flight-use`, via l'attribution raciale existante. Aucun nouveau fournisseur ni changement de règle ou de paramètre.

Migration : `Version20260926110000.php`, après `Version20260926100000`. Résolution par slugs uniquement. Garde-fous : présence des quatre identités, race sans parent, attribution canonique unique à la bonne race/niveau et sans autre origine, absence d'attribution historique, configuration exacte du compteur, absence de règle autonome et de relation entrante imprévue. Un fournisseur extérieur, deux fournisseurs, aucun fournisseur ou une liaison à une autre ressource provoquent une exception. L'état déjà transféré est accepté sans modification. Les deux UPDATE sont dans un bloc SQL atomique. Le retour arrière automatique est refusé pour ne pas défaire un état canonique préexistant.

## Tests et non-régressions

`test-gem-flight-scenarios.php` est inclus dans le harnais ressources existant, dont toutes les tables ORM et séquences sont temporaires et annulées.

- Reproduction du compteur absent avant transfert.
- Autre race et absence de race : absent ; race cristalline niveaux 4/5/20 : absent/1/1.
- Descendante synthétique niveaux 4/5 : absent/1 ; multiclassage total 4/5 : absent/1, y compris pour la descendante.
- Capacité canonique seule active ; ancienne conservée mais absente du runtime.
- Initialisation à 1 ; consommation à 0 ; synchronisation conserve 0 ; repos court conserve 0 ; repos long restaure 1.
- Rejets d'identités manquantes, mauvaises origines/niveaux, attributions concurrentes/historiques, configurations incorrectes, fournisseurs concurrents et nouvelles relations entrantes.
- Migration exécutée deux fois ; comparaison des lignes : seuls les deux liens attendus changent, y compris dans un jeu de données contenant les trois corrections précédentes.
- Comparaison des maxima de toutes les ressources des personnages de fixtures avant/après, hors seul compteur Gem Flight : identique.

Premier passage avant application locale : **140 assertions Gem Flight**, **919 assertions ressources/synchronisation**, incluant **53 Fougue**, **78 Imposition des mains**, **237 domaines de clerc**.

## Personnages et données persistantes

Aucun personnage actuel ne possède `dragonborn-gem-ftd` ou une descendante. Aucun état de session ne contient `gem-flight-use`. Aucune mutation directe de personnage ou session n'est nécessaire. Le runtime synchronisera le compteur pour les personnages éligibles créés/attribués ultérieurement.

Les empreintes des 38 tables publiques sont relevées avant/après, sans snapshot SQL. Des empreintes supplémentaires des champs hors lien et des autres capacités permettent de vérifier précisément les deux seules modifications métier attendues.

## Fichiers du ticket

- `backend/migrations/Version20260926110000.php` : nouvelle migration.
- `backend/tools/test-gem-flight-scenarios.php` : tests ciblés.
- `backend/tools/test-spell-slot-synchronization.php` : une inclusion supplémentaire uniquement pour ce ticket.
- `backend/docs/gem-flight-resource-audit.md` : présent rapport.

Les migrations et scénarios précédents ne sont pas modifiés. Aucun changement de JSON/importer, import, nettoyage historique, correction d'Escroc arcanique ou de descriptions/placeholders. Aucun commit, push ou déploiement.
