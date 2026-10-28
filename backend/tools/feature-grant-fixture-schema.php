<?php
// Only synthetic tables may be upgraded before the real migrations are applied.
$fixtureNamespace = $db->fetchOne("SELECT n.nspname FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE c.oid=to_regclass('feature_spell_grant')");
if ($fixtureNamespace === 'public' || !$fixtureNamespace) throw new RuntimeException('Isolated grant table required.');
$db->executeStatement('ALTER TABLE feature_spell_grant ADD COLUMN IF NOT EXISTS enabled BOOLEAN DEFAULT TRUE NOT NULL, ADD COLUMN IF NOT EXISTS managed_resource_id INT DEFAULT NULL');
$db->executeStatement('ALTER TABLE feature_spell_grant ALTER COLUMN enabled SET DEFAULT TRUE');
$db->executeStatement('CREATE UNIQUE INDEX IF NOT EXISTS fixture_managed_grant_resource ON feature_spell_grant(managed_resource_id)');
