# Ré-audit ciblé des ressources — 26 septembre 2026

## Conclusion

**Oui : les huit anomalies fonctionnelles certaines de ressources identifiées le 25 septembre sont toutes corrigées dans la base actuelle et dans les résolutions rejouées.**

**Oui : il existe encore des anomalies fonctionnelles certaines de couverture des ressources actuelles.** Trois constats supplémentaires portent sur trois compteurs raciaux déjà modélisés : souffle draconique FTD, protection chromatique FTD et souffle métallique FTD. Ils concernent cinq attributions canoniques. Les compteurs restent accessibles aux anciennes variantes, mais sont absents des nouvelles races correspondantes.

Bilan de cette passe : **60 ressources auditées ; 8/8 anciens cas corrigés ; 3 nouveaux constats CERTAINS ; 7 constats PROBABLES de fournisseurs redondants ; 4 points À VÉRIFIER MANUELLEMENT.** Les sept probables reprennent des coexistences déjà signalées ; ils ne sont pas sept nouvelles pannes de compteur. Les comptages sont par sujet/ressource, pas par ligne d'attribution.

**Aucune des 60 ressources n'est totalement sans chemin runtime.** Cela ne signifie pas que toutes les races qui devraient accéder à un compteur y accèdent. Les trois nouveaux constats sont précisément des défauts de couverture, pas trois ressources globalement inaccessibles.

Audit strictement diagnostique : aucun correctif, migration, import, changement de données métier, JSON/importer, description ou suppression historique. Seul ce rapport est créé.

## État réel et méthode

- Dépôt propre à l'entrée, commit préexistant `50f836f` (`docs: complete gem flight resource audit`).
- Dernière migration appliquée : `Version20260926110000`. 47 exécutées, 47 disponibles, 0 nouvelle, 0 indisponible. Les quatre migrations de correction restent en place et n'ont pas été réappliquées à la base persistante.
- PostgreSQL : **60 TrackableResourceDefinition**, **104 TrackableResourceRules**, **73 capacités liées** à une ressource, **78 attributions** portant sur ces fournisseurs. Répartition : 42 maximums fixes, 9 selon le bonus de maîtrise, 9 selon une caractéristique.
- Lecture actuelle de toutes les définitions, paramètres, fournisseurs, attributions et origines. Les anciens rapports servent uniquement à identifier les anciens cas, pas à démontrer leur correction.
- **343 résolutions** par les vrais `CharacterFeatureResolver` et `CharacterResourceResolver`, connectés aux repositories et données actuels. Personnages, races choisies, dons et progressions attribués uniquement en mémoire ; transaction PostgreSQL `READ ONLY`, aucun persist/flush. Aucun resolver remplacé par un calcul de simulation.
- Contextes construits pour chaque origine actuellement liée : avant acquisition lorsqu'applicable, niveau de chaque palier, niveau 20, mélange 5 niveaux de l'origine + 5 d'une autre classe, scores de caractéristiques 16 puis 8. Le paladin est sondé à tous les niveaux 1–20. Les trois progressions sont sondées sans contexte, juste sous/au-dessus et au seuil, sans assimiler leur `unlock_level=1` technique à un seuil.
- **60 cycles** synchronisation à zéro / repos court / repos long : un témoin accessible pour chaque ressource ; valeurs et caractéristiques propres au témoin. Zéro écart entre le cycle observé et le type de recharge configuré.
- **9 probes complémentaires** : cinq variantes raciales au niveau 5, trois sous-classes à fournisseurs multiples au niveau 20, clerc Crépuscule 6 / guerrier 5.
- **118 contrôles explicites réussis** sur les maxima demandés et l'absence des compteurs spécifiques dans les autres domaines sondés.
- Recherche des capacités attribuées sans lien par identité de slug/nom, puis examen des textes et origines. Les collisions lexicales telles que `ki` dans `skill` sont écartées. Une absence d'automatisation sans compteur modélisé n'est pas un défaut déduit.
- Aucun recours à une intuition D&D ou à une nouvelle source Internet. Les textes génériques/absents limitent la certification mécanique : les paramètres observés ne sont pas présentés comme une validation exhaustive des règles officielles.
- Les emplacements standards `spell-slot-N` sont calculés séparément et ne sont pas comptés parmi les 60 définitions. Leur cycle et la magie flexible restent couverts par le harnais. Pact Magic est bien la ressource distincte `warlock-pact-slots`. Escroc arcanique reste hors périmètre.

## Revalidation des huit anciennes anomalies

SAG 16 = modificateur +3 ; BM = bonus de maîtrise sur niveau total.

| Cas | État avant | État actuel | Résolution runtime actuelle | Corrigé ? | Anomalie restante sur ce cas ? |
|---|---|---|---|---|---|
| Fougue | Maximum 1 au niveau 17 malgré le texte | Fournisseur `fougue`, règle guerrier 17 → 2 | Guerrier 16 → 1 ; 17 → 2 ; 20 → 2 | Oui | Non |
| Imposition des mains | Fournisseur historique non attribué ; base 5 sans paliers | `lay-on-hands` attribuée paladin 1 ; ancien lien NULL ; 19 overrides 2–20 | Paladin 1 → 5 ; 5 → 25 ; 20 → 100 ; paladin 5 / guerrier 5 → 25 | Oui | Non |
| Lueur protectrice | Ancien fournisseur non attribué | `light-domain-warding-flare`, Lumière 1 ; ancien lien NULL | Clerc Lumière 1 → 3 ; autre domaine → absent ; SAG 8 → 1 | Oui | Non |
| Regard de la tombe | Ancien fournisseur non attribué | `grave-domain-eyes-of-the-grave`, Tombe 1 ; ancien lien NULL | Clerc Tombe 1 → 3 ; autre domaine → absent ; SAG 8 → 1 | Oui | Non |
| Sentinelle aux portes de la mort | Ancien fournisseur non attribué | `grave-domain-sentinel-at-death-s-door`, Tombe 6 ; ancien lien NULL | Tombe 5 → absent ; 6 → 3 ; autre domaine → absent | Oui | Non |
| Colère de l'orage | Ancien fournisseur non attribué | `tempest-domain-wrath-of-the-storm`, Tempête 1 ; ancien lien NULL | Tempête 1 → 3 ; autre domaine → absent ; SAG 8 → 1 | Oui | Non |
| Pas de la nuit | Ancien fournisseur non attribué | `twilight-domain-steps-of-night`, Crépuscule 6 ; ancien lien NULL | Crépuscule 5 → absent ; 6 → 3 ; 20 → 6 ; clerc 6 / guerrier 5 → 4 ; autre domaine → absent | Oui | Non |
| Vol diamantin / cristallin | Ancien `gem-flight` non attribué ; canonique sans compteur | Fournisseur unique `racial-dragonborn-gem-ftd-gem-flight-5` ; ancien lien NULL | `dragonborn-gem-ftd`, total 4 → absent ; total 5 → 1 | Oui | Non |

Les autres domaines sondés incluent Vie, Lumière, Tombe, Tempête et Crépuscule : une ressource propre à un domaine n'est pas présente dans les quatre autres. Les règles raciales utilisent le niveau total ; les règles de classe/sous-classe utilisent le niveau dans la classe parente. Les anciens liens détachés ont été relus directement en SQL ; leur absence n'est pas déduite du seul passage d'un test de migration.

Pour les cinq compteurs de domaines et Vol cristallin : synchronisation à 0 → 0, repos court → 0, repos long → maximum. Fougue se recharge au repos court ou long ; Imposition des mains seulement au repos long. Les contrôles d'activation/passive ou descriptions hors périmètre ne sont pas silencieusement corrigés.

## Nouveaux constats CERTAINS : couverture raciale incomplète

Le caractère certain porte sur l'absence runtime d'un compteur **déjà modélisé pour la même aptitude**, avec un texte local explicite et concordant. Il ne découle pas de l'existence d'une aptitude D&D non automatisée. Les anciennes variantes restent sélectionnables, les nouvelles races FTD aussi ; ce sont des racines distinctes, sans héritage donnant accès aux compteurs historiques.

