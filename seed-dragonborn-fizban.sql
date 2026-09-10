BEGIN;

-- Ressources
INSERT INTO trackable_resource_definition
(slug, name, description, recharge_type, maximum_type, base_maximum, multiplier, minimum_maximum, scaling_ability, custom, created_at, updated_at)
VALUES
('breath-weapon-uses','Utilisations du souffle draconique','Nombre d''utilisations disponibles du Souffle draconique.','long-rest','proficiency-bonus',0,1,1,NULL,FALSE,NOW(),NOW()),
('chromatic-warding-use','Utilisation de Protection chromatique','Une utilisation, récupérée après un repos long.','long-rest','fixed',1,1,0,NULL,FALSE,NOW(),NOW()),
('gem-flight-use','Utilisation de Vol diamantin','Une utilisation, récupérée après un repos long.','long-rest','fixed',1,1,0,NULL,FALSE,NOW(),NOW()),
('metallic-breath-weapon-use','Utilisation du souffle métallique','Une utilisation, récupérée après un repos long.','long-rest','fixed',1,1,0,NULL,FALSE,NOW(),NOW())
ON CONFLICT (slug) DO NOTHING;

-- Capacités
INSERT INTO character_feature_definition
(slug, name, description, activation_type, resource_definition_id, visible, custom, created_at, updated_at)
VALUES
('draconic-ancestry','Ascendance draconique','Votre ascendance draconique détermine le type de dégâts associé à votre souffle et à votre résistance draconique.','passive',NULL,TRUE,FALSE,NOW(),NOW()),
('breath-weapon','Souffle draconique','Lorsque vous effectuez l''action Attaquer, vous pouvez remplacer une attaque par un souffle draconique. Son type de dégâts dépend de votre ascendance et ses dégâts augmentent avec votre niveau. Vous disposez d''un nombre d''utilisations égal à votre bonus de maîtrise et les récupérez après un repos long.','special',(SELECT id FROM trackable_resource_definition WHERE slug='breath-weapon-uses'),TRUE,FALSE,NOW(),NOW()),
('draconic-resistance','Résistance draconique','Vous avez la résistance au type de dégâts associé à votre ascendance draconique.','passive',NULL,TRUE,FALSE,NOW(),NOW()),
('chromatic-warding','Protection chromatique','À partir du niveau 5, vous pouvez utiliser une action pour devenir temporairement immunisé au type de dégâts associé à votre ascendance chromatique. Cette capacité est utilisable une fois par repos long.','action',(SELECT id FROM trackable_resource_definition WHERE slug='chromatic-warding-use'),TRUE,FALSE,NOW(),NOW()),
('psionic-mind','Esprit psionique','Vous pouvez envoyer télépathiquement des messages à une créature proche que vous pouvez voir ; elle doit comprendre au moins une langue pour saisir le message.','passive',NULL,TRUE,FALSE,NOW(),NOW()),
('gem-flight','Vol diamantin','À partir du niveau 5, vous pouvez manifester des ailes spectrales par une action bonus et obtenir temporairement une vitesse de vol égale à votre vitesse de marche. Cette capacité est utilisable une fois par repos long.','bonus_action',(SELECT id FROM trackable_resource_definition WHERE slug='gem-flight-use'),TRUE,FALSE,NOW(),NOW()),
('metallic-breath-weapon','Souffle métallique','À partir du niveau 5, vous pouvez remplacer une attaque par un souffle métallique spécial. Choisissez à chaque utilisation entre un souffle affaiblissant et un souffle répulsif. Cette capacité est utilisable une fois par repos long.','special',(SELECT id FROM trackable_resource_definition WHERE slug='metallic-breath-weapon-use'),TRUE,FALSE,NOW(),NOW())
ON CONFLICT (slug) DO NOTHING;

