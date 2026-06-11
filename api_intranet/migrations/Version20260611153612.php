<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260611153612 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE calendar_event_user (calendar_event_id INT NOT NULL, user_id INT NOT NULL, INDEX IDX_4125EE77495C8E3 (calendar_event_id), INDEX IDX_4125EE7A76ED395 (user_id), PRIMARY KEY(calendar_event_id, user_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE calendar_event_user ADD CONSTRAINT FK_4125EE77495C8E3 FOREIGN KEY (calendar_event_id) REFERENCES calendar_event (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE calendar_event_user ADD CONSTRAINT FK_4125EE7A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE calendar_event ADD cliente VARCHAR(255) DEFAULT NULL, ADD color VARCHAR(7) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE calendar_event_user DROP FOREIGN KEY FK_4125EE77495C8E3');
        $this->addSql('ALTER TABLE calendar_event_user DROP FOREIGN KEY FK_4125EE7A76ED395');
        $this->addSql('DROP TABLE calendar_event_user');
        $this->addSql('ALTER TABLE calendar_event DROP cliente, DROP color');
    }
}