| Réf. | Compteur | Aptitude(s) canonique(s) active(s), sans ressource | Preuve actuelle |
|---|---|---|---|
| C1 | #57 `breath-weapon-uses` | #923 `racial-dragonborn-chromatic-ftd-breath-weapon-2`, #927 `racial-dragonborn-gem-ftd-breath-weapon-2`, #932 `racial-dragonborn-metallic-ftd-breath-weapon-2` | Règles #984/#988/#993 dès niveau 1. Textes : souffle remplaçant une attaque, usages = BM, repos long. Définition #57 identique sur ces paramètres. Resolver : absent pour les trois races FTD aux niveaux 1/5/20 ; présent pour les anciennes variantes (niveau 5 → 3). |
| C2 | #58 `chromatic-warding-use` | #925 `racial-dragonborn-chromatic-ftd-chromatic-warding-4` | Règle #986, race `dragonborn-chromatic-ftd`, niveau 5. Textes ancien/canonique : immunité chromatique, une utilisation par repos long. Resolver : capacité présente mais compteur absent au niveau 5 et 20 ; ancienne `chromatic-dragonborn-red` niveau 5 → 1. |
| C3 | #60 `metallic-breath-weapon-use` | #934 `racial-dragonborn-metallic-ftd-metallic-breath-weapon-4` | Règle #995, race `dragonborn-metallic-ftd`, niveau 5. Textes ancien/canonique : souffle spécial affaiblissant/répulsif, une utilisation par repos long. Resolver : capacité présente mais compteur absent au niveau 5 et 20 ; ancienne `metallic-dragonborn-silver` niveau 5 → 1. |

La preuve fonctionnelle additionnelle au niveau 5 donne :

| Race | Capacités ciblées actives | Compteurs ciblés résolus |
|---|---|---|
| `chromatic-dragonborn-red` | `breath-weapon`, `chromatic-warding` | souffle 3, protection 1 |
| `metallic-dragonborn-silver` | `breath-weapon`, `metallic-breath-weapon` | souffle 3, souffle spécial 1 |
| `dragonborn-chromatic-ftd` | souffle canonique, protection canonique | Aucun des deux |
| `dragonborn-gem-ftd` | souffle canonique, vol canonique | vol 1, souffle absent |
| `dragonborn-metallic-ftd` | souffle canonique, souffle spécial canonique | Aucun des deux |

Le souffle PHB n'est pas assimilé à C1 : son texte utilise une action et une recharge courte/longue, donc il ne correspond pas au compteur FTD actuel. C1 contient trois attributions manquantes d'une même ressource ; C2 et C3 une chacune. **3 constats / 5 attributions canoniques**, sans double comptage.

Les fournisseurs historiques de ces trois compteurs sont encore attribués : ce diagnostic **n'autorise pas** à reproduire aveuglément la migration de Vol cristallin en les détachant. Aucun modèle de réparation, partage nouveau ou suppression n'est appliqué ici.

## Constats PROBABLES : sept paires de fournisseurs redondants

Deux capacités distinctes sont effectivement actives dans chaque paire ci-dessous, pour la même sous-classe et le même niveau. Une redondance de catalogue est probable ; le dédoublement du compteur est, lui, **écarté** : le resolver indexe les définitions par slug et retourne une seule ressource, sans addition des maximums de fournisseurs. Aucun consommateur historique inactif n'est pris à tort pour un fournisseur actif.

| Réf. | Ressource | Fournisseurs simultanés | Origine / acquisition | Maximum observé au niveau 20, scores 16 |
|---|---|---|---|---|
| P1 | `bottled-respite-uses` | `bottled-respite` + `genie-bottled-respite` | warlock/genie 1 | 1 |
| P2 | `elemental-gift-flight-uses` | `elemental-gift-flight` + `genie-elemental-gift` | warlock/genie 6 | 6 |
| P3 | `form-of-dread-uses` | `form-of-dread` + `undead-form-of-dread` | warlock/undead 1 | 6 |
| P4 | `spirit-projection-use` | `spirit-projection` + `undead-spirit-projection` | warlock/undead 14 | 1 |
| P5 | `unleash-incarnation-uses` | `unleash-incarnation` + `echo-knight-unleash-incarnation` | fighter/echo-knight 3 | 3 |
| P6 | `shadow-martyr-use` | `shadow-martyr` + `echo-knight-shadow-martyr` | fighter/echo-knight 10 | 1 |
| P7 | `reclaim-potential-uses` | `reclaim-potential` + `echo-knight-reclaim-potential` | fighter/echo-knight 15 | 3 |

Conduit divin possède aussi plusieurs fournisseurs, mais `channel-divinity` et `turn-undead` décrivent un compteur partagé et un de ses usages : ce n'est pas assimilé à ces sept paires. Une règle directe de ressource et une capacité donnant le même compteur sont également dédupliquées, et ne constituent pas automatiquement un conflit.

## À VÉRIFIER MANUELLEMENT : quatre sujets

| Réf. | Sujet | Observation certaine | Pourquoi pas une anomalie fonctionnelle certaine |
|---|---|---|---|
| M1 | `utilisation-de-inflexible` | Maximum 1 aux niveaux guerrier 9/13/17/20 ; attributions aux niveaux 9/13/17, aucun override | Texte local générique : les paliers seuls ne prouvent pas que le nombre d'usages doit évoluer. Aucune cible D&D inventée. |
| M2 | `cleric-channel-divinity` | Maximum 1 aux niveaux clerc 2/6/18/20 ; plusieurs paliers de capacité, aucun override | Les textes locaux ne chiffrent pas les augmentations d'utilisations. Le fait que le compteur reste 1 est démontré, pas sa non-conformité aux règles externes. |
| M3 | `bardic-inspiration-uses` | Maximum = mod. CHA minimum 1 ; recharge courte dès barde 1, sans changement au niveau 5 ; paliers de capacité 1/5/10/15 | Les textes locaux ne détaillent pas les effets de ces paliers ni Source d'inspiration. Aucun manque d'override de quantité ne doit être déduit automatiquement. La condition éventuelle de recharge doit être vérifiée séparément. |
| M4 | Souffle PHB `racial-dragonborn-phb-breath-weapon-4` | Capacité active sans compteur dédié ; texte : recharge courte ou longue. Le compteur #57 est configuré BM/repos long | Il s'agit d'une autre version de l'aptitude. L'absence d'une automatisation dédiée n'est pas une anomalie certaine du compteur FTD ; aucun partage ni ajout de ressource n'est déduit. |

Les descriptions absentes/génériques et coquilles de noms/slugs ne sont pas recomptées comme anomalies mécaniques. La récupération arcanique est un compteur d'utilisation, pas un pool égal au total de niveaux d'emplacements récupérables : son maximum 1 ne constitue pas à lui seul une contradiction. Le compteur de progression `entite-symbiotique` n'est pas assimilé à l'aptitude homonyme du druide. Conduit divin du paladin n'est pas arbitrairement fusionné avec celui du clerc.

## Fournisseurs historiques et accessibilité

Les sept définitions transférées sont toujours présentes avec lien ressource NULL et **zéro attribution** : `imposition-des-mains`, `warding-flare`, `eyes-of-the-grave`, `sentinel-at-deaths-door`, `wrath-of-the-storm`, `steps-of-night`, `gem-flight`. Elles ne fournissent plus les compteurs corrigés et ne sont pas nécessaires à leur runtime.

Cinq anciens fournisseurs conservent un lien au compteur commun `cleric-channel-divinity` mais n'ont aucune attribution : `radiance-of-the-dawn`, `preserve-life`, `path-to-the-grave`, `destructive-wrath`, `twilight-sanctuary`. Ils n'activent aucune ressource ; l'accès réel passe par les attributions de clerc. Leur présence n'est pas une anomalie et aucune suppression n'est proposée.

