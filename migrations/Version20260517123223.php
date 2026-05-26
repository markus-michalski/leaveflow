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
final class Version20260517123223 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 11.2 — Microsoft Entra ID OAuth: add entra_oauth_enabled and entra_oauth_tenant_id to companies';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
                ALTER TABLE
                  companies
                ADD
                  entra_oauth_enabled TINYINT DEFAULT 0 NOT NULL,
                ADD
                  entra_oauth_tenant_id VARCHAR(36) DEFAULT NULL
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE companies DROP entra_oauth_enabled, DROP entra_oauth_tenant_id');
    }
}
