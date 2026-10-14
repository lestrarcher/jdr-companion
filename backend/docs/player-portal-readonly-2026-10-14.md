# Portail joueur : fiche commune LIVE et CLOSED

> **État de livraison — 14 octobre 2026.** Voir l'[état consolidé](artificer-proficiencies-2014.md#état-de-livraison--14-octobre-2026).
> Migration Bricolages et import artificier réalisés localement ; tests manuels
> LIVE/CLOSED/LIVE satisfaisants, selon confirmation du MJ. Résolution du 503
> médias non confirmée en production. Aucun déploiement effectué.
>
> **Historique :** le contenu ci-dessous conserve les constats, tests et consignes
> du lot à sa date de rédaction. Les mentions d'opérations encore à autoriser,
> de validation manuelle restante ou de l'ancien écran fermé sont historiques.
> Aucun import ni migration supplémentaire n'a été exécuté pour les commits.


## Cause et correction

Le template remplaçait toute la fiche hors LIVE par « Cette session est
terminée ». Une exception affichait uniquement les ressources et notes de
Bricolages magiques : elle expliquait la différence entre Bloup et Nikita.
Cette branche est supprimée. La fiche, les onglets et les composants sont
désormais communs ; `canEdit` dépend exclusivement du statut LIVE.

Les GET publics fournissent déjà le profil complet hors LIVE. Aucun endpoint,
permission MJ, token, modèle de persistance ou code applicatif backend n'est
modifié dans ce lot. DRAFT reste également consultable sans édition.

Les commandes PV, dés de vie, progressions, ressources, présages, inspiration,
repos, préparation/actions, bourse et objets magiques sont masquées ou
désactivées en lecture seule. Les gestionnaires de mutation sont protégés
également. Les notes de bricolages réutilisent leur affichage consultatif.
Les GET de bourse/objets et les interactions de consultation restent actifs.

Le changement de statut annule le timer de sauvegarde, invalide les snapshots
en attente et désabonne la sauvegarde globale en cours. Les valeurs serveur
remplacent les modifications locales non envoyées ; celles-ci ne sont pas
renvoyées à la réouverture. Le polling peut observer une fermeture même si une
sauvegarde locale est en attente. Une nouvelle édition LIVE fonctionne ensuite.

Limite réseau : le statut est détecté au polling (toutes les cinq secondes).
Avant cette détection, le serveur reste responsable de refuser une écriture
sur session fermée. Une requête déjà exécutée en LIVE ne peut pas être annulée
rétroactivement. Aucun nouveau système temps réel n'est introduit.

## Fichiers de ce lot

Sous `frontend/src/app/features/player-portal/` :

- `player-portal.ts`, `.html`, `.scss` : rendu commun, droits et annulation.
- `components/character-vitals/character-vitals.{ts,html,scss}`.
- `components/character-progressions/character-progressions.{ts,html,scss}`.
- `components/character-stored-values/character-stored-values.{ts,html}`.
- `components/character-magic-items/character-magic-items.{ts,html,scss}`.
- `components/character-wallet/character-wallet.html`.
- `player-portal-readonly.spec.ts` (nouveau),
  `player-portal-inspiration.spec.ts`,
  `components/flexible-casting-action-modal/flexible-casting-action-modal.spec.ts`.

Backend : `tools/test-player-security.php` et ce compte rendu. Les composants
Ressources et notes utilisent leurs protections déjà présentes dans le lot
précédent. Les autres modifications non commitées sont conservées.

## Vérifications

- Backend `php tools/test-player-security.php` : 341 assertions réussies,
  dont 50 inspiration ; profil et état identiques LIVE/CLOSED, ressources,
  notes et refus d'écriture directe. Fixtures SQL temporaires et rollback.
- Angular `npm test -- --watch=false` : 77 tests réussis dans 12 fichiers ;
  rendu complet artificier/magicien, navigation mobile/desktop,
  consultation bourse/objets, gardes readonly, absence d'écriture initiale et
  sur navigation, annulation du délai et réouverture couverts.
- Build Angular réussi ; budget SCSS déjà dépassé, désormais 11,51 kB / 10 kB.
- `lint:container` réussi. Pas de script lint Angular dans `package.json`.
- `git diff --check` réussi. Avertissement Angular préexistant sur l'attribut
  disabled d'un contrôle réactif de la bourse en LIVE ; formulaire absent en CLOSED.
- Aucun import, migration, changement de données réelles, commit ou push.

L'onglet Sorts était et reste un emplacement réservé (« Bientôt disponible »)
dans les deux modes ; les emplacements sont affichés dans Ressources.
Les tests simulent les dispositions mobile/desktop ; aucune validation visuelle
sur tablette ou téléphone réel n'a été effectuée.

## Validation manuelle

1. En LIVE, ouvrir Bloup puis Nikita : parcourir tous les onglets, modifier un
   compteur/les notes et vérifier la sauvegarde puis le rechargement.
2. Fermer la session depuis le MJ en laissant le portail ouvert. Après le
   polling, vérifier la même fiche, ses valeurs et l'indication Lecture seule.
   Vérifier aussi une actualisation complète du navigateur en CLOSED.
3. Parcourir onglets/descriptions, bourse et objets. Aucune commande d'édition
   utilisable ; dans Réseau, aucune nouvelle requête PATCH/POST/DELETE.
4. Réouvrir LIVE : aucune ancienne modification locale ne doit partir.
   Effectuer une nouvelle édition et vérifier qu'elle est sauvegardée.
5. Répéter sur tablette/mobile, notamment ressources, notes et navigation.
