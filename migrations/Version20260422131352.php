<?php

declare(strict_types=1);

/*
 * This file is part of LeaveFlow.
 *
 * (c) Markus Michalski <ich@markus-michalski.net>
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260422131352 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 6: append-only audit log for LeaveRequest workflow transitions.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE leave_request_audit_entries (id INT AUTO_INCREMENT NOT NULL, transition VARCHAR(40) NOT NULL, from_status VARCHAR(20) NOT NULL, to_status VARCHAR(20) NOT NULL, occurred_at DATETIME NOT NULL, reason LONGTEXT DEFAULT NULL, leave_request_id INT NOT NULL, actor_id INT DEFAULT NULL, INDEX IDX_4E4F6539F2E1C15D (leave_request_id), INDEX IDX_4E4F653910DAF24A (actor_id), INDEX idx_audit_leave_request (leave_request_id, occurred_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE leave_request_audit_entries ADD CONSTRAINT FK_4E4F6539F2E1C15D FOREIGN KEY (leave_request_id) REFERENCES leave_requests (id)');
        $this->addSql('ALTER TABLE leave_request_audit_entries ADD CONSTRAINT FK_4E4F653910DAF24A FOREIGN KEY (actor_id) REFERENCES employees (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE leave_request_audit_entries DROP FOREIGN KEY FK_4E4F6539F2E1C15D');
        $this->addSql('ALTER TABLE leave_request_audit_entries DROP FOREIGN KEY FK_4E4F653910DAF24A');
        $this->addSql('DROP TABLE leave_request_audit_entries');
    }
}