Les trois ressources de progression sont accessibles avec attribution explicite et contexte suffisant, absentes sans contexte ou sous leur seuil. Aucune session implicite n'est chargée. Les 60 ressources possèdent chacune au moins un témoin runtime actuel ; zéro ressource globalement inaccessible, mais C1–C3 restent absentes des cinq chemins canoniques décrits plus haut.

## Intégrité

- **54 FK** de `public` contrôlées par anti-jointures : zéro orphelin ; zéro FK non validée.
- Zéro règle de ressource à origine nulle/multiple, niveau hors 1–20, bonus négatif ou override négatif.
- Zéro règle de capacité à origine nulle/multiple, niveau ordinaire invalide, seuil de progression absent ou contaminant une autre origine ; zéro seuil dépassant un maximum de progression.
- Zéro configuration de maximum/recharge inconnue, scaling de caractéristique manquant ou paramètre négatif.
- Zéro cycle dans l'héritage racial ; zéro niveau de personnage rattaché à une sous-classe d'une autre classe.
- Aucun palier de ressource lié à une sous-classe avant son niveau de sélection de classe ; zéro paire de règles de ressource de même origine et même niveau.
- Les variantes homonymes `wild-magic` sont distinguées par leur classe parente (barbare / ensorceleur), pas fusionnées par slug seul.
- Aucun maximum négatif dans les probes. Le don `metamagic-adept` est bien une origine autonome accordant le bonus 2 de sorcellerie, pas une règle sans classe donc inatteignable.
- Pas de preuve d'une règle de ressource manifestement inatteignable dans le périmètre sondé. Les valeurs configurées ne sont pas une certification externe de toutes les mécaniques D&D.

## Tests et contrôles exécutés

| Contrôle | Résultat actuel |
|---|---|
| Probes sur la BDD actuelle | 343 résolutions, 60 ressources atteintes, 60 cycles conformes, 9 probes complémentaires |
| Vérifications explicites des anciens cas | 118 contrôles réussis |
| Harnais ressources/synchronisation | **919 assertions OK** |
| Dont Fougue | **53 OK** |
| Dont Imposition des mains | **78 OK** |
| Dont domaines de clerc | **237 OK** |
| Dont Vol cristallin | **140 OK** |
| Sécurité / Présage | **256 assertions OK** |
| `doctrine:migrations:status` | 47 exécutées, dernière `Version20260926110000`, aucune en attente |
| `lint:container` | OK |
| `doctrine:schema:validate` | Mapping et schéma synchronisés |
| `git diff --check` et contrôle du nouveau fichier | OK |

Les harnais utilisent leurs tables/séquences temporaires et leur rollback. Ils peuvent y exécuter les migrations pour tester les garde-fous ; aucune migration n'est créée ni appliquée à la base persistante par cet audit. Le harnais ne teste pas tous les chemins raciaux FTD de C1–C3 : son passage au vert n'invalide pas ces probes actuelles.

## Inventaire exhaustif des 60 ressources

Les maximums observés ci-dessous sont un ensemble de résultats des 343 probes, pas une plage normative de tous les personnages possibles. Les autres ressources de classe présentes sur un personnage multiclassé sont conservées dans les sorties de preuve. Les neuf caractéristiques/PB et les minimums sont testés par scores 16/8 et niveaux variables.

Abréviations : BM = bonus de maîtrise, mod. = modificateur effectif. En présence d'un override applicable, le resolver retient celui du plus grand niveau puis ajoute les bonus des règles applicables. Sinon : max(minimum, formule de base), puis bonus. Repos `short-rest` restaure au court **et** au long ; `long-rest` au long seulement.

