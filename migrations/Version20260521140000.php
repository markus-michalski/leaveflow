<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260521140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Slack integration fields to companies and users tables (#74, #75)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE companies ADD slack_enabled TINYINT(1) NOT NULL DEFAULT 0, ADD slack_bot_token VARCHAR(512) DEFAULT NULL, ADD slack_signing_secret VARCHAR(512) DEFAULT NULL, ADD slack_channel_id VARCHAR(32) DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD slack_user_id VARCHAR(32) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE companies DROP slack_enabled, DROP slack_bot_token, DROP slack_signing_secret, DROP slack_channel_id');
        $this->addSql('ALTER TABLE users DROP slack_user_id');
    }
}
