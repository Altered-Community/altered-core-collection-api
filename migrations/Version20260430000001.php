<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260430000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Initial schema: user, collection_card (write model), collection_card_view (read model)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE "user" (
            id UUID NOT NULL,
            keycloak_id VARCHAR(255) NOT NULL,
            email VARCHAR(255) DEFAULT NULL,
            username VARCHAR(100) DEFAULT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            is_admin BOOLEAN NOT NULL DEFAULT false,
            PRIMARY KEY(id)
        )');
        $this->addSql('CREATE UNIQUE INDEX uniq_user_keycloak ON "user" (keycloak_id)');
        $this->addSql('COMMENT ON COLUMN "user".id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN "user".created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN "user".updated_at IS \'(DC2Type:datetime_immutable)\'');

        // Write model — minimal aggregate
        $this->addSql('CREATE TABLE collection_card (
            id SERIAL NOT NULL,
            user_id UUID NOT NULL,
            card_reference VARCHAR(100) NOT NULL,
            quantity SMALLINT NOT NULL DEFAULT 1,
            is_foil BOOLEAN NOT NULL DEFAULT false,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            PRIMARY KEY(id)
        )');
        $this->addSql('CREATE UNIQUE INDEX uq_user_card ON collection_card (user_id, card_reference)');
        $this->addSql('CREATE INDEX idx_collection_user ON collection_card (user_id)');
        $this->addSql('COMMENT ON COLUMN collection_card.user_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN collection_card.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN collection_card.updated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE collection_card
            ADD CONSTRAINT fk_card_user FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        // Read model — denormalized view of collection + card metadata
        $this->addSql('CREATE TABLE collection_card_view (
            id SERIAL NOT NULL,
            collection_card_id INT NOT NULL,
            user_id UUID NOT NULL,
            card_reference VARCHAR(100) NOT NULL,
            quantity SMALLINT NOT NULL DEFAULT 1,
            is_foil BOOLEAN NOT NULL DEFAULT false,
            card_set VARCHAR(30) NOT NULL DEFAULT \'\',
            faction VARCHAR(10) NOT NULL DEFAULT \'\',
            rarity VARCHAR(20) NOT NULL DEFAULT \'\',
            name VARCHAR(255) DEFAULT NULL,
            image_path TEXT DEFAULT NULL,
            main_cost SMALLINT DEFAULT NULL,
            recall_cost SMALLINT DEFAULT NULL,
            card_type VARCHAR(100) DEFAULT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            PRIMARY KEY(id)
        )');
        $this->addSql('CREATE UNIQUE INDEX uniq_view_collection_card ON collection_card_view (collection_card_id)');
        $this->addSql('CREATE INDEX idx_view_user ON collection_card_view (user_id)');
        $this->addSql('CREATE INDEX idx_view_filters ON collection_card_view (card_set, faction, rarity)');
        $this->addSql('COMMENT ON COLUMN collection_card_view.user_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN collection_card_view.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN collection_card_view.updated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE collection_card_view
            ADD CONSTRAINT fk_view_collection_card FOREIGN KEY (collection_card_id) REFERENCES collection_card (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE collection_card_view
            ADD CONSTRAINT fk_view_user FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE collection_card_view DROP CONSTRAINT fk_view_collection_card');
        $this->addSql('ALTER TABLE collection_card_view DROP CONSTRAINT fk_view_user');
        $this->addSql('ALTER TABLE collection_card DROP CONSTRAINT fk_card_user');
        $this->addSql('DROP TABLE collection_card_view');
        $this->addSql('DROP TABLE collection_card');
        $this->addSql('DROP TABLE "user"');
    }
}
