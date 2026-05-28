<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260527203426 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE INDEX idx_calendar_event_deleted_at ON calendar_event (deleted_at)');
        $this->addSql('CREATE INDEX idx_calendar_event_date ON calendar_event (date)');
        $this->addSql('CREATE INDEX idx_news_deleted_at ON news (deleted_at)');
        $this->addSql('CREATE INDEX idx_product_deleted_at ON product (deleted_at)');
        $this->addSql('CREATE INDEX idx_product_empresa ON product (empresa)');
        $this->addSql('CREATE INDEX idx_user_deleted_at ON user (deleted_at)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX idx_calendar_event_deleted_at ON calendar_event');
        $this->addSql('DROP INDEX idx_calendar_event_date ON calendar_event');
        $this->addSql('DROP INDEX idx_news_deleted_at ON news');
        $this->addSql('DROP INDEX idx_product_deleted_at ON product');
        $this->addSql('DROP INDEX idx_product_empresa ON product');
        $this->addSql('DROP INDEX idx_user_deleted_at ON `user`');
    }
}
