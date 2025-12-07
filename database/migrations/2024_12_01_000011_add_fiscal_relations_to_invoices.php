<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * DEPRECATED: This migration is now a no-op.
     * The columns company_fiscal_config_id and customer_fiscal_data_id are now
     * created directly in create_invoices_table.php as part of ADR-001 architecture.
     * Foreign keys are added in 2025_01_26_000001_add_invoices_foreign_keys.php.
     */
    public function up(): void
    {
        // No-op: columns and foreign keys are now handled in:
        // - 2024_12_01_000003_create_invoices_table.php (columns)
        // - 2025_01_26_000001_add_invoices_foreign_keys.php (foreign keys)
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No-op: see up() comment
    }
};