| ID / ressource | Type ; base ; min ; multiplicateur ; caractéristique | Recharge | Maximums réellement observés | Cycle témoin sync zéro / court / long |
|---|---|---|---|---|
| #1 `rage` | fixed ; 2 ; 0 ; 1 ; — | long-rest | 2, 3, 4, 5, 6 | 0 / 0 / 2 |
| #2 `des-de-presage` | fixed ; 2 ; 0 ; 1 ; — | long-rest | 2, 3 | 0 / 0 / 2 |
| #3 `utilisations-de-conscience-magique` | proficiency-bonus ; 0 ; 0 ; 1 ; — | long-rest | 2, 3, 4, 6 | 0 / 0 / 2 |
| #4 `utilisations-de-magie-galvanisante` | proficiency-bonus ; 0 ; 0 ; 1 ; — | long-rest | 3, 6 | 0 / 0 / 3 |
| #5 `arcane-recovery` | fixed ; 1 ; 1 ; 1 ; — | long-rest | 1 | 0 / 0 / 1 |
| #6 `utiisation-du-troisieme-oeil` | fixed ; 1 ; 1 ; 1 ; — | short-rest | 1 | 0 / 1 / 1 |
| #7 `utilisation-de-second-souffle` | fixed ; 1 ; 1 ; 1 ; — | short-rest | 1 | 0 / 1 / 1 |
| #8 `utilisation-de-fougue` | fixed ; 1 ; 1 ; 1 ; — | short-rest | 1, 2 | 0 / 1 / 1 |
| #9 `utilisation-de-inflexible` | fixed ; 1 ; 1 ; 1 ; — | long-rest | 1 | 0 / 0 / 1 |
| #10 `utilisations-d-imposition-des-mains` | fixed ; 5 ; 5 ; 1 ; — | long-rest | 5, 10, 15, 20, 25, 30, 35, 40, 45, 50, 55, 60, 65, 70, 75, 80, 85, 90, 95, 100 | 0 / 0 / 25 |
| #11 `sorcery-points` | fixed ; 0 ; 0 ; 1 ; — | long-rest | 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20 | 0 / 0 / 2 |
| #12 `tides-of-chaos-use` | fixed ; 1 ; 1 ; 1 ; — | long-rest | 1 | 0 / 0 / 1 |
| #13 `favored-by-the-gods-use` | fixed ; 1 ; 1 ; 1 ; — | short-rest | 1 | 0 / 1 / 1 |
| #14 `unearthly-recovery-use` | fixed ; 1 ; 1 ; 1 ; — | long-rest | 1 | 0 / 0 / 1 |
| #15 `cleric-channel-divinity` | fixed ; 1 ; 1 ; 1 ; — | short-rest | 1 | 0 / 1 / 1 |
| #16 `warding-flare-uses` | ability-modifier ; 0 ; 1 ; 1 ; wisdom | long-rest | 1, 3 | 0 / 0 / 3 |
| #17 `eyes-of-the-grave-uses` | ability-modifier ; 0 ; 1 ; 1 ; wisdom | long-rest | 1, 3 | 0 / 0 / 3 |
| #18 `sentinel-at-deaths-door-uses` | ability-modifier ; 0 ; 1 ; 1 ; wisdom | long-rest | 1, 3 | 0 / 0 / 3 |
| #19 `wrath-of-the-storm-uses` | ability-modifier ; 0 ; 1 ; 1 ; wisdom | long-rest | 1, 3 | 0 / 0 / 3 |
| #20 `steps-of-night-uses` | proficiency-bonus ; 0 ; 1 ; 1 ; — | long-rest | 3, 6 | 0 / 0 / 3 |
| #21 `warlock-pact-slots` | fixed ; 1 ; 1 ; 1 ; — | short-rest | 1, 2, 3, 4 | 0 / 1 / 1 |
| #22 `dark-ones-own-luck-use` | fixed ; 1 ; 1 ; 1 ; — | short-rest | 1 | 0 / 1 / 1 |
| #23 `hurl-through-hell-use` | fixed ; 1 ; 1 ; 1 ; — | long-rest | 1 | 0 / 0 / 1 |
| #24 `bottled-respite-uses` | fixed ; 1 ; 1 ; 1 ; — | long-rest | 1 | 0 / 0 / 1 |
| #25 `elemental-gift-flight-uses` | proficiency-bonus ; 0 ; 1 ; 1 ; — | long-rest | 3, 6 | 0 / 0 / 3 |
| #26 `form-of-dread-uses` | proficiency-bonus ; 0 ; 1 ; 1 ; — | long-rest | 2, 4, 5, 6 | 0 / 0 / 2 |
| #27 `spirit-projection-use` | fixed ; 1 ; 1 ; 1 ; — | long-rest | 1 | 0 / 0 / 1 |
| #28 `hexblades-curse-use` | fixed ; 1 ; 1 ; 1 ; — | short-rest | 1 | 0 / 1 / 1 |
| #29 `accursed-specter-use` | fixed ; 1 ; 1 ; 1 ; — | long-rest | 1 | 0 / 0 / 1 |
| #30 `mystic-arcanum-6-use` | fixed ; 1 ; 1 ; 1 ; — | long-rest | 1 | 0 / 0 / 1 |
| #31 `mystic-arcanum-7-use` | fixed ; 1 ; 1 ; 1 ; — | long-rest | 1 | 0 / 0 / 1 |
| #32 `mystic-arcanum-8-use` | fixed ; 1 ; 1 ; 1 ; — | long-rest | 1 | 0 / 0 / 1 |
| #33 `mystic-arcanum-9-use` | fixed ; 1 ; 1 ; 1 ; — | long-rest | 1 | 0 / 0 / 1 |
| #34 `eldritch-master-use` | fixed ; 1 ; 1 ; 1 ; — | long-rest | 1 | 0 / 0 / 1 |
| #35 `ki-points` | fixed ; 0 ; 0 ; 1 ; — | short-rest | 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20 | 0 / 2 / 2 |
| #36 `wholeness-of-body-use` | fixed ; 1 ; 1 ; 1 ; — | long-rest | 1 | 0 / 0 / 1 |
| #37 `hunters-sense-uses` | ability-modifier ; 0 ; 1 ; 1 ; wisdom | long-rest | 1, 3 | 0 / 0 / 3 |
| #38 `magic-users-nemesis-use` | fixed ; 1 ; 1 ; 1 ; — | short-rest | 1 | 0 / 1 / 1 |
| #39 `superiority-dice` | fixed ; 4 ; 4 ; 1 ; — | short-rest | 4, 5, 6 | 0 / 4 / 4 |
| #40 `unleash-incarnation-uses` | ability-modifier ; 0 ; 1 ; 1 ; constitution | long-rest | 1, 3 | 0 / 0 / 3 |
| #41 `shadow-martyr-use` | fixed ; 1 ; 1 ; 1 ; — | short-rest | 1 | 0 / 1 / 1 |
| #42 `reclaim-potential-uses` | ability-modifier ; 0 ; 1 ; 1 ; constitution | long-rest | 1, 3 | 0 / 0 / 3 |
| #43 `wild-shape-uses` | fixed ; 2 ; 2 ; 1 ; — | short-rest | 2 | 0 / 2 / 2 |
| #44 `spirit-totem-use` | fixed ; 1 ; 1 ; 1 ; — | short-rest | 1 | 0 / 1 / 1 |
| #45 `faithful-summons-use` | fixed ; 1 ; 1 ; 1 ; — | long-rest | 1 | 0 / 0 / 1 |
| #46 `bardic-inspiration-uses` | ability-modifier ; 0 ; 1 ; 1 ; charisma | short-rest | 1, 3 | 0 / 3 / 3 |
| #47 `universal-speech-use` | fixed ; 1 ; 1 ; 1 ; — | long-rest | 1 | 0 / 0 / 1 |
| #48 `infectious-inspiration-uses` | ability-modifier ; 0 ; 1 ; 1 ; charisma | long-rest | 1, 3 | 0 / 0 / 3 |
| #49 `enthralling-performance-use` | fixed ; 1 ; 1 ; 1 ; — | short-rest | 1 | 0 / 1 / 1 |
| #50 `mantle-of-majesty-use` | fixed ; 1 ; 1 ; 1 ; — | long-rest | 1 | 0 / 0 / 1 |
| #51 `unbreakable-majesty-use` | fixed ; 1 ; 1 ; 1 ; — | short-rest | 1 | 0 / 1 / 1 |
| #52 `utilisation-de-soins-draconiques` | fixed ; 1 ; 1 ; 1 ; — | long-rest | 1 | 0 / 0 / 1 |
| #53 `utilisations-d-ailes-protectrices` | proficiency-bonus ; 0 ; 0 ; 1 ; — | long-rest | 2, 4, 6 | 0 / 0 / 2 |
| #54 `enchevetrement` | proficiency-bonus ; 0 ; 0 ; 1 ; — | long-rest | 3 | 0 / 0 / 3 |
| #55 `entite-symbiotique` | fixed ; 2 ; 2 ; 1 ; — | long-rest | 2 | 0 / 0 / 2 |
| #56 `deplacement-eclair` | proficiency-bonus ; 0 ; 0 ; 1 ; — | long-rest | 3 | 0 / 0 / 3 |
| #57 `breath-weapon-uses` | proficiency-bonus ; 0 ; 1 ; 1 ; — | long-rest | 2, 3, 4, 6 | 0 / 0 / 2 |
| #58 `chromatic-warding-use` | fixed ; 1 ; 0 ; 1 ; — | long-rest | 1 | 0 / 0 / 1 |
| #59 `gem-flight-use` | fixed ; 1 ; 0 ; 1 ; — | long-rest | 1 | 0 / 0 / 1 |
| #60 `metallic-breath-weapon-use` | fixed ; 1 ; 0 ; 1 ; — | long-rest | 1 | 0 / 0 / 1 |

### Fournisseurs, acquisitions et règles de maximum

Toutes les relations ci-dessous proviennent de la base actuelle. « Historique sans attribution » signifie inactif dans le resolver, pas définition à supprimer. Les origines d'une règle de ressource donnent aussi accès au compteur indépendamment d'une capacité.

