<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260502130917 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 8: leave_entitlements.expiry_warning_sent_at column for EntitlementExpiringSoon idempotency.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE leave_entitlements ADD expiry_warning_sent_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE leave_entitlements DROP expiry_warning_sent_at');
    }
}
