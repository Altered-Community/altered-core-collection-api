<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260504000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add ocean_power, mountain_power, forest_power to collection_card_view';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE collection_card_view ADD COLUMN ocean_power SMALLINT DEFAULT NULL');
        $this->addSql('ALTER TABLE collection_card_view ADD COLUMN mountain_power SMALLINT DEFAULT NULL');
        $this->addSql('ALTER TABLE collection_card_view ADD COLUMN forest_power SMALLINT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE collection_card_view DROP COLUMN ocean_power');
        $this->addSql('ALTER TABLE collection_card_view DROP COLUMN mountain_power');
        $this->addSql('ALTER TABLE collection_card_view DROP COLUMN forest_power');
    }
}
