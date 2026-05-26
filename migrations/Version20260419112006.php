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
final class Version20260419112006 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 3 — add holiday_overrides and company_holidays tables.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE company_holidays (id INT AUTO_INCREMENT NOT NULL, holiday_date DATE NOT NULL, name VARCHAR(150) NOT NULL, company_id INT NOT NULL, INDEX IDX_22F1B461979B1AD6 (company_id), UNIQUE INDEX uniq_company_holiday_date (company_id, holiday_date), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE holiday_overrides (id INT AUTO_INCREMENT NOT NULL, federal_state VARCHAR(10) NOT NULL, override_date DATE NOT NULL, name VARCHAR(150) NOT NULL, type VARCHAR(10) NOT NULL, company_id INT NOT NULL, INDEX IDX_53D970B6979B1AD6 (company_id), UNIQUE INDEX uniq_override_company_state_date (company_id, federal_state, override_date), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE company_holidays ADD CONSTRAINT FK_22F1B461979B1AD6 FOREIGN KEY (company_id) REFERENCES companies (id)');
        $this->addSql('ALTER TABLE holiday_overrides ADD CONSTRAINT FK_53D970B6979B1AD6 FOREIGN KEY (company_id) REFERENCES companies (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE company_holidays DROP FOREIGN KEY FK_22F1B461979B1AD6');
        $this->addSql('ALTER TABLE holiday_overrides DROP FOREIGN KEY FK_53D970B6979B1AD6');
        $this->addSql('DROP TABLE company_holidays');
        $this->addSql('DROP TABLE holiday_overrides');
    }
}
