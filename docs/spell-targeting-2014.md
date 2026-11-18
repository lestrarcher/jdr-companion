# Ciblage des sorts D&D 2014

## Périmètre et bilan DEV

Référence : `backend/data/spells-2014-targeting.json`, identités `edition` + `import_key`.
Le catalogue DEV contient **487 sorts officiels et 1 sort CUSTOM**, soit 488 au total.
Le fichier couvre les 487 identités officielles : **365 profils prêts**, dont les **14 configurations préexistantes conservées**, et **122 revues manuelles**. Le plan initial prévoit donc **351 mises à jour**. Aucun sort n’est créé ni supprimé.

La référence est une liste explicite de décisions ; l’importeur n’interprète jamais les noms ou les descriptions. Les textes et paragraphes de surclassement du catalogue local ont servi à préparer cette référence. Aucun texte intégral n’est ajouté au fichier. Les cas insuffisamment représentables restent non configurés, même si leur incompatibilité avec Sort jumelé paraît probable.

Application DEV effectuée : **351 mises à jour**, puis **0 changement** à la seconde simulation (365 inchangés, 122 revues, 0 erreur). Comparaison avant/après des 488 définitions : les 14 profils existants, le sort CUSTOM et toutes les colonnes hors ciblage sont strictement identiques.