| Ressource | Capacités et attributions actuelles | TrackableResourceRules actuelles (ID, origine, override/bonus) |
|---|---|---|
| `rage` | `rage` : #10 barbarian niv.1 | #1 barbarian niv.1 : max=2, bonus 0<br>#2 barbarian niv.3 : max=3, bonus 0<br>#3 barbarian niv.6 : max=4, bonus 0<br>#4 barbarian niv.12 : max=5, bonus 0<br>#5 barbarian niv.17 : max=6, bonus 0 |
| `des-de-presage` | `divination-portent` : #743 wizard/divination niv.2 | #87 wizard/divination niv.14 : max=3, bonus 0 |
| `utilisations-de-conscience-magique` | `conscience-magique` : #14 barbarian/wild-magic niv.3 | Aucune |
| `utilisations-de-magie-galvanisante` | `magie-galvanisante` : #15 barbarian/wild-magic niv.6 | Aucune |
| `arcane-recovery` | `arcane-recovery` : #856 wizard niv.1 | #86 wizard niv.1 : sans override, bonus 0 |
| `utiisation-du-troisieme-oeil` | `divination-the-third-eye` : #745 wizard/divination niv.10 | Aucune |
| `utilisation-de-second-souffle` | `second-souffle` : #29 fighter niv.2 | Aucune |
| `utilisation-de-fougue` | `fougue` : #28 fighter niv.2; #871 fighter niv.17 | #88 fighter niv.17 : max=2, bonus 0 |
| `utilisation-de-inflexible` | `inflexible` : #26 fighter niv.9; #874 fighter niv.13; #875 fighter niv.17 | Aucune |
| `utilisations-d-imposition-des-mains` | `lay-on-hands` : #843 paladin niv.1 | #95 paladin niv.2 : max=10, bonus 0<br>#104 paladin niv.3 : max=15, bonus 0<br>#103 paladin niv.4 : max=20, bonus 0<br>#98 paladin niv.5 : max=25, bonus 0<br>#101 paladin niv.6 : max=30, bonus 0<br>#107 paladin niv.7 : max=35, bonus 0<br>#100 paladin niv.8 : max=40, bonus 0<br>#106 paladin niv.9 : max=45, bonus 0<br>#93 paladin niv.10 : max=50, bonus 0<br>#90 paladin niv.11 : max=55, bonus 0<br>#92 paladin niv.12 : max=60, bonus 0<br>#97 paladin niv.13 : max=65, bonus 0<br>#105 paladin niv.14 : max=70, bonus 0<br>#96 paladin niv.15 : max=75, bonus 0<br>#102 paladin niv.16 : max=80, bonus 0<br>#91 paladin niv.17 : max=85, bonus 0<br>#94 paladin niv.18 : max=90, bonus 0<br>#99 paladin niv.19 : max=95, bonus 0<br>#89 paladin niv.20 : max=100, bonus 0 |
| `sorcery-points` | `font-of-magic` : #39 sorcerer niv.2 | #85 don metamagic-adept total 1 : sans override, bonus 2<br>#6 sorcerer niv.2 : max=2, bonus 0<br>#7 sorcerer niv.3 : max=3, bonus 0<br>#8 sorcerer niv.4 : max=4, bonus 0<br>#9 sorcerer niv.5 : max=5, bonus 0<br>#10 sorcerer niv.6 : max=6, bonus 0<br>#11 sorcerer niv.7 : max=7, bonus 0<br>#12 sorcerer niv.8 : max=8, bonus 0<br>#13 sorcerer niv.9 : max=9, bonus 0<br>#14 sorcerer niv.10 : max=10, bonus 0<br>#15 sorcerer niv.11 : max=11, bonus 0<br>#16 sorcerer niv.12 : max=12, bonus 0<br>#17 sorcerer niv.13 : max=13, bonus 0<br>#18 sorcerer niv.14 : max=14, bonus 0<br>#19 sorcerer niv.15 : max=15, bonus 0<br>#20 sorcerer niv.16 : max=16, bonus 0<br>#21 sorcerer niv.17 : max=17, bonus 0<br>#22 sorcerer niv.18 : max=18, bonus 0<br>#23 sorcerer niv.19 : max=19, bonus 0<br>#24 sorcerer niv.20 : max=20, bonus 0 |
| `tides-of-chaos-use` | `tides-of-chaos` : #53 sorcerer/wild-magic niv.1 | #27 sorcerer/wild-magic niv.1 : max=1, bonus 0 |
| `favored-by-the-gods-use` | `favored-by-the-gods` : #48 sorcerer/divine-soul niv.1 | #26 sorcerer/divine-soul niv.1 : max=1, bonus 0 |
| `unearthly-recovery-use` | `unearthly-recovery` : #51 sorcerer/divine-soul niv.18 | #25 sorcerer/divine-soul niv.18 : max=1, bonus 0 |
| `cleric-channel-divinity` | `radiance-of-the-dawn` : historique sans attribution<br>`preserve-life` : historique sans attribution<br>`path-to-the-grave` : historique sans attribution<br>`destructive-wrath` : historique sans attribution<br>`twilight-sanctuary` : historique sans attribution<br>`channel-divinity` : #837 cleric niv.2; #865 cleric niv.6; #866 cleric niv.18<br>`turn-undead` : #838 cleric niv.2 | Aucune |
| `warding-flare-uses` | `light-domain-warding-flare` : #380 cleric/light-domain niv.1 | Aucune |
| `eyes-of-the-grave-uses` | `grave-domain-eyes-of-the-grave` : #365 cleric/grave-domain niv.1 | Aucune |
| `sentinel-at-deaths-door-uses` | `grave-domain-sentinel-at-death-s-door` : #368 cleric/grave-domain niv.6 | Aucune |
| `wrath-of-the-storm-uses` | `tempest-domain-wrath-of-the-storm` : #412 cleric/tempest-domain niv.1 | Aucune |
| `steps-of-night-uses` | `twilight-domain-steps-of-night` : #431 cleric/twilight-domain niv.6 | Aucune |
| `warlock-pact-slots` | `pact-magic` : #99 warlock niv.1 | #31 warlock niv.1 : max=1, bonus 0<br>#32 warlock niv.2 : max=2, bonus 0<br>#33 warlock niv.11 : max=3, bonus 0<br>#34 warlock niv.17 : max=4, bonus 0 |
| `dark-ones-own-luck-use` | `dark-ones-own-luck` : #108 warlock/fiend niv.6 | #40 warlock/fiend niv.6 : max=1, bonus 0 |
| `hurl-through-hell-use` | `hurl-through-hell` : #110 warlock/fiend niv.14 | #41 warlock/fiend niv.14 : max=1, bonus 0 |
| `bottled-respite-uses` | `bottled-respite` : #112 warlock/genie niv.1<br>`genie-bottled-respite` : #711 warlock/genie niv.1 | #42 warlock/genie niv.1 : max=1, bonus 0 |
| `elemental-gift-flight-uses` | `elemental-gift-flight` : #115 warlock/genie niv.6<br>`genie-elemental-gift` : #714 warlock/genie niv.6 | #43 warlock/genie niv.6 : sans override, bonus 0 |
| `form-of-dread-uses` | `form-of-dread` : #123 warlock/undead niv.1<br>`undead-form-of-dread` : #722 warlock/undead niv.1 | #46 warlock/undead niv.1 : sans override, bonus 0 |
| `spirit-projection-use` | `spirit-projection` : #126 warlock/undead niv.14<br>`undead-spirit-projection` : #725 warlock/undead niv.14 | #47 warlock/undead niv.14 : max=1, bonus 0 |
| `hexblades-curse-use` | `hexblades-curse` : #118 warlock/hexblade niv.1 | #44 warlock/hexblade niv.1 : max=1, bonus 0 |
| `accursed-specter-use` | `accursed-specter` : #120 warlock/hexblade niv.6 | #45 warlock/hexblade niv.6 : max=1, bonus 0 |
| `mystic-arcanum-6-use` | `mystic-arcanum-6` : #102 warlock niv.11 | #35 warlock niv.11 : max=1, bonus 0 |
| `mystic-arcanum-7-use` | `mystic-arcanum-7` : #103 warlock niv.13 | #36 warlock niv.13 : max=1, bonus 0 |
| `mystic-arcanum-8-use` | `mystic-arcanum-8` : #104 warlock niv.15 | #37 warlock niv.15 : max=1, bonus 0 |
| `mystic-arcanum-9-use` | `mystic-arcanum-9` : #105 warlock niv.17 | #38 warlock niv.17 : max=1, bonus 0 |
| `eldritch-master-use` | `eldritch-master` : #106 warlock niv.20 | #39 warlock niv.20 : max=1, bonus 0 |
| `ki-points` | `ki` : #129 monk niv.2 | #48 monk niv.2 : max=2, bonus 0<br>#49 monk niv.3 : max=3, bonus 0<br>#50 monk niv.4 : max=4, bonus 0<br>#51 monk niv.5 : max=5, bonus 0<br>#52 monk niv.6 : max=6, bonus 0<br>#53 monk niv.7 : max=7, bonus 0<br>#54 monk niv.8 : max=8, bonus 0<br>#55 monk niv.9 : max=9, bonus 0<br>#56 monk niv.10 : max=10, bonus 0<br>#57 monk niv.11 : max=11, bonus 0<br>#58 monk niv.12 : max=12, bonus 0<br>#59 monk niv.13 : max=13, bonus 0<br>#60 monk niv.14 : max=14, bonus 0<br>#61 monk niv.15 : max=15, bonus 0<br>#62 monk niv.16 : max=16, bonus 0<br>#63 monk niv.17 : max=17, bonus 0<br>#64 monk niv.18 : max=18, bonus 0<br>#65 monk niv.19 : max=19, bonus 0<br>#66 monk niv.20 : max=20, bonus 0 |
| `wholeness-of-body-use` | `wholeness-of-body` : #239 monk/open-hand niv.6 | #84 monk/open-hand niv.6 : max=1, bonus 0 |
| `hunters-sense-uses` | `hunters-sense` : #231 ranger/monster-slayer niv.3 | #82 ranger/monster-slayer niv.3 : sans override, bonus 0 |
| `magic-users-nemesis-use` | `magic-users-nemesis` : #228 ranger/monster-slayer niv.11 | #83 ranger/monster-slayer niv.11 : max=1, bonus 0 |
| `superiority-dice` | `combat-superiority` : #202 fighter/battle-master niv.3 | #72 fighter/battle-master niv.3 : max=4, bonus 0<br>#73 fighter/battle-master niv.7 : max=5, bonus 0<br>#74 fighter/battle-master niv.15 : max=6, bonus 0 |
| `unleash-incarnation-uses` | `unleash-incarnation` : #194 fighter/echo-knight niv.3<br>`echo-knight-unleash-incarnation` : #495 fighter/echo-knight niv.3 | #69 fighter/echo-knight niv.3 : sans override, bonus 0 |
| `shadow-martyr-use` | `shadow-martyr` : #192 fighter/echo-knight niv.10<br>`echo-knight-shadow-martyr` : #497 fighter/echo-knight niv.10 | #70 fighter/echo-knight niv.10 : max=1, bonus 0 |
| `reclaim-potential-uses` | `reclaim-potential` : #191 fighter/echo-knight niv.15<br>`echo-knight-reclaim-potential` : #498 fighter/echo-knight niv.15 | #71 fighter/echo-knight niv.15 : sans override, bonus 0 |
| `wild-shape-uses` | `wild-shape` : #172 druid niv.2 | #67 druid niv.2 : max=2, bonus 0 |
| `spirit-totem-use` | `spirit-totem` : #216 druid/circle-of-the-shepherd niv.2 | #75 druid/circle-of-the-shepherd niv.2 : max=1, bonus 0 |
| `faithful-summons-use` | `faithful-summons` : #213 druid/circle-of-the-shepherd niv.14 | #76 druid/circle-of-the-shepherd niv.14 : max=1, bonus 0 |
| `bardic-inspiration-uses` | `bardic-inspiration` : #179 bard niv.1; #859 bard niv.5; #860 bard niv.10; #861 bard niv.15 | #68 bard niv.1 : sans override, bonus 0 |
| `universal-speech-use` | `universal-speech` : #223 bard/college-of-eloquence niv.6 | #80 bard/college-of-eloquence niv.6 : max=1, bonus 0 |
| `infectious-inspiration-uses` | `infectious-inspiration` : #222 bard/college-of-eloquence niv.14 | #81 bard/college-of-eloquence niv.14 : sans override, bonus 0 |
| `enthralling-performance-use` | `enthralling-performance` : #220 bard/college-of-glamour niv.3 | #77 bard/college-of-glamour niv.3 : max=1, bonus 0 |
| `mantle-of-majesty-use` | `mantle-of-majesty` : #219 bard/college-of-glamour niv.6 | #78 bard/college-of-glamour niv.6 : max=1, bonus 0 |
| `unbreakable-majesty-use` | `unbreakable-majesty` : #218 bard/college-of-glamour niv.14 | #79 bard/college-of-glamour niv.14 : max=1, bonus 0 |
| `utilisation-de-soins-draconiques` | `soins-draconiques` : #241 don gift-of-the-metallic-dragon total 1 | Aucune |
| `utilisations-d-ailes-protectrices` | `ailes-protectrices` : #242 don gift-of-the-metallic-dragon total 1 | Aucune |
| `enchevetrement` | `vignes-de-venlee` : #243 progression corruption-draconique seuil 10 | Aucune |
| `entite-symbiotique` | `entite-symbiotique` : #244 progression infestation-fongique seuil 15 | Aucune |
| `deplacement-eclair` | `deplacement-eclair` : #245 progression bombe-electrique seuil 50 | Aucune |
| `breath-weapon-uses` | `breath-weapon` : #247 chromatic-dragonborn-red total 1; #248 dragonborn total 1; #253 metallic-dragonborn-silver total 1 | Aucune |
| `chromatic-warding-use` | `chromatic-warding` : #255 chromatic-dragonborn-red total 5 | Aucune |
| `gem-flight-use` | `racial-dragonborn-gem-ftd-gem-flight-5` : #991 dragonborn-gem-ftd total 5 | Aucune |
| `metallic-breath-weapon-use` | `metallic-breath-weapon` : #256 metallic-dragonborn-silver total 5 | Aucune |

