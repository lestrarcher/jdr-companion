# CA : contrat des effets et détail du calcul (D&D 2014)

Ce lot ajoute du code et une structure vide. Il ne configure aucun objet,
ne lance aucun enrichissement, ne modifie aucune possession et ne crée aucun objet.
La configuration du catalogue est une opération manuelle ultérieure.

Mise à jour : le lot suivant configure désormais les données Docker DEV via une
liste explicite revue ; voir [finalisation du catalogue](armor-class-catalogue-dev.md).

## Structure

`Version20261107030000` ajoute uniquement `magic_item.armor_class_effects`,
JSON nullable, avec une contrainte de type tableau. Aucun UPDATE/INSERT ni
appel à l'enrichisseur. Sur une nouvelle installation, les mêmes migrations
créent la structure sans attribuer de bonus aux objets importés ensuite.

`MagicItem::configureArmorClassEffects()` valide intégralement la liste avant
de l'affecter. `null` signifie non configuré, `[]` signifie explicitement aucun
effet supplémentaire. Un objet magique équipé non configuré génère un avertissement.

Chaque effet comporte :

| Champ | Contrat |
|---|---|
| `key` | Clé stable, unique dans la définition (lettres minuscules, chiffres, tirets ; 80 caractères max). |
| `label` | Libellé lisible (150 caractères max). |
| `type` | `bonus` (addition) ou `formula` (base alternative). |
| `value` | Entier positif de 1 à 30 : bonus additionnel, ou base de la formule. |
| `conditions` | Liste facultative : `no-armor`, `no-shield`, `armor`, `held`. Toutes doivent être vraies. |
| `stackingKey` | Groupe facultatif de non-cumul : seul le plus grand bonus applicable est retenu. |
| `classSlugs` | Liste facultative de classes admissibles : au moins un niveau dans l'une d'elles est requis. |
| `dexterity` | Formules seulement, obligatoire : `full`, `capped`, `none`. |
| `dexterityCap` | Obligatoire uniquement pour `capped` (0 à 10). La DEX négative n'est pas ramenée à zéro. |
| `ability` | Formules seulement : caractéristique supplémentaire facultative, hors DEX déjà représentée. |

Tous les effets d'objet requièrent que l'objet soit équipé ; l'harmonisation
est contrôlée par `CharacterMagicItem::isEffectActive()` et le champ existant
`requiresAttunement`. `held` exige en plus l'emplacement équipé `hand` et au
moins une main occupée. Aucune déduction à partir du nom ou de la description.
Le système existant ne distingue pas les différents emplacements corporels :
un équipement hors main représente un objet porté. Les emplacements mains et
armures existants et leurs conflits restent autoritaires.

Une CA physique doit rester physique : par exemple une armure de base 11
avec un bonus intrinsèque +1 utilise `baseArmorClass = 11` et un seul effet
`bonus` de valeur 1. Ne pas mettre 12 dans la base puis ajouter encore +1.
Le moteur ne devine pas une mauvaise saisie de la base. Les champs physiques
et les effets sont lus chacun une seule fois ; la quantité ne multiplie pas les bonus.
Deux copies d'une même définition ne cumulent pas le même effet. Pour des
définitions distinctes représentant une même propriété non cumulable, configurer
le même `stackingKey`. Utiliser des groupes différents pour les bonus cumulables
(par exemple anneau, cape, armure et bouclier). Aucun nom d'objet n'est codé en dur.

## API de configuration future

Corps JSON exclusif : `{"armorClassEffects": [...]}` (ou `null` pour non configuré).

- `PUT /campaigns/{campaignId}/magic-items/{itemId}/armor-class-effects` :
  droit `CAMPAIGN_MANAGE`, objet appartenant exactement à cette campagne.
- `PUT /dnd/items/{itemId}/armor-class-effects` : `ROLE_REFERENCE_ADMIN`,
  définition officielle globale uniquement.

Ces routes ne sont pas appelées automatiquement. Les réponses du catalogue
et des listes de définitions exposent `armorClassEffects`. Il n'y a pas encore
d'éditeur graphique de ces effets ni de route dédiée aux définitions personnelles
hors campagne. Les états des possessions ne sont jamais modifiés par cette API.

## Calcul et contexte explicite

La CA retient la meilleure formule admissible (base + caractéristiques effectives),
puis ajoute le bouclier physique et les bonus compatibles. La Défense sans armure
du premier niveau acquis de barbare/moine garde ses restrictions de 2014.
Aucune formule alternative ne s'ajoute à une autre.

Le deuxième argument facultatif de `CharacterArmorClassCalculator::calculate()`
accepte des sources **déjà actives**, explicitement fournies par un appelant backend :

```php
[['source' => 'Effet actif fictif', 'effects' => [[
    'key' => 'mage-armor', 'label' => 'Armure de mage', 'type' => 'formula',
    'value' => 13, 'dexterity' => 'full', 'conditions' => ['no-armor'],
]]]]
```

Cet exemple est documentaire, pas une configuration du catalogue.
Le moteur évalue ainsi Armure de mage (bouclier permis) ou une autre formule
déclarative ; il ne déduit jamais son activation d'un sort connu/préparé.
Le portail n'alimente pas encore ce contexte : lancement, expiration,
concentration et activation des effets temporaires restent à raccorder dans
un lot dédié. Aucun lookup implicite de session. `held` échoue dans ce contexte
sans objet. Les bonus de contexte partagent leurs `key` (ou `stackingKey`)
pour éviter de cumuler plusieurs occurrences d'un même effet actif.

## Présentation et limites

Le résultat contient `value`, `source` principale, `formula`, `contributions`
(`kind`, `source`, `label`, `value`), `excluded` (source, libellé, raison),
ainsi que `complete`, `reasons` et `warnings`. `complete` vérifie les profils
physiques et conflits ; les effets magiques non configurés restent signalés
séparément. Une armure sans profil physique ne reçoit pas une CA inventée.

L'encart est replié par défaut, ouvre le détail avec le contrôle HTML natif
`details/summary` (clavier et tactile). Le frontend affiche les valeurs backend,
sans recalcul. L'événement d'équipement et d'harmonisation recharge toujours le profil.

Non pris en charge : bonus dépendant d'une attaque précise ou d'un état de combat,
malus, minimum de CA (Peau d'écorce), consommation d'une réaction, activation
du bouclier animé, contraintes corporelles supplémentaires, modification manuelle
de CA, configuration automatique des capacités autres que les deux Défenses sans
armure existantes. Les conditions inconnues sont rejetées, jamais ignorées.

Références : [règles des objets 2014](https://www.dndbeyond.com/sources/dnd/basic-rules-2014/magic-items),
[Armure de mage 2014](https://www.dndbeyond.com/spells/2172-mage-armor).
