<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261108010000 extends AbstractMigration
{
    public function getDescription(): string { return 'Permanent feature AC effects and official dragon-hide/draconic-resilience configuration.'; }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE character_feature_definition ADD armor_class_effects JSON DEFAULT NULL');
        $this->addSql("ALTER TABLE character_feature_definition ADD CONSTRAINT chk_feature_ac_effects CHECK (armor_class_effects IS NULL OR json_typeof(armor_class_effects) = 'array')");
        $this->addSql("INSERT INTO character_feature_definition (slug,name,activation_type,visible,custom,created_at,updated_at,origin) SELECT 'dragon-hide',name,'passive',true,false,NOW(),NOW(),'OFFICIAL' FROM feat WHERE slug='dragon-hide' AND origin='OFFICIAL' AND owner_id IS NULL ON CONFLICT (slug) DO NOTHING");
        $this->addSql(<<<'SQL'
UPDATE character_feature_definition SET armor_class_effects = '[{"key":"natural-armor","label":"Armure naturelle","type":"formula","value":13,"dexterity":"full","conditions":["no-armor"]}]', updated_at=NOW()
WHERE slug IN ('dragon-hide','draconic-resilience') AND origin='OFFICIAL' AND owner_id IS NULL AND activation_type='passive' AND armor_class_effects IS NULL
SQL);
        $this->addSql(<<<'SQL'
INSERT INTO character_feature_rule (feature_definition_id,feat_id,unlock_level,display_order,origin)
SELECT d.id,f.id,1,0,'OFFICIAL' FROM character_feature_definition d JOIN feat f ON f.slug='dragon-hide'
WHERE d.slug='dragon-hide' AND d.origin='OFFICIAL' AND d.owner_id IS NULL AND f.origin='OFFICIAL' AND f.owner_id IS NULL
AND NOT EXISTS (SELECT 1 FROM character_feature_rule r WHERE r.feature_definition_id=d.id AND r.feat_id=f.id)
SQL);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Preserve configured feature effects and attributions.');
    }
}
