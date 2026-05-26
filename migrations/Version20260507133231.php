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
final class Version20260507133231 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 9: add from/to AbsenceType FKs to leave_request_audit_entries for AdminTypeChange tracking.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE leave_request_audit_entries ADD from_absence_type_id INT DEFAULT NULL, ADD to_absence_type_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE leave_request_audit_entries ADD CONSTRAINT FK_4E4F653945CDCC04 FOREIGN KEY (from_absence_type_id) REFERENCES absence_types (id)');
        $this->addSql('ALTER TABLE leave_request_audit_entries ADD CONSTRAINT FK_4E4F65398D16ED7A FOREIGN KEY (to_absence_type_id) REFERENCES absence_types (id)');
        $this->addSql('CREATE INDEX IDX_4E4F653945CDCC04 ON leave_request_audit_entries (from_absence_type_id)');
        $this->addSql('CREATE INDEX IDX_4E4F65398D16ED7A ON leave_request_audit_entries (to_absence_type_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE leave_request_audit_entries DROP FOREIGN KEY FK_4E4F653945CDCC04');
        $this->addSql('ALTER TABLE leave_request_audit_entries DROP FOREIGN KEY FK_4E4F65398D16ED7A');
        $this->addSql('DROP INDEX IDX_4E4F653945CDCC04 ON leave_request_audit_entries');
        $this->addSql('DROP INDEX IDX_4E4F65398D16ED7A ON leave_request_audit_entries');
        $this->addSql('ALTER TABLE leave_request_audit_entries DROP from_absence_type_id, DROP to_absence_type_id');
    }
}
