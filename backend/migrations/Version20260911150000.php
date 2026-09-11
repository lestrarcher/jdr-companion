<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260911150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add private Markdown preparation notes to game sessions.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_session ADD preparation_notes TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_session DROP preparation_notes');
    }
}
