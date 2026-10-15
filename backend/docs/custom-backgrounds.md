# Historiques personnalisés

L’onglet **Historiques** de `/custom-content` affiche les historiques OFFICIAL
en consultation et les CUSTOM du MJ connecté en édition. Les historiques CUSTOM
sont proposés dans le builder des campagnes appartenant à ce MJ.

## Installation

La migration additive `Version20261015120000` ajoute équipement, nom et texte
d’aptitude, ainsi que quatre listes JSON : traits de personnalité, idéaux, liens,
défauts. Elle conserve les identifiants, descriptions, règles et associations.
Elle est testée sur des tables temporaires ; elle doit être appliquée avec le
processus normal de migration avant d’utiliser l’éditeur. Aucun import requis.

Validation locale du 15 octobre 2026 : migration appliquée en développement,
schéma Doctrine synchronisé. Les empreintes des champs préexistants des
historiques, des personnages et de leurs états de session sont inchangées.
Aucune opération de production effectuée.

Les descriptions existantes, notamment celle de Vagabond des Brumes, ne sont pas
découpées automatiquement. Ses deux maîtrises sont reconnues telles quelles.

## Formulaire et règles

- Slug généré depuis le nom à la création, modifiable ; unicité globale existante.
- Nombre de compétences et d’outils libre pour CUSTOM.
- Chaque groupe comporte un libellé, des options et un nombre de choix.
  Zéro signifie attribution automatique de toutes les options.
- Compétences et outils doivent être placés dans des groupes distincts.
- Les groupes de langues suivent le même principe.
- Les options doivent être accessibles au MJ. Doublons, références invalides,
  nombre de choix excessif et combinaisons impossibles sont refusés côté backend.
- Équipement, aptitude et propositions narratives sont du texte ; ils ne créent
  pas d’inventaire, de ressource ou de capacité mécanique supplémentaire.

## Historique utilisé

Le slug et les règles mécaniques sont verrouillés dès qu’un personnage utilise
l’historique. Les anciens choix/substitutions de maîtrise et les langues liées
par `sourceKey` le protègent également, même après changement d’historique.
Une attribution d’expertise ou une dépendance incohérente non gérée par cet
éditeur empêche aussi les changements mécaniques et la suppression.

Nom, description, équipement, nom/texte d’aptitude et listes narratives restent
modifiables. Une sauvegarde descriptive n’altère ni les choix ni l’état de session.
Les règles conservées gardent leurs IDs et clés, même après changement de slug
d’un historique inutilisé. Aucune duplication automatique en cas d’historique utilisé.

## API authentifiée

Préfixe Angular `/api`, routes Symfony sans ce préfixe :

| Méthode | Route | Usage |
|---|---|---|
| GET | `/reference/custom/backgrounds` | Liste OFFICIAL et CUSTOM du MJ |
| GET | `/reference/custom/backgrounds/options` | Compétences, outils et langues accessibles |
| GET | `/reference/custom/backgrounds/{id}` | Détail accessible |
| POST | `/reference/custom/backgrounds` | Création CUSTOM appartenant à l’utilisateur connecté |
| PATCH | `/reference/custom/backgrounds/{id}` | Mise à jour partielle de son CUSTOM |
| DELETE | `/reference/custom/backgrounds/{id}` | Suppression de son CUSTOM inutilisé |

Écriture OFFICIAL/tiers : 404 ; validation : 400 ; usage protégé ou slug en
collision : 409. `origin` et `owner_id` ne sont jamais acceptés en entrée.
Les mutations parent/règles/options sont atomiques. Aucun endpoint joueur ajouté.

## Vérifications

```sh
docker compose exec -T backend php tools/test-custom-reference-backgrounds.php
docker compose exec -T backend php tools/test-backgrounds-supplements-roundtrip.php
docker compose exec -T backend php bin/console lint:container
docker compose exec -T frontend npm test -- --watch=false '--include=src/app/features/custom-content/**/*.spec.ts'
docker compose exec -T frontend npm run build
git diff --check
```

Les tests backend utilisent des tables et séquences temporaires PostgreSQL avec
rollback, dont la migration sur un schéma antérieur isolé. Les tests frontend
exercent l’intégration à la page, les champs, les erreurs, les verrouillages et la
suppression avec HTTP simulé.
