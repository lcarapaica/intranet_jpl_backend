<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260527200628 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // Add column as nullable first
        $this->addSql('ALTER TABLE calendar_event ADD date DATE DEFAULT NULL');
        
        // Copy the date portion from existing start_at values
        $this->addSql('UPDATE calendar_event SET date = DATE(start_at)');
        
        // Make the column NOT NULL
        $this->addSql('ALTER TABLE calendar_event CHANGE date date DATE NOT NULL');
        
        // Make start_at and end_at nullable
        $this->addSql('ALTER TABLE calendar_event CHANGE start_at start_at DATETIME DEFAULT NULL, CHANGE end_at end_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // Set fallback values for start_at and end_at if they are null before making them NOT NULL
        $this->addSql('UPDATE calendar_event SET start_at = date WHERE start_at IS NULL');
        $this->addSql('UPDATE calendar_event SET end_at = date WHERE end_at IS NULL');

        $this->addSql('ALTER TABLE calendar_event DROP date, CHANGE start_at start_at DATETIME NOT NULL, CHANGE end_at end_at DATETIME NOT NULL');
    }
}
