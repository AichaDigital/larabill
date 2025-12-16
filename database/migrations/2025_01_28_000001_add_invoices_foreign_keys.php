<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * This migration adds foreign keys to the invoices table that reference
     * tables created in later migrations. Separated to avoid circular dependency.
     */
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            // Add foreign key for customer_id -> customers
            $table->foreign('customer_id')
                ->references('id')
                ->on('customers')
                ->nullOnDelete();

            // Add foreign key for company_fiscal_config_id -> company_fiscal_configs
            $table->foreign('company_fiscal_config_id')
                ->references('id')
                ->on('company_fiscal_configs')
                ->nullOnDelete();

            // Add foreign key for user_tax_profile_id -> user_tax_profiles (ADR-003)
            $table->foreign('user_tax_profile_id')
                ->references('id')
                ->on('user_tax_profiles')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropForeign(['customer_id']);
            $table->dropForeign(['company_fiscal_config_id']);
            $table->dropForeign(['user_tax_profile_id']);
        });
    }
};
