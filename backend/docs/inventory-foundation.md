# Inventaire — étape 1 : socle compatible

## Périmètre

Les classes et tables `MagicItem` / `magic_item` et `CharacterMagicItem` /
`character_magic_item` restent en place. Aucun catalogue importé, aucune nouvelle
API, aucun changement Angular. Les routes existantes conservent leurs contrats.

La migration `Version20261030010000` ajoute uniquement :

- aux définitions : origine CUSTOM, propriétaire issu de la campagne, catégorie
  `other`, nature magique, capacité à être équipé, mode de quantité `legacy`,
  emplacement libre, usage des mains nullable, provenance nullable, date
  d'archivage nullable ;
- aux possessions : emplacement et mains utilisés nullables, marqueur des piles
  héritées (`quantity > 1` au moment de la migration).

Les nouvelles colonnes sont préparatoires, accessibles en lecture dans les
entités, sans formulaire ni écriture publique. Les modes `legacy` conservent la
validation historique des quantités : ce jalon ne durcit pas les anciennes API.
Les piles héritées ne sont pas divisées ; les bonus, quantités, charges, notes,
dates et identifiants existants ne sont pas réécrits. Aucune rareté ni catégorie
précise n'est déduite du nom. Aucun objet n'est déclaré officiel.

L'origine de campagne, la rareté et la définition d'une possession restent
obligatoires dans cette étape. Leur assouplissement pour les définitions globales
et les possessions narratives appartient aux étapes catalogue/possessions, avec
adaptation simultanée des consommateurs. Les objets personnels ne sont donc pas
encore créables. Publication, masquage, journal d'acquisition, séparation de piles
et arbitrage des emplacements ne sont pas implémentés ici.

## Harmonisation

Le contrôleur public ne désaccorde plus lors d'un déséquipement et n'équipe plus
lors d'une harmonisation. Les deux états sont indépendants. Le calcul existant
continue d'exiger le port de l'objet et, si nécessaire, son harmonisation pour
activer ses effets. Les objets harmonisés non équipés comptent dans la limite de
trois. Les restrictions de participation et de session live restent inchangées.

## Migration et validation

La migration doit précéder l'utilisation du code avec une base persistante :
Doctrine sélectionne les nouvelles colonnes. Elle n'est pas appliquée à la base
de développement par les tests. Aucune intervention en production n'est incluse.
Le retour arrière automatique est refusé pour ne pas supprimer des métadonnées
éventuellement renseignées ; une correction ultérieure doit être une migration
relue préservant les données.

`tools/test-inventory-foundation.php` crée uniquement des tables temporaires
PostgreSQL dans une transaction, vérifie leur isolation, reconstitue les tables
avant ajout des colonnes puis exécute la vraie migration. Il compare toutes les
anciennes colonnes des définitions, possessions, effets, bourses et états de
session avant/après, puis vérifie l'hydratation Doctrine et les mutations du
contrôleur public. Le bloc `finally` annule toutes les transactions.

Commandes de validation :

```sh
docker compose exec -T backend php tools/test-inventory-foundation.php
docker compose exec -T backend php tools/test-media-item-removal.php
docker compose exec -T backend php bin/console lint:container
docker compose exec -T backend php bin/console doctrine:schema:validate
git diff --check
```

La validation du schéma persistant signale nécessairement les colonnes manquantes
tant que la migration n'y a pas été appliquée. Ne pas utiliser `schema:update`
pour résoudre cet écart. Le test isolé compare les noms, types et nullabilités
des colonnes migrées avec le schéma produit par le mapping Doctrine.
