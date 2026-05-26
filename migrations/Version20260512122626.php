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
final class Version20260512122626 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 10.5 — 2FA columns: users.totp_secret/totp_enabled/backup_codes + companies.requires_two_factor/two_factor_enforced_from';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE companies ADD requires_two_factor TINYINT DEFAULT 0 NOT NULL, ADD two_factor_enforced_from DATE DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD totp_secret VARCHAR(128) DEFAULT NULL, ADD totp_enabled TINYINT DEFAULT 0 NOT NULL, ADD backup_codes JSON DEFAULT \'[]\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE companies DROP requires_two_factor, DROP two_factor_enforced_from');
        $this->addSql('ALTER TABLE users DROP totp_secret, DROP totp_enabled, DROP backup_codes');
    }
}
