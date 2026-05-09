<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Initial schema:
 *   - processed_event: dedup table; UNIQUE (provider, external_event_id) is the source of truth.
 *   - received_webhook: append-only audit log, including signature failures.
 *   - messenger_messages: Doctrine messenger transport.
 */
final class Version20260509071454 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Initial schema: processed_event (idempotency), received_webhook (audit), messenger_messages.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE processed_event (
              id BLOB NOT NULL,
              provider VARCHAR(64) NOT NULL,
              external_event_id VARCHAR(255) NOT NULL,
              event_type VARCHAR(128) NOT NULL,
              status VARCHAR(16) NOT NULL,
              received_at DATETIME NOT NULL,
              processed_at DATETIME DEFAULT NULL,
              attempt_count INTEGER DEFAULT 0 NOT NULL,
              last_error CLOB DEFAULT NULL,
              last_error_class VARCHAR(255) DEFAULT NULL,
              PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('CREATE INDEX idx_status ON processed_event (status)');
        $this->addSql('CREATE INDEX idx_received_at ON processed_event (received_at)');
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_provider_external_event ON processed_event (provider, external_event_id)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE received_webhook (
              id BLOB NOT NULL,
              provider VARCHAR(64) NOT NULL,
              payload CLOB NOT NULL,
              headers CLOB NOT NULL,
              ip_address VARCHAR(45) DEFAULT NULL,
              received_at DATETIME NOT NULL,
              signature_valid BOOLEAN NOT NULL,
              processed_event_id BLOB DEFAULT NULL,
              PRIMARY KEY (id),
              CONSTRAINT FK_28971D0FE63645A9 FOREIGN KEY (processed_event_id) REFERENCES processed_event (id) ON DELETE
              SET
                NULL NOT DEFERRABLE INITIALLY IMMEDIATE
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_28971D0FE63645A9 ON received_webhook (processed_event_id)');
        $this->addSql('CREATE INDEX idx_received_at_received ON received_webhook (received_at)');
        $this->addSql('CREATE INDEX idx_signature_valid ON received_webhook (signature_valid)');
        $this->addSql(<<<'SQL'
            CREATE TABLE messenger_messages (
              id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
              body CLOB NOT NULL,
              headers CLOB NOT NULL,
              queue_name VARCHAR(190) NOT NULL,
              created_at DATETIME NOT NULL,
              available_at DATETIME NOT NULL,
              delivered_at DATETIME DEFAULT NULL
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_75EA56E0FB7336F0 ON messenger_messages (queue_name)');
        $this->addSql('CREATE INDEX IDX_75EA56E0E3BD61CE ON messenger_messages (available_at)');
        $this->addSql('CREATE INDEX IDX_75EA56E016BA31DB ON messenger_messages (delivered_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE processed_event');
        $this->addSql('DROP TABLE received_webhook');
        $this->addSql('DROP TABLE messenger_messages');
    }
}
