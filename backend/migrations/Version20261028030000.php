<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20261028030000 extends AbstractMigration
{
    public function getDescription(): string { return 'Managed grant resources and soft deletion; preserve legacy grants and resources.'; }
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE trackable_resource_definition ADD hidden_from_tracker BOOLEAN DEFAULT FALSE NOT NULL');
        $this->addSql('ALTER TABLE feature_spell_grant ADD enabled BOOLEAN DEFAULT TRUE NOT NULL, ADD managed_resource_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE feature_spell_grant ADD CONSTRAINT fk_grant_managed_resource FOREIGN KEY (managed_resource_id) REFERENCES trackable_resource_definition(id) ON DELETE RESTRICT');
        $this->addSql('CREATE UNIQUE INDEX uniq_grant_managed_resource ON feature_spell_grant (managed_resource_id)');
    }
    public function down(Schema $schema): void { $this->throwIrreversibleMigrationException('Managed resource identity and historical grants must be preserved.'); }
}
