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
final class Version20260509135808 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 9 — 6-week illness alert: AbsenceType.isIllnessTracking flag + illness_alerts idempotency table.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE illness_alerts (id INT AUTO_INCREMENT NOT NULL, period_started_on DATE NOT NULL, days_count INT NOT NULL, alerted_at DATETIME NOT NULL, employee_id INT NOT NULL, INDEX idx_illness_alert_employee (employee_id), UNIQUE INDEX uniq_illness_alert_employee_period (employee_id, period_started_on), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE illness_alerts ADD CONSTRAINT FK_799A87D8C03F15C FOREIGN KEY (employee_id) REFERENCES employees (id)');
        $this->addSql('ALTER TABLE absence_types ADD is_illness_tracking TINYINT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE illness_alerts DROP FOREIGN KEY FK_799A87D8C03F15C');
        $this->addSql('DROP TABLE illness_alerts');
        $this->addSql('ALTER TABLE absence_types DROP is_illness_tracking');
    }
}
