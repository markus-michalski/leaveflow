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

final class Version20260521120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Microsoft Teams integration fields to companies table (#77)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE companies ADD teams_enabled TINYINT(1) NOT NULL DEFAULT 0, ADD teams_webhook_url LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE companies DROP teams_enabled, DROP teams_webhook_url');
    }
}
