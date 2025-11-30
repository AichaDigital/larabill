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
            $table->decimal('total_amount', 15, 2)->default(0.00); // Monetary amount
            $table->decimal('threshold_amount', 15, 2)->default(10000.00); // Default EU threshold
            $table->boolean('threshold_exceeded')->default(false);
            $table->timestamp('exceeded_at')->nullable();
            $table->boolean('notification_sent')->default(false);
            $table->json('breakdown_by_country')->nullable(); // {"ES": 5000.00, "FR": 3000.00}
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

