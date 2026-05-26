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
final class Version20260509104430 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '#35 phase 2: scheduled_job_configs table for runtime toggle + last-run bookkeeping per scheduled handler.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE scheduled_job_configs (id INT AUTO_INCREMENT NOT NULL, last_run_at DATETIME DEFAULT NULL, last_run_status VARCHAR(20) DEFAULT NULL, last_error LONGTEXT DEFAULT NULL, name VARCHAR(80) NOT NULL, enabled TINYINT NOT NULL, UNIQUE INDEX UNIQ_6AA5B6085E237E06 (name), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE scheduled_job_configs');
    }
}
