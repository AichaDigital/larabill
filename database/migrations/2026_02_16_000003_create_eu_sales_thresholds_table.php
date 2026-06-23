<?php

use AichaDigital\Larabill\Support\MigrationHelper;
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
        Schema::create('eu_sales_thresholds', function (Blueprint $table) {
            $table->id();

            // User ID - agnostic type based on larabill config or users table detection
            MigrationHelper::userIdColumn($table, 'user_id');

            $table->integer('fiscal_year'); // e.g., 2024
            $table->integer('total_amount')->default(0); // Base-100 minor units (FixedDecimalCast:2): 500000 = €5,000.00
            $table->integer('threshold_amount')->default(1000000); // Base-100 minor units: 1000000 = €10,000.00 (default EU threshold)
            $table->boolean('threshold_exceeded')->default(false);
            $table->timestamp('exceeded_at')->nullable();
            $table->boolean('notification_sent')->default(false);
            $table->json('breakdown_by_country')->nullable(); // Base-100 minor units per country: {"DE": 200000, "FR": 300000}
            $table->string('currency', 3)->default('EUR'); // ISO 4217
            $table->timestamp('last_updated')->nullable();
            $table->timestamps();

            // Unique constraint: one record per user per fiscal year
            $table->unique(['user_id', 'fiscal_year']);

            // Indexes for common queries
            $table->index('threshold_exceeded');
            $table->index('fiscal_year');
            $table->index('last_updated');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('eu_sales_thresholds');
    }
};
