<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260910120000 extends AbstractMigration
{
    private const RENAMES = [
        'cuir-du-dragon' => 'dragon-hide',
        'peur-du-dragon' => 'dragon-fear',
        'don-des-dragons-metalliques' => 'gift-of-the-metallic-dragon',
    ];

    public function getDescription(): string
    {
        return 'Normalize three feat slugs to English while preserving IDs and relations.';
    }

    public function up(Schema $schema): void
    {
        $this->rename(self::RENAMES);
    }

    public function down(Schema $schema): void
    {
        $this->rename(array_flip(self::RENAMES));
    }

    /** @param array<string, string> $renames */
    private function rename(array $renames): void
    {
        foreach ($renames as $old => $new) {
            $source = $this->connection->fetchOne('SELECT id FROM feat WHERE slug = ?', [$old]);
            $target = $this->connection->fetchOne('SELECT id FROM feat WHERE slug = ?', [$new]);

            $this->abortIf(
                $source !== false && $target !== false,
                sprintf('Cannot rename feat %s to %s: both slugs exist.', $old, $new),
            );

            $this->addSql('UPDATE feat SET slug = ? WHERE slug = ?', [$new, $old]);
        }
    }
}
