<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260504000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add variation, sub_types, is_banned, is_suspended to collection_card_view';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE collection_card_view ADD COLUMN variation VARCHAR(50) DEFAULT NULL');
        $this->addSql('ALTER TABLE collection_card_view ADD COLUMN sub_types VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE collection_card_view ADD COLUMN is_banned BOOLEAN NOT NULL DEFAULT false');
        $this->addSql('ALTER TABLE collection_card_view ADD COLUMN is_suspended BOOLEAN NOT NULL DEFAULT false');
        $this->addSql('CREATE INDEX idx_view_variation ON collection_card_view (variation)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_view_variation');
        $this->addSql('ALTER TABLE collection_card_view DROP COLUMN variation');
        $this->addSql('ALTER TABLE collection_card_view DROP COLUMN sub_types');
        $this->addSql('ALTER TABLE collection_card_view DROP COLUMN is_banned');
        $this->addSql('ALTER TABLE collection_card_view DROP COLUMN is_suspended');
    }
}
