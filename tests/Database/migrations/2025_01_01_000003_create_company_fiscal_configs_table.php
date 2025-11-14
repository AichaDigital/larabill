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
        Schema::create('fiscal_settings', function (Blueprint $table) {
            $table->id();

            // Company identification
            $table->string('user_id');
            $table->integer('fiscal_year');

            // OSS and ROI status
            $table->boolean('is_oss')->default(false)->comment('Whether the company is registered in OSS (One Stop Shop)');
            $table->boolean('is_roi')->default(false)->comment('Whether the company is a Reverse Charge Operator (ROI)');

            // Destination VAT settings
            $table->boolean('apply_destination_iva')->default(false);
            $table->boolean('auto_apply_destination')->default(true);
            $table->integer('eu_sales_threshold')->default(1000000); // Base 100: €10,000.00 = 1000000
            $table->integer('current_eu_sales_amount')->default(0); // Base 100: €0.00 = 0
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
            $table->unique(['user_id', 'fiscal_year'], 'unique_company_fiscal_year');
            $table->index('threshold_exceeded_at');
            $table->index('apply_destination_iva');
            $table->index('fiscal_year');
            $table->index('notification_sent');
            $table->index('is_oss');
            $table->index('is_roi');

            // Constraints - removed for SQLite compatibility
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fiscal_settings');
    }
};
