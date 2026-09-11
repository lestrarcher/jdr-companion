<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260911120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Version character session state and enforce one pending rest per state.';
    }

    public function up(Schema $schema): void
    {
        // Do not silently resolve or remove pre-existing duplicate requests.
        $this->abortIf((bool) $this->connection->fetchOne("SELECT EXISTS (SELECT 1 FROM rest_request WHERE status = 'pending' GROUP BY character_session_state_id HAVING COUNT(*) > 1)"), 'Resolve duplicate pending rest requests before migrating.');
        $this->addSql('ALTER TABLE character_session_state ADD revision INT DEFAULT 1 NOT NULL');
        $this->addSql("CREATE UNIQUE INDEX uniq_pending_rest_per_state ON rest_request (character_session_state_id) WHERE (status = 'pending')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_pending_rest_per_state');
        $this->addSql('ALTER TABLE character_session_state DROP revision');
    }
}
