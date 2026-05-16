<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260516112850 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 11.1: add google_oauth_enabled + google_oauth_hosted_domain to companies';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE companies ADD google_oauth_enabled TINYINT DEFAULT 0 NOT NULL, ADD google_oauth_hosted_domain VARCHAR(253) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE companies DROP google_oauth_enabled, DROP google_oauth_hosted_domain');
    }
}
