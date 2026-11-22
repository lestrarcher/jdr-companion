<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261122030000 extends AbstractMigration
{
    public function getDescription(): string { return 'Declarative feature attacks and Dragon Hide claws (2014).'; }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE character_feature_definition ADD attack_effects JSON DEFAULT NULL');
        $this->addSql("ALTER TABLE character_feature_definition ADD CONSTRAINT chk_feature_attack_effects CHECK (attack_effects IS NULL OR json_typeof(attack_effects) = 'array')");
        $this->addSql(<<<'SQL'
UPDATE character_feature_definition
SET attack_effects = '[{"key":"claws","type":"natural-attack","name":"Griffes — Cuir du dragon","dice":"1d4","damageType":"slashing","ability":"strength","proficient":true,"unarmed":true,"notes":["Griffes rétractiles utilisables pour les attaques à mains nues. Sortir ou rétracter les griffes ne nécessite aucune action."]}]', updated_at=NOW()
WHERE slug='dragon-hide' AND origin='OFFICIAL' AND owner_id IS NULL AND activation_type='passive' AND attack_effects IS NULL
SQL);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Preserve configured feature attacks.');
    }
}