## Empreintes avant/après

**38/38 tables identiques**, nombres de lignes et empreintes MD5 de toutes les lignes triées. La table de suivi Doctrine est elle aussi inchangée. Aucun personnage, état de session, token, consommation, paramètre ou attribution n'a été modifié. Les empreintes sont un contrôle de non-mutation de cet audit, pas un snapshot SQL.

| Table | Lignes avant = après | Empreinte avant = après |
|---|---:|---|
| `campaign` | 2 | `9707cec75e27ee685876658736ba8abb` |
| `campaign_figure` | 13 | `90f54ebe0200e6ec3f888f039c7bd331` |
| `character` | 9 | `376c41b59bc6f477bd0d3a7e7f19e44e` |
| `character_ability_adjustment` | 17 | `8125ce17d49bf32d9c43b15f9e546bae` |
| `character_ability_score` | 54 | `b7e144a7e86ccd959d1cfc3226f888ff` |
| `character_action_class_rule` | 9 | `8c707e4289521be18f8052aaec69138c` |
| `character_action_definition` | 4 | `05ca701ceac3666824b5d951510b4782` |
| `character_active_effect` | 0 | `d41d8cd98f00b204e9800998ecf8427e` |
| `character_class` | 13 | `d07e18c45b64347a35ae6774300dacbd` |
| `character_class_level` | 103 | `1da63241ac70221991ae3f30cc182367` |
| `character_class_level_rule` | 68 | `7c364028be04c90693e7f6756c8bf11b` |
| `character_feat` | 11 | `e9f0bf9366d17b4c13c1a02667ab580f` |
| `character_feature_definition` | 1531 | `068f04bf5fe432a8dcc93a1527718045` |
| `character_feature_rule` | 1500 | `4552e38823dde96f98c4e2acd27add6c` |
| `character_magic_item` | 3 | `1285296ece7221cda7a8dd6fee4735b0` |
| `character_progression` | 3 | `7d809738397d8b803fe99f20a5de645a` |
| `character_race` | 160 | `ae6e7c3d46f0dbcba4640e225f6b0529` |
| `character_race_ability_choice` | 12 | `261158bf88f404853b78b9a4e71fdda7` |
| `character_session_state` | 15 | `d4f3677c4a06cb98e18791ab66663122` |
| `character_subclass` | 105 | `5c8ecb99a4a70336f0a787cd1b50304a` |
| `character_wallet` | 9 | `6367f42c0aa80d176c4bbf3d8215b091` |
| `doctrine_migration_versions` | 47 | `f0ae387b1620275afed2c5725623d91a` |
| `feat` | 83 | `ef105c10ca05d085d794473a69206033` |
| `game_session` | 5 | `de695a75b8e5bace335e92b2f5c2199b` |
| `magic_item` | 4 | `9bade96a27a17de358d2efbd5287d21f` |
| `magic_item_ability_effect` | 1 | `be916a6de92fd7808e22392dbeb19595` |
| `media` | 24 | `5c8265c866ae74c8e55b16447b899a2d` |
| `progression_adjustment_rule` | 0 | `d41d8cd98f00b204e9800998ecf8427e` |
| `progression_definition` | 3 | `021c6c4a16d07f0c3f1ced873d38ac44` |
| `progression_stage` | 17 | `d4603e852449b2eb8ada7d96f8706417` |
| `quest` | 10 | `a237785f4f399a05f1821f21213cdeae` |
| `race_ability_modifier` | 156 | `de2d45f9a5fc46eaf90c8474175c07e4` |
| `rest_request` | 18 | `50ca0ef92cf71b284a9027e51c9f91d6` |
| `tip` | 3 | `381d9615ee51799c8e83ffb9ec34dcb8` |
| `trackable_resource_definition` | 60 | `defd26ee02dc6840dceb33e8fafb6dfd` |
| `trackable_resource_rule` | 104 | `3869a7ff8f1bba0cf5472bad18ffe7db` |
| `user` | 1 | `e8b9d2f54308c45de5ba795780cec60e` |
| `weather` | 8 | `75211d4948bc75d749055db85b140572` |

