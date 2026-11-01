# Enrichissement français AideDD — JdR Companion

## Couverture et limites

- Source SRD anglais : 362 entrées (fichier JSON 5e-bits transmis).
- Index AideDD fourni : 323 lignes, dont certains objets non-SRD.
- **232 correspondances exactes uniques** sur le nom anglais, après normalisation des accents et de la ponctuation.
- **130 entrées sans correspondance exacte** : surtout variantes (+1/+2/+3, couleurs, types de géants, etc.), et quelques noms alternatifs. Elles sont dans `aidedd-unmatched.csv`. Ne pas les associer automatiquement par similarité.
- L'index AideDD est un instantané fourni dans la conversation, pas une extraction fraîche. Le script télécharge les fiches à l'exécution.

## Étape 1 — télécharger les descriptions

Depuis la racine du dépôt :

```bash
python -m pip install beautifulsoup4
python scripts/build_aidedd_translations.py --limit 2
```

Contrôler `backend/data/aidedd-translations.json` pour les deux premières fiches. Si le contenu est correct :

```bash
python scripts/build_aidedd_translations.py
```

Le script stocke les réponses HTML en cache, reprend après interruption, impose au moins une seconde entre téléchargements et ne modifie jamais la base. Une erreur d'extraction apparaît dans `backend/data/aidedd-translations.errors.json`. **Si les deux premiers tests extraient du menu ou des éléments hors fiche, ne pas importer : adapter le sélecteur HTML.**

## Étape 2 — mise à jour Symfony

La commande française déjà fournie est nécessaire (`--translations-file`). Utiliser le JSON SRD anglais original (362 entrées) :

```bash
docker compose exec backend php bin/console app:import-srd-magic-items \
  --file=backend/data/5e-SRD-Magic-Items.json \
  --translations-file=backend/data/aidedd-translations.json
```

**Attention :** si la racine Symfony est `/app`, adapter les chemins (`data/...` au lieu de `backend/data/...`). Vérifier le montage Docker et sauvegarder la base avant l'import. Si le JSON anglais n'est pas présent, omettre `--file` pour utiliser l'URL 5e-bits de la commande.

La commande actualise uniquement les entrées dont la clé d'import est `srd-5.1:magic-item:<index>` et dont la traduction est fournie. Elle ne modifie pas les objets personnalisés, ni les charges ou sorts configurés. **Elle réécrit néanmoins les champs descriptifs, classification, rareté et harmonisation des objets officiels correspondants.**

## Étape 3 — variantes restantes

Examiner `backend/data/aidedd-unmatched.csv` et préparer des correspondances explicites pour les variantes. Ne pas lancer un remplacement approximatif de 130 entrées.

## Droits

Source des textes : AideDD. Usage privé annoncé par l'utilisateur ; vérifier les autorisations avant toute publication ou redistribution.
