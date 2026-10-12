# Historiques du PHB 2014

13 historiques principaux, 26 règles : 19 automatiques et 7 choix d'un outil.
Chaque historique accorde exactement deux compétences automatiques. Les outils fixes
et les outils à choisir ont des règles séparées. Toutes les règles sont au niveau 1,
en mode ANY. Aucune langue n'est une ProficiencyDefinition.

| Historique | Slug | Règles automatiques | Règles à choix |
| --- | --- | ---: | ---: |
| Acolyte | acolyte-phb | 1 | 0 |
| Charlatan | charlatan-phb | 2 | 0 |
| Criminel | criminal-phb | 2 | 1 |
| Artiste | entertainer-phb | 2 | 1 |
| Héros du peuple | folk-hero-phb | 1 | 1 |
| Artisan de guilde | guild-artisan-phb | 1 | 1 |
| Ermite | hermit-phb | 2 | 0 |
| Noble | noble-phb | 1 | 1 |
| Sauvageon | outlander-phb | 1 | 1 |
| Sage | sage-phb | 1 | 0 |
| Marin | sailor-phb | 2 | 0 |
| Soldat | soldier-phb | 1 | 1 |
| Enfant des rues | urchin-phb | 2 | 0 |

## Sources et limites

Vérifié sur les règles 2014, pas les historiques 2024 :
- [Wizards / D&D Beyond : Personality and Background](https://www.dndbeyond.com/sources/dnd/basic-rules-2014/personality-and-background).
- [D&D Beyond : catalogue, entrées Legacy / Player's Handbook (2014)](https://www.dndbeyond.com/backgrounds).
- [AideDD : historiques, règles 2014](https://www.aidedd.org/regles/historiques/) pour les noms français et les outils des entrées PHB non accessibles sans achat sur D&D Beyond.

Les descriptions restent nulles ; aucun texte narratif du PHB n'est recopié.
Les capacités narratives, équipement, traits de personnalité et personnalisation
libre des historiques ne sont pas implémentés.

Limites explicites signalées par la commande :
- Acolyte : Deux langues au choix.
- Héros du peuple : Véhicules terrestres : définition de maîtrise absente du référentiel.
- Artisan de guilde : Une langue au choix.
- Ermite : Une langue au choix.
- Noble : Une langue au choix.
- Sauvageon : Une langue au choix.
- Sage : Deux langues au choix.
- Marin : Véhicules aquatiques : définition de maîtrise absente du référentiel.
- Soldat : Véhicules terrestres : définition de maîtrise absente du référentiel.

Les véhicules ne sont pas présents parmi les 104 définitions existantes.
Aucune définition, aucun nouveau type et aucune maîtrise de remplacement n'est
ajouté silencieusement. Héros du peuple, Marin et Soldat sont donc incomplets pour
les véhicules, mais leurs compétences et outils existants sont importables.

Les variantes Espion, Gladiateur, Chevalier, Pirate et Marchand de guilde n'ont
pas d'entrées distinctes dans ce premier catalogue. Les quatre premières
conservent les maîtrises de l'historique principal ; les changements narratifs ou
d'équipement ne donnent pas de maîtrise supplémentaire. Marchand de guilde peut
remplacer l'outil d'artisan par des outils de navigateur ou une langue : cette
alternative outil/langue reste hors modèle et n'est pas simulée.

Les doublons conservent toutes leurs origines. Le remplacement d'une compétence
ou d'un outil déjà maîtrisé prévu en 2014 reste à traiter explicitement ; aucune
substitution ni expertise implicite.

## Modèle et import

- CharacterBackground : slug global unique et immuable, nom, description nullable,
  origin OFFICIAL/CUSTOM et owner. Le constructeur crée une référence officielle ;
  la factory forOwner crée une référence personnalisée avec propriétaire.
- Character.background est nullable, sans cascade de création ; suppression d'un
  historique utilisé par un personnage interdite par FK RESTRICT.
- ProficiencyGrantRule.characterBackground complète les cinq sources exclusives.
  Source historique + mode spécifique à une classe est refusé.
- Le résolveur vérifie l'historique sélectionné, son niveau et la visibilité de
  sa source et de ses options. Changer/retirer l'historique ne supprime pas les
  choix persistés : les anciens choix deviennent inactifs.
- Aucun builder, contrôleur, contrat API ou fichier Angular n'est modifié.

Commande : `app:dnd:import-backgrounds [path] --dry-run`.
Les règles ont une clé `phb-2014-background-<slug>-<category>` avec les catégories
skills, tools-automatic ou tools-choice. Le libellé ne sert pas d'identifiant.

Le plan complet est validé sans mutation avant application transactionnelle.
Les références personnalisées et rapprochements ambigus sont refusés. Sans
`--update-existing`, une différence reste inchangée (keep). Avec cette option,
les données éditoriales peuvent évoluer ; toute modification mécanique d'une
règle utilisée par un choix persisté est refusée, même si elle paraît compatible.
Une modification d'options n'efface donc jamais silencieusement un choix.
Les règles absentes du catalogue ne sont ni supprimées ni désactivées.
Les autres sources sont conservées.

## Migration et vérification

`Version20261011212316.php` est générée par Doctrine puis complétée avec les
contraintes source exclusive et origin/owner, et un rollback ordonné/protégé.
La contrainte source exclusive n'existait ni dans les migrations ni dans la base
inspectée : la migration la crée pour les cinq sources. Elle échouera si des
règles préexistantes violent cet invariant, sans les corriger silencieusement.
Le rollback refuse les historiques encore liés à des personnages ou règles.

Migration non appliquée. Le schéma courant reste donc antérieur à ce code :
le dry-run connecté s'arrête explicitement avant hydratation des nouvelles
relations jusqu'à application manuelle de la migration.

Tests : `php tools/test-import-backgrounds.php`, fixtures entièrement en mémoire,
134 assertions. Les quatre suites de maîtrises existantes passent également
(323 assertions). Conteneur Symfony et mapping Doctrine validés ; synchronisation
avec la base volontairement non vérifiée, puisque la migration reste en attente.
Aucun import avec écriture ni migration de test exécuté.

