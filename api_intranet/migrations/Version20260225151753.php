<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260225151753 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE product (id INT AUTO_INCREMENT NOT NULL, nombre VARCHAR(255) NOT NULL, categoria VARCHAR(255) NOT NULL, marca VARCHAR(255) NOT NULL, modelo VARCHAR(255) NOT NULL, caracteristicas LONGTEXT DEFAULT NULL, color VARCHAR(100) DEFAULT NULL, serial VARCHAR(150) NOT NULL, condicion JSON NOT NULL COMMENT \'(DC2Type:json)\', locacion VARCHAR(255) DEFAULT NULL, deleted_at DATETIME DEFAULT NULL, UNIQUE INDEX UNIQ_D34A04ADD374C9DC (serial), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE product');
    }
}
