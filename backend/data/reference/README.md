# Catalogue des dons D&D 2014

`dnd-2014-feats.json` est un instantané contrôlé de 83 dons recensés dans
https://www.aidedd.org/dnd-filters/dons.php, collecté le 10 septembre 2026.
Seul le tableau a été consulté : noms, prérequis, ouvrages et liens.
Les descriptions sont des résumés éditoriaux courts, pas les textes des fiches.
Les titres d'ouvrages utilisent des apostrophes normalisées ; la mention SRD
du tableau n'est pas un ouvrage distinct.

Les neuf entrées `approved` reproduisent les paramètres mécaniques de la base
au moment de cette préparation. Cette approbation ne certifie pas leurs effets
supplémentaires ni l'application automatique des prérequis.
Les 74 autres entrées sont `pending` ; leurs quatre champs mécaniques sont
inconnus (`null`). Chaque entrée indique les points à vérifier.

Les slugs sont dérivés des noms anglais. Les anciens slugs français ne doivent
pas servir d'identifiants applicatifs. Certaines URL Aidedd conservent ces mots :
il s'agit de provenance externe, pas de références aux slugs de notre base.

Validation hors réseau et sans base :

```sh
php backend/tools/validate-feat-catalogue.php --self-test
# Depuis Docker Compose :
docker compose exec backend php tools/validate-feat-catalogue.php --self-test
```

Le conteneur backend ne monte pas les sources frontend : leur absence est
signalée et leur recherche de références doit alors être effectuée depuis
le workspace. Le validateur vérifie les champs exacts, les 83 slugs anglais
uniques, les limites de l'entité et les neuf configurations approuvées.
Son auto-test vérifie aussi le rejet de catalogues invalides, en mémoire.

La migration `Version20260910120000` renomme uniquement les trois dons existants,
en conservant leurs identifiants. Elle refuse une collision entre ancien et
nouveau slug et accepte une base sans ces dons ou déjà renommée. Son `down`
applique la correspondance inverse avec le même contrôle de collision.

Import explicite (depuis le conteneur backend) :

```sh
php bin/console app:dnd:import-feats --dry-run
php bin/console app:dnd:import-feats /chemin/catalogue.json --dry-run --update-existing
php bin/console app:dnd:import-feats
```

Le chemin est optionnel et pointe par défaut sur ce catalogue. Le fichier
entier est validé avant toute écriture. Les entrées `pending` sont ignorées.
Les dons approuvés absents sont créés ; les existants sont conservés par défaut,
avec affichage des différences champ par champ. `--update-existing` autorise
explicitement leur mise à jour, sur la même entité. Aucune suppression.
Les écritures sont transactionnelles et le dry-run ne modifie aucune entité.
Les métadonnées restent dans le JSON. Les listes de caractéristiques sont
comparées comme des ensembles ; les textes sont normalisés comme par `Feat`.

`FeatCatalogueValidator` partage la validation entre commande et script.
La commande accepte de nouvelles approbations cohérentes ; le script conserve
en plus le contrôle de la configuration initiale des neuf dons, pour cet
instantané. Ce contrôle devra évoluer lors de futures validations du catalogue.

Tests ciblés, sans dépendance PHPUnit supplémentaire :

```sh
docker compose exec backend php tools/test-import-feats.php
```

Ils utilisent une table PostgreSQL temporaire et une transaction annulée,
sans toucher aux dons réels ni à leurs séquences. Le catalogue n'est pas chargé
au runtime. Les 74 dons en attente ne sont pas importés.
