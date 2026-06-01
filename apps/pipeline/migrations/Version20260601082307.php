<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * P9: Add publish_requested_at to found_deal.
 *
 * Records when a Publish was requested for a deal (intent only).
 * The posted_deal row is written by a future real ChannelPublisher after
 * a successful channel delivery; this column tracks stub intent only.
 */
final class Version20260601082307 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add publish_requested_at (nullable) to found_deal for publish-intent tracking (P9).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE found_deal ADD publish_requested_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE found_deal DROP publish_requested_at');
    }
}
