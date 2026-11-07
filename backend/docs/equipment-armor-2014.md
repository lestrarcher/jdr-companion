# Équipement actif et profils physiques D&D 2014

Le calculateur utilise `CharacterMagicItem` et `CharacterAbilityCalculator`.
Les formules de Défense sans armure sont acquises selon la première position
globale de niveau de barbare ou de moine (règle de multiclassage 2014).
La meilleure formule admissible est retenue, sans addition des formules.

## Installation et enrichissement

Migrations additives : `Version20261107010000` (type et CA physique), puis
`Version20261107020000` (Force et Discrétion). Elles ne changent aucune possession.
Ne pas utiliser `doctrine:schema:update --force` : le projet possède aussi des
tables gérées hors ORM.

Depuis le backend, une fois les migrations appliquées :

```sh
php bin/console app:items:enrich-armor-2014
php bin/console app:items:enrich-armor-2014 --apply
php bin/console app:items:enrich-armor-2014
```

La première commande est un aperçu sans écriture. Vérifier les IDs, clés et
valeurs avant `--apply`. La dernière doit annoncer uniquement des entrées
`unchanged` ou `skipped`. Un conflit bloque toute écriture du lot (code de sortie 1).
Le rapport inclut les empreintes des possessions avant/après.

L'enrichissement ne crée aucun objet. Il remplit seulement les champs manquants
des définitions OFFICIAL globales d'édition 2014 identifiées par une clé exacte.
Les doublons de clé, classifications incompatibles et valeurs explicites
contradictoires sont signalés sans réécriture. Les données personnelles sont
exclues. Ni noms, ni descriptions, ni possessions ne servent à deviner un profil.

## Référentiel

`Dnd2014ArmorProfiles` contient les 12 armures ordinaires et le bouclier.
Les clés ordinaires réservées utilisent `srd-5.1:equipment:<index>` ; les objets
magiques utilisent les clés déjà produites par l'import SRD.

| Modèle physique | Type | CA | DEX | Force | Discrétion |
|---|---|---:|---|---:|---|
| Matelassée | légère | 11 | complète | — | désavantage |
| Cuir | légère | 11 | complète | — | normal |
| Cuir clouté | légère | 12 | complète | — | normal |
| Peaux | intermédiaire | 12 | plafond +2 | — | normal |
| Chemise de mailles | intermédiaire | 13 | plafond +2 | — | normal |
| Écailles | intermédiaire | 14 | plafond +2 | — | désavantage |
| Cuirasse | intermédiaire | 14 | plafond +2 | — | normal |
| Demi-plate | intermédiaire | 15 | plafond +2 | — | désavantage |
| Broigne | lourde | 14 | aucune | — | désavantage |
| Cotte de mailles | lourde | 16 | aucune | 13 | désavantage |
| Clibanion | lourde | 17 | aucune | 15 | désavantage |
| Harnois | lourde | 18 | aucune | 15 | désavantage |
| Bouclier | bouclier | +2 | aucune | — | normal |

DEX et son plafond sont dérivés de `armorType`, exposés dans `armorProfile` et
utilisés par le calculateur : aucune copie persistée susceptible de diverger.
Une DEX négative reste applicable aux armures légères/intermédiaires.
Discrétion `null` signifie non configurée ; `false` signifie explicitement normale.
La Force est une métadonnée, pas un blocage d'équipement ni une pénalité de vitesse
automatique dans ce lot. Les jets de Discrétion ne sont pas automatisés ici.

Sources : [table d'équipement 2014](https://www.dndbeyond.com/sources/dnd/basic-rules-2014/equipment),
[multiclassage 2014](https://www.dndbeyond.com/sources/dnd/basic-rules-2014/customization-options),
`backend/data/srd-magic-items-2014.json` pour les modèles physiques magiques.
Le test de correspondance vérifie les 22 clés magiques contre ce fichier SRD.

## Inventaire local observé le 7 novembre 2026

29 définitions d'armures/boucliers magiques ; aucune armure/bouclier ordinaire.
22 correspondances sûres :

- Harnois : invulnérabilité, vulnérabilité, démoniaque, nain et éthéré.
- Écailles : armure de dragon générique et ses dix couleurs.
- Chemise de mailles : mailles elfiques.
- Cuir clouté : armure de cuir clouté glamour.
- Boucliers : animé, antiprojectiles, attraction des projectiles, gardesort.

7 entrées restent ambiguës : adamantium, armure +1/+2/+3 générique, les variantes
+1, +2, +3, résistance et mithral. Aucune base physique ne leur est attribuée.
Une définition générique ne doit pas être modifiée globalement pour représenter
un seul exemplaire si ses possessions correspondent à différentes armures.
Il faudra alors des variantes précises du catalogue, dans un travail ultérieur.

## Limites explicites

Seule la CA physique est calculée. Les bonus magiques (+1, +2, etc.) ne sont pas
intégrés dans `baseArmorClass`. Le portail affiche cette limite pour une armure
ou un bouclier magique équipé. L'harmonisation conserve sa sémantique existante
pour les effets de caractéristiques ; elle ne supprime pas la protection physique.

Le bouclier animé est traité comme un bouclier tenu : son activation temporaire
libérant une main n'est pas implémentée. Mithral et adamantium génériques restent
sans profil. Pas de vitesses, de moteur d'effets temporaires ou de bonus de CA.
Les contraintes de mains dépendent des métadonnées du catalogue ; les anciennes
classifications d'armes et les piles historiques ne sont pas réécrites.