## Reproduction des preuves

Depuis le conteneur backend (répertoire /app), exécuter le PHP ci-dessous via stdin : il lit les données courantes dans une transaction READ ONLY, construit les personnages en mémoire, appelle les vrais resolvers et termine par un rollback. Aucun identifiant de personnage existant ni token n'est utilisé. Les IDs d'origines sont lus dans la base pendant l'exécution ; les slugs des huit cas servent uniquement à sélectionner les résultats à examiner.

<details>
<summary>Probe PHP complète : inventaire runtime et cycles des 60 ressources</summary>

```php
<?php
require 'vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv('.env');
$kernel=new App\Kernel('dev',true);$kernel->boot();$registry=$kernel->getContainer()->get('doctrine');$em=$registry->getManager();$db=$em->getConnection();
$db->beginTransaction();$db->executeStatement('SET TRANSACTION READ ONLY');
$ability=new App\Service\CharacterAbilityCalculator();
$features=new App\Service\CharacterFeatureResolver(new App\Repository\CharacterFeatureRuleRepository($registry));
$resources=new App\Service\CharacterResourceResolver(new App\Repository\TrackableResourceRuleRepository($registry),$ability,$features);
$owner=(new App\Entity\User())->setEmail('audit@example.invalid')->setPassword('unused');
$campaign=new App\Entity\Campaign($owner,'audit','Audit');
$classes=[];foreach($em->getRepository(App\Entity\CharacterClass::class)->findAll() as $x)$classes[$x->getId()]=$x;
$subclasses=[];foreach($em->getRepository(App\Entity\CharacterSubclass::class)->findAll() as $x)$subclasses[$x->getId()]=$x;
$races=[];foreach($em->getRepository(App\Entity\CharacterRace::class)->findAll() as $x)$races[$x->getId()]=$x;
$feats=[];foreach($em->getRepository(App\Entity\Feat::class)->findAll() as $x)$feats[$x->getId()]=$x;
$progs=[];foreach($em->getRepository(App\Entity\ProgressionDefinition::class)->findAll() as $x)$progs[$x->getId()]=$x;
$fighter=array_values(array_filter($classes,fn($x)=>$x->getSlug()==='fighter'))[0];
$paladin=array_values(array_filter($classes,fn($x)=>$x->getSlug()==='paladin'))[0];
$origins=[];
$add=function($row)use(&$origins){
$kind=null;$id=null;foreach(['class'=>'character_class_id','subclass'=>'character_subclass_id','race'=>'character_race_id','feat'=>'feat_id','progression'=>'progression_definition_id'] as $k=>$col)if(($row[$col]??null)!==null){$kind=$k;$id=(int)$row[$col];break;}
if($kind===null)return;
$key="$kind/$id";$origins[$key]??=['kind'=>$kind,'id'=>$id,'levels'=>[1=>true,20=>true],'thresholds'=>[]];
if($kind==='progression'){$origins[$key]['thresholds'][(int)$row['progression_threshold']]=true;return;}
$n=(int)$row['unlock_level'];$origins[$key]['levels'][$n]=true;if($n>1)$origins[$key]['levels'][$n-1]=true;
};
foreach($db->fetchAllAssociative('SELECT fr.* FROM character_feature_rule fr JOIN character_feature_definition f ON f.id=fr.feature_definition_id WHERE f.resource_definition_id IS NOT NULL') as $r)$add($r);
foreach($db->fetchAllAssociative('SELECT * FROM trackable_resource_rule') as $r)$add($r);
foreach($races as $race)if(str_contains($race->getSlug(),'dragonborn'))$add(['character_race_id'=>$race->getId(),'unlock_level'=>5]);
$add(['character_subclass_id'=>$db->fetchOne("SELECT s.id FROM character_subclass s JOIN character_class c ON c.id=s.character_class_id WHERE s.slug='life-domain' AND c.slug='cleric'"),'unlock_level'=>6]);
$origins['class/'.$fighter->getId()]['levels'][16]=true;
$origins['class/'.$fighter->getId()]['levels'][17]=true;
$origins['class/'.$paladin->getId()]['levels'][5]=true;
$make=function($kind,$id,$level,$other=0,$score=16)use($classes,$subclasses,$races,$feats,$progs,$fighter,$paladin,$campaign){
$class=$fighter;$sub=null;$race=null;$feat=null;$prog=null;
if($kind==='class')$class=$classes[$id];
if($kind==='subclass'){$sub=$subclasses[$id];$class=$sub->getCharacterClass();}
if($kind==='race')$race=$races[$id];
if($kind==='feat')$feat=$feats[$id];
if($kind==='progression')$prog=$progs[$id];
$c=(new App\Entity\Character($campaign,'probe','Probe',App\Entity\Character::TYPE_PLAYER))->setRace($race);
foreach(App\Enum\Ability::cases() as $a)$c->getAbilityScore($a)->setBaseValue($score);
if($feat)$c->addFeat(new App\Entity\CharacterFeat($c,$feat));
if($prog)$c->addProgression(new App\Entity\CharacterProgression($c,$prog));
foreach([[$class,$level,$sub],[$class===$fighter?$paladin:$fighter,$other,null]] as [$cl,$n,$sc])for($i=0;$i<$n;$i++)$c->addClassLevel(new App\Entity\CharacterClassLevel($c,$cl,$c->getTotalLevel()+1,$sc,intdiv($cl->getHitDie(),2)+1,App\Enum\HitPointGainMethod::Average));
return $c;
};
$output=[];$witness=[];$count=0;
foreach($origins as $key=>$o){
$kind=$o['kind'];$id=$o['id'];
$label=match($kind){'class'=>$classes[$id]->getSlug(),'subclass'=>$subclasses[$id]->getCharacterClass()->getSlug().'/'.$subclasses[$id]->getSlug(),'race'=>$races[$id]->getSlug(),'feat'=>$feats[$id]->getSlug(),'progression'=>$progs[$id]->getSlug()};
$levels=array_keys($o['levels']);sort($levels);$recipes=[];
if($kind==='progression'){
$thresholds=array_keys($o['thresholds']);foreach($thresholds as $t)foreach([null,$t-1,$t,$t+1] as $value)$recipes[]=[5,0,16,$value];
}else{
foreach($levels as $n)$recipes[]=[$n,0,16,null];
$recipes[]=[5,5,16,null];$recipes[]=[20,0,8,null];
}
foreach($recipes as [$level,$mixed,$score,$value]){
$c=$make($kind,$id,$level,$mixed,$score);$context=$kind==='progression'&&$value!==null?[$progs[$id]->getSlug()=>$value]:[];
$max=[];foreach($resources->resolve($c,$context) as $slug=>$r){$max[$slug]=$r->getMaximum();if(!isset($witness[$slug])&&$score===16)$witness[$slug]=[$kind,$id,$level,$mixed,$score,$value];}
ksort($max);$output[]=['origin'=>$label,'kind'=>$kind,'id'=>$id,'level'=>$level,'other'=>$mixed,'score'=>$score,'value'=>$value,'max'=>$max];$count++;
}
}
echo json_encode(['type'=>'resolution','count'=>$count,'witness'=>$witness,'cases'=>$output],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n";
$hp=new App\Service\CharacterHitPointCalculator($ability);$hpState=new App\Service\CharacterHitPointStateService($hp);$slots=new App\Service\CharacterSpellSlotCalculator();
$sync=new App\Service\CharacterSessionStateSynchronizer($hp,$hpState,$resources,new App\Repository\CharacterSessionStateRepository($registry),new App\Service\CharacterSpellSlotStateService($slots));
$rest=new App\Service\CharacterRestService($hp,$hpState,$resources,$sync,$slots,new App\Repository\TrackableResourceDefinitionRepository($registry),$ability,new App\Repository\CharacterActiveEffectRepository($registry),new App\Service\CharacterActiveEffectService($hpState),$em);
$game=new App\Entity\GameSession($campaign,'probe','Probe');$cycles=[];
$get=static function($s,$slug){foreach($s['resources']??[] as $r)if($r['id']===$slug)return $r['currentValue'];return null;};
foreach($witness as $slug=>[$kind,$id,$level,$mixed,$score,$value]){
$c=$make($kind,$id,$level,$mixed,$score);$p=$kind==='progression'?[['id'=>$progs[$id]->getSlug(),'currentValue'=>$value]]:[];
$initial=['resources'=>[['id'=>$slug,'currentValue'=>0]],'progressions'=>$p,'hitPoints'=>['current'=>1]];
$synced=$sync->synchronize($c,$initial);$ss=new App\Entity\CharacterSessionState($game,$c,$initial);
$rest->apply($ss,App\Entity\RestRequest::TYPE_SHORT_REST);$short=$get($ss->getState(),$slug);
$ss->setState($initial);$rest->apply($ss,App\Entity\RestRequest::TYPE_LONG_REST);
$cycles[$slug]=['sync'=>$get($synced,$slug),'short'=>$short,'long'=>$get($ss->getState(),$slug)];
}
echo json_encode(['type'=>'cycles','results'=>$cycles],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n";
$db->rollBack();
```

