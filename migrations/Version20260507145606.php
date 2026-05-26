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
final class Version20260507145606 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Issue #16: align employees.schedule_weekly_hours column with the float property type to silence doctrine:schema:validate.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE employees CHANGE schedule_weekly_hours schedule_weekly_hours DOUBLE PRECISION NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE employees CHANGE schedule_weekly_hours schedule_weekly_hours NUMERIC(5, 2) NOT NULL');
    }
}
