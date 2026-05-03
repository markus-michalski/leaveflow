<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260502104658 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 8: notifications + notification_preferences tables — in-app inbox + per-user email opt-out per NotificationType.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE notification_preferences (id INT AUTO_INCREMENT NOT NULL, email_enabled TINYINT DEFAULT 1 NOT NULL, type VARCHAR(40) NOT NULL, user_id INT NOT NULL, INDEX IDX_3CAA95B4A76ED395 (user_id), UNIQUE INDEX uniq_notif_pref_user_type (user_id, type), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE notifications (id INT AUTO_INCREMENT NOT NULL, read_at DATETIME DEFAULT NULL, type VARCHAR(40) NOT NULL, payload JSON NOT NULL, created_at DATETIME NOT NULL, related_entity_type VARCHAR(80) DEFAULT NULL, related_entity_id INT DEFAULT NULL, recipient_id INT NOT NULL, INDEX IDX_6000B0D3E92F8F78 (recipient_id), INDEX idx_notif_recipient_unread (recipient_id, read_at), INDEX idx_notif_recipient_created (recipient_id, created_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE notification_preferences ADD CONSTRAINT FK_3CAA95B4A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE notifications ADD CONSTRAINT FK_6000B0D3E92F8F78 FOREIGN KEY (recipient_id) REFERENCES users (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE notification_preferences DROP FOREIGN KEY FK_3CAA95B4A76ED395');
        $this->addSql('ALTER TABLE notifications DROP FOREIGN KEY FK_6000B0D3E92F8F78');
        $this->addSql('DROP TABLE notification_preferences');
        $this->addSql('DROP TABLE notifications');
    }
}