-- Traits communs au Drakéide parent et à tous ses descendants
WITH RECURSIVE dragonborn_races AS (
    SELECT id FROM character_race WHERE slug='dragonborn'
    UNION ALL
    SELECT c.id
    FROM character_race c
    JOIN dragonborn_races p ON c.parent_race_id=p.id
)
INSERT INTO character_feature_rule
(feature_definition_id, character_class_id, character_subclass_id, character_race_id, feat_id, progression_definition_id, unlock_level, progression_threshold, display_order)
SELECT f.id,NULL,NULL,r.id,NULL,NULL,1,NULL,v.display_order
FROM dragonborn_races r
CROSS JOIN (VALUES
    ('draconic-ancestry',10),
    ('breath-weapon',20),
    ('draconic-resistance',30)
) AS v(slug,display_order)
JOIN character_feature_definition f ON f.slug=v.slug
WHERE NOT EXISTS (
    SELECT 1 FROM character_feature_rule x
    WHERE x.feature_definition_id=f.id
      AND x.character_race_id=r.id
      AND x.unlock_level=1
);

-- Chromatique : niveau 5
INSERT INTO character_feature_rule
(feature_definition_id, character_class_id, character_subclass_id, character_race_id, feat_id, progression_definition_id, unlock_level, progression_threshold, display_order)
SELECT f.id,NULL,NULL,r.id,NULL,NULL,5,NULL,40
FROM character_race r
JOIN character_feature_definition f ON f.slug='chromatic-warding'
WHERE (r.slug LIKE '%chromatic%' OR r.name ILIKE 'Drakéide chromatique%')
AND NOT EXISTS (
    SELECT 1 FROM character_feature_rule x
    WHERE x.feature_definition_id=f.id
      AND x.character_race_id=r.id
      AND x.unlock_level=5
);

-- Diamantin : Esprit psionique niveau 1
INSERT INTO character_feature_rule
(feature_definition_id, character_class_id, character_subclass_id, character_race_id, feat_id, progression_definition_id, unlock_level, progression_threshold, display_order)
SELECT f.id,NULL,NULL,r.id,NULL,NULL,1,NULL,40
FROM character_race r
JOIN character_feature_definition f ON f.slug='psionic-mind'
WHERE (r.slug LIKE '%gem%' OR r.slug LIKE '%diamantin%' OR r.name ILIKE 'Drakéide diamantin%')
AND NOT EXISTS (
    SELECT 1 FROM character_feature_rule x
    WHERE x.feature_definition_id=f.id
      AND x.character_race_id=r.id
      AND x.unlock_level=1
);

-- Diamantin : Vol niveau 5
INSERT INTO character_feature_rule
(feature_definition_id, character_class_id, character_subclass_id, character_race_id, feat_id, progression_definition_id, unlock_level, progression_threshold, display_order)
SELECT f.id,NULL,NULL,r.id,NULL,NULL,5,NULL,50
FROM character_race r
JOIN character_feature_definition f ON f.slug='gem-flight'
WHERE (r.slug LIKE '%gem%' OR r.slug LIKE '%diamantin%' OR r.name ILIKE 'Drakéide diamantin%')
AND NOT EXISTS (
    SELECT 1 FROM character_feature_rule x
    WHERE x.feature_definition_id=f.id
      AND x.character_race_id=r.id
      AND x.unlock_level=5
);

-- Métallique : niveau 5
INSERT INTO character_feature_rule
(feature_definition_id, character_class_id, character_subclass_id, character_race_id, feat_id, progression_definition_id, unlock_level, progression_threshold, display_order)
SELECT f.id,NULL,NULL,r.id,NULL,NULL,5,NULL,40
FROM character_race r
JOIN character_feature_definition f ON f.slug='metallic-breath-weapon'
WHERE (r.slug LIKE '%metallic%' OR r.slug LIKE '%metallique%' OR r.name ILIKE 'Drakéide métallique%')
AND NOT EXISTS (
    SELECT 1 FROM character_feature_rule x
    WHERE x.feature_definition_id=f.id
      AND x.character_race_id=r.id
      AND x.unlock_level=5
);

COMMIT;

-- Contrôle après exécution
-- SELECT r.name AS race, f.name AS feature, fr.unlock_level, res.name AS resource
-- FROM character_feature_rule fr
-- JOIN character_race r ON r.id=fr.character_race_id
-- JOIN character_feature_definition f ON f.id=fr.feature_definition_id
-- LEFT JOIN trackable_resource_definition res ON res.id=f.resource_definition_id
-- WHERE f.slug IN ('draconic-ancestry','breath-weapon','draconic-resistance','chromatic-warding','psionic-mind','gem-flight','metallic-breath-weapon')
-- ORDER BY r.name, fr.unlock_level, fr.display_order;
