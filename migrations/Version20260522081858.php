<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260522081858 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Issue #92: Add locale column to users table for user-selectable UI language.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD locale VARCHAR(5) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP locale');
    }
}
