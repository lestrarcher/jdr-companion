# Maîtrises raciales D&D 2014

Périmètre : les 63 entrées du catalogue canonique `dnd-2014-races.json` (races sélectionnables et parents structurels). Les versions MPMM et autres suppléments du système 2014 sont conservées ; aucune règle 2024. Les anciennes variantes hors catalogue ne sont pas couvertes.

29 règles locales, toutes au niveau 1 et en mode ANY. Une règle peut accorder plusieurs maîtrises ou demander plusieurs choix. Les nombres ci-dessous comptent les règles, pas les maîtrises effectives. Les règles parentes ne sont jamais recopiées.

## Couverture

| Race / sous-race (slug) | Règles locales importables | Avec héritage |
| --- | ---: | ---: |
| aarakocra-mpmm | 0 | 0 |
| aasimar-mpmm | 0 | 0 |
| bugbear-mpmm | 1 | 1 |
| centaur-mpmm | 1 | 1 |
| changeling-mpmm | 1 | 1 |
| custom-lineage-tce | 0 | 0 |
| deep-gnome-mpmm | 0 | 0 |
| dhampir-vrgr | 0 | 0 |
| dragonborn-phb | 0 | 0 |
| dragonborn-chromatic-ftd | 0 | 0 |
| dragonborn-gem-ftd | 0 | 0 |
| dragonborn-metallic-ftd | 0 | 0 |
| duergar-mpmm | 0 | 0 |
| dwarf-phb | 2 | 2 |
| eladrin-mpmm | 1 | 1 |
| elf-phb | 1 | 1 |
| fairy-mpmm | 0 | 0 |
| firbolg-mpmm | 0 | 0 |
| genasi-mpmm | 0 | 0 |
| githyanki-mpmm | 0 | 0 |
| githzerai-mpmm | 0 | 0 |
| gnome-phb | 0 | 0 |
| goblin-mpmm | 0 | 0 |
| goliath-mpmm | 1 | 1 |
| half-elf-phb | 1 | 1 |
| half-orc-phb | 1 | 1 |
| halfling-phb | 0 | 0 |
| harengon-mpmm | 1 | 1 |
| hexblood-vrgr | 0 | 0 |
| hobgoblin-mpmm | 0 | 0 |
| human-phb | 0 | 0 |
| kenku-mpmm | 1 | 1 |
| kobold-mpmm | 0 | 0 |
| lizardfolk-mpmm | 1 | 1 |
| minotaur-mpmm | 0 | 0 |
| orc-mpmm | 0 | 0 |
| owlin-scc | 1 | 1 |
| reborn-vrgr | 0 | 0 |
| satyr-mpmm | 2 | 2 |
| sea-elf-mpmm | 1 | 1 |
| shadar-kai-mpmm | 1 | 1 |
| shifter-mpmm | 1 | 1 |
| tabaxi-mpmm | 1 | 1 |
| tiefling-phb | 0 | 0 |
| tortle-mpmm | 1 | 1 |
| triton-mpmm | 0 | 0 |
| warforged-erlw | 2 | 2 |
| yuan-ti-mpmm | 0 | 0 |
| dwarf-hill-phb | 0 | 2 |
| dwarf-mountain-phb | 1 | 3 |
| elf-drow-phb | 1 | 2 |
| elf-high-phb | 1 | 2 |
| elf-wood-phb | 1 | 2 |
| genasi-air-mpmm | 0 | 0 |
| genasi-earth-mpmm | 0 | 0 |
| genasi-fire-mpmm | 0 | 0 |
| genasi-water-mpmm | 0 | 0 |
| gnome-forest-phb | 0 | 0 |
| gnome-rock-phb | 1 | 1 |
| halfling-ghostwise-scag | 0 | 0 |
| halfling-lightfoot-phb | 0 | 0 |
| halfling-stout-phb | 0 | 0 |
| human-variant-phb | 1 | 1 |

## Limites explicites

Une valeur zéro ne signifie pas toujours absence de trait : les cas suivants exigent une mécanique non représentée. Ils sont signalés par la commande et ne donnent aucune attribution générale artificielle.

- **custom-lineage-tce** : Variable Trait : alternative entre vision dans le noir et compétence, choix de trait non persisté.
- **dhampir-vrgr** : Ancestral Legacy : choix dépendant de la création ou de la transformation et des maîtrises antérieures.
- **dwarf-phb** : Stonecunning : maîtrise/expertise d'Histoire conditionnelle à un ouvrage en pierre.
- **eladrin-mpmm** : Trance : deux maîtrises temporaires d'armes/outils renouvelées au repos long.
- **githyanki-mpmm** : Astral Knowledge : maîtrises temporaires renouvelées au repos long.
- **harengon-mpmm** : Hare-Trigger : ajout du bonus à l'initiative, qui n'est pas une maîtrise de compétence.
- **hexblood-vrgr** : Ancestral Legacy : choix dépendant de la création ou de la transformation et des maîtrises antérieures.
- **kobold-mpmm** : Kobold Legacy : maîtrise conditionnée au choix Craftiness, choix de trait non persisté.
- **reborn-vrgr** : Ancestral Legacy : choix dépendant de la création ou de la transformation et des maîtrises antérieures.
- **sea-elf-mpmm** : Trance : deux maîtrises temporaires d'armes/outils renouvelées au repos long.
- **shadar-kai-mpmm** : Trance : deux maîtrises temporaires d'armes/outils renouvelées au repos long.
- **gnome-rock-phb** : Artificer's Lore : bonus conditionnel d'Histoire, pas une maîtrise générale.

Le résolveur existant parcourt déjà les parents et conserve toutes les règles d'origine pour une même maîtrise effective. Aucun changement du résolveur. Les variantes historiques qui remplacent des traits parents nécessiteraient un mécanisme d'exclusion distinct ; leur couverture n'est pas revendiquée ici. Aucune substitution des doublons.

Les choix d'outils utilisent les définitions individuelles : trois outils pour le nain, dix instruments pour le satyre, les 37 outils du référentiel pour le forgé. Les catégories de choix ne deviennent pas des maîtrises effectives.

## Import et protection

Commande : `app:dnd:import-race-proficiencies`, options `--dry-run` et `--update-existing`. Clé stable : `dnd-2014-race-<slug>-<category>`. Catégories : skills, weapons, armor, tools.

Le plan complet est validé avant toute mutation : sources officielles, parenté conforme, références connues, types, unicité des options et clés, nombre de choix. Une règle personnalisée ou un rapprochement ambigu est refusé. Sans `--update-existing`, une différence conserve l'existant. Avec cette option, une modification mécanique est refusée si des choix persistés utilisent la règle ; un changement de libellé reste possible. Les règles absentes du catalogue ne sont pas supprimées. Les règles de classes ne sont pas modifiées.

Aucune migration nécessaire. Aucun import avec écriture n'a été exécuté pour cette livraison.

## Sources

- Catalogue racial local : `dnd-2014-races.json`, avec le code de publication de chaque entrée.
- [Basic Rules 2014 — Races](https://www.dndbeyond.com/sources/dnd/basic-rules-2014/races) pour les règles PHB.
- [D&D Beyond — Changeling](https://www.dndbeyond.com/posts/1675-how-to-build-and-play-a-changeling) : cinq options MPMM dont Tromperie, omise dans la description raciale locale. Le catalogue racial existant reste inchangé.

## Vérification

`php tools/test-import-race-proficiencies.php` utilise des fixtures en mémoire issues des catalogues : couverture, héritage, choix, origines multiples, idempotence, mises à jour et protections. Aucun accès SQL dans ce script.

