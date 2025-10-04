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
        Schema::create('roi_queries', function (Blueprint $table) {
            $table->id();

            // User and VAT identification
            $table->string('user_id');
            $table->string('vat_number');
            $table->string('country_code', 2);

            // Query information
            $table->enum('query_type', ['api', 'cache']);
            $table->string('api_source')->nullable();
            $table->json('response_data')->nullable();
            $table->boolean('cache_used')->default(false);

            // Timestamps
            $table->timestamp('queried_at');
            $table->timestamp('legal_retention_until');
            $table->timestamps();

            // Indexes
            $table->index(['user_id', 'queried_at']);
            $table->index('query_type');
            $table->index('legal_retention_until');
            $table->index(['vat_number', 'country_code']);
            $table->index('api_source');
            $table->index('cache_used');

            // Constraints - removed for SQLite compatibility
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('roi_queries');
    }
};
