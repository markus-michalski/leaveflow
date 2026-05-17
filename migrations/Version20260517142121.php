<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260517142121 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add LDAP/Active Directory configuration fields to companies table (Phase 11.3)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE companies ADD ldap_enabled TINYINT DEFAULT 0 NOT NULL, ADD ldap_host VARCHAR(253) DEFAULT NULL, ADD ldap_port INT DEFAULT NULL, ADD ldap_encryption VARCHAR(4) DEFAULT NULL, ADD ldap_bind_dn VARCHAR(512) DEFAULT NULL, ADD ldap_bind_password VARCHAR(255) DEFAULT NULL, ADD ldap_base_dn VARCHAR(512) DEFAULT NULL, ADD ldap_user_filter VARCHAR(255) DEFAULT NULL, ADD ldap_group_manager_dn VARCHAR(512) DEFAULT NULL, ADD ldap_group_admin_dn VARCHAR(512) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE companies DROP ldap_enabled, DROP ldap_host, DROP ldap_port, DROP ldap_encryption, DROP ldap_bind_dn, DROP ldap_bind_password, DROP ldap_base_dn, DROP ldap_user_filter, DROP ldap_group_manager_dn, DROP ldap_group_admin_dn');
    }
}
