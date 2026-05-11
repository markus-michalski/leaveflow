<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260511143255 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 10 iCal subscription: add nullable unique ical_token column on users';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD ical_token VARCHAR(64) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_1483A5E99F0C901D ON users (ical_token)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_1483A5E99F0C901D ON users');
        $this->addSql('ALTER TABLE users DROP ical_token');
    }
}
