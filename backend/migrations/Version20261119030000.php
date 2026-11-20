<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20261119030000 extends AbstractMigration
{
    public function getDescription(): string { return 'Beast habitat associations (many to many)'; }
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE beast_habitat (beast_id INT NOT NULL, habitat VARCHAR(40) NOT NULL, PRIMARY KEY (beast_id, habitat))');
        $this->addSql('CREATE INDEX idx_beast_habitat_habitat ON beast_habitat (habitat)');
        $this->addSql('ALTER TABLE beast_habitat ADD CONSTRAINT fk_beast_habitat_beast FOREIGN KEY (beast_id) REFERENCES beast (id) ON DELETE CASCADE');
    }
    public function down(Schema $schema): void { $this->addSql('DROP TABLE beast_habitat'); }
}
