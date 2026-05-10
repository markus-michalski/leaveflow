<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260510105205 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 9 — Location-scoped HolidayOverride (#47): nullable location_id for municipality-level holidays.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX uniq_override_company_state_date ON holiday_overrides');
        $this->addSql('ALTER TABLE holiday_overrides ADD location_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE holiday_overrides ADD CONSTRAINT FK_53D970B664D218E FOREIGN KEY (location_id) REFERENCES locations (id)');
        $this->addSql('CREATE INDEX IDX_53D970B664D218E ON holiday_overrides (location_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_override_company_state_loc_date ON holiday_overrides (company_id, federal_state, location_id, override_date)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE holiday_overrides DROP FOREIGN KEY FK_53D970B664D218E');
        $this->addSql('DROP INDEX IDX_53D970B664D218E ON holiday_overrides');
        $this->addSql('DROP INDEX uniq_override_company_state_loc_date ON holiday_overrides');
        $this->addSql('ALTER TABLE holiday_overrides DROP location_id');
        $this->addSql('CREATE UNIQUE INDEX uniq_override_company_state_date ON holiday_overrides (company_id, federal_state, override_date)');
    }
}
