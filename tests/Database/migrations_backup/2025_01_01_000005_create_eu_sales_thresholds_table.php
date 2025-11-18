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
        Schema::create('eu_sales_thresholds', function (Blueprint $table) {
            $table->id();

            // User identification
            $table->string('user_id');
            $table->integer('fiscal_year');

            // Sales amounts (using base 100 format: €12.34 = 1234)
            $table->integer('total_amount')->default(0); // Base 100: €0.00 = 0
            $table->integer('threshold_amount')->default(1000000); // Base 100: €10,000.00 = 1000000
            $table->string('currency', 3)->default('EUR');

            // Threshold status
            $table->boolean('threshold_exceeded')->default(false);
            $table->timestamp('exceeded_at')->nullable();
            $table->boolean('notification_sent')->default(false);

            // Breakdown by country
            $table->json('breakdown_by_country')->nullable();

            // Metadata
            $table->timestamp('last_updated');

            // Timestamps
            $table->timestamps();

            // Indexes
            $table->unique(['user_id', 'fiscal_year']);
            $table->index('threshold_exceeded');
            $table->index('exceeded_at');
            $table->index('fiscal_year');
            $table->index('notification_sent');
            $table->index('total_amount');

            // Constraints - removed for SQLite compatibility
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
