# Finalisation du catalogue de CA — Docker DEV

## Comparaison et application

Exports examinés : `magic_items_prod.csv` (586 lignes) et `magic_items_dev.csv`
(564 lignes). Comparaison par `import_key`, jamais par ID ou nom : 173 clés
de PROD absentes de DEV, dont seulement 42 retenues pour ce chantier. Les
97 lignes PROD et 117 lignes DEV sans clé n'ont pas été rapprochées ni modifiées.
Les 131 clés propres à DEV sont conservées. Les exports originaux restent intacts.

Application du 7 novembre 2026 : **42 imports, 24 définitions existantes configurées**,
soit 66 définitions, dont 63 immédiatement calculables et 3 armures génériques
au bonus connu mais sans variante physique. Catalogue final : 606 objets.
Le deuxième aperçu indique 66 entrées `unchanged`, zéro conflit, zéro écriture.

Les IDs existants et toutes les propriétés correctes sont conservés. Les seuls
champs corrigés dans les définitions existantes sont les effets de CA, les six
indicateurs `equippable` incorrects et l'emplacement/tenue du bâton de surpuissance.
Les 42 imports reçoivent des IDs locaux ; aucun ID PROD n'est réutilisé.
Les tatouages sont normalisés en `other`/`individual`/`free`, jamais en armure.
Les nouveaux boucliers +1/+2/+3 utilisent les raretés 2014 uncommon/rare/very-rare,
contrairement aux raretés copiées des armures dans l'export source.

Empreinte SQL de **toutes** les possessions avant/après import, configuration
et simulation : `bc21fb9d73f22ebb8395861f3266871f`.
Chaque définition hors du plan est aussi comparée intégralement avant/après.
Aucun inventaire, portefeuille, état de session ou fichier de production n'est modifié.

## Objets configurés

| Ensemble | Nombre | CA / conditions |
|---|---:|---|
| 12 types physiques d'armures × +1/+2/+3 | 36 importés | Base physique ordinaire + DEX selon type + enchantement distinct ; équipé. |
| Boucliers +1/+2/+3 | 3 importés | Bouclier +2 physique, puis enchantement +1/+2/+3 ; tenu. |
| Tatouages barrière peu commun/rare/très rare | 3 importés | Respectivement 12+DEX, 15+DEX plafonnée à +2, 18 ; sans armure, bouclier permis, équipé et harmonisé. |
| Bracelets de défense | 1 existant | +2 sans armure ni bouclier ; équipé et harmonisé. |
| Anneau et cape de protection | 2 existants | +1 chacun ; équipés et harmonisés ; groupes distincts cumulables. |
| Pierre de Ioun de protection | 1 existant | +1 ; équipée = en orbite, harmonisée. Les autres variantes ne reçoivent pas ce bonus. |
| Bâton de surpuissance | 1 existant | +2 ; tenu et harmonisé ; niveau d'ensorceleur, occultiste ou magicien. |
| Robe de l'archimage | 1 existant | 15+DEX sans armure ; équipée et harmonisée ; même prérequis de classe. |
| Armure démoniaque | 1 existant | Harnois 18 + bonus 1 ; harmonisation pour le bonus, pas pour la protection physique. |
| Écailles de dragon, générique et 10 couleurs | 11 existants | Écailles 14+DEX plafonnée à +2, puis bonus 1 ; harmonisation. |
| Harnois nain | 1 existant | Harnois 18 + bonus 2 ; sans harmonisation. |
| Mailles elfiques | 1 existant | Chemise 13+DEX plafonnée à +2, puis bonus 1 ; sans harmonisation. |
| Cuir clouté glamour | 1 existant | Cuir clouté 12+DEX + bonus 1 ; sans harmonisation. |
| Armures génériques +1/+2/+3 | 3 existants | Bonus connu configuré ; base physique laissée inconnue, résultat incomplet tant que la variante n'est pas précisée. |

Tous les bonus intrinsèques restent distincts de la CA physique. Les bonus d'une
armure et d'un bouclier se cumulent ; ils ne partagent pas le même `stackingKey`.
Les copies d'une même propriété ne se cumulent pas. La meilleure formule complète
remplace les autres ; le tatouage n'empêche pas les bracelets de fonctionner.
Le moteur et le frontend ne déduisent aucune règle depuis le nom ou la description.

## Objets exclus ou partiellement pris en charge

- Bouclier antiprojectiles : +2 seulement contre les attaques à distance.
  Gardienne : transfert variable après attaque. Sceptre de vigilance : aura
  activée, durée et proximité. Aucun bonus permanent approximatif n'est ajouté.
- Bouclier animé : profil de bouclier tenu conservé ; animation libérant les
  mains non gérée. Aucun état d'activation inventé.
- Armure générique « +1, +2 ou +3 », adamantium, mithral et résistance :
  variante ou bonus indéterminé. Aucune base physique devinée.