Sources de règles : [errata du PHB 2014](https://www.dndbeyond.com/sources/dnd/sae/players-handbook-2014) et [Sage Advice Compendium 2014, Sorcerer](https://www.dndbeyond.com/sources/dnd/sac/sage-advice-compendium). La possibilité de choisir plusieurs cibles compte, même si une seule est effectivement choisie. Les cibles successives pendant la durée du même sort sont prises en compte. Une augmentation de dégâts ne constitue pas une progression multicible. Les interprétations incertaines des cibles indirectes sont laissées au MJ.

## Utilisation

Prérequis : migration `Version20261114010000` déjà appliquée, catalogue officiel déjà importé.
Depuis le backend de l’instance visée :

```sh
php bin/console app:spells:import-targeting-2014
php bin/console app:spells:import-targeting-2014 --json
php bin/console app:spells:import-targeting-2014 --apply
php bin/console app:spells:import-targeting-2014 --json
```

Sans `--apply`, aucune écriture en base. `--json` expose chaque changement (avant/après), les inchangés, les revues et les erreurs. Un code de sortie 1 indique une erreur ; les revues documentées seules ne sont pas des erreurs bloquantes. Après application réussie, une nouvelle simulation doit annoncer zéro changement.

Pour PROD : livrer le fichier et le code par le processus habituel, exécuter d’abord la simulation, examiner les erreurs et le rapport, puis appliquer uniquement après autorisation de l’exploitant. **Aucun accès ni import PROD n’a été effectué dans ce chantier.**

## Validation et préservation

- Validation complète du document, des identités uniques et des quatre propriétés via le contrat `SpellDefinition::setTargeting()` avant écriture.
- Référence absente, doublon, sort officiel non reconnu, texte modifié ou configuration différente : erreur bloquant toute application. Aucun contournement `--force`.
- Les sorts CUSTOM et les autres éditions sont exclus. Les données des personnages ne sont ni lues ni modifiées par l’importeur.
- Une configuration existante, même partielle, est conservée. Une entrée `review` possède quatre valeurs `null` et ne provoque jamais d’écriture.
- Une entrée `ready` renseigne `target_type`, un booléen `multiple_targets`, et éventuellement `slot` ou `character` avec seuil inclusif. Les valeurs `self`, `object`, `area`, `special` ne deviennent pas jumelables. Le moteur existant reste inchangé.
- Empreinte SHA-256 de `[level, range, description, higher_levels]` (JSON UTF-8 non échappé ; espaces et fins de ligne normalisés). Une différence de contenu avec PROD demande une nouvelle revue, pas une correction automatique des descriptions.
- Application en une transaction, avec verrou de table empêchant une édition concurrente entre planification et écriture. Seules les quatre colonnes sont mises à jour. Une erreur SQL annule toutes les écritures.
- Pour ajouter une décision validée : revoir la description complète et le surclassement, mettre à jour l’entrée JSON et son empreinte, passer à `ready`, puis tester/simuler. Pas de migration supplémentaire nécessaire. Les configurations divergentes doivent être arbitrées explicitement hors import.

## Validations exécutées

26 tests backend ciblés, 1 735 assertions : simulation sans écriture, application, idempotence, validation globale avant écriture, absence de références, doublons, texte divergent, configurations existantes et partielles, exclusion CUSTOM/autres éditions, rollback sur erreur SQL, commande Symfony et non-régressions des coûts/reçus de métamagie. Tables de test temporaires uniquement. Validation du conteneur Symfony réussie.

## Revue manuelle — liste exhaustive

Ces sorts ne sont pas automatiquement configurés. Le nom et la raison sont également présents dans le JSON et le rapport de commande. Les configurations manuelles éventuelles restent conservées.

| Sort | Identité | Motif |
| --- | --- | --- |
| Alarme | aidedd-2014:alarme | Plusieurs modes possibles avec types de cibles ou zones différents ; aucun profil simple unique validé. |
| Allié planaire | aidedd-2014:allie-planaire | Création ou invocation d’une entité dans un espace : le nombre d’entités invoquées n’est pas le nombre de cibles ; ce modèle ne représente pas explicitement cette distinction. |
| Animation des morts | aidedd-2014:animation-des-morts | Cible morte, restes ou âme : le modèle ne distingue pas créature vivante, cadavre et âme ; ne pas autoriser le jumelage par assimilation. |
| Appel de destrier | aidedd-2014:appel-de-destrier | Création ou invocation d’une entité dans un espace : le nombre d’entités invoquées n’est pas le nombre de cibles ; ce modèle ne représente pas explicitement cette distinction. |
| Appel de destrier supérieur | aidedd-2014:appel-de-destrier-superieur | Création ou invocation d’une entité dans un espace : le nombre d’entités invoquées n’est pas le nombre de cibles ; ce modèle ne représente pas explicitement cette distinction. |
| Appel de familier | aidedd-2014:appel-de-familier | Création ou invocation d’une entité dans un espace : le nombre d’entités invoquées n’est pas le nombre de cibles ; ce modèle ne représente pas explicitement cette distinction. |
| Arme sacrée | aidedd-2014:arme-sacree | Objet initial puis dégâts ou effets sur des créatures ; distinguer les cibles directes et secondaires avant de renseigner le multiciblage. |
| Arme spirituelle | aidedd-2014:arme-spirituelle | Effet créé, autonome ou mobile avec cibles successives ; séparer l’espace de création des cibles de ses actions avant configuration. |
| Assignation infernale | aidedd-2014:assignation-infernale | Création ou invocation d’une entité dans un espace : le nombre d’entités invoquées n’est pas le nombre de cibles ; ce modèle ne représente pas explicitement cette distinction. |
| Baies nourricières | aidedd-2014:baies-nourricieres | Création d’objet, de nourriture ou de double avec bénéficiaires ultérieurs ; ne pas assimiler la création à une cible existante. |
| Cage des âmes | aidedd-2014:cage-des-ames | Cible morte, restes ou âme : le modèle ne distingue pas créature vivante, cadavre et âme ; ne pas autoriser le jumelage par assimilation. |
| Catapulte | aidedd-2014:catapulte | Objet initial puis dégâts ou effets sur des créatures ; distinguer les cibles directes et secondaires avant de renseigner le multiciblage. |
| Cercle de téléportation | aidedd-2014:cercle-de-teleportation | Déplacement, portail ou espace extradimensionnel avec voyageurs/bénéficiaires distincts du point ciblé ; représentation unique à valider. |
| Cérémonie | aidedd-2014:ceremonie | Plusieurs modes possibles avec types de cibles ou zones différents ; aucun profil simple unique validé. |
| Chien de garde de Mordenkainen | aidedd-2014:chien-de-garde-de-mordenkainen | Effet créé, autonome ou mobile avec cibles successives ; séparer l’espace de création des cibles de ses actions avant configuration. |
| Clairvoyance | aidedd-2014:clairvoyance | Illusion, lumière ou capteur créé dans un espace ; les observateurs ne sont pas automatiquement les cibles. Modélisation à confirmer. |
| Clone | aidedd-2014:clone | Création d’objet, de nourriture ou de double avec bénéficiaires ultérieurs ; ne pas assimiler la création à une cible existante. |
| Coffre secret de Léomund | aidedd-2014:coffre-secret-de-leomund | Cible environnementale, objets ou modes multiples ; contrôle manuel du type et de la cardinalité requis pour éviter une approximation. |
| Communication à distance | aidedd-2014:communication-a-distance | Lien de communication ou de perception avec plusieurs participants potentiels ; distinction entre cible et destinataire/bénéficiaire à confirmer. |
| Communication avec les morts | aidedd-2014:communication-avec-les-morts | Cible morte, restes ou âme : le modèle ne distingue pas créature vivante, cadavre et âme ; ne pas autoriser le jumelage par assimilation. |
| Contresort | aidedd-2014:contresort | Réaction à un lancement précis : plusieurs lanceurs simultanés et ciblage du sort interrompu à arbitrer avant automatisation. |
| Contrôle des flammes | aidedd-2014:controle-des-flammes | Cible environnementale, objets ou modes multiples ; contrôle manuel du type et de la cardinalité requis pour éviter une approximation. |
| Convocation d'aberration | aidedd-2014:convocation-d-aberration | Création ou invocation d’une entité dans un espace : le nombre d’entités invoquées n’est pas le nombre de cibles ; ce modèle ne représente pas explicitement cette distinction. |
| Convocation d'artificiel | aidedd-2014:convocation-d-artificiel | Création ou invocation d’une entité dans un espace : le nombre d’entités invoquées n’est pas le nombre de cibles ; ce modèle ne représente pas explicitement cette distinction. |
| Convocation d'élémentaire | aidedd-2014:convocation-d-elementaire | Création ou invocation d’une entité dans un espace : le nombre d’entités invoquées n’est pas le nombre de cibles ; ce modèle ne représente pas explicitement cette distinction. |
| Convocation d'esprit draconique | aidedd-2014:convocation-d-esprit-draconique | Création ou invocation d’une entité dans un espace : le nombre d’entités invoquées n’est pas le nombre de cibles ; ce modèle ne représente pas explicitement cette distinction. |
| Convocation de bête | aidedd-2014:convocation-de-bete | Création ou invocation d’une entité dans un espace : le nombre d’entités invoquées n’est pas le nombre de cibles ; ce modèle ne représente pas explicitement cette distinction. |
| Convocation de céleste | aidedd-2014:convocation-de-celeste | Création ou invocation d’une entité dans un espace : le nombre d’entités invoquées n’est pas le nombre de cibles ; ce modèle ne représente pas explicitement cette distinction. |
| Convocation de démon majeur | aidedd-2014:convocation-de-demon-majeur | Création ou invocation d’une entité dans un espace : le nombre d’entités invoquées n’est pas le nombre de cibles ; ce modèle ne représente pas explicitement cette distinction. |
| Convocation de démons mineurs | aidedd-2014:convocation-de-demons-mineurs | Création ou invocation d’une entité dans un espace : le nombre d’entités invoquées n’est pas le nombre de cibles ; ce modèle ne représente pas explicitement cette distinction. |
| Convocation de fée | aidedd-2014:convocation-de-fee | Création ou invocation d’une entité dans un espace : le nombre d’entités invoquées n’est pas le nombre de cibles ; ce modèle ne représente pas explicitement cette distinction. |
| Convocation de fiélon | aidedd-2014:convocation-de-fielon | Création ou invocation d’une entité dans un espace : le nombre d’entités invoquées n’est pas le nombre de cibles ; ce modèle ne représente pas explicitement cette distinction. |
| Convocation de mort-vivant | aidedd-2014:convocation-de-mort-vivant | Création ou invocation d’une entité dans un espace : le nombre d’entités invoquées n’est pas le nombre de cibles ; ce modèle ne représente pas explicitement cette distinction. |
| Convocation de rejeton d'ombre | aidedd-2014:convocation-de-rejeton-d-ombre | Création ou invocation d’une entité dans un espace : le nombre d’entités invoquées n’est pas le nombre de cibles ; ce modèle ne représente pas explicitement cette distinction. |
| Convocations instantanées de Drawmij | aidedd-2014:convocations-instantanees-de-drawmij | Cible environnementale, objets ou modes multiples ; contrôle manuel du type et de la cardinalité requis pour éviter une approximation. |
| Corde enchantée | aidedd-2014:corde-enchantee | Déplacement, portail ou espace extradimensionnel avec voyageurs/bénéficiaires distincts du point ciblé ; représentation unique à valider. |
| Cordon de flèches | aidedd-2014:cordon-de-fleches | Objet initial puis dégâts ou effets sur des créatures ; distinguer les cibles directes et secondaires avant de renseigner le multiciblage. |
| Coup au but | aidedd-2014:coup-au-but | La description dit seulement cible, sans limiter explicitement aux créatures ; ne pas déduire un ciblage exclusif. |
| Couronne du dément | aidedd-2014:couronne-du-dement | La cible ou un effet au choix peut affecter d’autres créatures ; interprétation des cibles indirectes/effets variables à confirmer avant automatisation. |
| Couteau de glace | aidedd-2014:couteau-de-glace | Créature initiale puis zone affectant des créatures supplémentaires ; distinguer cible initiale et effet secondaire avant de choisir un profil unique. Jumelage non autorisé en attendant. |
| Création | aidedd-2014:creation | Création d’objet, de nourriture ou de double avec bénéficiaires ultérieurs ; ne pas assimiler la création à une cible existante. |
| Création d'homoncule | aidedd-2014:creation-d-homoncule | Création d’objet, de nourriture ou de double avec bénéficiaires ultérieurs ; ne pas assimiler la création à une cible existante. |
| Création de mort-vivant | aidedd-2014:creation-de-mort-vivant | Cible morte, restes ou âme : le modèle ne distingue pas créature vivante, cadavre et âme ; ne pas autoriser le jumelage par assimilation. |
| Création de nourriture et d'eau | aidedd-2014:creation-de-nourriture-et-d-eau | Création d’objet, de nourriture ou de double avec bénéficiaires ultérieurs ; ne pas assimiler la création à une cible existante. |
| Création ou destruction d'eau | aidedd-2014:creation-ou-destruction-d-eau | Plusieurs modes possibles avec types de cibles ou zones différents ; aucun profil simple unique validé. |
| Danse macabre | aidedd-2014:danse-macabre | Cible morte, restes ou âme : le modèle ne distingue pas créature vivante, cadavre et âme ; ne pas autoriser le jumelage par assimilation. |
| Demi-plan | aidedd-2014:demi-plan | Texte du catalogue mal encodé ; faire vérifier le texte et son paragraphe de surclassement avant de fixer la référence. |
| Disque flottant de Tenser | aidedd-2014:disque-flottant-de-tenser | Force magique créée dans un espace et manipulant des objets ; le modèle ne distingue pas création et cibles ultérieures. |
| Don des langues | aidedd-2014:don-des-langues | Lien de communication ou de perception avec plusieurs participants potentiels ; distinction entre cible et destinataire/bénéficiaire à confirmer. |
| Dragon illusoire | aidedd-2014:dragon-illusoire | Effet créé, autonome ou mobile avec cibles successives ; séparer l’espace de création des cibles de ses actions avant configuration. |
| Druidisme | aidedd-2014:druidisme | Plusieurs modes possibles avec types de cibles ou zones différents ; aucun profil simple unique validé. |
| Duel forcé | aidedd-2014:duel-force | La cible ou un effet au choix peut affecter d’autres créatures ; interprétation des cibles indirectes/effets variables à confirmer avant automatisation. |
| Énervation | aidedd-2014:enervation | Le lancement lie la cible et le lanceur avec transfert de soins ou dégâts ; rôle des bénéficiaires/cibles secondaires à arbitrer dans le modèle. |
| Ennemis à foison | aidedd-2014:ennemis-a-foison | La cible ou un effet au choix peut affecter d’autres créatures ; interprétation des cibles indirectes/effets variables à confirmer avant automatisation. |
| Épée de Mordenkainen | aidedd-2014:epee-de-mordenkainen | Effet créé, autonome ou mobile avec cibles successives ; séparer l’espace de création des cibles de ses actions avant configuration. |
| Esprit guérisseur | aidedd-2014:esprit-guerisseur | Effet créé, autonome ou mobile avec cibles successives ; séparer l’espace de création des cibles de ses actions avant configuration. |
| Fabrication | aidedd-2014:fabrication | Cible environnementale, objets ou modes multiples ; contrôle manuel du type et de la cardinalité requis pour éviter une approximation. |
| Façonnage de l'eau | aidedd-2014:faconnage-de-l-eau | Cible environnementale, objets ou modes multiples ; contrôle manuel du type et de la cardinalité requis pour éviter une approximation. |
| Façonnage de la pierre | aidedd-2014:faconnage-de-la-pierre | Cible environnementale, objets ou modes multiples ; contrôle manuel du type et de la cardinalité requis pour éviter une approximation. |
| Façonnage de la terre | aidedd-2014:faconnage-de-la-terre | Cible environnementale, objets ou modes multiples ; contrôle manuel du type et de la cardinalité requis pour éviter une approximation. |
| Festin des héros | aidedd-2014:festin-des-heros | Création d’objet, de nourriture ou de double avec bénéficiaires ultérieurs ; ne pas assimiler la création à une cible existante. |
| Flèche acide de Melf | aidedd-2014:fleche-acide-de-melf | La description dit seulement cible, sans limiter explicitement aux créatures ; ne pas déduire un ciblage exclusif. |
| Foulée tonitruante | aidedd-2014:foulee-tonitruante | Déplacement, portail ou espace extradimensionnel avec voyageurs/bénéficiaires distincts du point ciblé ; représentation unique à valider. |
| Fusion dans la pierre | aidedd-2014:fusion-dans-la-pierre | Cible environnementale, objets ou modes multiples ; contrôle manuel du type et de la cardinalité requis pour éviter une approximation. |
| Gardien de la foi | aidedd-2014:gardien-de-la-foi | Effet créé, autonome ou mobile avec cibles successives ; séparer l’espace de création des cibles de ses actions avant configuration. |
| Glyphe de protection | aidedd-2014:glyphe-de-protection | Plusieurs modes possibles avec types de cibles ou zones différents ; aucun profil simple unique validé. |
| Illusion mineure | aidedd-2014:illusion-mineure | Illusion, lumière ou capteur créé dans un espace ; les observateurs ne sont pas automatiquement les cibles. Modélisation à confirmer. |
| Illusion programmée | aidedd-2014:illusion-programmee | Illusion, lumière ou capteur créé dans un espace ; les observateurs ne sont pas automatiquement les cibles. Modélisation à confirmer. |
| Image majeure | aidedd-2014:image-majeure | Illusion, lumière ou capteur créé dans un espace ; les observateurs ne sont pas automatiquement les cibles. Modélisation à confirmer. |
| Image silencieuse | aidedd-2014:image-silencieuse | Illusion, lumière ou capteur créé dans un espace ; les observateurs ne sont pas automatiquement les cibles. Modélisation à confirmer. |
| Invocation d'animaux | aidedd-2014:invocation-d-animaux | Création ou invocation d’une entité dans un espace : le nombre d’entités invoquées n’est pas le nombre de cibles ; ce modèle ne représente pas explicitement cette distinction. |
| Invocation d'élémentaire | aidedd-2014:invocation-d-elementaire | Création ou invocation d’une entité dans un espace : le nombre d’entités invoquées n’est pas le nombre de cibles ; ce modèle ne représente pas explicitement cette distinction. |
| Invocation d'élémentaires mineurs | aidedd-2014:invocation-d-elementaires-mineurs | Création ou invocation d’une entité dans un espace : le nombre d’entités invoquées n’est pas le nombre de cibles ; ce modèle ne représente pas explicitement cette distinction. |
| Invocation d'êtres sylvestres | aidedd-2014:invocation-d-etres-sylvestres | Création ou invocation d’une entité dans un espace : le nombre d’entités invoquées n’est pas le nombre de cibles ; ce modèle ne représente pas explicitement cette distinction. |
| Invocation de céleste | aidedd-2014:invocation-de-celeste | Création ou invocation d’une entité dans un espace : le nombre d’entités invoquées n’est pas le nombre de cibles ; ce modèle ne représente pas explicitement cette distinction. |
| Invocation de fée | aidedd-2014:invocation-de-fee | Création ou invocation d’une entité dans un espace : le nombre d’entités invoquées n’est pas le nombre de cibles ; ce modèle ne représente pas explicitement cette distinction. |
| Lame du désastre | aidedd-2014:lame-du-desastre | Effet créé, autonome ou mobile avec cibles successives ; séparer l’espace de création des cibles de ses actions avant configuration. |
| Liane avide | aidedd-2014:liane-avide | Effet créé, autonome ou mobile avec cibles successives ; séparer l’espace de création des cibles de ses actions avant configuration. |
| Libellé aérien | aidedd-2014:libelle-aerien | Cible environnementale, objets ou modes multiples ; contrôle manuel du type et de la cardinalité requis pour éviter une approximation. |
| Lien avec une bête | aidedd-2014:lien-avec-une-bete | Lien de communication ou de perception avec plusieurs participants potentiels ; distinction entre cible et destinataire/bénéficiaire à confirmer. |
| Lien de protection | aidedd-2014:lien-de-protection | Le lancement lie la cible et le lanceur avec transfert de soins ou dégâts ; rôle des bénéficiaires/cibles secondaires à arbitrer dans le modèle. |
| Lumière du jour | aidedd-2014:lumiere-du-jour | Cible environnementale, objets ou modes multiples ; contrôle manuel du type et de la cardinalité requis pour éviter une approximation. |
| Lumières dansantes | aidedd-2014:lumieres-dansantes | Illusion, lumière ou capteur créé dans un espace ; les observateurs ne sont pas automatiquement les cibles. Modélisation à confirmer. |
| Main de Bigby | aidedd-2014:main-de-bigby | Effet créé, autonome ou mobile avec cibles successives ; séparer l’espace de création des cibles de ses actions avant configuration. |
| Main de mage | aidedd-2014:main-de-mage | Force magique créée dans un espace et manipulant des objets ; le modèle ne distingue pas création et cibles ultérieures. |
| Malédiction | aidedd-2014:malediction | La cible ou un effet au choix peut affecter d’autres créatures ; interprétation des cibles indirectes/effets variables à confirmer avant automatisation. |
| Manoir somptueux de Mordenkainen | aidedd-2014:manoir-somptueux-de-mordenkainen | Déplacement, portail ou espace extradimensionnel avec voyageurs/bénéficiaires distincts du point ciblé ; représentation unique à valider. |
| Message | aidedd-2014:message | Lien de communication ou de perception avec plusieurs participants potentiels ; distinction entre cible et destinataire/bénéficiaire à confirmer. |
| Messager animal | aidedd-2014:messager-animal | Lien de communication ou de perception avec plusieurs participants potentiels ; distinction entre cible et destinataire/bénéficiaire à confirmer. |
| Métal brûlant | aidedd-2014:metal-brulant | Objet initial puis dégâts ou effets sur des créatures ; distinguer les cibles directes et secondaires avant de renseigner le multiciblage. |
| Monture fantôme | aidedd-2014:monture-fantome | Création ou invocation d’une entité dans un espace : le nombre d’entités invoquées n’est pas le nombre de cibles ; ce modèle ne représente pas explicitement cette distinction. |
| Mur de feu | aidedd-2014:mur-de-feu | Texte du catalogue mal encodé ; faire vérifier le texte et son paragraphe de surclassement avant de fixer la référence. |
| Oeil magique | aidedd-2014:oeil-magique | Illusion, lumière ou capteur créé dans un espace ; les observateurs ne sont pas automatiquement les cibles. Modélisation à confirmer. |
| Passe-muraille | aidedd-2014:passe-muraille | Cible environnementale, objets ou modes multiples ; contrôle manuel du type et de la cardinalité requis pour éviter une approximation. |
| Poigne terreuse de Maximilien | aidedd-2014:poigne-terreuse-de-maximilien | Effet créé, autonome ou mobile avec cibles successives ; séparer l’espace de création des cibles de ses actions avant configuration. |
| Portail | aidedd-2014:portail | Déplacement, portail ou espace extradimensionnel avec voyageurs/bénéficiaires distincts du point ciblé ; représentation unique à valider. |
| Portail magique | aidedd-2014:portail-magique | Déplacement, portail ou espace extradimensionnel avec voyageurs/bénéficiaires distincts du point ciblé ; représentation unique à valider. |
| Porte dimensionnelle | aidedd-2014:porte-dimensionnelle | Déplacement, portail ou espace extradimensionnel avec voyageurs/bénéficiaires distincts du point ciblé ; représentation unique à valider. |
| Préservation des morts | aidedd-2014:preservation-des-morts | Cible morte, restes ou âme : le modèle ne distingue pas créature vivante, cadavre et âme ; ne pas autoriser le jumelage par assimilation. |
| Prestidigitation | aidedd-2014:prestidigitation | Plusieurs modes possibles avec types de cibles ou zones différents ; aucun profil simple unique validé. |
| Projection d'image | aidedd-2014:projection-d-image | Illusion, lumière ou capteur créé dans un espace ; les observateurs ne sont pas automatiquement les cibles. Modélisation à confirmer. |
| Rappel à la vie | aidedd-2014:rappel-a-la-vie | Cible morte, restes ou âme : le modèle ne distingue pas créature vivante, cadavre et âme ; ne pas autoriser le jumelage par assimilation. |
| Réincarnation | aidedd-2014:reincarnation | Cible morte, restes ou âme : le modèle ne distingue pas créature vivante, cadavre et âme ; ne pas autoriser le jumelage par assimilation. |
| Résurrection | aidedd-2014:resurrection | Cible morte, restes ou âme : le modèle ne distingue pas créature vivante, cadavre et âme ; ne pas autoriser le jumelage par assimilation. |
| Résurrection suprême | aidedd-2014:resurrection-supreme | Cible morte, restes ou âme : le modèle ne distingue pas créature vivante, cadavre et âme ; ne pas autoriser le jumelage par assimilation. |
| Retour à la vie | aidedd-2014:retour-a-la-vie | Cible morte, restes ou âme : le modèle ne distingue pas créature vivante, cadavre et âme ; ne pas autoriser le jumelage par assimilation. |
| Sanctuaire | aidedd-2014:sanctuaire | Une créature protégée mais plusieurs assaillants peuvent effectuer des sauvegardes ; conflit possible entre ciblage direct et critères de jumelage du Sage Advice. Arbitrage requis. |
| Saute de vent | aidedd-2014:saute-de-vent | Plusieurs modes possibles avec types de cibles ou zones différents ; aucun profil simple unique validé. |
| Sens animal | aidedd-2014:sens-animal | Lien de communication ou de perception avec plusieurs participants potentiels ; distinction entre cible et destinataire/bénéficiaire à confirmer. |
| Sens des pièges | aidedd-2014:sens-des-pieges | Cible environnementale, objets ou modes multiples ; contrôle manuel du type et de la cardinalité requis pour éviter une approximation. |
| Serviteur invisible | aidedd-2014:serviteur-invisible | Création ou invocation d’une entité dans un espace : le nombre d’entités invoquées n’est pas le nombre de cibles ; ce modèle ne représente pas explicitement cette distinction. |
| Serviteur miniature | aidedd-2014:serviteur-miniature | Surclassement incohérent : le texte parle de cadavres alors que le sort anime des objets ; vérification de la source requise. |
| Simulacre | aidedd-2014:simulacre | Création d’objet, de nourriture ou de double avec bénéficiaires ultérieurs ; ne pas assimiler la création à une cible existante. |
| Songe | aidedd-2014:songe | Lien de communication ou de perception avec plusieurs participants potentiels ; distinction entre cible et destinataire/bénéficiaire à confirmer. |
| Souffle du dragon | aidedd-2014:souffle-du-dragon | Créature initiale puis zone affectant des créatures supplémentaires ; distinguer cible initiale et effet secondaire avant de choisir un profil unique. Jumelage non autorisé en attendant. |
| Souhait | aidedd-2014:souhait | Duplication de sorts ou effets libres : le ciblage dépend du souhait formulé et ne peut être déduit du profil de lancement personnel. |
| Symbole | aidedd-2014:symbole | Plusieurs modes possibles avec types de cibles ou zones différents ; aucun profil simple unique validé. |
| Télépathie | aidedd-2014:telepathie | Lien de communication ou de perception avec plusieurs participants potentiels ; distinction entre cible et destinataire/bénéficiaire à confirmer. |
| Ténèbres | aidedd-2014:tenebres | Cible environnementale, objets ou modes multiples ; contrôle manuel du type et de la cardinalité requis pour éviter une approximation. |
| Thaumaturgie | aidedd-2014:thaumaturgie | Plusieurs modes possibles avec types de cibles ou zones différents ; aucun profil simple unique validé. |
| Transfert de vie | aidedd-2014:transfert-de-vie | Le lancement lie la cible et le lanceur avec transfert de soins ou dégâts ; rôle des bénéficiaires/cibles secondaires à arbitrer dans le modèle. |
| Voie végétale | aidedd-2014:voie-vegetale | Déplacement, portail ou espace extradimensionnel avec voyageurs/bénéficiaires distincts du point ciblé ; représentation unique à valider. |
