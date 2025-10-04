<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('company_fiscal_configs', function (Blueprint $table) {
            $table->id();

            // Company identification
            $table->string('company_id');
            $table->integer('fiscal_year');

            // Destination VAT settings
            $table->boolean('apply_destination_iva')->default(false);
            $table->boolean('auto_apply_destination')->default(true);
            $table->decimal('eu_sales_threshold', 10, 2)->default(10000.00);
            $table->decimal('current_eu_sales_amount', 10, 2)->default(0.00);
            $table->timestamp('threshold_exceeded_at')->nullable();
            $table->boolean('threshold_exceeded')->default(false);
            $table->boolean('notification_sent')->default(false);

            // Fiscal year configuration
            $table->string('fiscal_year_start', 5)->default('01-01');
            $table->string('currency', 3)->default('EUR');

            // Notification settings
            $table->string('threshold_notification_email')->nullable();

            // Custom rules
            $table->json('custom_threshold_rules')->nullable();

            // Timestamps
            $table->timestamps();

            // Indexes
            $table->unique(['company_id', 'fiscal_year'], 'unique_company_fiscal_year');
            $table->index('threshold_exceeded_at');
            $table->index('apply_destination_iva');
            $table->index('fiscal_year');
            $table->index('notification_sent');

            // Constraints - removed for SQLite compatibility
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('company_fiscal_configs');
    }
};
