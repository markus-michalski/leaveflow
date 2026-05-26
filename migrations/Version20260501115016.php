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
final class Version20260501115016 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 7: blackout_periods table — admin-managed hard-block date ranges (company-wide or department-scoped)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE blackout_periods (id INT AUTO_INCREMENT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, start_date DATE NOT NULL, end_date DATE NOT NULL, reason VARCHAR(255) NOT NULL, company_id INT NOT NULL, department_id INT DEFAULT NULL, INDEX IDX_A6C40732979B1AD6 (company_id), INDEX IDX_A6C40732AE80F5DF (department_id), INDEX idx_blackout_company_range (company_id, start_date, end_date), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE blackout_periods ADD CONSTRAINT FK_A6C40732979B1AD6 FOREIGN KEY (company_id) REFERENCES companies (id)');
        $this->addSql('ALTER TABLE blackout_periods ADD CONSTRAINT FK_A6C40732AE80F5DF FOREIGN KEY (department_id) REFERENCES departments (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE blackout_periods DROP FOREIGN KEY FK_A6C40732979B1AD6');
        $this->addSql('ALTER TABLE blackout_periods DROP FOREIGN KEY FK_A6C40732AE80F5DF');
        $this->addSql('DROP TABLE blackout_periods');
    }
}
