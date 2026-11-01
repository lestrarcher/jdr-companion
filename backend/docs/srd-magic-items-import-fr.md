# Import SRD 5.1 — catalogue français

Le fichier source anglais sert uniquement aux métadonnées et aux identifiants techniques.

## Exécution

```bash
php bin/console app:import-srd-magic-items --file=/chemin/5e-SRD-Magic-Items.json --translations-file=/chemin/traductions-fr.json
```

Sans `--file`, la commande tente de télécharger la source SRD anglaise. Le fichier français doit être un objet JSON indexé par `index` SRD ; chaque entrée doit contenir `name` et `description` non vides. Voir `backend/data/srd-magic-items-fr.example.json` (exemple de format, **pas** une traduction complète).

Les objets sans traduction sont ignorés, pas importés en anglais. La commande ne modifie pas les objets CUSTOM. Elle réactualise les objets qu'elle a elle-même importés, identifiés par `srd-5.1:magic-item:<index>` ; ne pas utiliser cette commande pour conserver des modifications manuelles sur ces objets importés.

La classification est indicative. Les charges, recharges et sorts octroyés ne sont pas configurés automatiquement à partir des descriptions. Les traductions officielles non libres doivent être fournies depuis une source dont vous avez le droit d'utiliser le contenu.

Attribution SRD 5.1 : https://creativecommons.org/licenses/by/4.0/ ; données structurées : https://github.com/5e-bits/5e-database.
