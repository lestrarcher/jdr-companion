<?php
// Only synthetic tables may be upgraded before the real migrations are applied.
$fixtureNamespace = $db->fetchOne("SELECT n.nspname FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE c.oid=to_regclass('feature_spell_grant')");
if ($fixtureNamespace === 'public' || !$fixtureNamespace) throw new RuntimeException('Isolated grant table required.');
// LIKE INCLUDING DEFAULTS can retain a public serial sequence. Never advance it.
foreach (['feature_spell_grant', 'character_feature_spell'] as $fixtureTable) {
    if ($db->fetchOne('SELECT relpersistence FROM pg_class WHERE oid=to_regclass(?)', [$fixtureTable]) !== 't') continue;
    if ($db->fetchOne("SELECT attidentity FROM pg_attribute WHERE attrelid=to_regclass(?) AND attname='id'", [$fixtureTable]) !== '') continue;
    $db->executeStatement('CREATE TEMP SEQUENCE IF NOT EXISTS fixture_'.$fixtureTable.'_id');
    $db->executeStatement("ALTER TABLE ".$fixtureTable." ALTER COLUMN id SET DEFAULT nextval('fixture_".$fixtureTable."_id')");
}
$db->executeStatement('ALTER TABLE feature_spell_grant ADD COLUMN IF NOT EXISTS enabled BOOLEAN DEFAULT TRUE NOT NULL, ADD COLUMN IF NOT EXISTS managed_resource_id INT DEFAULT NULL');
$db->executeStatement('ALTER TABLE feature_spell_grant ALTER COLUMN enabled SET DEFAULT TRUE');
$db->executeStatement('CREATE UNIQUE INDEX IF NOT EXISTS fixture_managed_grant_resource ON feature_spell_grant(managed_resource_id)');
