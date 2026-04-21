<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260421151223 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 5: leave_requests + leave_request_days tables (request with start/end/dayType/status and per-day breakdown snapshot).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE leave_request_days (id INT AUTO_INCREMENT NOT NULL, date DATE NOT NULL, hours DOUBLE PRECISION NOT NULL, status VARCHAR(20) NOT NULL, reason VARCHAR(30) DEFAULT NULL, leave_request_id INT NOT NULL, INDEX IDX_61FFC92EF2E1C15D (leave_request_id), UNIQUE INDEX uniq_leave_request_day (leave_request_id, date), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE leave_requests (id INT AUTO_INCREMENT NOT NULL, status VARCHAR(20) NOT NULL, total_hours DOUBLE PRECISION NOT NULL, start_date DATE NOT NULL, end_date DATE NOT NULL, day_type VARCHAR(20) NOT NULL, requested_at DATETIME NOT NULL, employee_id INT NOT NULL, absence_type_id INT NOT NULL, INDEX IDX_45ADFEF28C03F15C (employee_id), INDEX IDX_45ADFEF2CCAA91B (absence_type_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE leave_request_days ADD CONSTRAINT FK_61FFC92EF2E1C15D FOREIGN KEY (leave_request_id) REFERENCES leave_requests (id)');
        $this->addSql('ALTER TABLE leave_requests ADD CONSTRAINT FK_45ADFEF28C03F15C FOREIGN KEY (employee_id) REFERENCES employees (id)');
        $this->addSql('ALTER TABLE leave_requests ADD CONSTRAINT FK_45ADFEF2CCAA91B FOREIGN KEY (absence_type_id) REFERENCES absence_types (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE leave_request_days DROP FOREIGN KEY FK_61FFC92EF2E1C15D');
        $this->addSql('ALTER TABLE leave_requests DROP FOREIGN KEY FK_45ADFEF28C03F15C');
        $this->addSql('ALTER TABLE leave_requests DROP FOREIGN KEY FK_45ADFEF2CCAA91B');
        $this->addSql('DROP TABLE leave_request_days');
        $this->addSql('DROP TABLE leave_requests');
    }
}
