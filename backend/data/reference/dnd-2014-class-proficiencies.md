# Maîtrises des classes du PHB 2014

Sources vérifiées le 11 octobre 2026 :

- [Classes, Basic Rules 2014, Wizards / D&D Beyond](https://www.dndbeyond.com/sources/dnd/basic-rules-2014/classes)
- [Multiclassage, Basic Rules 2014, Wizards / D&D Beyond](https://www.dndbeyond.com/sources/dnd/basic-rules-2014/customization-options)
- [Table française du multiclassage 2014, AideDD](https://www.aidedd.org/regles/personnalisation/multiclassage/)

Périmètre : sections « Proficiencies » de niveau 1 des douze classes et table
« Multiclassing Proficiencies ». Pas de sous-classes, capacités ultérieures,
historiques, langues ni règles 2024. Les libellés sont éditoriaux ; les listes
utilisent les slugs du catalogue de définitions existant.

| Classe | STARTING_CLASS | MULTICLASS |
| --- | ---: | ---: |
| Barbare | 4 | 2 |
| Barde | 5 | 3 |
| Clerc | 4 | 1 |
| Druide | 5 | 1 |
| Ensorceleur | 3 | 0 |
| Guerrier | 4 | 2 |
| Magicien | 3 | 0 |
| Moine | 4 | 1 |
| Occultiste | 4 | 2 |
| Paladin | 4 | 2 |
| Rôdeur | 4 | 3 |
| Roublard | 5 | 3 |
| **Total** | **49** | **20** |

Une règle regroupe les options d'une catégorie : sauvegardes, armes, armures,
outils ou compétences. Une catégorie absente ne produit pas de règle vide.
Les armes individuelles et groupes d'armes restent des options distinctes.
Le moine choisit UN outil parmi 17 outils d'artisan et 10 instruments, pas un de
chaque catégorie. Le barde choisit 3 instruments initialement, 1 en multiclassage.
Aucune sauvegarde n'est accordée par les règles de multiclassage.

## Import

Commande : `app:dnd:import-class-proficiencies [path] --dry-run`.
`--update-existing` autorise explicitement la mise à jour des différences.

Prérequis : migrations de maîtrises et de `import_key` appliquées après revue,
douze classes officielles présentes et définitions du catalogue de maîtrises
déjà importées. La commande ne crée aucune classe ni définition manquante et
n'applique aucune migration elle-même.

`import_key` est nullable et unique. La clé `phb-2014-<classe>-<mode>-<catégorie>`
est indépendante du libellé et du contenu des options. La migration laisse les
anciennes règles sans clé. Une règle sans clé avec un contenu identique, ou une
autre clé avec le même contenu/libellé dans le même contexte, est un conflit à
rapprocher manuellement ; aucune adoption ou suppression implicite.

Le plan complet est validé avant les écritures. L'écriture est transactionnelle,
avec verrouillage des tables de règles/options/choix pendant l'import. Les
références personnalisées sont protégées. Une modification mécanique d'une règle
utilisée par des choix est refusée ; une correction du libellé conserve son ID.
L'absence d'une règle dans le catalogue ne supprime jamais une règle en base.
Un nouvel import inchangé produit uniquement `unchanged`.

## Limites explicites

- Le druide maîtrise les catégories d'armures et boucliers indiquées, mais le
  modèle ne sait pas exprimer sa restriction sur le métal. Cette restriction
  n'est pas appliquée par ces règles de maîtrise.
- L'expertise du roublard n'est pas une maîtrise supplémentaire. Son choix parmi
  les maîtrises déjà acquises et le doublement du bonus restent hors modèle.
- Les groupes d'armes ne sont pas développés en armes individuelles par le
  résolveur actuel. Ce catalogue ne change pas cette limite.
- Les outils et instruments proposés sont ceux du catalogue PHB disponible.
  Les instruments/métiers supplémentaires permis par le MJ ne sont pas inventés.
- Les doublons entre sources sont conservés sans substitution automatique.

## Tests sans écriture

`php tools/test-import-class-proficiencies.php` vérifie le catalogue complet,
les cas de classe, le résolveur réel, les choix, les plans de création/réimport,
les mises à jour en mémoire et les rejets. Aucun SQL ni import avec écriture n'est
exécuté par ce test ; la persistance et les contraintes SQL devront être vérifiées
sur une base de test migrée lors d'un jalon autorisant ce type de vérification.