</details>

<details>
<summary>Probe complémentaire : capacités canoniques sans compteur et fournisseurs multiples</summary>

```php
<?php
require 'vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv('.env');
$kernel=new App\Kernel('dev',true);$kernel->boot();$registry=$kernel->getContainer()->get('doctrine');$em=$registry->getManager();$db=$em->getConnection();
$db->beginTransaction();$db->executeStatement('SET TRANSACTION READ ONLY');
$ability=new App\Service\CharacterAbilityCalculator();
$features=new App\Service\CharacterFeatureResolver(new App\Repository\CharacterFeatureRuleRepository($registry));
$resources=new App\Service\CharacterResourceResolver(new App\Repository\TrackableResourceRuleRepository($registry),$ability,$features);
$owner=(new App\Entity\User())->setEmail('audit@example.invalid')->setPassword('unused');
$campaign=new App\Entity\Campaign($owner,'audit','Audit');
$classes=[];foreach($em->getRepository(App\Entity\CharacterClass::class)->findAll() as $x)$classes[$x->getId()]=$x;
$subclasses=[];foreach($em->getRepository(App\Entity\CharacterSubclass::class)->findAll() as $x)$subclasses[$x->getId()]=$x;
$races=[];foreach($em->getRepository(App\Entity\CharacterRace::class)->findAll() as $x)$races[$x->getId()]=$x;
$feats=[];foreach($em->getRepository(App\Entity\Feat::class)->findAll() as $x)$feats[$x->getId()]=$x;
$progs=[];foreach($em->getRepository(App\Entity\ProgressionDefinition::class)->findAll() as $x)$progs[$x->getId()]=$x;
$fighter=array_values(array_filter($classes,fn($x)=>$x->getSlug()==='fighter'))[0];
$paladin=array_values(array_filter($classes,fn($x)=>$x->getSlug()==='paladin'))[0];
$make=function($kind,$id,$level,$other=0,$score=16)use($classes,$subclasses,$races,$feats,$progs,$fighter,$paladin,$campaign){
$class=$fighter;$sub=null;$race=null;$feat=null;$prog=null;
if($kind==='class')$class=$classes[$id];
if($kind==='subclass'){$sub=$subclasses[$id];$class=$sub->getCharacterClass();}
if($kind==='race')$race=$races[$id];
if($kind==='feat')$feat=$feats[$id];
if($kind==='progression')$prog=$progs[$id];
$c=(new App\Entity\Character($campaign,'probe','Probe',App\Entity\Character::TYPE_PLAYER))->setRace($race);
foreach(App\Enum\Ability::cases() as $a)$c->getAbilityScore($a)->setBaseValue($score);
if($feat)$c->addFeat(new App\Entity\CharacterFeat($c,$feat));
if($prog)$c->addProgression(new App\Entity\CharacterProgression($c,$prog));
foreach([[$class,$level,$sub],[$class===$fighter?$paladin:$fighter,$other,null]] as [$cl,$n,$sc])for($i=0;$i<$n;$i++)$c->addClassLevel(new App\Entity\CharacterClassLevel($c,$cl,$c->getTotalLevel()+1,$sc,intdiv($cl->getHitDie(),2)+1,App\Enum\HitPointGainMethod::Average));
return $c;
};

$out=[];
foreach($races as $race)if(in_array($race->getSlug(),['dragonborn-chromatic-ftd','dragonborn-gem-ftd','dragonborn-metallic-ftd','chromatic-dragonborn-red','metallic-dragonborn-silver'],true)){
$c=$make('race',$race->getId(),5);
$fs=[];foreach($features->resolve($c) as $slug=>$rule)if(preg_match('/breath|warding|gem-flight/',$slug))$fs[$slug]=$rule->getFeatureDefinition()->getResourceDefinition()?->getSlug();
$ms=[];foreach($resources->resolve($c) as $slug=>$r)if(preg_match('/breath|warding|gem-flight/',$slug))$ms[$slug]=$r->getMaximum();
$out[]=['race'=>$race->getSlug(),'level'=>5,'features'=>$fs,'resources'=>$ms];
}
foreach($subclasses as $sub)if(in_array($sub->getSlug(),['genie','undead','echo-knight'],true)){
$c=$make('subclass',$sub->getId(),20);$fs=[];
foreach($features->resolve($c) as $slug=>$rule){$r=$rule->getFeatureDefinition()->getResourceDefinition();if($r)$fs[$r->getSlug()][]=$slug;}
$ms=[];foreach($resources->resolve($c) as $slug=>$r)$ms[$slug]=$r->getMaximum();
$out[]=['subclass'=>$sub->getSlug(),'providers'=>$fs,'resources'=>$ms];
}
foreach($subclasses as $sub)if($sub->getSlug()==='twilight-domain'){
$c=$make('subclass',$sub->getId(),6,5);$out[]=['twilight6_fighter5'=>$resources->resolve($c)['steps-of-night-uses']->getMaximum()];
}
echo json_encode($out,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n";
$db->rollBack();
```

</details>

Requête de preuve des attributions raciales sans compteur :

```sql
SELECT f.slug, f.resource_definition_id, r.slug AS race, fr.unlock_level
FROM character_feature_definition f
JOIN character_feature_rule fr ON fr.feature_definition_id = f.id
JOIN character_race r ON r.id = fr.character_race_id
WHERE f.slug IN (
 'racial-dragonborn-chromatic-ftd-breath-weapon-2',
 'racial-dragonborn-gem-ftd-breath-weapon-2',
 'racial-dragonborn-metallic-ftd-breath-weapon-2',
 'racial-dragonborn-chromatic-ftd-chromatic-warding-4',
 'racial-dragonborn-metallic-ftd-metallic-breath-weapon-4'
)
ORDER BY f.slug;
```

Calcul des empreintes avec psql, sans export de lignes métier :

```sql
SELECT format(
 'SELECT %L, count(*), md5(coalesce(string_agg(to_jsonb(t)::text, '''' ORDER BY to_jsonb(t)::text), '''')) FROM public.%I t;',
 tablename, tablename
) FROM pg_tables WHERE schemaname='public' ORDER BY tablename
\gexec
```

Commandes de validation :

```text
docker compose exec -T backend php bin/console doctrine:migrations:status
docker compose exec -T backend php tools/test-spell-slot-synchronization.php
docker compose exec -T backend php tools/test-player-security.php
docker compose exec -T backend php bin/console lint:container
docker compose exec -T backend php bin/console doctrine:schema:validate
git diff --check
```

## Portée finale

Les huit réparations sont confirmées sur les données actuelles. Le système n'est toutefois pas exempt de défauts certains : C1–C3 restent reproduits sur cinq attributions raciales FTD. Aucune donnée ni correction n'a été changée pour les masquer. Les sept probables et quatre points manuels sont explicitement distincts des trois pannes démontrées. Aucun travail sur Escroc arcanique, aucune correction éditoriale, aucun JSON/importer, import, snapshot SQL, commit, push ou déploiement.
