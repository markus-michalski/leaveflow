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
final class Version20260417165157 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 2: locations + employees (1:1 optional to users) with embedded work schedule.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE employees (id INT AUTO_INCREMENT NOT NULL, full_name VARCHAR(150) NOT NULL, employee_number VARCHAR(50) NOT NULL, joined_at DATE NOT NULL, left_at DATE DEFAULT NULL, schedule_hours_per_day_json JSON NOT NULL, schedule_weekly_hours NUMERIC(5, 2) NOT NULL, company_id INT NOT NULL, location_id INT NOT NULL, user_id INT DEFAULT NULL, INDEX IDX_BA82C300979B1AD6 (company_id), INDEX IDX_BA82C30064D218E (location_id), UNIQUE INDEX UNIQ_BA82C300A76ED395 (user_id), UNIQUE INDEX uniq_employee_number_per_company (company_id, employee_number), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE locations (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(150) NOT NULL, country VARCHAR(2) NOT NULL, federal_state VARCHAR(10) NOT NULL, city VARCHAR(150) NOT NULL, company_id INT NOT NULL, INDEX IDX_17E64ABA979B1AD6 (company_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE employees ADD CONSTRAINT FK_BA82C300979B1AD6 FOREIGN KEY (company_id) REFERENCES companies (id)');
        $this->addSql('ALTER TABLE employees ADD CONSTRAINT FK_BA82C30064D218E FOREIGN KEY (location_id) REFERENCES locations (id)');
        $this->addSql('ALTER TABLE employees ADD CONSTRAINT FK_BA82C300A76ED395 FOREIGN KEY (user_id) REFERENCES users (id)');
        $this->addSql('ALTER TABLE locations ADD CONSTRAINT FK_17E64ABA979B1AD6 FOREIGN KEY (company_id) REFERENCES companies (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE employees DROP FOREIGN KEY FK_BA82C300979B1AD6');
        $this->addSql('ALTER TABLE employees DROP FOREIGN KEY FK_BA82C30064D218E');
        $this->addSql('ALTER TABLE employees DROP FOREIGN KEY FK_BA82C300A76ED395');
        $this->addSql('ALTER TABLE locations DROP FOREIGN KEY FK_17E64ABA979B1AD6');
        $this->addSql('DROP TABLE employees');
        $this->addSql('DROP TABLE locations');
    }
}
