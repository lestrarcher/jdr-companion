# Patch grimoires : catalogue + exemplaires en réserve

## Installation
Depuis la racine du projet :

```bash
unzip -o jdr-grimoires-catalogue-reserve.zip
docker compose exec backend php bin/console cache:clear
docker compose exec frontend npm run build
```

Aucune migration : le schéma de réserve déjà migré est réutilisé.

## Fonctionnement
1. Dans « Objets magiques » > « Nouvel objet », choisir « Grimoire individuel », nommer « Grimoire de Nikita », laisser charges/harmonisation/octrois de sorts/effets vides, puis créer.
2. Dans « Réserve MJ — Grimoires », choisir la définition créée. Laisser les sorts vides (ou les sélectionner) et créer l'exemplaire unique dans la réserve.
3. Choisir Nikita comme destinataire et cliquer « Restituer ».
4. Lire l'ID de l'exemplaire :

```bash
docker compose exec database psql -U jdr -d jdr_companion -c "SELECT cmi.id, cmi.display_name, mi.category FROM character_magic_item cmi JOIN magic_item mi ON mi.id=cmi.magic_item_id WHERE cmi.character_id=28 AND mi.category='spellbook';"
```

5. Sur un grimoire **vide**, restaurer les 16 sorts avec la commande déjà présente :

```bash
docker compose exec backend php bin/console app:spellbooks:backfill-character 28 ID_DU_GRIMOIRE
docker compose exec backend php bin/console app:spellbooks:backfill-character 28 ID_DU_GRIMOIRE --apply
```

6. Vérifier que `SELECT COUNT(*) FROM spellbook_entry WHERE owned_item_id = ID_DU_GRIMOIRE;` retourne 16.
7. Tester un retrait et un retour depuis la réserve sur un grimoire factice avant de retester celui de Nikita.

## Portée et limites
- Un modèle du catalogue peut servir à créer plusieurs exemplaires physiques, chacun avec ses propres inscriptions. Pour un livre unique, créer un seul exemplaire.
- Le patch ne modifie pas les règles de préparation ni de copie de sorts, et n'ajoute pas de tests automatisés.
- La création de la définition et celle de l'exemplaire sont deux opérations séparées dans l'interface.
- PHP : syntaxe vérifiée sur les trois fichiers backend. Le build Angular doit être effectué dans le Docker local.
