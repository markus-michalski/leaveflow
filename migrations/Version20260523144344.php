<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260523144344 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 14 — API tokens table for machine-to-machine access';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE api_tokens (id INT AUTO_INCREMENT NOT NULL, last_used_at DATETIME DEFAULT NULL, revoked_at DATETIME DEFAULT NULL, name VARCHAR(100) NOT NULL, token_hash VARCHAR(64) NOT NULL, created_at DATETIME NOT NULL, expires_at DATETIME DEFAULT NULL, company_id INT NOT NULL, UNIQUE INDEX UNIQ_2CAD560EB3BC57DA (token_hash), INDEX idx_api_token_hash (token_hash), INDEX idx_api_token_company (company_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE api_tokens ADD CONSTRAINT FK_2CAD560E979B1AD6 FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE api_tokens DROP FOREIGN KEY FK_2CAD560E979B1AD6');
        $this->addSql('DROP TABLE api_tokens');
    }
}
