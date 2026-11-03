# Import des objets magiques SRD 5.1 (D&D 2014)

Exécuter `php bin/console app:import-srd-magic-items`.

Source structurée : https://raw.githubusercontent.com/5e-bits/5e-database/main/src/2014/en/5e-SRD-Magic-Items.json

Hors réseau : télécharger ce JSON, puis `php bin/console app:import-srd-magic-items --file=/chemin/vers/fichier.json`.

Import relançable par clé `srd-5.1:magic-item:<index>`. Aucune définition CUSTOM n'est modifiée. Descriptions en anglais, propriétés complexes descriptives uniquement (pas d'automatisation implicite des charges ou sorts). Les familles d'objets sont classées à titre indicatif.

## Attribution

This work includes material taken from the System Reference Document 5.1 (“SRD 5.1”) by Wizards of the Coast LLC and available at https://dnd.wizards.com/resources/systems-reference-document. The SRD 5.1 is licensed under the Creative Commons Attribution 4.0 International License available at https://creativecommons.org/licenses/by/4.0/legalcode.

Structured dataset: 5e-bits / 5e-database, https://github.com/5e-bits/5e-database.
