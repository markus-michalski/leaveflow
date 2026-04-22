<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260422134400 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 6: Department aggregate (lead + deputy) + Employee.department FK for approval hierarchy.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE departments (id INT AUTO_INCREMENT NOT NULL, active TINYINT DEFAULT 1 NOT NULL, name VARCHAR(150) NOT NULL, company_id INT NOT NULL, lead_id INT DEFAULT NULL, deputy_id INT DEFAULT NULL, INDEX IDX_16AEB8D4979B1AD6 (company_id), INDEX IDX_16AEB8D455458D (lead_id), INDEX IDX_16AEB8D44B6F93BB (deputy_id), UNIQUE INDEX uniq_department_company_name (company_id, name), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE departments ADD CONSTRAINT FK_16AEB8D4979B1AD6 FOREIGN KEY (company_id) REFERENCES companies (id)');
        $this->addSql('ALTER TABLE departments ADD CONSTRAINT FK_16AEB8D455458D FOREIGN KEY (lead_id) REFERENCES employees (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE departments ADD CONSTRAINT FK_16AEB8D44B6F93BB FOREIGN KEY (deputy_id) REFERENCES employees (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE employees ADD department_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE employees ADD CONSTRAINT FK_BA82C300AE80F5DF FOREIGN KEY (department_id) REFERENCES departments (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_BA82C300AE80F5DF ON employees (department_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE departments DROP FOREIGN KEY FK_16AEB8D4979B1AD6');
        $this->addSql('ALTER TABLE departments DROP FOREIGN KEY FK_16AEB8D455458D');
        $this->addSql('ALTER TABLE departments DROP FOREIGN KEY FK_16AEB8D44B6F93BB');
        $this->addSql('DROP TABLE departments');
        $this->addSql('ALTER TABLE employees DROP FOREIGN KEY FK_BA82C300AE80F5DF');
        $this->addSql('DROP INDEX IDX_BA82C300AE80F5DF ON employees');
        $this->addSql('ALTER TABLE employees DROP department_id');
    }
}
