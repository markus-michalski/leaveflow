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
final class Version20260521053747 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Encrypt ldap_bind_password at rest: widen column from VARCHAR(255) to LONGTEXT to accommodate sodium ciphertext + nonce.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE companies CHANGE ldap_bind_password ldap_bind_password LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE companies CHANGE ldap_bind_password ldap_bind_password VARCHAR(255) DEFAULT NULL');
    }
}
