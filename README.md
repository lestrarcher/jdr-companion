# Lot 4 — socle backend (partiel)

Ce lot contient les entités modifiées et un contrôleur/service de catalogue personnel. Routes GET/POST/PATCH uniquement, cinq catégories. **Pas de suppression** tant que les dépendances de personnages/règles ne sont pas auditées. Pas encore de gestion des paliers, règles d’ajustement, prérequis de dons ni modificateurs raciaux.

## Installation

Depuis la racine du dépôt, extraire le ZIP. Faire une sauvegarde de vos changements locaux avant extraction.

## Vérifications obligatoires

`docker compose exec backend php -l src/Service/CustomReferenceCatalogueService.php` (adapter le nom du service Docker), idem pour le contrôleur et les cinq entités.

`docker compose exec backend php bin/console debug:router | grep api_custom_reference_catalogue`

Tester les POST/PATCH avec des références de test et vérifier le contrôle de propriété. Aucun test ni exécution Symfony n'a été réalisé dans l'environnement de préparation.

**Ne pas déployer en production avant tests.** Le contrat des autres composants (enums et règles métiers) doit encore être vérifié dans le dépôt complet.
