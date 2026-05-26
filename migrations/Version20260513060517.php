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
final class Version20260513060517 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 10.6 — Company profile fields: address, logo_path, primary_color, tax_id, commercial_register';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE companies ADD address LONGTEXT DEFAULT NULL, ADD logo_path VARCHAR(255) DEFAULT NULL, ADD primary_color VARCHAR(7) DEFAULT NULL, ADD tax_id VARCHAR(50) DEFAULT NULL, ADD commercial_register VARCHAR(100) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE companies DROP address, DROP logo_path, DROP primary_color, DROP tax_id, DROP commercial_register');
    }
}
