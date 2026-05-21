<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260520164927 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('UPDATE kanban_task SET category = CONCAT(\'["\', category, \'"]\') WHERE category NOT LIKE \'[%\'');
        $this->addSql('ALTER TABLE kanban_task ADD message LONGTEXT DEFAULT NULL, ADD due_at DATETIME DEFAULT NULL, ADD position INT DEFAULT 0 NOT NULL, CHANGE category category JSON NOT NULL COMMENT \'(DC2Type:json)\'');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE kanban_task DROP message, DROP due_at, DROP position, CHANGE category category VARCHAR(100) NOT NULL');
    }
}
