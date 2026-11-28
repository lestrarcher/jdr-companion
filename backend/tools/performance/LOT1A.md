# Lot 1A — résolutions partagées pendant la lecture d'un profil

## Architecture et périmètre

`CharacterFeatureReadContext::read()` capture une résolution runtime avec choix,
le personnage, l'identité du propriétaire et une copie des progressions. Le profil
passe explicitement ce contexte aux ressources, caractéristiques, PV et maîtrises.
Les paramètres sont facultatifs pour conserver les appelants existants.

Durée de vie : un appel synchrone à `CharacterProfileSerializer::serialize`.
Le `finally` retire les règles du contexte à la sortie, y compris sur exception.
Un contexte conservé par erreur retourne alors `null` ; les consommateurs
recalculent indépendamment. Un autre objet personnage, propriétaire ou ensemble
de progressions provoque aussi ce repli. Les contextes imbriqués sont indépendants.
Chaque nouvelle sérialisation résout de nouveau, même avec le même EntityManager.

La projection doit rester sans mutation. Ce contrat n'est pas un détecteur
générique de mutations d'entités : ne pas étendre le contexte autour d'une action,
d'un repos ou d'un level-up. Aucun contexte n'est stocké dans un service, une
requête, une propriété statique ou un cache persistant. La classe est exclue
de l'autowiring.

Les simulations de level-up ignorent explicitement le contexte. Les acquisitions,
les statistiques de sorts et les maximums de ressources calculés sans progression
conservent leurs chemins indépendants. La sérialisation complète de session
bénéficie du partage via son appel au profil ; aucun contexte ne traverse le
reste de sa lecture. Les deux parcours des capacités dans un même appel à
`CharacterResourceResolver::resolve` utilisent également le même tableau local.

## Fichiers applicatifs et tests

- Nouveau : `src/Dto/CharacterFeatureReadContext.php`.
- Modifiés : `CharacterProfileSerializer`, `CharacterResourceResolver`,
  `CharacterAbilityCalculator`, `CharacterHitPointCalculator`,
  `CharacterTrainingPresenter`, `CharacterTrainingResolver`,
  `CharacterProficiencyResolver`, dans `src/Service/`.
- Tests : trois scénarios ajoutés à `tests/Integration/CustomFeatureEffectsTest.php`.
- Documentation : ce fichier et le statut du plan dans `BENCHMARK.md`.

Aucun changement du resolver central, du contrat JSON, du frontend, du schéma,
des migrations, de la configuration PostgreSQL ou du banc de mesure.
Aucun commit, push ou déploiement.

## Mesures locales : sérialisation complète

Avant : cinq mesures déjà conservées du lot 0, sans nouvelle exécution.
Après : un échauffement et trois mesures par état et par mode (six groupes).
Les jeux de données et la clé HMAC sont identiques. Les sorties normalisées
sont strictement équivalentes avant/après, stables entre itérations et modes,
et les empreintes de la base sont inchangées.

Chaque cellule indique **avant → après**, médianes. SQL/répétitions/appels sont
instrumentés ; CPU, temps et pic mémoire proviennent des séries légères.

| État | SQL | Répétitions exactes | `resolve` | CPU ms | Écoulé ms | Pic alloué Mio |
|---|---:|---:|---:|---:|---:|---:|
| 27 | 1243 → 1022 | 844 → 623 | 46 → 33 | 4340 → 3364 | 5623 → 4428 | 28 → 28 |
| 34 | 680 → 563 | 418 → 301 | 44 → 31 | 3960 → 2991 | 4840 → 3703 | 26 → 26 |
| 35 | 2897 → 2143 | 2501 → 1747 | 40 → 27 | 3965 → 2836 | 6310 → 4648 | 26 → 26 |

Le pic mémoire PHP inclut les allocations conservées par le banc ; ce n'est pas
le RSS. Trois échantillons ne permettent pas une estimation robuste des queues
de latence (p95 = maximum). Les modes ont été exécutés séparément ; la dispersion
explique que le mode léger ne soit pas systématiquement plus rapide. Ces mesures
locales ne garantissent pas le même gain sur Alwaysdata.

Résultats locaux ignorés par Git : `var/performance/lot1a-before-full.json`
(extraction des six groupes du lot 0), `lot1a-after-full.json`,
`lot1a-comparison.txt`, `lot1a-http.json`. Les rapports JSON conservent toutes les
mesures, médianes, p95 et maxima, dont SQL par forme et temps DBAL.

## Validation et commandes

Tests ciblés : 12 tests / 1040 assertions pour les effets personnalisés,
5 tests / 467 assertions pour caractéristiques et PV. Fixtures isolées dans
des tables temporaires, sans modification des données applicatives.
Les nouveaux tests couvrent progression sous/au-dessus/au seuil, valeurs copiées,
absence de contexte, autre personnage/propriétaire, contextes imbriqués,
activation/acquisition distinctes, fermeture sur exception, puis perte et retour
d'une progression et changement d'activation entre deux lectures du même profil.
`lint:container` et `git diff --check` réussis.

```powershell
docker compose exec -T backend php bin/console lint:container
docker compose exec -T backend php bin/phpunit tests/Integration/CustomFeatureEffectsTest.php --filter '/test(?!.*Migration)/'
docker compose exec -T backend php bin/phpunit tests/Integration/PermanentHitPointEffectsTest.php --filter '/test(Ability|AcquiredAbility|UnknownMaximum|FeatureAndEquipment|Missing|Incomplete)/'
docker compose exec -T backend php tools/performance/benchmark.php --states=27,34,35 --operations=full --modes=instrumented,light --iterations=3 --warmup=1 --output=var/performance/lot1a-after-full.json
docker compose exec -T backend php tools/performance/benchmark.php --compare=var/performance/lot1a-before-full.json --against=var/performance/lot1a-after-full.json
docker compose exec -T backend php tools/performance/http.php --states=35 --iterations=3 --warmup=1 --output=var/performance/lot1a-http.json
docker compose exec -T backend php tools/performance/verify-http.php --services=var/performance/lot0-services-reference.json --http=var/performance/lot1a-http.json
```

Les sorties existantes ne sont pas écrasées : choisir un nouveau nom pour relancer.
Le contrôle HTTP compare les trois réponses de l'état 35 aux sorties métier
du lot 0 ; le profil complet après modification est aussi couvert par le
comparateur des services. Aucun benchmark complet du lot 0 n'a été relancé.

Contrôle HTTP réussi : trois réponses HTTP 200, instantanés équivalents à la
référence et base inchangée. Médianes pour l'état 35 : profil complet
6625 → 4798 ms, gestion des sorts 4745 → 4404 ms, choix 480 → 425 ms.
Les variations des chemins non mutualisés ne constituent pas un gain attribuable
à ce lot ; seules trois mesures ont été réalisées après modification.

## Risques résiduels

- Le contrat de lecture sans mutation doit être conservé lors de futurs ajouts
  au profil. Fermer la projection avant une mutation et en ouvrir une nouvelle.
- Les instantanés couvrent trois états réels, pas toutes les combinaisons métier.
- Les résolutions restantes, choix SQL répétitifs, sorts et rafraîchissements
  frontend restent hors de ce lot ; la saturation en production n'est pas
  déclarée résolue sur la seule base de ces mesures locales.