- Robe de l'archimage : couleur/alignement à valider manuellement avant
  harmonisation. Les exceptions aux prérequis de classe (p. ex. capacités
  permettant d'ignorer les prérequis d'objet) ne sont pas encore modélisées.
- Pierre de Ioun générique : plusieurs variantes ; utiliser la définition
  précise de protection. Les autres pierres mentionnent leur propre CA 24,
  pas un bonus de CA au porteur. Les bonus indirects de caractéristiques restent
  du ressort des effets de caractéristiques existants.
- Appareil du crabe, figurines, miroir et cordes : la CA citée appartient à
  l'objet ou à la créature créée ; aucune modification de CA du personnage.
- Les 13 armures/bouclier ordinaires déjà en DEV et les protections physiques
  des armures d'invulnérabilité, vulnérabilité, harnois éthéré et boucliers
  gardesort/attraction conservent leurs profils. Pas de nouveau bonus numérique.
- Potions, armes sans modification directe de CA, munitions et autres objets
  non concernés : pas d'import. Aucun effet temporaire issu d'un sort n'est activé.

## Frank : vérification sans attribution

Frank DEV (ID 24) possède une DEX effective de 14 (+2), et **zéro possession**.
Sa CA réelle reste donc 12. Une transaction PostgreSQL `READ ONLY` a chargé ses
caractéristiques et les définitions DEV ; seules des instances PHP transitoires
ont été ajoutées pour la simulation, sans `persist`, `flush`, INSERT ni endpoint
de mutation. La transaction est ensuite annulée et l'EntityManager vidé.

| Simulation | CA |
|---|---:|
| Tatouage rare + bracelets, équipés et harmonisés | **19 = 15 + 2 + 2** |
| Bracelets déséquipés ou non harmonisés | 17 |
| Tatouage déséquipé ou non harmonisé, bracelets actifs | 14 |
| Tatouage + bouclier +1, bracelets exclus | 20 = 15 + 2 + 2 + 1 |

Le résultat backend expose exactement trois contributions pour la combinaison
prioritaire : base du tatouage 15, DEX retenue 2, bonus des bracelets 2.
L'encart repliable existant affiche ces contributions. L'attribution réelle à
Frank reste une action du MJ : ce chantier interdit de modifier son inventaire.

## Reproduction

Le fichier `backend/data/armor-class-import-2014.json` contient uniquement les
42 définitions sélectionnées de l'export et les SHA-256 des deux exports.
`Dnd2014ArmorClassCatalogue` contient les règles revues par clé exacte, en
réutilisant `Dnd2014ArmorProfiles`. Le synchroniseur fonctionne après import
du catalogue SRD et application des migrations structurelles du lot précédent.
**Aucune nouvelle migration n'est nécessaire.**

```sh
docker compose exec -T backend php bin/console app:items:sync-ac-2014
docker compose exec -T backend php bin/console app:items:sync-ac-2014 --apply
docker compose exec -T backend php bin/console app:items:sync-ac-2014
docker compose exec -T backend php tools/verify-armor-class-character.php 24
```

La commande refuse de fonctionner hors de Docker DEV ou si l'hôte DB n'est pas
le service `database`. L'aperçu est le défaut. Toute clé dupliquée, donnée
personnelle, référence manquante ou mécanique contradictoire bloque le lot.
Les valeurs existantes correctes ne sont pas réécrites. L'application est
transactionnelle avec verrous sur catalogue et possessions, empreintes et
contrôle intégral des définitions hors plan.

Rapports locaux ignorés par Git : `backend/var/ac-preview.json`,
`ac-applied.json`, `ac-idempotence.json`, `ac-frank-verification.json`.
L'audit retourne les candidats de toutes les catégories ; son analyse de texte
sert uniquement au rapport, jamais à générer les propriétés.

## Sources et validation

[Objets magiques des règles 2014](https://www.dndbeyond.com/sources/dnd/basic-rules-2014/magic-items),
[Tatouages barrière, présentation officielle](https://www.dndbeyond.com/posts/1072-monk-201-the-best-magic-items-for-monks?page=3),
descriptions des deux exports fournis et profils physiques déjà vérifiés du dépôt.

Les tests utilisent des personnages fictifs pour les formules, restrictions,
cumul, harmonisation et multiclassage, et des tables temporaires avec rollback
pour l'import, l'idempotence, les IDs locaux, la préservation des objets personnels
et des possessions, ainsi que le refus des conflits.

Résultats de cette exécution : **98 tests backend / 2695 assertions**, **14 tests
frontend**, build Angular, `lint:container`, mapping Doctrine (`--skip-sync`)
et `git diff --check` réussis. Les deux avertissements SCSS existants de
`player-portal.scss` et `character-magic-items.scss` restent présents. La
synchronisation globale du schéma n'a pas été relancée : aucune structure n'a
changé dans ce lot et les écarts hors périmètre du lot précédent restent connus.
