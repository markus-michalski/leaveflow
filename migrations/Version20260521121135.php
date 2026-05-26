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
final class Version20260521121135 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add exit_leave_handling to companies table (Phase 13 DSGVO Lifecycle)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE companies ADD exit_leave_handling VARCHAR(30) DEFAULT \'pay_out\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE companies DROP exit_leave_handling');
    }
}
