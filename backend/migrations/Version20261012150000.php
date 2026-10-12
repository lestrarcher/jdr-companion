<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261012150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Persist character-wide inspiration, initially false for all existing characters.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "character" ADD inspiration BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "character" DROP inspiration');
    }
}
