<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260507144251 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 9: bind AbsenceType to a specific LeaveEntitlement bucket (Regular/Carryover) so Resturlaub-typed requests cannot draw from the regular grant.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE absence_types ADD required_bucket VARCHAR(20) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE absence_types DROP required_bucket');
    }
}
