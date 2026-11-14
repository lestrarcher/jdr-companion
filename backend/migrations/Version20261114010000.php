<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261114010000 extends AbstractMigration
{
    public function getDescription(): string { return 'Explicit spell targeting and six canonical D&D 2014 targeting profiles'; }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE spell_definition ADD target_type VARCHAR(10) DEFAULT NULL, ADD multiple_targets BOOLEAN DEFAULT FALSE NOT NULL, ADD multiple_targets_progression VARCHAR(15) DEFAULT NULL, ADD multiple_targets_threshold INT DEFAULT NULL');
        $this->addSql("ALTER TABLE spell_definition ADD CONSTRAINT chk_spell_targeting CHECK ((target_type IS NULL OR target_type IN ('self', 'creature', 'object', 'area', 'special')) AND ((multiple_targets_progression IS NULL AND multiple_targets_threshold IS NULL) OR (multiple_targets_progression IS NOT NULL AND multiple_targets_threshold IS NOT NULL AND ((multiple_targets_progression = 'slot' AND multiple_targets_threshold BETWEEN 1 AND 9) OR (multiple_targets_progression = 'character' AND multiple_targets_threshold BETWEEN 1 AND 20)))))");
        // Exact import identities only; never alter custom spells or another edition.
        $this->addSql("UPDATE spell_definition s SET target_type=v.target_type, multiple_targets=v.multiple_targets, multiple_targets_progression=v.progression, multiple_targets_threshold=v.threshold FROM (VALUES
            ('aidedd-2014:orbe-chromatique', 'creature', FALSE, NULL::VARCHAR, NULL::INT),
            ('aidedd-2014:immobilisation-de-personne', 'creature', FALSE, 'slot', 3),
            ('aidedd-2014:immobilisation-de-monstre', 'creature', FALSE, 'slot', 6),
            ('aidedd-2014:decharge-occulte', 'creature', FALSE, 'character', 5),
            ('aidedd-2014:boule-de-feu', 'area', TRUE, NULL, NULL),
            ('aidedd-2014:projectile-magique', 'creature', TRUE, NULL, NULL)
        ) AS v(import_key,target_type,multiple_targets,progression,threshold) WHERE s.import_key=v.import_key AND s.edition='2014' AND s.origin='OFFICIAL' AND s.owner_id IS NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE spell_definition DROP CONSTRAINT chk_spell_targeting, DROP target_type, DROP multiple_targets, DROP multiple_targets_progression, DROP multiple_targets_threshold');
    }
}
