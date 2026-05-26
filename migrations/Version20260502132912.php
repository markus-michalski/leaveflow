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
final class Version20260502132912 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 8: companies.approval_escalation_days threshold + leave_requests.escalation_notified_at idempotency stamp.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE companies ADD approval_escalation_days INT DEFAULT 3 NOT NULL');
        $this->addSql('ALTER TABLE leave_requests ADD escalation_notified_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE companies DROP approval_escalation_days');
        $this->addSql('ALTER TABLE leave_requests DROP escalation_notified_at');
    }
}
