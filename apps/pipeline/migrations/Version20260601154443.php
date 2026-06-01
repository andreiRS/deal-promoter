<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Cross-run page continuation: add next_start_page to cycle_run.
 *
 * Stores the Keepa `/deal` page index the next Cycle should resume from, so
 * cycles walk deeper across runs instead of re-scanning page 0 every time.
 * Existing rows default to 0 (start from the top).
 */
final class Version20260601154443 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add next_start_page (default 0) to cycle_run for cross-run page continuation.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cycle_run ADD next_start_page INT NOT NULL DEFAULT 0');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cycle_run DROP next_start_page');
    }
}
