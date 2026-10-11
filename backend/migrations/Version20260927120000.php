<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260927120000 extends AbstractMigration
{
    private const TABLES = [
        'character_class', 'character_subclass', 'character_race', 'feat',
        'character_feature_definition', 'trackable_resource_definition',
        'progression_definition', 'character_action_definition',
        'character_feature_rule', 'trackable_resource_rule', 'character_action_class_rule',
    ];

    public function getDescription(): string
    {
        return 'Add reference provenance and ownership; preserve legacy flags and all character state.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform, 'PostgreSQL required.');
        $this->addSql('LOCK TABLE '.implode(', ', self::TABLES).' IN SHARE ROW EXCLUSIVE MODE');
        $this->addSql(<<<'SQL'
DO $$
DECLARE item RECORD;
BEGIN
    PERFORM id FROM "user" WHERE id = 1 FOR KEY SHARE;
    IF NOT FOUND THEN RAISE EXCEPTION 'Reference ownership: User #1 is missing'; END IF;
    FOR item IN SELECT * FROM (VALUES
        ('character_subclass', 20, 'arch-hag'),
        ('character_feature_definition', 247, 'vignes-de-venlee'),
        ('character_feature_definition', 248, 'entite-symbiotique'),
        ('character_feature_definition', 249, 'deplacement-eclair'),
        ('trackable_resource_definition', 54, 'enchevetrement'),
        ('trackable_resource_definition', 55, 'entite-symbiotique'),
        ('trackable_resource_definition', 56, 'deplacement-eclair'),
        ('progression_definition', 1, 'corruption-draconique'),
        ('progression_definition', 2, 'infestation-fongique'),
        ('progression_definition', 3, 'bombe-electrique'),
        ('trackable_resource_definition', 4, 'utilisations-de-magie-galvanisante'),
        ('trackable_resource_definition', 53, 'utilisations-d-ailes-protectrices'),
        ('character_feature_definition', 365, 'circle-of-spores-fungal-infestation'),
        ('character_feature_definition', 368, 'circle-of-spores-symbiotic-entity')
    ) AS expected(table_name, id, slug)
    LOOP
        IF NOT EXISTS (
            SELECT 1 FROM pg_class WHERE oid = to_regclass(item.table_name)
        ) THEN RAISE EXCEPTION 'Reference ownership: missing table %', item.table_name; END IF;
        DECLARE matched BOOLEAN;
        BEGIN
            EXECUTE format('SELECT EXISTS (SELECT 1 FROM %I WHERE id = $1 AND slug = $2)', item.table_name)
                INTO matched USING item.id, item.slug;
            IF NOT matched THEN
                RAISE EXCEPTION 'Reference ownership: identity mismatch %.% (%)', item.table_name, item.id, item.slug;
            END IF;
        END;
    END LOOP;
    FOR item IN SELECT * FROM (VALUES
        (243, 1, 247, 10, 54), (244, 2, 248, 15, 55), (245, 3, 249, 50, 56)
    ) AS expected(id, progression_id, feature_id, threshold, resource_id)
    LOOP
        IF NOT EXISTS (
            SELECT 1 FROM character_feature_rule r
            JOIN character_feature_definition f ON f.id = r.feature_definition_id
            WHERE r.id = item.id AND r.progression_definition_id = item.progression_id
              AND r.feature_definition_id = item.feature_id AND r.progression_threshold = item.threshold
              AND f.resource_definition_id = item.resource_id
              AND r.character_class_id IS NULL AND r.character_subclass_id IS NULL
              AND r.character_race_id IS NULL AND r.feat_id IS NULL
        ) THEN RAISE EXCEPTION 'Reference ownership: assignment mismatch #%', item.id; END IF;
    END LOOP;
    IF NOT EXISTS (
        SELECT 1 FROM character_subclass s JOIN character_class c ON c.id = s.character_class_id
        WHERE s.id = 20 AND c.id = 9 AND c.slug = 'warlock'
    ) THEN RAISE EXCEPTION 'Reference ownership: arch-hag parent mismatch'; END IF;
    IF EXISTS (
        SELECT 1 FROM character_progression cp JOIN "character" ch ON ch.id = cp.character_id
        JOIN campaign c ON c.id = ch.campaign_id
        WHERE cp.progression_definition_id IN (1,2,3) AND c.owner_id <> 1
    ) OR EXISTS (
        SELECT 1 FROM character_class_level l JOIN "character" ch ON ch.id = l.character_id
        JOIN campaign c ON c.id = ch.campaign_id WHERE l.subclass_id = 20 AND c.owner_id <> 1
    ) THEN RAISE EXCEPTION 'Reference ownership: personal content has another campaign owner'; END IF;
END $$;
SQL);
        foreach (self::TABLES as $table) {
            $this->addSql("ALTER TABLE $table ADD origin VARCHAR(8) DEFAULT 'OFFICIAL' NOT NULL, ADD owner_id INT DEFAULT NULL");
        }
        foreach ([
            'character_subclass' => '20',
            'character_feature_definition' => '247,248,249',
            'trackable_resource_definition' => '54,55,56',
            'progression_definition' => '1,2,3',
            'character_feature_rule' => '243,244,245',
        ] as $table => $ids) {
            $this->addSql("UPDATE $table SET origin = 'CUSTOM', owner_id = 1 WHERE id IN ($ids)");
        }
        foreach (self::TABLES as $table) {
            $this->addSql("ALTER TABLE $table ADD CONSTRAINT chk_{$table}_origin_owner CHECK ((origin = 'OFFICIAL' AND owner_id IS NULL) OR (origin = 'CUSTOM' AND owner_id IS NOT NULL))");
            $this->addSql("ALTER TABLE $table ADD CONSTRAINT fk_{$table}_owner FOREIGN KEY (owner_id) REFERENCES \"user\" (id) ON DELETE RESTRICT");
            $this->addSql("CREATE INDEX idx_{$table}_owner ON $table (owner_id)");
        }
        // Reject unexpected official assignments of any of the personal definitions.
        foreach ([
            'character_feature_rule' => ['feature_definition_id' => 'character_feature_definition', 'character_subclass_id' => 'character_subclass', 'progression_definition_id' => 'progression_definition'],
            'trackable_resource_rule' => ['resource_definition_id' => 'trackable_resource_definition', 'character_subclass_id' => 'character_subclass'],
        ] as $table => $relations) {
            foreach ($relations as $column => $target) {
                $this->addSql("DO $$ BEGIN IF EXISTS (SELECT 1 FROM $table r JOIN $target d ON d.id = r.$column WHERE r.origin = 'OFFICIAL' AND d.origin = 'CUSTOM') THEN RAISE EXCEPTION 'Reference ownership: unexpected official assignment in $table.$column'; END IF; END $$");
            }
        }
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Removing provenance would discard ownership; use an explicitly reviewed recovery plan.');
    }
}
