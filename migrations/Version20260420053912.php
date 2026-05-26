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
final class Version20260420053912 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 4: absence_types + leave_entitlements tables (AbsenceType per company, annual entitlements per employee/year/type).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE absence_types (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(100) NOT NULL, color VARCHAR(7) NOT NULL, deducts_from_leave TINYINT NOT NULL, requires_approval TINYINT NOT NULL, active TINYINT DEFAULT 1 NOT NULL, company_id INT NOT NULL, INDEX IDX_39F7162B979B1AD6 (company_id), UNIQUE INDEX uniq_absence_type_name_per_company (company_id, name), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE leave_entitlements (id INT AUTO_INCREMENT NOT NULL, hours_used DOUBLE PRECISION NOT NULL, year INT NOT NULL, type VARCHAR(20) NOT NULL, hours_granted DOUBLE PRECISION NOT NULL, expires_at DATE DEFAULT NULL, employee_id INT NOT NULL, INDEX IDX_A22189678C03F15C (employee_id), UNIQUE INDEX uniq_entitlement_employee_year_type (employee_id, year, type), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE absence_types ADD CONSTRAINT FK_39F7162B979B1AD6 FOREIGN KEY (company_id) REFERENCES companies (id)');
        $this->addSql('ALTER TABLE leave_entitlements ADD CONSTRAINT FK_A22189678C03F15C FOREIGN KEY (employee_id) REFERENCES employees (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE absence_types DROP FOREIGN KEY FK_39F7162B979B1AD6');
        $this->addSql('ALTER TABLE leave_entitlements DROP FOREIGN KEY FK_A22189678C03F15C');
        $this->addSql('DROP TABLE absence_types');
        $this->addSql('DROP TABLE leave_entitlements');
    }
}
