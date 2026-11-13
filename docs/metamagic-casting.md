# Métamagies au lancement — règles 2014

Le backend accepte un champ facultatif `metamagic` dans la commande existante
`POST /public/characters/{accessToken}/spells/cast`, par exemple
`"metamagic": "metamagic-twinned-spell"`. Une seule option est acceptée.
Omettre le champ conserve le contrat historique ; `null`, les listes et les coûts
fournis par le client sont refusés.

`CharacterSpellMetamagic` utilise `CharacterFeatureResolver` : seuls les choix
actuellement acquis/applicables sont proposés, y compris les options Tasha déjà
autorisées par le système de choix. Aucune option n'est accordée par ce raccordement.
Le sort peut provenir d'une autre classe du personnage.

## Coûts

Les clés sont `metamagic-<option>-spell` :

| Option | Points de sorcellerie |
|---|---:|
| careful, distant, empowered, extended, subtle, transmuted | 1 |
| quickened, seeking | 2 |
| heightened | 3 |
| twinned | Niveau effectif de lancement, minimum 1 pour un sort mineur |

Références : [métamagies PHB 2014](https://www.dndbeyond.com/sources/dnd/basic-rules-2014/classes#Metamagic),
Tasha's Cauldron of Everything, p. 66. Les coûts 2024 ne s'appliquent pas
([comparaison officielle](https://www.dndbeyond.com/posts/1769-2024-sorcerer-vs-2014-sorcerer-whats-new)).

## Disponibilité et transaction

Chaque méthode de `castingAvailability.methods` expose `metamagics`, liste des
options acquises avec `key`, `name`, `poolSlug`, `cost`, `status`, `reason` et
`playerChecks`. Le coût dépend de cette méthode et de son niveau. Une méthode
indisponible ne devient jamais disponible grâce à une métamagie.

Le même snapshot de ressources validé que pour les lancements ordinaires est
utilisé : réserve active, compteur unique, entier, non négatif et dans son maximum.
Si le sort coûte lui-même des points de sorcellerie, la disponibilité vérifie la
somme des deux coûts. Emplacements standards, pacte, ressources et sorts mineurs
conservent leurs parcours existants.

Le validateur choisit l'option côté serveur. L'exécuteur débite localement les
points, puis la ressource habituelle, dans sa transaction et sous ses verrous.
Le résultat `cast.metamagic` conserve `key`, `poolSlug`, `cost`.
Les reçus version 3 incluent ce choix et son coût historique ; les versions 1 et 2
restent valides. Rejouer une commande identique ne dépense rien, même après perte
de l'option. Modifier ou retirer la métamagie avec le même requestId est un conflit.

## Frontière du tracker

Les incompatibilités certaines des champs de catalogue reconnus sont refusées :
portée personnelle pour Sort jumelé/distant, portée numérique trop courte pour
Sort distant, durée instantanée ou inférieure à une minute pour Sort étendu,
incantation autre qu'une action reconnue ou rituel pour Sort accéléré, absence de
composantes V/S pour Sort subtil. Les formes françaises et anglaises usuelles sont
reconnues. Aucune règle n'est déduite du nom ou de la description du sort.

Les descriptions du catalogue ne fournissent pas de données structurées fiables
sur les cibles possibles, sauvegardes, jets d'attaque ou types de dégâts. Les codes
`playerChecks` indiquent les conditions restant au joueur, notamment :

- Sort jumelé : une seule créature ciblable au niveau effectif du sort (y compris
  les augmentations du nombre de cibles/rayons avec le niveau du sort/personnage).
  Deux cas canoniques sont contrôlés automatiquement par leur `importKey` 2014 :
  Décharge occulte est refusée dès le niveau **global** 5 ; Immobilisation de monstre
  est refusée dès le niveau de **lancement** 6. Au niveau de lancement 5, cette
  dernière coûte 5 points. Aucun nom affiché ni donnée de personnage n'est codé en dur.
- Prudent/intensifié : sauvegardes et créatures concernées.
- Puissant/chercheur : jet de dégâts ou attaque effectivement manquée ; limites de relance.
- Transmuté : dégâts acide, froid, feu, foudre, poison ou tonnerre.
- Accéléré : économie d'actions et restriction des sorts en action bonus 2014.
- Subtil : les composantes matérielles restent nécessaires.
- Étendu : durée minimale d'une minute, doublée avec plafond de 24 heures.

Une métadonnée textuelle inconnue reste un contrôle joueur. Les particularités des
objets lanceurs de sorts (composantes/activation spécifiques) restent également à
vérifier par le joueur ; aucun moteur d'activation d'objet n'est ajouté.
Ce lot enregistre le choix et la dépense au lancement : il ne modifie ni les jets,
ni les cibles, ni les dégâts, ni la durée des effets persistés. Il ne permet pas
d'ajouter après coup Sort puissant/chercheur à un lancement déjà enregistré, ni de
combiner deux métamagies malgré leurs exceptions de cumul. L'interface viendra
séparément ; pas de migration ni de configuration de données.

## Validation

`php bin/phpunit --filter 'SpellMetamagicTest|MetamagicReceiptTest|ItemSpellCastReceiptTest'`

Les tests d'intégration créent des personnages, choix et sorts fictifs dans des
tables et séquences PostgreSQL temporaires, vérifiées avant les mutations. Ils
testent l'exécuteur réel avec ses transactions, le multiclassage, les emplacements
de pacte, les dix coûts et sorts mineurs, les refus sans consommation partielle,
le cumul des coûts d'une même réserve, les reçus et un échec SQL au flush.
