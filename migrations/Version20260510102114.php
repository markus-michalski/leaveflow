<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260510102114 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 9 — manual entitlement override audit trail (#45). Append-only log per (entitlement, change_type), required reason.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE leave_entitlement_audit_entries (id INT AUTO_INCREMENT NOT NULL, change_type VARCHAR(40) NOT NULL, occurred_at DATETIME NOT NULL, reason LONGTEXT NOT NULL, old_hours_granted DOUBLE PRECISION DEFAULT NULL, new_hours_granted DOUBLE PRECISION DEFAULT NULL, old_expires_at DATE DEFAULT NULL, new_expires_at DATE DEFAULT NULL, leave_entitlement_id INT NOT NULL, actor_id INT DEFAULT NULL, INDEX IDX_BF1A8CCC30B6BC11 (leave_entitlement_id), INDEX IDX_BF1A8CCC10DAF24A (actor_id), INDEX idx_audit_entitlement (leave_entitlement_id, occurred_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE leave_entitlement_audit_entries ADD CONSTRAINT FK_BF1A8CCC30B6BC11 FOREIGN KEY (leave_entitlement_id) REFERENCES leave_entitlements (id)');
        $this->addSql('ALTER TABLE leave_entitlement_audit_entries ADD CONSTRAINT FK_BF1A8CCC10DAF24A FOREIGN KEY (actor_id) REFERENCES employees (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE leave_entitlement_audit_entries DROP FOREIGN KEY FK_BF1A8CCC30B6BC11');
        $this->addSql('ALTER TABLE leave_entitlement_audit_entries DROP FOREIGN KEY FK_BF1A8CCC10DAF24A');
        $this->addSql('DROP TABLE leave_entitlement_audit_entries');
    }
}
